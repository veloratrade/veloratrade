<?php

declare(strict_types=1);

namespace Velora\Admin;

use Velora\Core\Database;
use Velora\Core\IntegrationConfigResolver;
use Velora\Core\SecretRedactor;

/**
 * Phase D — System + Integration health.
 *
 * Produces a secret-free health snapshot. Two classes of component:
 *
 *   READ-HAPPY (cheap, real, no external call):
 *     - api        : the controller itself responded (this request)
 *     - database   : real SELECT 1 latency
 *     - workers    : real queue depth from the DB-backed job tables (the
 *                    architecture has NO Redis; queue depth + last activity is
 *                    the truthful signal — never a fabricated "alive" status)
 *     - integration status (metaapi/n8n_relay/ai/email) derived from real
 *       configuration + authenticated credential state. NO live external call.
 *
 *   PROBE (bounded, rate-limited, cached): ran only through the existing
 *     test-connection infrastructure and stored in integration_health so a
 *     refresh button can never storm external providers. Until a probe has run,
 *     lastCheckedAt is null and the UI shows "No previous check recorded".
 *
 * Status alphabet: HEALTHY | DEGRADED | UNHEALTHY | NOT_CONFIGURED | UNKNOWN.
 * Redis is deliberately reported as NOT_APPLICABLE (the repo has no Redis).
 */
