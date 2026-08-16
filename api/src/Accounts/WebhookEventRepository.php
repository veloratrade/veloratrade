<?php

declare(strict_types=1);

namespace Velora\Accounts;

use PDOException;
use Velora\Core\Database;

/** Durable MetaApi replay ledger with unique event keys and fenced processing. */
final class WebhookEventRepository
{
    /**
     * Reserve or safely reclaim an unprocessed event.
     *
     * @return array{id:int,duplicate:bool,processed:bool,claim_token:?string}
     */
    public function claim(
        string $eventKey,
        ?int $accountId,
        ?string $metaapiAccountId,
        string $eventType,
        array $payload,
        int $leaseSeconds = 300,
    ): array {
        $claimToken = bin2hex(random_bytes(32));
        $safePayload = json_encode($this->redactSensitive($payload), JSON_THROW_ON_ERROR);
        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO webhook_events
                 (event_key, account_id, metaapi_account_id, event_type, payload,
                  hmac_verified, processed, processing_token, processing_started_at, created_at)
                 VALUES
                 (:event_key, :account_id, :metaapi_account_id, :event_type, :payload,
                  1, 0, :processing_token, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
            );
            $stmt->execute([
                'event_key' => $eventKey,
                'account_id' => $accountId,
                'metaapi_account_id' => $metaapiAccountId,
                'event_type' => substr($eventType, 0, 50),
                'payload' => $safePayload,
                'processing_token' => $claimToken,
            ]);
            return [
                'id' => (int) Database::connection()->lastInsertId(),
                'duplicate' => false,
                'processed' => false,
                'claim_token' => $claimToken,
            ];
        } catch (PDOException $e) {
            if (!$this->isUniqueViolation($e)) {
                throw $e;
            }
        }

        $existing = $this->findByEventKey($eventKey);
        if ($existing === null) {
            throw new \RuntimeException('Webhook replay record could not be reloaded.');
        }
        if ((int) $existing['processed'] === 1) {
            return [
                'id' => (int) $existing['id'],
                'duplicate' => true,
                'processed' => true,
                'claim_token' => null,
            ];
        }

        $staleBefore = gmdate('Y-m-d H:i:s', time() - max(30, min($leaseSeconds, 3600)));
        $stmt = Database::connection()->prepare(
            'UPDATE webhook_events SET processing_token=:processing_token,
             processing_started_at=CURRENT_TIMESTAMP, last_error=NULL
             WHERE id=:id AND processed=0
               AND (processing_token IS NULL OR processing_started_at < :stale_before)'
        );
        $stmt->execute([
            'processing_token' => $claimToken,
            'id' => (int) $existing['id'],
            'stale_before' => $staleBefore,
        ]);
        return [
            'id' => (int) $existing['id'],
            'duplicate' => true,
            'processed' => false,
            'claim_token' => $stmt->rowCount() === 1 ? $claimToken : null,
        ];
    }

    public function markProcessed(int $eventId, string $claimToken): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE webhook_events SET processed=1, processed_at=CURRENT_TIMESTAMP,
             processing_token=NULL, processing_started_at=NULL, last_error=NULL
             WHERE id=:id AND processed=0 AND processing_token=:processing_token'
        );
        $stmt->execute(['id' => $eventId, 'processing_token' => $claimToken]);
        return $stmt->rowCount() === 1;
    }

    public function releaseAfterFailure(int $eventId, string $claimToken, string $errorCode): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE webhook_events SET processing_token=NULL, processing_started_at=NULL,
             last_error=:last_error
             WHERE id=:id AND processed=0 AND processing_token=:processing_token'
        );
        $stmt->execute([
            'last_error' => substr($errorCode, 0, 64),
            'id' => $eventId,
            'processing_token' => $claimToken,
        ]);
        return $stmt->rowCount() === 1;
    }

    public function findByEventKey(string $eventKey): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, processed, processing_token, processing_started_at
             FROM webhook_events WHERE event_key=:event_key LIMIT 1'
        );
        $stmt->execute(['event_key' => $eventKey]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function redactSensitive(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (preg_match('/password|token|authorization|credential|secret/i', (string) $key)) {
                $payload[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $payload[$key] = $this->redactSensitive($value);
            }
        }
        return $payload;
    }

    private function isUniqueViolation(PDOException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        return $sqlState === '23000' || $sqlState === '19';
    }
}
