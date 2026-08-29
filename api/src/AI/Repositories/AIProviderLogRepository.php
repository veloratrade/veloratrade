<?php

declare(strict_types=1);

namespace Velora\AI\Repositories;

use PDO;

/**
 * Repository for ai_provider_logs.
 * Simple persistence for provider health, no Redis.
 * Extends AIRepository for future DB separation.
 */
final class AIProviderLogRepository extends AIRepository
{
    private const TABLE = 'ai_provider_logs';

    /**
     * Log provider attempt.
     */
    public function log(string $provider, string $status, int $latencyMs = 0, ?string $errorCode = null): void
    {
        try {
            $stmt = $this->connection()->prepare(
                'INSERT INTO ' . self::TABLE . ' (provider, status, latency_ms, error_code)
                 VALUES (:provider, :status, :latency_ms, :error_code)'
            );
            $stmt->bindValue(':provider', $provider);
            $stmt->bindValue(':status', $status);
            $stmt->bindValue(':latency_ms', $latencyMs, PDO::PARAM_INT);
            $stmt->bindValue(':error_code', $errorCode);
            $stmt->execute();
        } catch (\Throwable $e) {
            // Best effort — don't fail extraction if log fails
            error_log('[VELORA_AI_LOG] failed for ' . $provider . ': ' . $e->getMessage());
        }
    }

    /**
     * Recent logs for provider.
     *
     * @return array<int,array>
     */
    public function recentForProvider(string $provider, int $limit = 50): array
    {
        try {
            $stmt = $this->connection()->prepare(
                'SELECT * FROM ' . self::TABLE . ' WHERE provider = :provider ORDER BY created_at DESC LIMIT :limit'
            );
            $stmt->bindValue(':provider', $provider);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Failure count in last N minutes.
     */
    public function failureCountLastMinutes(string $provider, int $minutes = 5): int
    {
        try {
            $stmt = $this->connection()->prepare(
                'SELECT COUNT(*) FROM ' . self::TABLE . '
                 WHERE provider = :provider AND status != :success AND created_at >= DATE_SUB(NOW(), INTERVAL :minutes MINUTE)'
            );
            $stmt->bindValue(':provider', $provider);
            $stmt->bindValue(':success', 'success');
            $stmt->bindValue(':minutes', $minutes, PDO::PARAM_INT);
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
