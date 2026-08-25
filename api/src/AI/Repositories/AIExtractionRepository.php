<?php

declare(strict_types=1);

namespace Velora\AI\Repositories;

/**
 * Repository for ai_extractions table.
 * Follows existing TradeRepository pattern.
 * Extends AIRepository for future separate AI database migration.
 */
final class AIExtractionRepository extends AIRepository
{
    private const TABLE = 'ai_extractions';

    /**
     * Create extraction record.
     *
     * @param array<string,mixed> $data
     * @return int New ID
     */
    public function create(array $data): int
    {
        $stmt = $this->connection()->prepare(
            'INSERT INTO ' . self::TABLE . '
                (user_id, provider, image_hash, original_result, final_result, confidence, latency_ms, status, error_code)
             VALUES
                (:user_id, :provider, :image_hash, :original_result, :final_result, :confidence, :latency_ms, :status, :error_code)'
        );

        $stmt->bindValue(':user_id', $data['user_id'], PDO::PARAM_INT);
        $stmt->bindValue(':provider', $data['provider']);
        $stmt->bindValue(':image_hash', $data['image_hash']);
        $stmt->bindValue(':original_result', json_encode($data['original_result'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $stmt->bindValue(':final_result', json_encode($data['final_result'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $stmt->bindValue(':confidence', $data['confidence'] ?? 0.0);
        $stmt->bindValue(':latency_ms', $data['latency_ms'] ?? 0, PDO::PARAM_INT);
        $stmt->bindValue(':status', $data['status'] ?? 'success');
        $stmt->bindValue(':error_code', $data['error_code'] ?? null);

        $stmt->execute();
        return (int) $this->connection()->lastInsertId();
    }

    /**
     * Find by image hash for dedup cache.
     */
    public function findByHash(string $imageHash, int $userId): ?array
    {
        $stmt = $this->connection()->prepare(
            'SELECT * FROM ' . self::TABLE . '
             WHERE image_hash = :hash AND user_id = :user_id AND status = :status
             ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([
            'hash' => $imageHash,
            'user_id' => $userId,
            'status' => 'success',
        ]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Find owned extraction.
     */
    public function findOwned(int $id, int $userId): ?array
    {
        $stmt = $this->connection()->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE id = :id AND user_id = :user_id LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Recent extractions for user.
     *
     * @return array<int,array>
     */
    public function recentForUser(int $userId, int $limit = 20): array
    {
        $stmt = $this->connection()->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE user_id = :user_id ORDER BY created_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
