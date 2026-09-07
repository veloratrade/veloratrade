<?php

declare(strict_types=1);

namespace Velora\Auth;

use PDO;
use Throwable;
use Velora\Core\Database;

/**
 * Phase 3 — data access for auth_events (real authentication event history).
 *
 * Privacy/secret policy: rows carry ONLY the event type, result, a reason
 * CODE (never user-entered text), the IP/user-agent captured at the boundary
 * and the timestamp. No passwords, tokens, hashes or credentials are ever
 * written or read here.
 *
 * Reliability policy: record() swallows its own failures — authentication
 * must never become dependent on history writes (same policy as
 * UserDeviceRepository).
 */
final class AuthEventRepository
{
    private const ALLOWED_RESULTS = ['success', 'failure'];

    public function record(?int $userId, string $eventType, string $result, ?string $reason, ?string $ip, ?string $userAgent): void
    {
        if (!in_array($result, self::ALLOWED_RESULTS, true)) {
            return;
        }
        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO auth_events (user_id, event_type, result, reason, ip_address, user_agent)
                 VALUES (:user_id, :event_type, :result, :reason, :ip, :ua)'
            );
            $stmt->execute([
                'user_id' => $userId,
                'event_type' => mb_substr($eventType, 0, 32),
                'result' => $result,
                'reason' => $reason !== null ? mb_substr($reason, 0, 64) : null,
                'ip' => $ip !== null ? mb_substr(trim($ip), 0, 45) : null,
                'ua' => $userAgent !== null ? mb_substr(trim($userAgent), 0, 250) : null,
            ]);
        } catch (Throwable) {
            // History must never break authentication.
        }
    }

    /** @return array{items:array<int,array<string,mixed>>,total:int} */
    public function listForUser(int $userId, int $page = 1, int $perPage = 25, ?string $result = null): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $now = gmdate('Y-m-d H:i:s');

        $where = 'user_id = :uid';
        $params = ['uid' => $userId];
        if ($result !== null && in_array($result, self::ALLOWED_RESULTS, true)) {
            $where .= ' AND result = :result';
            $params['result'] = $result;
        }

        try {
            $db = Database::connection();
            $c = $db->prepare("SELECT COUNT(*) AS n FROM auth_events WHERE {$where}");
            $c->execute($params);
            $total = (int) $c->fetch()['n'];

            $offset = ($page - 1) * $perPage;
            $q = $db->prepare(
                "SELECT id, event_type, result, reason, ip_address, user_agent, created_at
                 FROM auth_events WHERE {$where}
                 ORDER BY created_at DESC, id DESC
                 LIMIT {$perPage} OFFSET {$offset}"
            );
            $q->execute($params);
            $items = array_map(static fn (array $r): array => [
                'id' => (int) $r['id'],
                'eventType' => (string) $r['event_type'],
                'result' => (string) $r['result'],
                'reason' => $r['reason'] !== null ? (string) $r['reason'] : null,
                'ipAddress' => $r['ip_address'] !== null ? (string) $r['ip_address'] : null,
                'userAgent' => $r['user_agent'] !== null ? (string) $r['user_agent'] : null,
                'createdAt' => (string) $r['created_at'],
            ], $q->fetchAll());
        } catch (Throwable) {
            return ['items' => [], 'total' => 0];
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Phase 6 — GLOBAL login-history listing for the frozen #/security-logins
     * page (route carries `audit.view`). Additive; the per-user
     * `listForUser()` contract above is untouched. Only `event_type='login'`
     * rows are returned (unknown-account attempts have user_id NULL and are
     * included — anti-enumeration semantics are unaffected because this is
     * an Admin-only read of already-recorded events). Filters are whitelisted
     * upstream; ordering is deterministic (created_at DESC, id DESC);
     * pagination is bounded (1..100).
     *
     * NOTE: rows include the raw ip/user_agent columns; the CONTROLLER strips
     * them for callers without the sensitive-audit permission (D3 — the API
     * response itself must not carry them for plain admins).
     *
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    public function listGlobal(int $page, int $perPage, ?string $result, ?int $userId, ?string $dateFrom, ?string $dateTo): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));

        $where = ["event_type = 'login'"];
        $params = [];
        if ($result !== null && in_array($result, self::ALLOWED_RESULTS, true)) {
            $where[] = 'result = :result';
            $params['result'] = $result;
        }
        if ($userId !== null) {
            $where[] = 'user_id = :uid';
            $params['uid'] = $userId;
        }
        if ($dateFrom !== null) {
            $where[] = 'created_at >= :date_from';
            $params['date_from'] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== null) {
            $where[] = 'created_at <= :date_to';
            $params['date_to'] = $dateTo . ' 23:59:59';
        }
        $whereSql = implode(' AND ', $where);

        try {
            $db = Database::connection();
            $c = $db->prepare("SELECT COUNT(*) AS n FROM auth_events WHERE {$whereSql}");
            $c->execute($params);
            $total = (int) $c->fetch()['n'];

            $offset = ($page - 1) * $perPage;
            $q = $db->prepare(
                "SELECT id, user_id, event_type, result, reason, ip_address, user_agent, created_at
                 FROM auth_events WHERE {$whereSql}
                 ORDER BY created_at DESC, id DESC
                 LIMIT {$perPage} OFFSET {$offset}"
            );
            $q->execute($params);
            $items = array_map(static fn (array $r): array => [
                'id' => (int) $r['id'],
                'userId' => $r['user_id'] !== null ? (int) $r['user_id'] : null,
                'eventType' => (string) $r['event_type'],
                'result' => (string) $r['result'],
                'reason' => $r['reason'] !== null ? (string) $r['reason'] : null,
                'ipAddress' => $r['ip_address'] !== null ? (string) $r['ip_address'] : null,
                'userAgent' => $r['user_agent'] !== null ? (string) $r['user_agent'] : null,
                'createdAt' => (string) $r['created_at'],
            ], $q->fetchAll());
        } catch (Throwable) {
            return ['items' => [], 'total' => 0];
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Phase 6 — deterministic SIGNUP CLUSTERS for the frozen
     * #/security-signups page (D1-A + spec §5.20). Specification basis: the
     * frozen cluster card is keyed on "IP/device fingerprint"; the
     * owner-confirmed D1 persists signup-time IP/UA in auth_events, so a
     * cluster is a shared signup IP with MORE THAN ONE distinct user inside
     * the bounded window (the mockup's «حساب‌های هم‌منبع» shared-source
     * concept). Deterministic: member_count DESC, ip ASC tiebreak. Bounded:
     * date window + paginated (1..100 per page). Raw keys are masked by the
     * CONTROLLER for callers without the sensitive-audit permission (D3).
     *
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    public function signupClusters(string $startDate, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $base = "FROM auth_events
                 WHERE event_type = 'signup' AND user_id IS NOT NULL
                   AND ip_address IS NOT NULL AND ip_address <> ''
                   AND created_at >= :start
                 GROUP BY ip_address
                 HAVING COUNT(DISTINCT user_id) > 1";
        try {
            $db = Database::connection();
            $c = $db->prepare("SELECT COUNT(*) AS n FROM (SELECT ip_address {$base}) t");
            $c->execute(['start' => $startDate]);
            $total = (int) $c->fetch()['n'];

            $offset = ($page - 1) * $perPage;
            $q = $db->prepare(
                "SELECT ip_address AS cluster_key,
                        COUNT(DISTINCT user_id) AS member_count,
                        MIN(created_at) AS first_signup_at,
                        MAX(created_at) AS last_signup_at
                 {$base}
                 ORDER BY member_count DESC, cluster_key ASC
                 LIMIT {$perPage} OFFSET {$offset}"
            );
            $q->execute(['start' => $startDate]);
            $items = array_map(static fn (array $r): array => [
                'clusterKey' => (string) $r['cluster_key'],
                'memberCount' => (int) $r['member_count'],
                'firstSignupAt' => (string) $r['first_signup_at'],
                'lastSignupAt' => (string) $r['last_signup_at'],
            ], $q->fetchAll());
        } catch (Throwable) {
            return ['items' => [], 'total' => 0];
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Phase 6 — members for a set of signup clusters in ONE query (no N+1).
     * Returns the minimal member fields plus the signup-event row; the
     * controller strips sensitive values for non-sensitive viewers (D3).
     *
     * @param list<string> $ips
     * @return array<string,list<array<string,mixed>>> ip => members (oldest first)
     */
    public function membersForClusterIps(array $ips): array
    {
        if ($ips === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ips), '?'));
        try {
            $q = Database::connection()->prepare(
                "SELECT ae.ip_address, ae.user_id, ae.created_at, ae.user_agent,
                        u.email, u.full_name, u.email_verified_at
                 FROM auth_events ae
                 JOIN users u ON u.id = ae.user_id
                 WHERE ae.event_type = 'signup' AND ae.ip_address IN ({$ph})
                 ORDER BY ae.created_at ASC, ae.id ASC"
            );
            $q->execute($ips);
            $out = [];
            foreach ($q->fetchAll() as $r) {
                $out[(string) $r['ip_address']][] = [
                    'userId' => (int) $r['user_id'],
                    'email' => (string) $r['email'],
                    'fullName' => (string) $r['full_name'],
                    'signupAt' => (string) $r['created_at'],
                    'verified' => $r['email_verified_at'] !== null,
                    'ipAddress' => (string) $r['ip_address'],
                    'userAgent' => $r['user_agent'] !== null ? (string) $r['user_agent'] : null,
                ];
            }
            return $out;
        } catch (Throwable) {
            return [];
        }
    }
}
