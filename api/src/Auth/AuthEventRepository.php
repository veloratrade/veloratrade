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
}
