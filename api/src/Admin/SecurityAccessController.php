<?php

declare(strict_types=1);

namespace Velora\Admin;

use PDO;
use Velora\Auth\Role;
use Velora\Core\Database;
use Velora\Core\Exceptions\ValidationException;
use Velora\Core\Request;
use Velora\Core\Response;
use Velora\Auth\AuthEventRepository;

/**
 * Phase 6 — GLOBAL signup history/clusters + global login history for the
 * frozen Admin v2.2 pages #/security-signups and #/security-logins.
 *
 * Read-only listing endpoints over EXISTING tables (`users`, `auth_events`).
 * Authorization is enforced at the route (admin stack + `audit.view` — the
 * frozen frontend gates). D3 (owner-confirmed, server-side only): raw IP /
 * User-Agent values are returned ONLY to callers holding the existing
 * sensitive-audit permission (`P_AUDIT_SENSITIVE_VIEW`, super_admin);
 * plain admins receive masked cluster keys and NO raw ip/user_agent fields
 * at all — the API response itself omits them (never a client-side hide).
 * Clustering internally always uses the full values. No new permission, no
 * migration, no new table (D1-A reuses `auth_events` with event_type
 * 'signup', persisted at successful registration only).
 *
 * D2 (owner-confirmed): this API is informational — there are no cluster
 * write actions, no bulk operations and no review-state persistence.
 * D4 (owner-confirmed): trade journal data is NOT touched here.
 *
 * Privacy: responses never contain password/session/reset/verification
 * tokens or credentials. Ordering is deterministic (counts DESC + stable
 * key tiebreak / created_at DESC, id DESC); pagination is bounded
 * (per_page 1..100); the clustering window is bounded (days 1..365).
 * The per-page member identity is resolved with ONE additional join query
 * (no N+1).
 */
final class SecurityAccessController
{
    private const RESULTS = ['success', 'failure'];
    private const CLUSTER_DEFAULT_PER_PAGE = 20;

    /** GET /api/v1/admin/security/signups */
    public function signups(Request $request): never
    {
        $days = min(365, max(1, (int) ($request->query['days'] ?? 30)));
        $start = gmdate('Y-m-d 00:00:00', time() - ($days - 1) * 86400);
        $sensitive = $this->sensitiveViewer($request);

        $pdo = Database::connection();

        $total = (int) $pdo->query('SELECT COUNT(*) AS n FROM users')->fetch()['n'];
        $s = $pdo->prepare('SELECT COUNT(*) AS n FROM users WHERE created_at >= :start');
        $s->execute(['start' => $start]);
        $newInRange = (int) $s->fetch()['n'];
        $s = $pdo->prepare('SELECT COUNT(*) AS n FROM users WHERE created_at >= :start AND email_verified_at IS NOT NULL');
        $s->execute(['start' => $start]);
        $verified = (int) $s->fetch()['n'];

        $t = $pdo->prepare(
            "SELECT substr(created_at,1,10) AS d, COUNT(*) AS n
             FROM users WHERE created_at >= :start
             GROUP BY substr(created_at,1,10) ORDER BY d ASC"
        );
        $t->execute(['start' => $start]);
        $trend = array_map(static fn (array $r): array => ['date' => (string) $r['d'], 'count' => (int) $r['n']], $t->fetchAll(PDO::FETCH_ASSOC));

        $page = max(1, (int) ($request->query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($request->query['per_page'] ?? self::CLUSTER_DEFAULT_PER_PAGE)));
        $clusters = (new AuthEventRepository())->signupClusters($start, $page, $perPage);
        $memberRows = (new AuthEventRepository())->membersForClusterIps(array_map(static fn (array $c): string => $c['clusterKey'], $clusters['items']));

        $out = [];
        foreach ($clusters['items'] as $c) {
            $members = [];
            foreach ($memberRows[$c['clusterKey']] ?? [] as $m) {
                if (!$sensitive) {
                    unset($m['ipAddress'], $m['userAgent']);
                }
                $members[] = $m;
            }
            $row = [
                'keyMasked' => self::maskIp($c['clusterKey']),
                'memberCount' => $c['memberCount'],
                'firstSignupAt' => $c['firstSignupAt'],
                'lastSignupAt' => $c['lastSignupAt'],
                'members' => $members,
            ];
            if ($sensitive) {
                $row['key'] = $c['clusterKey'];
            }
            $out[] = $row;
        }

        Response::json([
            'range' => ['days' => $days, 'start' => $start],
            'sensitiveVisible' => $sensitive,
            'kpis' => ['totalUsers' => $total, 'newInRange' => $newInRange, 'verified' => $verified, 'unverified' => $newInRange - $verified],
            'trend' => $trend,
            'clusters' => $out,
            'pagination' => ['total' => $clusters['total'], 'page' => $page, 'per_page' => $perPage, 'has_more' => $page * $perPage < $clusters['total']],
        ]);
    }

