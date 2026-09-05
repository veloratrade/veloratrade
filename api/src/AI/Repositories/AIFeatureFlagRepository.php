<?php

declare(strict_types=1);

namespace Velora\AI\Repositories;

/**
 * Repository for ai_feature_flags — gradual rollout.
 * Extends AIRepository for future DB separation.
 *
 * Flag semantics (server-authoritative, unchanged from v0.5):
 *   - enabled : gate; a disabled feature is not available to any user.
 *   - rollout_percentage : deterministic, per-user percentage rollout
 *     (crc32(feature:user) % 100 < rollout). Stable for a user (never random
 *     per request) and evaluated server-side by the runtime consumer.
 *
 * Phase F adds: an actor-tracking, success-reporting setFlag() with a portable
 * cross-driver upsert (same pattern as AIGlobalSettingRepository), plus get(),
 * so an Admin can enable/disable/target a flag and the change is persisted
 * truthfully on both MySQL and SQLite and is reflected in the runtime consumer.
 * isEnabled()/all() semantics are unchanged (no flag behaviour regression).
 */
final class AIFeatureFlagRepository extends AIRepository
{
    private const TABLE = 'ai_feature_flags';

    /** Runtime flag space (canonical, ai_-prefixed — matches v0.5 seed + AIGuard). */
    public const CANONICAL_FLAGS = [
        'ai_screenshot_extraction',
        'ai_trade_analysis',
        'ai_weekly_report',
        'ai_assistant',
    ];

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
     * Get a single flag row, or null when absent / table unavailable.
     * Row includes feature_name, enabled, rollout_percentage, updated_by,
     * created_at, updated_at (the latter two when present in the schema).
     */
    public function get(string $featureName): ?array
    {
        try {
            $stmt = $this->connection()->prepare(
                'SELECT * FROM ' . self::TABLE . ' WHERE feature_name = :name LIMIT 1'
            );
            $stmt->execute(['name' => $featureName]);
            $row = $stmt->fetch();
            return $row === false ? null : $row;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Upsert a flag's enabled state + rollout targeting.
     *
     * Portable cross-driver upsert (SELECT + UPDATE/INSERT) so the value
     * persists on both MySQL and SQLite — never a silent no-op. Records the
     * acting admin ($updatedBy, 0 = system) for "last updated by". Returns true
     * only when the row was actually persisted.
     */
    public function setFlag(string $featureName, bool $enabled, int $rolloutPercentage = 100, int $updatedBy = 0): bool
    {
        try {
            $rollout = max(0, min(100, $rolloutPercentage));
            $pdo = $this->connection();

            $exists = $pdo->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE feature_name = :name LIMIT 1');
            $exists->execute(['name' => $featureName]);
            if ($exists->fetch() !== false) {
                $stmt = $pdo->prepare(
                    'UPDATE ' . self::TABLE
                    . ' SET enabled = :enabled, rollout_percentage = :rollout, updated_by = :u, updated_at = CURRENT_TIMESTAMP'
                    . ' WHERE feature_name = :name'
                );
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO ' . self::TABLE . ' (feature_name, enabled, rollout_percentage, updated_by)
                     VALUES (:name, :enabled, :rollout, :u)'
                );
            }
            $stmt->execute([
                'name' => $featureName,
                'enabled' => $enabled ? 1 : 0,
                'rollout' => $rollout,
                'u' => max(0, $updatedBy),
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log('[VELORA_AI_FLAGS] setFlag failed: ' . $e->getMessage());
            return false;
        }
    }
}
