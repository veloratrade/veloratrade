<?php

declare(strict_types=1);

namespace Velora\Admin;

use Velora\Core\Database;
use Velora\Core\Config;

/**
 * Admin Overview dashboard (Module A) — computes metrics from the REAL schema
 * only. Nothing here is fabricated: where a metric cannot be derived from
 * actual data, it is reported as unavailable (null) with an accompanying
 * `available`/`reason` field rather than a made-up number.
 */
final class AdminOverviewService
{
    /** @return array<string,mixed> */
    public function overview(): array
    {
        return [
            'users' => $this->usersMetrics(),
            'trading' => $this->tradingMetrics(),
            'ai' => $this->aiMetrics(),
            'system' => $this->systemMetrics(),
            'billing' => $this->billingMetrics(),
        ];
    }

    private function usersMetrics(): array
    {
        $pdo = Database::connection();
        $cutoff = gmdate('Y-m-d H:i:s', time() - 86400); // UTC, portable across PDO drivers
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status=\'active\' THEN 1 ELSE 0 END) AS active,
                    SUM(CASE WHEN status=\'suspended\' THEN 1 ELSE 0 END) AS suspended,
                    SUM(CASE WHEN created_at >= :cutoff THEN 1 ELSE 0 END) AS new
             FROM users'
        );
        $stmt->execute(['cutoff' => $cutoff]);
        $row = $stmt->fetch();
        $admins = $pdo->query("SELECT COUNT(*) AS n FROM users WHERE role IN ('admin','super_admin')")->fetch()['n'];

        $hasPlan = $this->hasColumn($pdo, 'users', 'plan');
        $free = $pro = null;
        if ($hasPlan) {
            $free = (int) $pdo->query("SELECT COUNT(*) AS n FROM users WHERE plan='free'")->fetch()['n'];
            $pro = (int) $pdo->query("SELECT COUNT(*) AS n FROM users WHERE plan='pro'")->fetch()['n'];
        }

        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'suspended' => (int) ($row['suspended'] ?? 0),
            'newLast24h' => (int) ($row['new'] ?? 0),
            'free' => $free,
            'pro' => $pro,
            'admins' => (int) $admins,
            'planDistributionAvailable' => $hasPlan,
        ];
    }

    private function tradingMetrics(): array
    {
        $pdo = Database::connection();
        $connected = (int) $pdo->query('SELECT COUNT(*) AS n FROM trading_accounts WHERE sync_status=\'CONNECTED\'')->fetch()['n'];
        $metaapiConnected = (int) $pdo->query(
            'SELECT COUNT(*) AS n FROM trading_accounts WHERE metaapi_account_id IS NOT NULL AND metaapi_account_id <> \'\' AND sync_status=\'CONNECTED\''
        )->fetch()['n'];
        $tradeCount = (int) $pdo->query('SELECT COUNT(*) AS n FROM trades')->fetch()['n'];
        $recent = $pdo->query('SELECT open_time, symbol, direction, profit_loss FROM trades ORDER BY open_time DESC LIMIT 10')->fetchAll();

        return [
            'connectedAccounts' => $connected,
            'metaapiConnected' => $metaapiConnected,
            'totalTrades' => $tradeCount,
            'recentActivity' => array_map(static fn ($r) => [
                'symbol' => $r['symbol'],
                'direction' => $r['direction'],
                'profitLoss' => (float) $r['profit_loss'],
                'openTime' => $r['open_time'],
            ], $recent),
        ];
    }

    private function aiMetrics(): array
    {
        $pdo = Database::connection();
        $out = ['available' => true];

        $out['providerStatus'] = $this->tryQuery($pdo, 'SELECT provider, status FROM ai_provider_credentials');
        $out['enabledProviders'] = $this->tryQuery($pdo, 'SELECT DISTINCT provider FROM ai_feature_providers WHERE enabled=1');

        // verified = status VALID; blocked = confirmed-invalid states.
        $out['verifiedProviders'] = $this->tryQuery($pdo, "SELECT provider FROM ai_provider_credentials WHERE status='VALID'");
        $out['blockedProviders'] = $this->tryQuery($pdo, "SELECT provider FROM ai_provider_credentials WHERE status IN ('INVALID_CREDENTIAL','EXPIRED','REVOKED','DISABLED')");

        $req = $this->tryRow($pdo, 'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status=\'success\' THEN 1 ELSE 0 END) AS succeeded,
                    SUM(CASE WHEN status IN (\'failed\',\'quota_exhausted\',\'timeout\') THEN 1 ELSE 0 END) AS failed,
                    SUM(CASE WHEN status=\'success\' THEN tokens_used ELSE 0 END) AS tokens
             FROM ai_requests');
        $out['requests'] = $req !== null ? [
            'total' => (int) ($req['total'] ?? 0),
            'succeeded' => (int) ($req['succeeded'] ?? 0),
            'failed' => (int) ($req['failed'] ?? 0),
            'tokensUsed' => (int) ($req['tokens'] ?? 0),
        ] : null;

        // Rate-limit/blocked events: quota_exhausted request rows + live limiter buckets.
        $out['rateLimitEvents'] = $this->tryRow($pdo, "SELECT COUNT(*) AS n FROM ai_requests WHERE status='quota_exhausted'");
        $out['activeLimiterBuckets'] = $this->tryRow($pdo, 'SELECT COUNT(*) AS n FROM rate_limits');
        $out['internalUsageLabel'] = 'internal usage'; // explicitly NOT provider billing

        return $out;
    }

    private function systemMetrics(): array
    {
        $pdo = Database::connection();
        $dbLatency = $this->measureDbLatency($pdo);

        return [
            'api' => ['status' => 'ok', 'uptimeNote' => 'API responding'],
            'database' => ['status' => $dbLatency !== null ? 'ok' : 'error', 'latencyMs' => $dbLatency, 'lastCheck' => gmdate('c')],
            'metaapi' => [
                'configured' => \Velora\Core\IntegrationConfigResolver::metaApiConfigured(),
                'source' => \Velora\Core\IntegrationConfigResolver::metaApiSource(),
                'status' => 'configured_only',   // no live probe performed in the overview; reachability is separate
                'note' => 'live connectivity is probed only via the Test Connection action',
            ],
            'aiProviders' => $this->tryQuery($pdo, 'SELECT provider, status, last_checked_at, error_code FROM ai_provider_credentials'),
            'email' => [
                'driver' => \Velora\Core\IntegrationConfigResolver::mailDriver(),
                'configured' => \Velora\Core\IntegrationConfigResolver::mailSecretConfigured(),
                'source' => \Velora\Core\IntegrationConfigResolver::mailDriverSource(),
                'sentLast24h' => $this->tryRowBound($pdo, "SELECT COUNT(*) AS n FROM email_notifications WHERE sent_at >= :cutoff", ['cutoff' => gmdate('Y-m-d H:i:s', time() - 86400)]),
                'failedLast24h' => $this->tryRowBound($pdo, "SELECT COUNT(*) AS n FROM email_notifications WHERE failed_at >= :cutoff", ['cutoff' => gmdate('Y-m-d H:i:s', time() - 86400)]),
            ],
            'workers' => [
                'jobsPending' => $this->tryRow($pdo, "SELECT COUNT(*) AS n FROM ai_jobs WHERE status IN ('pending','processing')"),
                'jobsFailed' => $this->tryRow($pdo, "SELECT COUNT(*) AS n FROM ai_jobs WHERE status='failed'"),
                'syncFailed' => $this->tryRow($pdo, "SELECT COUNT(*) AS n FROM sync_jobs WHERE status IN ('FAILED','DEAD_LETTER')"),
            ],
        ];
    }

    private function billingMetrics(): array
    {
        $pdo = Database::connection();
        if (!$this->hasColumn($pdo, 'users', 'plan')) {
            return ['available' => false, 'reason' => 'v1.3 migration not applied'];
        }
        $activeSubs = (int) $pdo->query("SELECT COUNT(*) AS n FROM users WHERE subscription_status='active'")->fetch()['n'];
        $distribution = $pdo->query('SELECT plan, COUNT(*) AS n FROM users GROUP BY plan')->fetchAll();
        return [
            'available' => true,
            'activeSubscriptions' => $activeSubs,
            'planDistribution' => array_map(static fn ($r) => ['plan' => $r['plan'], 'count' => (int) $r['n']], $distribution),
            'revenue' => ['available' => false, 'reason' => 'No external payment/billing integration exists; revenue is not auditable/data-backed.'],
        ];
    }

    // ---------------------------------------------------------------------
    private function hasColumn(\PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = "$table.$column";
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $driver = (string) ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) ?? '');
        try {
            if ($driver === 'sqlite') {
                foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll() as $r) {
                    if (($r['name'] ?? null) === $column) {
                        return $cache[$key] = true;
                    }
                }
                return $cache[$key] = false;
            }
            $stmt = $pdo->prepare('SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c');
            $stmt->execute(['t' => $table, 'c' => $column]);
            $cache[$key] = ((int) $stmt->fetch()['n']) > 0;
        } catch (\Throwable $e) {
            $cache[$key] = false;
        }
        return $cache[$key];
    }

    private function tryQuery(\PDO $pdo, string $sql): ?array
    {
        try {
            return $pdo->query($sql)->fetchAll();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function tryRow(\PDO $pdo, string $sql): ?array
    {
        try {
            $r = $pdo->query($sql)->fetch();
            return $r === false ? null : $r;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @param array<string,mixed> $params */
    private function tryRowBound(\PDO $pdo, string $sql, array $params = []): ?array
    {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $r = $stmt->fetch();
            return $r === false ? null : $r;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function measureDbLatency(\PDO $pdo): ?int
    {
        try {
            $start = microtime(true);
            $pdo->query('SELECT 1');
            return (int) round((microtime(true) - $start) * 1000);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
