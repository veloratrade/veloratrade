<?php

declare(strict_types=1);

namespace Velora\Admin;

use PDO;
use Velora\Core\Config;
use Velora\Core\Database;
use Velora\Core\Exceptions\ValidationException;

/**
 * Phase H — Analytics + Revenue Intelligence (read-only, evidence-first).
 *
 * Domain separation (never mixed):
 *   - Product analytics   : users, trades, AI requests.
 *   - Operational analytics: system logs, integration health, admin audit.
 *   - Financial analytics  : revenue / MRR / ARR / churn / LTV / payment volume.
 *
 * Revenue rule: the repository has NO billing system (Phase G classification C,
 * reconfirmed in docs/AI_P2_PHASE_H_AUDIT.md). Every financial metric therefore
 * reports `available:false` + `reason:NO_BILLING_SOURCE` — it is never zeroed,
 * derived from plan count, or fabricated. "Unavailable" is not "zero".
 *
 * Metric sources (all authoritative, never from raw logs where a real ledger
 * exists):
 *   - users             -> users table (created_at = registration).
 *   - trades            -> trades table (profit_loss sign-split = the canonical
 *                           MetricsService semantics; aggregate P&L is trading
 *                           performance, NOT platform revenue).
 *   - AI requests       -> ai_requests table (authoritative request ledger).
 *   - operations        -> system_logs / integration_health / admin_audit_logs.
 *
 * Timezone: DB timestamps are stored in UTC (schema + init default `UTC`); all
 * date boundaries are computed in UTC. No browser/server/Tehran mixing.
 *
 * Query safety: every filter is bound as a parameter; range and token inputs are
 * validated (real dates, start<=end, bounded max range). No SQL concatenation.
 *
 * Privacy: output is fully aggregated. No email / full_name / password_hash /
 * provider credential / token / session id is ever returned.
 */
final class AnalyticsService
{
    private const RANGES = ['today', '7d', '30d', '90d', 'all'];
    private const RANGE_DAYS = ['today' => 1, '7d' => 7, '30d' => 30, '90d' => 90];
    private const MAX_CUSTOM_RANGE_DAYS = 366;

    /** @return array<string,mixed> */
    public function overview(array $query): array
    {
        $r = $this->range($query);
        $pdo = Database::connection();

        $users = [
            'total' => $this->count($pdo, 'users', '1=1', []),
            'active' => $this->count($pdo, 'users', 'status = :st', ['st' => 'active']),
            'suspended' => $this->count($pdo, 'users', 'status = :st', ['st' => 'suspended']),
            'newInRange' => $this->count($pdo, 'users', 'created_at BETWEEN :start AND :end', $r->bind),
        ];

        $trades = [
            'totalTrades' => $this->count($pdo, 'trades', '1=1', []),
            'tradesInRange' => $this->count($pdo, 'trades', 'created_at BETWEEN :start AND :end', $r->bind),
        ];
        $trades['tradingAccounts'] = $this->count($pdo, 'trading_accounts', '1=1', []);

        $ai = [
            'totalRequests' => $this->count($pdo, 'ai_requests', '1=1', []),
            'requestsInRange' => $this->count($pdo, 'ai_requests', 'created_at BETWEEN :start AND :end', $r->bind),
            'failedInRange' => $this->count($pdo, 'ai_requests', 'status != :ok AND created_at BETWEEN :start AND :end', ['ok' => 'success'] + $r->bind),
        ];

        $operations = [
            'systemErrors' => $this->count($pdo, 'system_logs', "severity = 'ERROR' AND created_at BETWEEN :start AND :end", $r->bind),
            'integrationFailures' => $this->count($pdo, 'integration_health', "status NOT IN ('HEALTHY','OK')", []),
        ];

        return [
            'range' => ($r->describe)(),
            'users' => $users,
            'trading' => $trades,
            'ai' => $ai,
            'operations' => $operations,
            'revenue' => $this->revenueUnavailable(),
        ];
    }