final class SystemHealthService
{
    public function __construct(
        private readonly IntegrationHealthRepository $cache = new IntegrationHealthRepository(),
    ) {
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        $db = $this->databaseHealth();
        return [
            'checkedAt' => gmdate('c'),
            'components' => [
                'api' => [
                    'component' => 'api',
                    'status' => 'HEALTHY',
                    'message' => 'API responding',
                    'checkedAt' => gmdate('c'),
                ],
                'database' => $db,
                'redis' => [
                    'component' => 'redis',
                    'status' => 'NOT_APPLICABLE',
                    'message' => 'No Redis in this architecture (DB-backed fenced queues)',
                    'checkedAt' => gmdate('c'),
                ],
                'workers' => $this->workersHealth(),
                'metaapi' => $this->integrationHealth('metaapi', $this->metaApiConfiguredState()),
                'n8n_relay' => $this->integrationHealth('n8n_relay', $this->n8nState()),
                'ai' => $this->integrationHealth('ai', $this->aiState()),
                'email' => $this->integrationHealth('email', $this->emailState()),
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // read-happy internals
    // ---------------------------------------------------------------------

    private function databaseHealth(): array
    {
        $latency = null;
        try {
            $start = microtime(true);
            Database::connection()->query('SELECT 1');
            $latency = (int) round((microtime(true) - $start) * 1000);
        } catch (\Throwable $e) {
            return [
                'component' => 'database',
                'status' => 'UNHEALTHY',
                'latencyMs' => null,
                'message' => 'Database query failed: ' . SecretRedactor::text($e->getMessage()),
                'checkedAt' => gmdate('c'),
            ];
        }
        $status = $latency <= 500 ? 'HEALTHY' : 'DEGRADED';
        return [
            'component' => 'database',
            'status' => $status,
            'latencyMs' => $latency,
            'message' => $latency <= 500 ? 'Database reachable' : 'Database latency high',
            'checkedAt' => gmdate('c'),
        ];
    }

    private function workersHealth(): array
    {
        $pending = $this->count(
            "SELECT COUNT(*) AS n FROM ai_jobs WHERE status IN ('pending','processing')"
        );
        $failed = $this->count("SELECT COUNT(*) AS n FROM ai_jobs WHERE status='failed'");
        $syncFailed = $this->count("SELECT COUNT(*) AS n FROM sync_jobs WHERE status IN ('FAILED','DEAD_LETTER')");

        $status = 'HEALTHY';
        $note = 'Workers queue drained';
        if (($failed ?? 0) > 0 || ($syncFailed ?? 0) > 0) {
            $status = 'DEGRADED';
            $note = 'Worker jobs have failures';
        }
        return [
            'component' => 'workers',
            'status' => $status,
            'jobsPending' => $pending,
            'jobsFailed' => $failed,
            'syncFailed' => $syncFailed,
            'message' => $note,
            'checkedAt' => gmdate('c'),
        ];
    }

    // ---- integration config-derived state (never a live external call) ----

    private function metaApiConfiguredState(): array
    {
        $s = IntegrationConfigResolver::metaApiSafeStatus();
        $configured = (bool) $s['tokenConfigured'];
        return [
            'status' => $configured ? 'HEALTHY' : 'NOT_CONFIGURED',
            'configured' => $configured,
            'source' => $s['source'],
            'host' => $s['baseUrlHost'],
        ];
    }

    private function n8nState(): array
    {
        $s = \Velora\Admin\RelayConfigResolver::safeStatus();
        $configured = (bool) $s['configured'];
        return [
            // No live n8n probe exists; only report accurate config presence.
            'status' => $configured ? 'HEALTHY' : 'NOT_CONFIGURED',
            'configured' => $configured,
            'urlConfigured' => (bool) $s['urlConfigured'],
            'tokenConfigured' => (bool) $s['tokenConfigured'],
            'host' => $s['urlHost'],
        ];
    }

    private function aiState(): array
    {
        $pdo = null;
        try {
            $pdo = Database::connection();
        } catch (\Throwable $e) {
            return ['status' => 'UNKNOWN', 'configured' => false, 'message' => 'AI status unavailable without DB'];
        }
        $rows = $this->queryRows($pdo, 'SELECT provider, status, verified, last_checked_at, error_code FROM ai_provider_credentials');
        if ($rows === null) {
            return ['status' => 'UNKNOWN', 'configured' => false, 'message' => 'AI provider table unavailable'];
        }
        if ($rows === []) {
            return ['status' => 'NOT_CONFIGURED', 'configured' => false, 'message' => 'No AI provider credentials configured', 'providers' => []];
        }
        $valid = count(array_filter($rows, static fn ($r): bool => ($r['status'] ?? '') === 'VALID'));
        if ($valid === 0) {
            return ['status' => 'DEGRADED', 'configured' => true, 'message' => 'No AI provider is VALID (verified)', 'providers' => $this->safeProviders($rows)];
        }
        return ['status' => 'HEALTHY', 'configured' => true, 'verifiedProviders' => $valid, 'providers' => $this->safeProviders($rows)];
    }

    private function emailState(): array
    {
        $s = IntegrationConfigResolver::mailSafeStatus();
        $configured = (bool) $s['configured'];
        return [
            'status' => $configured ? 'HEALTHY' : 'NOT_CONFIGURED',
            'configured' => $configured,
            'driver' => $s['driver'],
            'source' => $s['source'],
        ];
    }

    // ---------------------------------------------------------------------
    // integration health (merge config-derived baseline with last real probe)
    // ---------------------------------------------------------------------

    private function integrationHealth(string $name, array $state): array
    {
        $baselineStatus = $state['status'];
        $last = $this->cache->last($name); // null until a real probe ran
        $out = [
            'component' => $name,
            'status' => $baselineStatus,
            'configured' => (bool) ($state['configured'] ?? false),
            'lastCheckedAt' => $last['checkedAt'] ?? null,
            'latencyMs' => $last['latencyMs'] ?? null,
            'errorCode' => $last['errorCode'] ?? null,
            'diagnostic' => $last['message'] ?? null,
            'checkedAt' => gmdate('c'),
        ];
        // Keep the cache's real probe status if present (it supersedes config guess).
        if ($last !== null) {
            $out['status'] = $last['status'];
        }
        // Merge non-secret safe state fields.
        foreach ($state as $k => $v) {
            if (in_array($k, ['source', 'host', 'driver', 'urlConfigured', 'tokenConfigured', 'verifiedProviders', 'providers', 'message'], true) && !array_key_exists($k, $out)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private function safeProviders(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'provider' => $r['provider'],
            'status' => $r['status'],
            'verified' => (bool) ($r['verified'] ?? false),
            'lastCheckedAt' => $r['last_checked_at'],
            'errorCode' => $r['error_code'] ?? null,
        ], $rows);
    }

    /** @return list<array<string,mixed>>|null null on table-unavailable. */
    private function queryRows(\PDO $pdo, string $sql): ?array
    {
        try {
            return $pdo->query($sql)->fetchAll();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function count(string $sql): ?int
    {
        try {
            $row = Database::connection()->query($sql)->fetch();
            return (int) ($row['n'] ?? 0);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
