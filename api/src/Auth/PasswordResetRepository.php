<?php

declare(strict_types=1);

namespace Velora\Auth;

use PDO;
use Velora\Core\Database;

/**
 * Data access for password_resets table.
 * توکن‌ها فقط به‌صورت هش (sha256) ذخیره می‌شوند.
 */
final class PasswordResetRepository
{
    public function create(int $userId, string $tokenHash, int $ttlSeconds): int
    {
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);
        $stmt = Database::connection()->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at)
             VALUES (:user_id, :token_hash, :expires_at)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    public function findValidByHash(string $tokenHash): ?array
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, token_hash, expires_at, used_at
             FROM password_resets
             WHERE token_hash = :hash
               AND used_at IS NULL
               AND expires_at >= :now
             LIMIT 1'
        );
        $stmt->execute(['hash' => $tokenHash, 'now' => $now]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Atomically consume a still-valid reset token. */
    public function consume(int $id, string $tokenHash): bool
    {
        $now = gmdate('Y-m-d H:i:s');
        // Native MySQL prepares require unique named placeholders.
        $stmt = Database::connection()->prepare(
            'UPDATE password_resets
             SET used_at = :used_at
             WHERE id = :id
               AND token_hash = :hash
               AND used_at IS NULL
               AND expires_at >= :expires_cutoff'
        );
        $stmt->execute([
            'used_at' => $now,
            'expires_cutoff' => $now,
            'id' => $id,
            'hash' => $tokenHash,
        ]);
        return $stmt->rowCount() === 1;
    }

    public function invalidateAllForUser(int $userId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = Database::connection()->prepare(
            'UPDATE password_resets SET used_at = :now WHERE user_id = :user_id AND used_at IS NULL'
        );
        $stmt->execute(['now' => $now, 'user_id' => $userId]);
    }

    public function cleanupExpired(): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = Database::connection()->prepare(
            'DELETE FROM password_resets WHERE expires_at < :now OR used_at IS NOT NULL'
        );
        $stmt->execute(['now' => $now]);
        return $stmt->rowCount();
    }
}