    /** @return array<string,mixed> */
    public function users(array $query): array
    {
        $r = $this->range($query);
        $pdo = Database::connection();

        $byRole = $this->groupCount($pdo, 'users', 'role', [], 'role IS NOT NULL');
        $byStatus = $this->groupCount($pdo, 'users', 'status', [], '1=1');
        $byLocale = $this->groupCount($pdo, 'users', 'locale', [], 'locale IS NOT NULL AND locale <> \'\'');
        $newInRange = $this->count($pdo, 'users', 'created_at BETWEEN :start AND :end', $r->bind);

        return [
            'range' => ($r->describe)(),
            'total' => $this->count($pdo, 'users', '1=1', []),
            'newInRange' => $newInRange,
            'byRole' => $byRole,
            'byStatus' => $byStatus,
            'byLocale' => $byLocale,
            'registrationTrend' => $this->dailyTrend($pdo, 'users', 'created_at', $r),
        ];
    }

    /** @return array<string,mixed> */
    public function trading(array $query): array
    {
        $r = $this->range($query);
        $pdo = Database::connection();
        $bind = $r->bind;

        $bySymbol = $this->groupCount($pdo, 'trades', 'symbol', $bind, 'created_at BETWEEN :start AND :end');
        $byDirection = $this->groupCount($pdo, 'trades', 'direction', $bind, 'created_at BETWEEN :start AND :end');

        // Canonical sign-split (matches Velora\Dashboard\MetricsService semantics):
        // P&L is trading performance, not platform revenue.
        $agg = $pdo->prepare(
            'SELECT
                COUNT(*) AS trade_count,
                COALESCE(SUM(CASE WHEN profit_loss > 0 THEN 1 ELSE 0 END), 0) AS wins,
                COALESCE(SUM(CASE WHEN profit_loss < 0 THEN 1 ELSE 0 END), 0) AS losses,
                COALESCE(SUM(CASE WHEN profit_loss = 0 THEN 1 ELSE 0 END), 0) AS breakeven,
                COALESCE(SUM(profit_loss), 0) AS net_pnl,
                COALESCE(SUM(volume), 0) AS total_volume
             FROM trades
             WHERE created_at BETWEEN :start AND :end'
        );
        $agg->execute($bind);
        $row = $agg->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'range' => ($r->describe)(),
            'total' => $this->count($pdo, 'trades', '1=1', []),
            'tradesInRange' => (int) ($row['trade_count'] ?? 0),
            'bySymbol' => $bySymbol,
            'byDirection' => $byDirection,
            'winLoss' => [
                'wins' => (int) ($row['wins'] ?? 0),
                'losses' => (int) ($row['losses'] ?? 0),
                'breakeven' => (int) ($row['breakeven'] ?? 0),
            ],
            'netPnl' => $this->round(bcadd((string) ($row['net_pnl'] ?? '0'), '0', 2), 2),
            'totalVolume' => $this->round(bcadd((string) ($row['total_volume'] ?? '0'), '0', 2), 2),
            'trend' => $this->dailyTrend($pdo, 'trades', 'created_at', $r),
            'isRevenue' => false,
            'note' => 'Aggregate trading P&L is trading performance, NOT platform revenue.',
        ];
    }

    /** @return array<string,mixed> */
    public function ai(array $query): array
    {
        $r = $this->range($query);
        $pdo = Database::connection();
        $bind = $r->bind;

        $byStatus = $this->groupCount($pdo, 'ai_requests', 'status', $bind, 'created_at BETWEEN :start AND :end');
        $byProvider = $this->groupCount($pdo, 'ai_requests', 'provider', $bind, 'created_at BETWEEN :start AND :end');
        $byFeature = $this->groupCount($pdo, 'ai_requests', 'feature', $bind, 'created_at BETWEEN :start AND :end');
        $byModel = $this->groupCount($pdo, 'ai_requests', 'model', $bind, 'created_at BETWEEN :start AND :end');

        $s = $pdo->prepare(
            'SELECT COALESCE(SUM(tokens_used),0) AS tokens, COALESCE(SUM(cost),0) AS cost
             FROM ai_requests WHERE created_at BETWEEN :start AND :end'
        );
        $s->execute($bind);
        $sf = $s->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'range' => ($r->describe)(),
            'total' => $this->count($pdo, 'ai_requests', '1=1', []),
            'inRange' => $this->count($pdo, 'ai_requests', 'created_at BETWEEN :start AND :end', $bind),
            'byStatus' => $byStatus,
            'byProvider' => $byProvider,
            'byFeature' => $byFeature,
            'byModel' => $byModel,
            'tokensUsed' => (int) ($sf['tokens'] ?? 0),
            'cost' => $this->round(bcadd((string) ($sf['cost'] ?? '0'), '0', 4), 4),
            'trend' => $this->dailyTrend($pdo, 'ai_requests', 'created_at', $r),
        ];
    }

    /** @return array<string,mixed> */
    public function operations(array $query): array
    {
        $r = $this->range($query);
        $pdo = Database::connection();
        $bind = $r->bind;

        $bySeverity = $this->groupCount($pdo, 'system_logs', 'severity', $bind, 'created_at BETWEEN :start AND :end');
        $bySource = $this->groupCount($pdo, 'system_logs', 'source', $bind, 'created_at BETWEEN :start AND :end');

        $integrations = [];
        foreach ($this->integrationHealth($pdo) as $row) {
            $integrations[] = [
                'integration' => $row['integration'],
                'status' => $row['status'],
                'latencyMs' => $row['latency_ms'] !== null ? (int) $row['latency_ms'] : null,
                'errorCode' => $row['error_code'] !== null ? (string) $row['error_code'] : null,
                'checkedAt' => $row['checked_at'],
            ];
        }

        return [
            'range' => ($r->describe)(),
            'systemLogs' => [
                'total' => $this->count($pdo, 'system_logs', 'created_at BETWEEN :start AND :end', $bind),
                'errors' => $this->count($pdo, 'system_logs', "severity = 'ERROR' AND created_at BETWEEN :start AND :end", $bind),
                'bySeverity' => $bySeverity,
                'bySource' => $bySource,
            ],
            'integrations' => $integrations,
            'integrationFailures' => $this->count($pdo, 'integration_health', "status NOT IN ('HEALTHY','OK')", []),
            'adminAudit' => [
                'eventsInRange' => $this->count($pdo, 'admin_audit_logs', 'created_at BETWEEN :start AND :end', $bind),
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function revenue(): array
    {
        return $this->revenueUnavailable();
    }

    /** @return array<string,mixed> */
    private function revenueUnavailable(): array
    {
        $unavailable = fn (): array => ['available' => false, 'reason' => 'NO_BILLING_SOURCE'];
        return [
            'available' => false,
            'reason' => 'NO_BILLING_SOURCE',
            'note' => 'No authoritative billing source is configured. Financial metrics are unavailable, not zero.',
            'metrics' => [
                'revenue' => $unavailable(),
                'mrr' => $unavailable(),
                'arr' => $unavailable(),
                'churn' => $unavailable(),
                'ltv' => $unavailable(),
                'paymentVolume' => $unavailable(),
                'refunds' => $unavailable(),
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // Time-range handling (UTC boundaries, validated, bounded).
    // ---------------------------------------------------------------------

    /** @return object{bind:array<string,string>,describe:function():array} */
    private function range(array $query): object
    {
        $token = strtolower(trim((string) ($query['range'] ?? '')));
        $start = trim((string) ($query['start'] ?? ''));
        $end = trim((string) ($query['end'] ?? ''));

        if ($token !== '' && $start === '' && $end === '') {
            return $this->presetRange($token);
        }
        if ($token !== '') {
            throw new ValidationException('Invalid range.', ['range' => ['code' => 'INVALID_RANGE']]);
        }
        if ($start === '' && $end === '') {
            return $this->presetRange('30d');
        }
        // Custom range: both endpoints required, real dates, ordered, bounded.
        if ($start === '' || $end === '') {
            throw new ValidationException('Both start and end are required.', ['range' => ['code' => 'INCOMPLETE_RANGE']]);
        }
        [$sy, $sm, $sd] = $this->parseDate($start, 'start');
        [$ey, $em, $ed] = $this->parseDate($end, 'end');

        $startTs = strtotime($start . ' 00:00:00');
        $endTs = strtotime($end . ' 23:59:59');
        if ($endTs < $startTs) {
            throw new ValidationException('end must not precede start.', ['range' => ['code' => 'INVALID_ORDER']]);
        }
        $days = max(0, (int) floor(($endTs - $startTs) / 86400));
        if ($days > self::MAX_CUSTOM_RANGE_DAYS) {
            throw new ValidationException('Range too large.', ['range' => ['code' => 'RANGE_TOO_LARGE']]);
        }

        $startBound = sprintf('%04d-%02d-%02d 00:00:00', $sy, $sm, $sd);
        $endBound = sprintf('%04d-%02d-%02d 23:59:59', $ey, $em, $ed);
        return $this->bounded($startBound, $endBound, 'custom', "$start..$end");
    }

    /** @return object{bind:array<string,string>,describe:function():array} */
    private function presetRange(string $token): object
    {
        if (!in_array($token, self::RANGES, true)) {
            throw new ValidationException('Invalid range.', ['range' => ['code' => 'INVALID_RANGE']]);
        }
        $end = gmdate('Y-m-d 23:59:59', time());
        if ($token === 'all') {
            $start = '1970-01-01 00:00:00';
            return $this->bounded($start, $end, 'all', 'all');
        }
        $days = self::RANGE_DAYS[$token] ?? 30;
        $start = gmdate('Y-m-d 00:00:00', time() - $days * 86400);
        return $this->bounded($start, $end, $token, $token);
    }

    /** @return object{bind:array<string,string>,describe:function():array} */
    private function bounded(string $start, string $end, string $label, string $presentation): object
    {
        return (object) [
            'bind' => ['start' => $start, 'end' => $end],
            'describe' => fn (): array => [
                'start' => $start,
                'end' => $end,
                'label' => $label,
                'presentation' => $presentation,
                'timezone' => 'UTC',
            ],
        ];
    }

    /** @return array{int,int,int} */
    private function parseDate(string $date, string $field): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new ValidationException('Invalid ' . $field . ' date.', [$field => ['code' => 'INVALID_FORMAT']]);
        }
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        if (!checkdate($m, $d, $y)) {
            throw new ValidationException('Invalid real date.', [$field => ['code' => 'INVALID_FORMAT']]);
        }
        return [$y, $m, $d];
    }

    // ---------------------------------------------------------------------
    // Helpers.
    // ---------------------------------------------------------------------

    private function count(PDO $pdo, string $table, string $where, array $params): int
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS n FROM {$table} WHERE {$where}");
        $stmt->execute($params);
        return (int) ($stmt->fetch(PDO::FETCH_ASSOC)['n'] ?? 0);
    }

    /** @return list<array{key:string,count:int}> */
    private function groupCount(PDO $pdo, string $table, string $column, array $params, string $where): array
    {
        $stmt = $pdo->prepare(
            "SELECT {$column} AS `key`, COUNT(*) AS `n` FROM {$table} WHERE {$where} GROUP BY {$column} ORDER BY `n` DESC"
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['key'] === null) {
                continue;
            }
            $out[] = ['key' => (string) $row['key'], 'count' => (int) $row['n']];
        }
        return $out;
    }

    /** @return list<array{date:string,count:int}> */
    private function dailyTrend(PDO $pdo, string $table, string $column, object $r): array
    {
        $bind = $r->bind;
        $stmt = $pdo->prepare(
            "SELECT substr({$column},1,10) AS d, COUNT(*) AS n
             FROM {$table}
             WHERE {$column} BETWEEN :start AND :end
             GROUP BY substr({$column},1,10)
             ORDER BY d ASC"
        );
        $stmt->execute($bind);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = ['date' => (string) $row['d'], 'count' => (int) $row['n']];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function integrationHealth(PDO $pdo): array
    {
        $rows = [];
        foreach ($pdo->query('SELECT integration, status, latency_ms, error_code, checked_at FROM integration_health ORDER BY integration') as $row) {
            $rows[] = [
                'integration' => (string) $row['integration'],
                'status' => (string) $row['status'],
                'latency_ms' => $row['latency_ms'],
                'error_code' => $row['error_code'],
                'checked_at' => (string) $row['checked_at'],
            ];
        }
        return $rows;
    }

    private function round(string $value, int $scale): string
    {
        return bcadd($value, '0', $scale);
    }
}
