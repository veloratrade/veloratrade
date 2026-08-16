<?php

declare(strict_types=1);

namespace Velora\Auth;

use PDO;
use Velora\Core\Database;

/**
 * Data access for the user_sessions table (refresh-token store).
 * Refresh tokens are stored hashed (sha256) — never in plain text.
 */
final class SessionRepository
{
    public function create(
        int $userId,
        string $refreshTokenHash,
        string $accessTokenHash,
        ?string $ip,
        ?string $userAgent,
        int $ttlSeconds,
    ): int {
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);
        $stmt = Database::connection()->prepare(
            'INSERT INTO user_sessions
                (user_id, refresh_token_hash, access_token_hash, ip_address, user_agent, expires_at)
             VALUES (:user_id, :refresh_hash, :access_hash, :ip, :ua, :expires_at)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'refresh_hash' => $refreshTokenHash,
            'access_hash' => $accessTokenHash,
            'ip' => $ip,
            'ua' => $userAgent !== null ? mb_substr($userAgent, 0, 250) : null,
            'expires_at' => $expiresAt,
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    public function findByRefreshHash(string $refreshTokenHash): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, refresh_token_hash, expires_at, revoked_at
             FROM user_sessions WHERE refresh_token_hash = :hash LIMIT 1'
        );
        $stmt->execute(['hash' => $refreshTokenHash]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findByAccessHash(string $accessTokenHash, int $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, revoked_at, expires_at
             FROM user_sessions
             WHERE user_id = :user_id AND access_token_hash = :hash
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'hash' => $accessTokenHash]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Atomically rotate both tokens. The expected refresh hash makes the
     * single-use transition concurrency-safe: only one racing request wins.
     */
    public function rotate(
        int $sessionId,
        string $expectedRefreshHash,
        string $newRefreshHash,
        string $newAccessHash,
        ?string $ip,
        ?string $userAgent,
        int $ttlSeconds,
    ): bool {
        $stmt = Database::connection()->prepare(
            'UPDATE user_sessions
             SET refresh_token_hash = :new_refresh,
                 access_token_hash = :new_access,
                 expires_at = :expires_at,
                 ip_address = :ip,
                 user_agent = :ua
             WHERE id = :id
               AND refresh_token_hash = :expected_refresh
               AND revoked_at IS NULL
               AND expires_at > :now'
        );
        $stmt->execute([
            'new_refresh' => $newRefreshHash,
            'new_access' => $newAccessHash,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttlSeconds),
            'ip' => $ip,
            'ua' => $userAgent !== null ? mb_substr($userAgent, 0, 250) : null,
            'id' => $sessionId,
            'expected_refresh' => $expectedRefreshHash,
            'now' => gmdate('Y-m-d H:i:s'),
        ]);
        return $stmt->rowCount() === 1;
    }

    public function revoke(int $sessionId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = Database::connection()->prepare(
            'UPDATE user_sessions SET revoked_at = :now WHERE id = :id AND revoked_at IS NULL'
        );
        $stmt->execute(['now' => $now, 'id' => $sessionId]);
    }

    public function revokeAllForUser(int $userId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = Database::connection()->prepare(
            'UPDATE user_sessions SET revoked_at = :now WHERE user_id = :user_id AND revoked_at IS NULL'
        );
        $stmt->execute(['now' => $now, 'user_id' => $userId]);
    }

    public function cleanupExpired(): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = Database::connection()->prepare(
            'DELETE FROM user_sessions WHERE expires_at < :now OR revoked_at IS NOT NULL'
        );
        $stmt->execute(['now' => $now]);
        return $stmt->rowCount();
    }
}