    /** GET /api/v1/admin/security/logins */
    public function logins(Request $request): never
    {
        $page = max(1, (int) ($request->query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($request->query['per_page'] ?? 25)));
        $result = (string) ($request->query['result'] ?? '');
        if ($result !== '' && !in_array($result, self::RESULTS, true)) {
            throw $this->invalid('result');
        }
        $sensitive = $this->sensitiveViewer($request);

        $res = (new AuthEventRepository())->listGlobal(
            $page,
            $perPage,
            $result !== '' ? $result : null,
            $this->idFilter($request, 'user_id'),
            $this->dateFilter($request, 'date_from'),
            $this->dateFilter($request, 'date_to'),
        );

        $events = array_map(static function (array $r) use ($sensitive): array {
            $row = [
                'id' => $r['id'],
                'userId' => $r['userId'],
                'eventType' => $r['eventType'],
                'result' => $r['result'],
                'reason' => $r['reason'],
                'createdAt' => $r['createdAt'],
            ];
            if ($sensitive) {
                $row['ipAddress'] = $r['ipAddress'];
                $row['userAgent'] = $r['userAgent'];
            }
            return $row;
        }, $res['items']);
        $events = $this->withUsers($events);

        Response::json([
            'events' => $events,
            'sensitiveVisible' => $sensitive,
            'pagination' => ['total' => $res['total'], 'page' => $page, 'per_page' => $perPage, 'has_more' => $page * $perPage < $res['total']],
        ]);
    }

    /** D3 — server-side sensitive-field decision (existing permission; never role-name inference). */
    private function sensitiveViewer(Request $request): bool
    {
        $role = (string) ($request->attributes['user_role'] ?? '');

        return Role::can($role, Role::P_AUDIT_SENSITIVE_VIEW);
    }

    /** Deterministic masking for cluster keys shown to non-sensitive viewers (display only). */
    private static function maskIp(string $ip): string
    {
        if (str_contains($ip, ':')) {
            $p = explode(':', $ip);

            return implode(':', array_slice($p, 0, 3)) . ':***';
        }
        $p = explode('.', $ip);

        return count($p) === 4 ? $p[0] . '.' . $p[1] . '.*.*' : '***';
    }

    /** Date bounds must be real calendar days (strict: format + checkdate, per AnalyticsService::parseDate discipline). */
    private function dateFilter(Request $request, string $field): ?string
    {
        $v = trim((string) ($request->query[$field] ?? ''));
        if ($v === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            throw $this->invalidDate($field);
        }
        [$y, $m, $d] = array_map('intval', explode('-', $v));
        if (!checkdate($m, $d, $y)) {
            throw $this->invalidDate($field);
        }

        return $v;
    }

    private function idFilter(Request $request, string $field): ?int
    {
        $v = (string) ($request->query[$field] ?? '');
        if ($v === '' || $v === '0') {
            return null;
        }
        if (!ctype_digit($v)) {
            throw $this->invalid($field);
        }

        return (int) $v;
    }

    private function invalid(string $field): ValidationException
    {
        return new ValidationException('Invalid filter.', [$field => ['code' => 'INVALID_CHOICE', 'messageKey' => 'errors.validation.choice', 'params' => []]]);
    }

    private function invalidDate(string $field): ValidationException
    {
        return new ValidationException('Invalid filter.', [$field => ['code' => 'INVALID_DATE']]);
    }

    /** One identity query per page of rows — never per row. Missing users resolve to null safely (NULL user_id rows stay anonymous). */
    private function withUsers(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int) ($r['userId'] ?? 0), $rows))));
        if ($ids === []) {
            return $rows;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::connection()->prepare("SELECT id, email, full_name FROM users WHERE id IN ({$ph})");
        $stmt->execute($ids);
        $byId = [];
        foreach ($stmt->fetchAll() as $u) {
            $byId[(int) $u['id']] = $u;
        }
        foreach ($rows as &$r) {
            if (($r['userId'] ?? null) === null) {
                continue;
            }
            $u = $byId[(int) $r['userId']] ?? null;
            $r['userEmail'] = $u['email'] ?? null;
            $r['userFullName'] = $u['full_name'] ?? null;
        }

        return $rows;
    }
}
