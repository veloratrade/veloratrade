<?php

declare(strict_types=1);

namespace Velora\AI\Repositories;

use Velora\AI\Providers\CredentialStatus;
use Velora\AI\Providers\VerificationResult;

/**
 * Safe credential METADATA persistence (ai_provider_credentials).
 *
 * This repository NEVER stores a credential value, key, token or secret. It
 * persists only metadata: status, verified flag, a non-reversible fingerprint,
 * timestamps, a sanitized error code and latency. Plaintext secrets remain in
 * the private velora.env (SecureCredentialStore) — unchanged.
 *
 * When a credential is replaced it is re-marked UNVERIFIED (a new value has
 * not been validated), so it is never reported healthy merely because it was
 * saved.
 */
final class AICredentialMetadataRepository extends AIRepository
{
    private const TABLE = 'ai_provider_credentials';

    public function tableExists(): bool
    {
        try {
            $pdo = $this->connection();
            $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :t");
            $stmt->execute(['t' => self::TABLE]);
            if ($stmt->fetch() !== false) {
                return true;
            }
            $stmt = $pdo->prepare('SHOW TABLES LIKE :t');
            $stmt->execute(['t' => self::TABLE]);
            return $stmt->fetch() !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @return array<string,mixed>|null */
    public function get(string $provider): ?array
    {
        if (!$this->tableExists()) {
            return null;
        }
        try {
            $stmt = $this->connection()->prepare(
                'SELECT * FROM ' . self::TABLE . ' WHERE provider = :p LIMIT 1'
            );
            $stmt->execute(['p' => strtolower(trim($provider))]);
            $row = $stmt->fetch();
            return $row === false ? null : $row;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Persist the outcome of a live verification (or a reset to UNVERIFIED).
     *
     * @param array<string,mixed> $extra e.g. ['fingerprint' => ..., 'verified_at' => ...]
     */
    public function record(string $provider, VerificationResult $result, ?string $fingerprint = null): bool
    {
        return $this->upsert([
            'provider' => strtolower(trim($provider)),
            'status' => $result->status(),
            'verified' => $result->verified() ? 1 : 0,
            'fingerprint' => $fingerprint ?? '',
            'verified_at' => $result->verified() ? $result->checkedAt() : null,
            'last_checked_at' => $result->checkedAt(),
            'error_code' => $result->code(),
            'latency_ms' => $result->latencyMs(),
            'version' => 1,
        ]);
    }

    /** Mark a (re)placed credential as UNVERIFIED until explicitly verified. */
    public function markUnverified(string $provider, ?string $reason = null): bool
    {
        return $this->upsert([
            'provider' => strtolower(trim($provider)),
            'status' => CredentialStatus::UNVERIFIED,
            'verified' => 0,
            'fingerprint' => '',
            'verified_at' => null,
            'last_checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'error_code' => $reason ?? null,
            'latency_ms' => 0,
            'version' => 1,
        ]);
    }

    /** Safe shape for the admin overview — includes NO secret, NO fingerprint. */
    public function safeMetadata(string $provider): array
    {
        $row = $this->get($provider);
        if ($row === null) {
            return [
                'status' => CredentialStatus::UNVERIFIED,
                'verified' => false,
                'checkedAt' => null,
                'lastCheckedAt' => null,
                'errorCode' => null,
                'latencyMs' => 0,
            ];
        }
        return [
            'status' => (string) ($row['status'] ?? CredentialStatus::UNVERIFIED),
            'verified' => ((int) ($row['verified'] ?? 0)) === 1,
            'checkedAt' => $row['verified_at'] ?? null,
            'lastCheckedAt' => $row['last_checked_at'] ?? null,
            'errorCode' => $row['error_code'] ?? null,
            'latencyMs' => (int) ($row['latency_ms'] ?? 0),
        ];
    }

    /** @param array<string,mixed> $data */
    private function upsert(array $data): bool
    {
        try {
            $pdo = $this->connection();
            $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $sql = 'INSERT INTO ' . self::TABLE . ' (provider,status,verified,fingerprint,verified_at,last_checked_at,error_code,latency_ms,version)
                        VALUES (:provider,:status,:verified,:fingerprint,:verified_at,:last_checked_at,:error_code,:latency_ms,:version)
                        ON CONFLICT(provider) DO UPDATE SET
                          status=:status, verified=:verified, fingerprint=:fingerprint,
                          verified_at=:verified_at, last_checked_at=:last_checked_at,
                          error_code=:error_code, latency_ms=:latency_ms, version=:version, updated_at=CURRENT_TIMESTAMP';
            } else {
                $sql = 'INSERT INTO ' . self::TABLE . ' (provider,status,verified,fingerprint,verified_at,last_checked_at,error_code,latency_ms,version)
                        VALUES (:provider,:status,:verified,:fingerprint,:verified_at,:last_checked_at,:error_code,:latency_ms,:version)
                        ON DUPLICATE KEY UPDATE
                          status=:status, verified=:verified, fingerprint=:fingerprint,
                          verified_at=:verified_at, last_checked_at=:last_checked_at,
                          error_code=:error_code, latency_ms=:latency_ms, version=:version, updated_at=CURRENT_TIMESTAMP';
            }
            $stmt = $pdo->prepare($sql);
            return $stmt->execute([
                'provider' => (string) $data['provider'],
                'status' => (string) $data['status'],
                'verified' => (int) $data['verified'],
                'fingerprint' => (string) ($data['fingerprint'] ?? ''),
                'verified_at' => $data['verified_at'],
                'last_checked_at' => $data['last_checked_at'],
                'error_code' => $data['error_code'],
                'latency_ms' => (int) $data['latency_ms'],
                'version' => (int) ($data['version'] ?? 1),
            ]);
        } catch (\Throwable $e) {
            error_log('[VELORA_AI_CRED_META] persist failed for ' . ($data['provider'] ?? '?'));
            return false;
        }
    }
}
