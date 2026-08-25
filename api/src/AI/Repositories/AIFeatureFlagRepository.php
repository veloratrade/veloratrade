<?php

declare(strict_types=1);

namespace Velora\AI\Repositories;

/**
 * Repository for ai_feature_flags — gradual rollout.
 * Extends AIRepository for future DB separation.
 */
final class AIFeatureFlagRepository extends AIRepository
{
    private const TABLE = 'ai_feature_flags';

    /**
     * Check if feature is enabled for user (with rollout percentage).
     */
    public function isEnabled(string $featureName, ?int $userId = null): bool
    {
        try {
            $stmt = $this->connection()->prepare(
                'SELECT enabled, rollout_percentage FROM ' . self::TABLE . ' WHERE feature_name = :name LIMIT 1'
            );
            $stmt->execute(['name' => $featureName]);
            $row = $stmt->fetch();
            if ($row === false) {
                // Default: only screenshot extraction enabled
                return $featureName === 'ai_screenshot_extraction';
            }

            if (!(bool) $row['enabled']) {
                return false;
            }

            $rollout = (int) ($row['rollout_percentage'] ?? 100);
            if ($rollout >= 100) {
                return true;
            }
            if ($rollout <= 0) {
                return false;
            }

            // Deterministic rollout based on user_id hash
            if ($userId === null) {
                return true;
            }
            $hash = crc32($featureName . ':' . $userId) % 100;
            return $hash < $rollout;
        } catch (\Throwable $e) {
            // Fail closed for new features, open for extraction
            return $featureName === 'ai_screenshot_extraction';
        }
    }

    /**
     * Get all flags.
     *
     * @return array<int,array>
     */
    public function all(): array
    {
        try {
            $stmt = $this->connection()->query('SELECT * FROM ' . self::TABLE . ' ORDER BY feature_name');
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Set flag (admin only).
     */
    public function setFlag(string $featureName, bool $enabled, int $rolloutPercentage = 100): void
    {
        try {
            $stmt = $this->connection()->prepare(
                'INSERT INTO ' . self::TABLE . ' (feature_name, enabled, rollout_percentage)
                 VALUES (:name, :enabled, :rollout)
                 ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), rollout_percentage = VALUES(rollout_percentage), updated_at = NOW()'
            );
            $stmt->bindValue(':name', $featureName);
            $stmt->bindValue(':enabled', $enabled ? 1 : 0, \PDO::PARAM_INT);
            $stmt->bindValue(':rollout', max(0, min(100, $rolloutPercentage)), \PDO::PARAM_INT);
            $stmt->execute();
        } catch (\Throwable $e) {
            error_log('[VELORA_AI_FLAGS] setFlag failed: ' . $e->getMessage());
        }
    }
}
