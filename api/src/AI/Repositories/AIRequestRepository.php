<?php

declare(strict_types=1);

namespace Velora\AI\Repositories;

use Velora\AI\DTOs\AIRequestDTO;
use Velora\AI\DTOs\AIResponseDTO;

/**
 * Repository for ai_requests — full audit of all AI calls.
 * Follows existing repository pattern, extends AIRepository.
 */
final class AIRequestRepository extends AIRepository
{
    private const TABLE = 'ai_requests';

    /**
     * Log AI request + response for audit, cost, debugging.
     *
     * @return int New ID
     */
    public function logRequest(AIRequestDTO $request, AIResponseDTO $response): int
    {
        try {
            $stmt = $this->connection()->prepare(
                'INSERT INTO ' . self::TABLE . '
                    (user_id, feature, provider, model, prompt_hash, tokens_used, latency_ms, status, cost)
                 VALUES
                    (:user_id, :feature, :provider, :model, :prompt_hash, :tokens_used, :latency_ms, :status, :cost)'
            );

            $stmt->bindValue(':user_id', $request->userId, \PDO::PARAM_INT);
            $stmt->bindValue(':feature', $request->feature);
            $stmt->bindValue(':provider', $response->provider);
            $stmt->bindValue(':model', $response->model);
            $stmt->bindValue(':prompt_hash', $request->promptHash !== '' ? $request->promptHash : hash('sha256', $request->prompt));
            $stmt->bindValue(':tokens_used', $response->tokensUsed, \PDO::PARAM_INT);
            $stmt->bindValue(':latency_ms', $response->latencyMs, \PDO::PARAM_INT);
            $stmt->bindValue(':status', $response->status);
            // Simple cost calculation: $0 for free tier, placeholder for paid
            $cost = $this->calculateCost($response);
            $stmt->bindValue(':cost', $cost);

            $stmt->execute();
            return (int) $this->connection()->lastInsertId();
        } catch (\Throwable $e) {
            error_log('[VELORA_AI_REQUEST] log failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Simple cost calculation — MVP: free tier = 0, future: token-based pricing.
     */
    private function calculateCost(AIResponseDTO $response): string
    {
        // Free tier providers cost 0
        if (in_array($response->provider, ['tesseract', 'gemini'], true)) {
            // Gemini free tier 1500/day = 0 cost, OpenAI would have cost
            return '0.000000';
        }
        // Placeholder: $0.000001 per token for paid
        $cost = $response->tokensUsed * 0.000001;
        return number_format($cost, 6, '.', '');
    }

    /**
     * Recent requests for user.
     *
     * @return array<int,array>
     */
    public function recentForUser(int $userId, int $limit = 20): array
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

    /**
     * Usage stats per provider.
     *
     * @return array<int,array>
     */
    public function usageStats(int $days = 7): array
    {
        try {
            $stmt = $this->connection()->prepare(
                'SELECT provider, feature, COUNT(*) as total, AVG(latency_ms) as avg_latency, SUM(tokens_used) as total_tokens, SUM(cost) as total_cost
                 FROM ' . self::TABLE . '
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                 GROUP BY provider, feature'
            );
            $stmt->bindValue(':days', $days, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
