<?php

declare(strict_types=1);

namespace Velora\AI\Repositories;

/**
 * Key-value store for the Admin-managed GLOBAL AI settings (v1.4).
 *
 * This is intentionally NOT the same thing as ai_feature_flags (boolean +
 * rollout table) and NOT a duplicate secret store. It holds one explicit,
 * server-validated, non-secret global AI setting: the default route
 * ('direct' | 'n8n_relay'). It is the single source of truth consumed by the
 * runtime route resolver (AiRouteResolver) so a value saved by a Super Admin
 * is actually effective without an .env edit.
 *
 * Absent row = "unset" (inherit): the resolver then falls back to legacy
 * GEMINI_ROUTE env -> ai_gemini_relay_route flag -> direct.
 */
final class AIGlobalSettingRepository extends AIRepository
{
    private const TABLE = 'ai_global_settings';

    /**
     * Return the stored value for a setting key, or null when the key is unset
     * (or the table is unavailable — treated as unset, never an error).
     */
    public function get(string $key): ?string
    {
        try {
            $stmt = $this->connection()->prepare(
                'SELECT setting_value FROM ' . self::TABLE . ' WHERE setting_key = :k LIMIT 1'
            );
            $stmt->execute(['k' => $key]);
            $row = $stmt->fetch();
            if ($row === false) {
                return null;
            }
            $value = trim((string) ($row['setting_value'] ?? ''));
            return $value === '' ? null : $value;
        } catch (\Throwable $e) {
            // Table unavailable (fresh pre-migration deployment) => inherited.
            return null;
        }
    }

    /** Upsert a value; $updatedBy is the acting admin id (0 = system). */
    public function set(string $key, string $value, int $updatedBy = 0): bool
    {
        try {
            $pdo = $this->connection();
            // Portable upsert (works on MySQL + SQLite, unlike ON DUPLICATE /
            // ON CONFLICT which are driver-specific).
            $exists = $pdo->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE setting_key = :k LIMIT 1');
            $exists->execute(['k' => $key]);
            if ($exists->fetch() !== false) {
                $stmt = $pdo->prepare(
                    'UPDATE ' . self::TABLE . ' SET setting_value = :v, updated_by = :u WHERE setting_key = :k'
                );
                $stmt->execute(['k' => $key, 'v' => $value, 'u' => $updatedBy]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO ' . self::TABLE . ' (setting_key, setting_value, updated_by) VALUES (:k, :v, :u)'
                );
                $stmt->execute(['k' => $key, 'v' => $value, 'u' => $updatedBy]);
            }
            return true;
        } catch (\Throwable $e) {
            error_log('[VELORA_AI_GLOBAL] set failed for ' . $key . ': ' . $e->getMessage());
            return false;
        }
    }

    /** Remove a value -> "inherit/unset". Returns true if a row existed and was removed. */
    public function delete(string $key): bool
    {
        try {
            $stmt = $this->connection()->prepare(
                'DELETE FROM ' . self::TABLE . ' WHERE setting_key = :k'
            );
            $stmt->execute(['k' => $key]);
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            error_log('[VELORA_AI_GLOBAL] delete failed for ' . $key);
            return false;
        }
    }
}
