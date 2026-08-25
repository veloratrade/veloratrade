<?php

declare(strict_types=1);

namespace Velora\AI\Feedback;

use Velora\AI\Repositories\AIRepository;

/**
 * Repository for ai_feedback — stores user corrections for future training.
 * Never stores screenshots, only JSON results.
 * Preserves ownership validation.
 */
final class AIFeedbackRepository extends AIRepository
{
    private const TABLE = 'ai_feedback';

    /**
     * Create feedback from user correction.
     *
     * @param array<string,mixed> $originalResult
     * @param array<string,mixed> $correctedResult
     * @param string[] $changedFields
     * @return int New ID
     */
    public function createFeedback(int $userId, ?int $extractionId, array $originalResult, array $correctedResult, array $changedFields): int
    {
        $stmt = $this->connection()->prepare(
            'INSERT INTO ' . self::TABLE . ' (user_id, extraction_id, original_result, corrected_result, changed_fields)
             VALUES (:user_id, :extraction_id, :original_result, :corrected_result, :changed_fields)'
        );

        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        self::bindNullable($stmt, ':extraction_id', $extractionId, \PDO::PARAM_INT);
        $stmt->bindValue(':original_result', json_encode($originalResult, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $stmt->bindValue(':corrected_result', json_encode($correctedResult, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $stmt->bindValue(':changed_fields', json_encode($changedFields, JSON_UNESCAPED_SLASHES));

        $stmt->execute();
        return (int) $this->connection()->lastInsertId();
    }

    /**
     * Find user feedback with ownership validation.
     *
     * @return array<int,array>
     */
    public function findUserFeedback(int $userId, int $limit = 50): array
    {
        $stmt = $this->connection()->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE user_id = :user_id ORDER BY created_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Statistics for provider accuracy.
     *
     * @return array<string,mixed>
     */
    public function statistics(int $days = 30): array
    {
        try {
            $stmt = $this->connection()->prepare(
                'SELECT COUNT(*) as total_feedbacks,
                        COUNT(DISTINCT user_id) as unique_users,
                        COUNT(DISTINCT extraction_id) as unique_extractions
                 FROM ' . self::TABLE . '
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)'
            );
            $stmt->bindValue(':days', $days, \PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch();
            return $row === false ? ['total_feedbacks' => 0] : $row;
        } catch (\Throwable $e) {
            return ['total_feedbacks' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Find owned feedback.
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
}
