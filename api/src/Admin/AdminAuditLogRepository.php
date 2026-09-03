<?php

declare(strict_types=1);

namespace Velora\Admin;

use Velora\Core\Database;

/**
 * Immutable-from-UI admin audit trail (admin_audit_logs, v1.3).
 *
 * Security contract:
 *   - record() NEVER stores a secret: no API key, token, password, broker
 *     credential, or JWT. Only a sanitized `summary` and sanitized
 *     `metadata_json` are persisted, and callers must pass already-sanitized
 *     values (normalizeSecretMap() strips AES-encryptable/credential-shaped
 *     keys defensively).
 *   - There is intentionally NO update or delete path. The UI can append
 *     (create) entries and LIST them; it cannot mutate or erase history.
 *   - Recording is best-effort/fail-open: on environments that have not applied
 *     v1.3 the table may be absent, in which case a sensitive action still
 *     succeeds (documented trade-off, consistent with the codebase's existing
 *     compatibility policy). When the table exists, a DB error is still
 *     suppressed rather than failing the operation.
 */
final class AdminAuditLogRepository
{
    /** @var list<string> keys whose values must never be persisted. */
    private const FORBIDDEN_METADATA_KEYS = [
        'api_key', 'apikey', 'api-key', 'token', 'access_token', 'secret',
        'password', 'pass', 'credential', 'refresh_token', 'jwt', 'bearer',
        'private_key', 'relay_token', 'encryption_key', 'client_secret',
        'broker_password', 'connection_credentials', 'webhook_secret',
    ];

    /**
     * Append an audit entry. Returns the new id, or 0 when the trail is
     * unavailable/best-effort.
     *
     * @param array<string,mixed> $metadata safe metadata; sensitive keys stripped
     * @return int
     */
    public function record(
        int $actorUserId,
        string $actorRole,
        string $action,
        string $targetType = 'user',
        ?int $targetId = null,
        string $result = 'success',
        ?string $summary = null,
        ?string $ip = null,
        ?string $userAgent = null,
        ?string $contextId = null,
        array $metadata = [],
    ): int {
        $filtered = $this->sanitize($metadata);
        $summary = $this->sanitizeText($summary);

        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare(
                'INSERT INTO admin_audit_logs
                    (actor_user_id, actor_role, action, target_type, target_id, result,
                     summary, ip_address, user_agent, context_id, metadata_json)
                 VALUES
                    (:actor, :role, :action, :ttype, :tid, :result,
                     :summary, :ip, :ua, :ctx, :meta)'
            );
            $stmt->execute([
                'actor' => $actorUserId,
                'role' => substr($actorRole, 0, 32),
                'action' => substr($action, 0, 64),
                'ttype' => substr($targetType, 0, 32),
                'tid' => $targetId !== null ? (string) $targetId : null,
                'result' => substr($result, 0, 16),
                'summary' => $summary,
                'ip' => $ip !== null ? substr($ip, 0, 45) : null,
                'ua' => $userAgent !== null ? substr($userAgent, 0, 250) : null,
                'ctx' => $contextId !== null ? substr($contextId, 0, 64) : null,
                'meta' => $filtered === [] ? null : json_encode($filtered, JSON_UNESCAPED_UNICODE),
            ]);
            return (int) $pdo->lastInsertId();
        } catch (\Throwable $e) {
            // Fail-open (documented): never break the operation because the
            // trail is unavailable. Emit safe evidence (no data).
            error_log('[VELORA_AUDIT_SKIP] action=' . $action . ' actor=' . $actorUserId);
            return 0;
        }
    }

    /**
     * List audit entries, newest first, with pagination + optional filters.
     *
     * @return array{items:list<array<string,mixed>>, total:int, page:int, per_page:int}
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];
        if (!empty($filters['action'])) {
            $where[] = 'action = :action';
            $params['action'] = $filters['action'];
        }
        if (!empty($filters['actor'])) {
            $where[] = 'actor_user_id = :actor';
            $params['actor'] = (int) $filters['actor'];
        }
        if (!empty($filters['result']) && in_array($filters['result'], ['success', 'denied', 'error'], true)) {
            $where[] = 'result = :result';
            $params['result'] = $filters['result'];
        }
        if (!empty($filters['since'])) {
            $where[] = 'created_at >= :since';
            $params['since'] = $filters['since'];
        }
        // Phase E — filter by audit target so the User-360 view can show the
        // trail for one specific user (target_type='user', target_id=<id>).
        if (!empty($filters['target_type'])) {
            $where[] = 'target_type = :target_type';
            $params['target_type'] = $filters['target_type'];
        }
        if (isset($filters['target_id']) && $filters['target_id'] !== '') {
            $where[] = 'target_id = :target_id';
            $params['target_id'] = (int) $filters['target_id'];
        }
        $clause = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $pdo = Database::connection();

        $countStmt = $pdo->prepare("SELECT COUNT(*) AS n FROM admin_audit_logs $clause");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['n'];

        $listStmt = $pdo->prepare(
            "SELECT id, actor_user_id, actor_role, action, target_type, target_id, result,
                    summary, ip_address, context_id, metadata_json, created_at
             FROM admin_audit_logs $clause
             ORDER BY id DESC LIMIT $perPage OFFSET $offset"
        );
        $listStmt->execute($params);
        $items = [];
        foreach ($listStmt->fetchAll() as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'actorUserId' => (int) $row['actor_user_id'],
                'actorRole' => $row['actor_role'],
                'action' => $row['action'],
                'targetType' => $row['target_type'],
                'targetId' => $row['target_id'] !== null ? (int) $row['target_id'] : null,
                'result' => $row['result'],
                'summary' => $row['summary'],
                'ipAddress' => $row['ip_address'],
                'contextId' => $row['context_id'],
                'metadata' => $row['metadata_json'] !== null ? json_decode($row['metadata_json'], true) : [],
                'createdAt' => $row['created_at'],
            ];
        }

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * Defensively strip credential-shaped keys and truncate values so an
     * accidental secret can never be persisted even if a caller passes one.
     */
    public function sanitize(array $metadata): array
    {
        $out = [];
        foreach ($metadata as $k => $v) {
            $lk = strtolower((string) $k);
            foreach (self::FORBIDDEN_METADATA_KEYS as $bad) {
                if (str_contains($lk, $bad)) {
                    $out[$k] = '[REDACTED]';
                    continue 2;
                }
            }
            if (is_scalar($v) || $v === null) {
                $out[$k] = is_string($v) ? substr($v, 0, 200) : $v;
            } else {
                $out[$k] = $this->sanitize((array) $v);
            }
        }
        return $out;
    }

    public function sanitizeText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        // Strip anything that looks like a long opaque secret before persisting.
        $text = preg_replace('/AIza[A-Za-z0-9_-]{10,}/', '[REDACTED]', $text);
        $text = preg_replace('/eyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,}/', '[REDACTED]', $text);
        return $text !== null ? mb_substr($text, 0, 500) : null;
    }
}
