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
     * Explicit safe Admin projection for the Phase-5 global listing.
     * `prompt_hash` is deliberately EXCLUDED (minimum-necessary: the drill-down
     * never needs it), so no `SELECT *` can leak audit-only columns.
     */
    private const PUBLIC_COLUMNS = 'id, user_id, feature, provider, model, tokens_used, latency_ms, status, cost, created_at';

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

    /**
     * Phase 5 — global Admin search across ALL users' AI requests (the route
     * carries `aiManage`). Same additive, whitelisted conventions as the
     * Phase-4 `searchGlobal` extensions: `user_id` becomes an OPTIONAL
     * filter; feature/provider/status are exact-match whitelisted upstream by
     * the controller; `model` is an exact-match bounded string; date bounds
     * are validated Y-m-d upstream and widened to full days here. Order is
     * match-whitelisted with an `id` tiebreaker (deterministic pagination).
     * The projection is the explicit safe column list — `prompt_hash` is
     * never returned and this table never stores prompt/response payloads.
     * Existing per-user methods (`recentForUser`, `usageStats`) are untouched.
     *
     * @param array<string,mixed> $filters ['user_id'?, 'feature'?, 'provider'?, 'model'?, 'status'?, 'date_from'?, 'date_to'?]
     * @param array{limit:int, offset:int, order?:string, dir?:string} $page
     * @return array{items: array<int,array>, total: int}
     */
    public function searchGlobal(array $filters, array $page): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = :user_id';
            $params['user_id'] = (int) $filters['user_id'];
        }
        if (!empty($filters['feature'])) {
            $where[] = 'feature = :feature';
            $params['feature'] = (string) $filters['feature'];
        }
        if (!empty($filters['provider'])) {
            $where[] = 'provider = :provider';
            $params['provider'] = (string) $filters['provider'];
        }
        if (!empty($filters['model'])) {
            $where[] = 'model = :model';
            $params['model'] = (string) $filters['model'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'created_at >= :date_from';
            $params['date_from'] = (string) $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'created_at <= :date_to';
            $params['date_to'] = (string) $filters['date_to'] . ' 23:59:59';
        }

        $whereSql = $where === [] ? '1=1' : implode(' AND ', $where);
        $dir = strtolower((string) ($page['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $orderCol = match ($page['order'] ?? 'created_at') {
            'tokens_used' => 'tokens_used',
            'latency_ms' => 'latency_ms',
            'cost' => 'cost',
            default => 'created_at',
        };
        $orderSql = "{$orderCol} {$dir}, id {$dir}";

        $countStmt = $this->connection()->prepare(
            "SELECT COUNT(*) FROM " . self::TABLE . " WHERE {$whereSql}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->connection()->prepare(
            'SELECT ' . self::PUBLIC_COLUMNS . ' FROM ' . self::TABLE . "
             WHERE {$whereSql}
             ORDER BY {$orderSql}
             LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':limit', $page['limit'], \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $page['offset'], \PDO::PARAM_INT);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }
}
