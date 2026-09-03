<?php

declare(strict_types=1);

namespace Velora\Admin;

use Velora\Core\Database;
use Velora\Core\SecretRedactor;

/**
 * Persists the outcome of the last REAL integration probe (v1.5).
 *
 * Only ever stores a probe result; never a credential. `checked_at` is only
 * written when an actual probe ran, so the UI can always distinguish
 * "No previous check recorded" from a fabricated timestamp.
 */
final class IntegrationHealthRepository
{
    private const VALID = ['metaapi', 'n8n_relay', 'ai', 'email'];
    private const STATUSES = ['HEALTHY', 'DEGRADED', 'UNHEALTHY', 'NOT_CONFIGURED', 'UNKNOWN'];

    public function remember(
        string $integration,
        string $status,
        int $latencyMs = 0,
        ?string $errorCode = null,
        ?string $message = null,
    ): void {
        $integration = strtolower(trim($integration));
        if (!in_array($integration, self::VALID, true)) {
            return;
        }
        $status = strtoupper(trim($status));
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'UNKNOWN';
        }
        $message = SecretRedactor::text($message);
        if ($message !== null && $message !== '') {
            $message = mb_substr($message, 0, 500);
        }
        $vals = [
            'i' => $integration,
            's' => $status,
            'lat' => $latencyMs > 0 ? $latencyMs : null,
            'code' => $errorCode !== null ? mb_substr($errorCode, 0, 64) : null,
            'msg' => $message !== '' ? $message : null,
            'ts' => gmdate('Y-m-d H:i:s'),
        ];
        try {
            $conn = Database::connection();

            // Portable upsert: try UPDATE first; if no row exists, INSERT.
            $upd = $conn->prepare(
                'UPDATE integration_health SET status=:s, latency_ms=:lat, error_code=:code,
                 message=:msg, checked_at=:ts WHERE integration=:i'
            );
            $upd->execute([
                'i' => $vals['i'], 's' => $vals['s'], 'lat' => $vals['lat'],
                'code' => $vals['code'], 'msg' => $vals['msg'], 'ts' => $vals['ts'],
            ]);
            if ($upd->rowCount() === 0) {
                $ins = $conn->prepare(
                    'INSERT INTO integration_health (integration, status, latency_ms, error_code, message, checked_at)
                     VALUES (:i, :s, :lat, :code, :msg, :ts)'
                );
                $ins->execute($vals);
            }
        } catch (\Throwable $e) {
            // Fail-open: health cache never breaks the request.
        }
    }

    /** @return array<string,mixed>|null null when no probe has run for this integration. */
    public function last(string $integration): ?array
    {
        $integration = strtolower(trim($integration));
        if (!in_array($integration, self::VALID, true)) {
            return null;
        }
        try {
            $stmt = Database::connection()->prepare(
                'SELECT integration, status, latency_ms, error_code, message, checked_at
                 FROM integration_health WHERE integration = :i LIMIT 1'
            );
            $stmt->execute(['i' => $integration]);
            $row = $stmt->fetch();
            if ($row === false) {
                return null;
            }
            return [
                'integration' => $row['integration'],
                'status' => strtoupper((string) $row['status']),
                'latencyMs' => $row['latency_ms'] !== null ? (int) $row['latency_ms'] : null,
                'errorCode' => $row['error_code'],
                'message' => SecretRedactor::text($row['message']),
                'checkedAt' => $row['checked_at'],
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }
}
