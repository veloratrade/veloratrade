<?php

declare(strict_types=1);

namespace Velora\AI\Analysis;

use Velora\AI\Repositories\AIRepository;

/**
 * Repository for trade analysis results.
 * MUST NOT query trades table — receives TradeDataDTO[] from Service layer.
 * Stores analysis results in ai_analysis (future) or uses ai_requests for audit.
 */
final class TradeAnalysisRepository extends AIRepository
{
    private const TABLE = 'ai_analysis';

    /**
     * Create analysis record — stores only analysis result, not trades data.
     *
     * @param array<string,mixed> $analysisData
     * @return int New ID
     */
    public function create(int $userId, array $analysisData, string $provider = 'gemini', string $model = 'gemini-1.5-flash', float $confidence = 0.0): int
    {
        try {
            $stmt = $this->connection()->prepare(
                'INSERT INTO ' . self::TABLE . ' (user_id, provider, model, result_json, confidence)
                 VALUES (:user_id, :provider, :model, :result_json, :confidence)'
            );
            $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
            $stmt->bindValue(':provider', $provider);
            $stmt->bindValue(':model', $model);
            $stmt->bindValue(':result_json', json_encode($analysisData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $stmt->bindValue(':confidence', $confidence);
            $stmt->execute();
            return (int) $this->connection()->lastInsertId();
        } catch (\Throwable $e) {
            // Table may not exist yet (P1 structure only) — best effort
            error_log('[VELORA_AI_ANALYSIS] create failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Recent analysis for user — ownership enforced.
     *
     * @return array<int,array>
     */
    public function recentForUser(int $userId, int $limit = 10): array
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
