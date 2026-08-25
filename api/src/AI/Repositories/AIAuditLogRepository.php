<?php

declare(strict_types=1);

namespace Velora\AI\Repositories;

/**
 * Repository for ai_audit_logs — security and privacy audit.
 * Never stores raw screenshots, only image hash.
 * Extends AIRepository.
 */
final class AIAuditLogRepository extends AIRepository
{
    private const TABLE = 'ai_audit_logs';

    /**
     * Log audit event.
     */
    public function log(int $userId, string $feature, string $provider, string $imageHash, string $action = 'extraction'): void
    {
        try {
            $stmt = $this->connection()->prepare(
                'INSERT INTO ' . self::TABLE . ' (user_id, feature, provider, image_hash, action)
                 VALUES (:user_id, :feature, :provider, :image_hash, :action)'
            );
            $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
            $stmt->bindValue(':feature', $feature);
            $stmt->bindValue(':provider', $provider);
            $stmt->bindValue(':image_hash', $imageHash);
            $stmt->bindValue(':action', $action);
            $stmt->execute();
        } catch (\Throwable $e) {
            error_log('[VELORA_AI_AUDIT] log failed: ' . $e->getMessage());
        }
    }

    /**
     * Recent audit logs for user.
     *
     * @return array<int,array>
     */
    public function recentForUser(int $userId, int $limit = 50): array
    {
        try {
            $stmt = $this->connection()->prepare(
                'SELECT * FROM ' . self::TABLE . ' WHERE user_id = :user_id ORDER BY created_at DESC LIMIT :limit'
            );
            $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
