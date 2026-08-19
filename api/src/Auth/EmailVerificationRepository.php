<?php

declare(strict_types=1);

namespace Velora\Auth;

use Velora\Core\Database;

final class EmailVerificationRepository
{
    public function invalidateAllForUser(int $userId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = Database::connection()->prepare(
            'UPDATE email_verifications SET verified_at = :now WHERE user_id = :user_id AND verified_at IS NULL'
        );
        $stmt->execute(['now' => $now, 'user_id' => $userId]);
    }

    public function create(int $userId, string $tokenHash, int $ttlSeconds): void
    {
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);
        $stmt = Database::connection()->prepare(
            'INSERT INTO email_verifications (user_id, token_hash, expires_at) VALUES (:user_id, :token_hash, :expires_at)'
        );
        $stmt->execute(['user_id' => $userId, 'token_hash' => $tokenHash, 'expires_at' => $expiresAt]);
    }

    public function findValid(string $tokenHash): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, verified_at, expires_at FROM email_verifications WHERE token_hash = :hash LIMIT 1'
        );
        $stmt->execute(['hash' => $tokenHash]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function latestForUser(int $userId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT created_at FROM email_verifications WHERE user_id = :id ORDER BY id DESC LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** تعداد درخواست‌های ارسال‌شده در بازه زمانی اخیر (پیش‌فرض ۲۴ ساعت گذشته) */
    public function countRecentForUser(int $userId, int $windowSeconds = 86400): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $windowSeconds);
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM email_verifications WHERE user_id = :id AND created_at >= :cutoff'
        );
        $stmt->execute(['id' => $userId, 'cutoff' => $cutoff]);
        return (int) $stmt->fetchColumn();
    }

    /** Atomically consume a still-valid verification row. */
    public function consume(int $id): bool
    {
        $now = gmdate('Y-m-d H:i:s');
        // Native MySQL prepared statements do not allow reusing one named
        // placeholder twice. Bind the same timestamp under distinct names.
        $stmt = Database::connection()->prepare(
            'UPDATE email_verifications
             SET verified_at = :verified_at
             WHERE id = :id AND verified_at IS NULL AND expires_at >= :expires_cutoff'
        );
        $stmt->execute([
            'verified_at' => $now,
            'expires_cutoff' => $now,
            'id' => $id,
        ]);
        return $stmt->rowCount() === 1;
    }
}
