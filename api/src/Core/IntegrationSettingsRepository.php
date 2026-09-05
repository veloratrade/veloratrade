<?php

declare(strict_types=1);

namespace Velora\Core;

use PDO;

/**
 * Generic key-value store for Admin-managed INTEGRATION settings (Phase C).
 *
 * REUSE, not duplication: this reads/writes the SAME generic key-value table
 * created for Phase B (`ai_global_settings`): setting_key / setting_value /
 * updated_by / timestamps. It is not a per-integration table and not a second
 * "configuration system" — it is the single generic settings table, one row per
 * non-secret operational setting (e.g. metaapi base URL, mail driver/from).
 *
 * Secrets are NEVER stored here. They live in the encrypted SecureCredentialStore
 * (same Crypto / same APP_ENCRYPTION_KEY) and are read by IntegrationConfigResolver.
 *
 * Absent row = "unset/inherit": the resolver then falls back to process ENV /
 * velora.env / default. Portability: works on MySQL AND SQLite (no driver-specific
 * ON DUPLICATE KEY / ON CONFLICT upsert).
 */
final class IntegrationSettingsRepository
{
    private const TABLE = 'ai_global_settings';

    static function database(): PDO
    {
        return Database::connection();
    }

    /** Return the stored non-secret value, or null when unset (or table unavailable). */
    public function get(string $key): ?string
    {
        try {
            $stmt = self::database()->prepare(
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
            // Table unavailable (fresh pre-migration deployment) => inherit.
            return null;
        }
    }

    /** Upsert a non-secret value; $updatedBy is the acting admin id (0 = system). */
    public function set(string $key, string $value, int $updatedBy = 0): bool
    {
        try {
            $pdo = self::database();
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
            error_log('[VELORA_INTEGRATIONS] set failed for ' . $key . ': ' . $e->getMessage());
            return false;
        }
    }

    /** Remove a value -> "inherit". Returns true if a row existed and was removed. */
    public function delete(string $key): bool
    {
        try {
            $stmt = self::database()->prepare(
                'DELETE FROM ' . self::TABLE . ' WHERE setting_key = :k'
            );
            $stmt->execute(['k' => $key]);
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            error_log('[VELORA_INTEGRATIONS] delete failed for ' . $key);
            return false;
        }
    }
}
