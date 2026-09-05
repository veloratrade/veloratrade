<?php

declare(strict_types=1);

namespace Velora\Core;

use Velora\Admin\AdminAuditLogRepository;

/**
 * Append-only structured application log store (system_logs, v1.5).
 *
 * Security contract (mirrors AdminAuditLogRepository):
 *   - record() NEVER persists a secret. Values are run through SecretRedactor
 *     defensively (even if a caller ignores the contract), and the audit repo's
 *     sanitizeText() is applied to the message as a second net.
 *   - No update/delete path exists for the UI. The UI can LIST and the runtime
 *     can APPEND; it cannot mutate or erase history.
 *   - Writing is best-effort/fail-open: if the table is absent (v1.5 not applied)
 *     the caller still succeeds — a diagnostic log must never break a request.
 *   - Allowed severities: DEBUG|INFO|WARN|ERROR (allowlisted server-side).
 */
final class SystemLogRepository
{
    private const SEVERITIES = ['DEBUG', 'INFO', 'WARN', 'ERROR'];

    public function __construct(private readonly AdminAuditLogRepository $audit = new AdminAuditLogRepository())
    {
    }

    /**
     * Convenience static entry point for fire-and-forget logging (e.g. the
     * global exception handler). Always fail-open; never throws.
     */
    public static function recordIfAvailable(
        string $severity,
        string $source,
        ?string $message,
        ?string $requestId = null,
        ?string $correlationId = null,
        ?int $userId = null,
        ?string $errorCode = null,
        array $metadata = [],
    ): void {
        try {
            (new self())->record($severity, $source, $message, $requestId, $correlationId, $userId, $errorCode, $metadata);
        } catch (\Throwable $e) {
            // Never let logging throw.
        }
    }

    /**
     * @param array<string,mixed> $metadata safe metadata (redacted again here)
     */
    public function record(
        string $severity,
        string $source,
        ?string $message,
        ?string $requestId = null,
        ?string $correlationId = null,
        ?int $userId = null,
        ?string $errorCode = null,
        array $metadata = [],
    ): void {
        $severity = strtoupper(trim($severity));
        if (!in_array($severity, self::SEVERITIES, true)) {
            $severity = 'INFO';
        }
        $source = substr(trim($source), 0, 64);
        if ($source === '') {
            $source = 'system';
        }
        $message = self::clip($this->audit->sanitizeText(SecretRedactor::text($message)), 1000);
        $metadata = SecretRedactor::metadata($metadata);
        $metaJson = $metadata === [] ? null : self::clip((string) json_encode($metadata, JSON_UNESCAPED_UNICODE), 4000);

        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO system_logs
                    (severity, source, message, request_id, correlation_id, user_id, error_code, metadata_json, created_at)
                 VALUES
                    (:severity, :source, :message, :req, :corr, :user, :code, :meta, :created)'
            );
            $stmt->execute([
                'severity' => $severity,
                'source' => $source,
                'message' => $message,
                'req' => $requestId !== null && $requestId !== '' ? substr($requestId, 0, 64) : null,
                'corr' => $correlationId !== null && $correlationId !== '' ? substr($correlationId, 0, 64) : null,
                'user' => $userId !== null && $userId > 0 ? $userId : null,
                'code' => $errorCode !== null && $errorCode !== '' ? substr($errorCode, 0, 64) : null,
                'meta' => $metaJson,
                'created' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Best-effort / fail-open — never let logging break the request.
        }
    }

    /**
     * List entries with optional filters + pagination. Returns metadata already
     * redacted (belt-and-suspenders; storage is already redacted).
     *
     * @param array<string,mixed> $filters severity|source|since|until|q
     * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int}
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];
        if (!empty($filters['severity']) && in_array(strtoupper($filters['severity']), self::SEVERITIES, true)) {
            $where[] = 'severity = :severity';
            $params['severity'] = strtoupper($filters['severity']);
        }
        if (!empty($filters['source'])) {
            $where[] = 'source = :source';
            $params['source'] = substr((string) $filters['source'], 0, 64);
        }
        if (!empty($filters['since'])) {
            $where[] = 'created_at >= :since';
            $params['since'] = $filters['since'];
        }
        if (!empty($filters['until'])) {
            $where[] = 'created_at <= :until';
            $params['until'] = $filters['until'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(message LIKE :q OR request_id LIKE :q2 OR error_code LIKE :q3)';
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']) . '%';
            $params['q'] = $like;
            $params['q2'] = $like;
            $params['q3'] = $like;
        }
        $clause = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        try {
            $pdo = Database::connection();
        } catch (\Throwable $e) {
            return ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
        }

        $countStmt = $pdo->prepare("SELECT COUNT(*) AS n FROM system_logs $clause");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['n'];

        $listStmt = $pdo->prepare(
            "SELECT id, severity, source, message, request_id, correlation_id, user_id,
                    error_code, metadata_json, created_at
             FROM system_logs $clause
             ORDER BY id DESC LIMIT $perPage OFFSET $offset"
        );
        $listStmt->execute($params);
        $items = [];
        foreach ($listStmt->fetchAll() as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'severity' => strtoupper((string) $row['severity']),
                'source' => $row['source'],
                'message' => SecretRedactor::text($row['message']),
                'requestId' => $row['request_id'],
                'correlationId' => $row['correlation_id'],
                'userId' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
                'errorCode' => $row['error_code'],
                'metadata' => SecretRedactor::metadata($row['metadata_json'] !== null ? (array) json_decode($row['metadata_json'], true) : []),
                'createdAt' => $row['created_at'],
            ];
        }

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    private static function clip(?string $s, int $len): string
    {
        if ($s === null) {
            return '';
        }
        return mb_substr($s, 0, $len);
    }
}
