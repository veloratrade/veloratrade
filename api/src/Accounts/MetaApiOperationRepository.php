<?php

declare(strict_types=1);

namespace Velora\Accounts;

use PDOException;
use Velora\Core\Database;
use Velora\Core\Exceptions\ConflictException;

/**
 * Durable, non-secret lifecycle journal for Velora-created MetaApi resources.
 *
 * Passwords, provider tokens and encrypted credentials are deliberately absent
 * from this table. operation_key and provider_marker are opaque SHA-256 based
 * identifiers used for idempotency and reconciliation only.
 */
final class MetaApiOperationRepository
{
    /**
     * @return array{operation:array,created:bool}
     */
    public function createOrGetConnect(
        int $userId,
        string $clientIdempotencyKey,
        string $requestFingerprint,
    ): array {
        $operationKey = hash('sha256', "velora-metaapi\0{$userId}\0{$clientIdempotencyKey}");
        $providerMarker = 'velora-op-' . substr($operationKey, 0, 40);

        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO metaapi_operations
                    (operation_key, provider_marker, request_fingerprint, user_id, operation_type, status,
                     attempts, created_at, updated_at)
                 VALUES
                    (:operation_key, :provider_marker, :request_fingerprint, :user_id, :operation_type, :status,
                     0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
            );
            $stmt->execute([
                'operation_key' => $operationKey,
                'provider_marker' => $providerMarker,
                'request_fingerprint' => $requestFingerprint,
                'user_id' => $userId,
                'operation_type' => 'CONNECT',
                'status' => 'PENDING',
            ]);
            $operation = $this->findByOperationKey($operationKey);
            if ($operation === null) {
                throw new \RuntimeException('MetaApi operation could not be reloaded.');
            }
            return ['operation' => $operation, 'created' => true];
        } catch (PDOException $e) {
            if (!$this->isUniqueViolation($e)) {
                throw $e;
            }
        }

        // A duplicate can be either the client operation key or the stable
        // connection fingerprint. In both cases continue the original
        // operation so a new client key cannot create a second provider
        // account after an ambiguous response.
        $operation = $this->findByOperationKey($operationKey)
            ?? $this->findByRequestFingerprint($requestFingerprint);
        if ($operation === null) {
            throw new ConflictException(
                'The MetaApi operation conflicts with an existing request.',
                'METAAPI_OPERATION_CONFLICT'
            );
        }
        if (!hash_equals((string) $operation['request_fingerprint'], $requestFingerprint)) {
            throw new ConflictException(
                'Idempotency-Key was already used with a different connection request.',
                'IDEMPOTENCY_KEY_REUSED'
            );
        }
        return ['operation' => $operation, 'created' => false];
    }

    public function findByOperationKey(string $operationKey): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM metaapi_operations WHERE operation_key = :operation_key LIMIT 1'
        );
        $stmt->execute(['operation_key' => $operationKey]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findByRequestFingerprint(string $requestFingerprint): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM metaapi_operations WHERE request_fingerprint = :request_fingerprint LIMIT 1'
        );
        $stmt->execute(['request_fingerprint' => $requestFingerprint]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findByAccount(int $accountId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM metaapi_operations
             WHERE account_id = :account_id AND operation_type = :operation_type
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['account_id' => $accountId, 'operation_type' => 'CONNECT']);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Count durable provider reservations not yet represented by an account. */
    public function countOpenReservationsForUser(int $userId): int
    {
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM metaapi_operations
             WHERE user_id=:user_id AND operation_type='CONNECT' AND account_id IS NULL
               AND status NOT IN ('FAILED','DELETED')"
        );
        $stmt->execute(['user_id' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    public function incrementAttempt(int $id): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE metaapi_operations
             SET attempts = attempts + 1, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    public function markProviderCreated(int $id, string $providerAccountId): void
    {
        $this->update($id, 'PROVIDER_CREATED', $providerAccountId, null, null);
    }

    public function markAmbiguous(int $id, string $errorCode): void
    {
        $this->update($id, 'RECONCILIATION_REQUIRED', null, null, $errorCode);
    }

    public function markFailed(int $id, string $errorCode): void
    {
        $this->update($id, 'FAILED', null, null, $errorCode);
    }

    public function markCompleted(int $id, int $accountId, string $providerAccountId): void
    {
        $this->update($id, 'COMPLETED', $providerAccountId, $accountId, null);
    }

    public function markDeletePending(int $id): void
    {
        $this->update($id, 'DELETE_PENDING', null, null, null);
    }

    public function markDeleteAmbiguous(int $id, string $errorCode): void
    {
        $this->update($id, 'DELETE_RECONCILIATION_REQUIRED', null, null, $errorCode);
    }

    public function markExternalDeleted(int $id): void
    {
        $this->update($id, 'EXTERNAL_DELETED', null, null, null);
    }

    public function markDeleted(int $id): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE metaapi_operations
             SET status = :status, account_id = NULL, completed_at = CURRENT_TIMESTAMP,
                 last_error_code = NULL, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute(['status' => 'DELETED', 'id' => $id]);
    }

    private function update(
        int $id,
        string $status,
        ?string $providerAccountId,
        ?int $accountId,
        ?string $errorCode,
    ): void {
        $sets = ['status = :status', 'updated_at = CURRENT_TIMESTAMP'];
        $params = ['status' => $status, 'id' => $id];
        if ($providerAccountId !== null) {
            $sets[] = 'provider_account_id = :provider_account_id';
            $params['provider_account_id'] = $providerAccountId;
        }
        if ($accountId !== null) {
            $sets[] = 'account_id = :account_id';
            $params['account_id'] = $accountId;
        }
        if ($errorCode !== null) {
            $sets[] = 'last_error_code = :last_error_code';
            $params['last_error_code'] = substr($errorCode, 0, 64);
        } else {
            $sets[] = 'last_error_code = NULL';
        }
        if (in_array($status, ['COMPLETED', 'DELETED'], true)) {
            $sets[] = 'completed_at = CURRENT_TIMESTAMP';
        }
        $stmt = Database::connection()->prepare(
            'UPDATE metaapi_operations SET ' . implode(', ', $sets) . ' WHERE id = :id'
        );
        $stmt->execute($params);
    }

    private function isUniqueViolation(PDOException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        return $sqlState === '23000' || $sqlState === '19';
    }
}
