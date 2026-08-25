<?php

declare(strict_types=1);

namespace Velora\AI\Repositories;

/**
 * Repository for ai_provider_quotas — hardened with atomic reservation.
 * Fixes race condition: hasQuota() + incrementUsage() -> tryReserveQuota() atomic.
 */
final class AIProviderQuotaRepository extends AIRepository
{
    private const TABLE = 'ai_provider_quotas';

    /**
     * Check if provider has quota remaining — fail-closed for paid, fail-open for free.
     *
     * @param int $costTier 0=free,1=cheap,2=paid
     */
    public function hasQuota(string $provider, int $costTier = 0): bool
    {
        try {
            $stmt = $this->connection()->prepare(
                'SELECT daily_used, quota_limit, reset_at FROM ' . self::TABLE . ' WHERE provider = :provider LIMIT 1'
            );
            $stmt->execute(['provider' => $provider]);
            $row = $stmt->fetch();
            if ($row === false) {
                return true;
            }

            $resetAt = strtotime($row['reset_at'] ?? '');
            $todayStart = strtotime(gmdate('Y-m-d 00:00:00'));
            if ($resetAt !== false && $resetAt < $todayStart) {
                $this->resetQuota($provider);
                return true;
            }

            return (int) $row['daily_used'] < (int) $row['quota_limit'];
        } catch (\Throwable $e) {
            error_log('[VELORA_AI_QUOTA] check failed for ' . $provider . ': ' . $e->getMessage());
            // P0 fix: fail-closed for paid, fail-open for free
            return $costTier < 2;
        }
    }

    /**
     * Atomic quota reservation — fixes race condition.
     * UPDATE ... WHERE daily_used < quota_limit, check affected rows.
     *
     * @return bool true if reserved, false if quota exhausted
     */
    public function tryReserveQuota(string $provider): bool
    {
        try {
            $pdo = $this->connection();
            $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

            // Ensure row exists first (for MySQL, INSERT IGNORE)
            if ($driver === 'mysql') {
                $pdo->prepare(
                    'INSERT IGNORE INTO ' . self::TABLE . ' (provider, quota_limit, reset_at) VALUES (:provider, 1500, NOW())'
                )->execute(['provider' => $provider]);
            } else {
                // SQLite
                $pdo->prepare(
                    'INSERT OR IGNORE INTO ' . self::TABLE . ' (provider, quota_limit) VALUES (:provider, 1500)'
                )->execute(['provider' => $provider]);
            }

            // Reset if needed before reservation
            $stmt = $pdo->prepare('SELECT reset_at FROM ' . self::TABLE . ' WHERE provider = :provider LIMIT 1');
            $stmt->execute(['provider' => $provider]);
            $row = $stmt->fetch();
            if ($row !== false) {
                $resetAt = strtotime($row['reset_at'] ?? '');
                $todayStart = strtotime(gmdate('Y-m-d 00:00:00'));
                if ($resetAt !== false && $resetAt < $todayStart) {
                    $this->resetQuota($provider);
                }
            }

            // Atomic reservation
            if ($driver === 'sqlite') {
                // SQLite doesn't support < in UPDATE with affected rows check same way, but we simulate
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('SELECT daily_used, quota_limit FROM ' . self::TABLE . ' WHERE provider = :provider LIMIT 1');
                $stmt->execute(['provider' => $provider]);
                $row = $stmt->fetch();
                if ($row === false || (int) $row['daily_used'] >= (int) $row['quota_limit']) {
                    $pdo->rollBack();
                    return false;
                }
                $stmt = $pdo->prepare('UPDATE ' . self::TABLE . ' SET daily_used = daily_used + 1, updated_at = CURRENT_TIMESTAMP WHERE provider = :provider');
                $stmt->execute(['provider' => $provider]);
                $pdo->commit();
                return $stmt->rowCount() > 0;
            }

            // MySQL atomic
            $stmt = $pdo->prepare(
                'UPDATE ' . self::TABLE . '
                 SET daily_used = daily_used + 1, updated_at = NOW()
                 WHERE provider = :provider AND daily_used < quota_limit'
            );
            $stmt->execute(['provider' => $provider]);
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            error_log('[VELORA_AI_QUOTA] reserve failed for ' . $provider . ': ' . $e->getMessage());
            // Fail open for free tier to allow fallback, but caller will check costTier
            return false;
        }
    }

    /**
     * Legacy increment — kept for backward compat, now calls tryReserve.
     */
    public function incrementUsage(string $provider): void
    {
        // For backward compat, try atomic reserve but ignore result
        $this->tryReserveQuota($provider);
    }

    public function resetQuota(string $provider): void
    {
        try {
            $pdo = $this->connection();
            $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $pdo->prepare(
                    'UPDATE ' . self::TABLE . ' SET daily_used = 0, reset_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE provider = :provider'
                )->execute(['provider' => $provider]);
            } else {
                $pdo->prepare(
                    'UPDATE ' . self::TABLE . ' SET daily_used = 0, reset_at = NOW(), updated_at = NOW() WHERE provider = :provider'
                )->execute(['provider' => $provider]);
            }
        } catch (\Throwable $e) {
            error_log('[VELORA_AI_QUOTA] reset failed: ' . $e->getMessage());
        }
    }

    public function getQuota(string $provider): ?array
    {
        try {
            $stmt = $this->connection()->prepare(
                'SELECT * FROM ' . self::TABLE . ' WHERE provider = :provider LIMIT 1'
            );
            $stmt->execute(['provider' => $provider]);
            $row = $stmt->fetch();
            return $row === false ? null : $row;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
