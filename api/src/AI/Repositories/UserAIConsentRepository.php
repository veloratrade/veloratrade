<?php

declare(strict_types=1);

namespace Velora\AI\Repositories;

/**
 * Repository for users.ai_consent_at — privacy consent.
 * Updates users table directly, isolated for AI module, does not modify Auth core logic.
 * Follows existing repository pattern.
 */
final class UserAIConsentRepository extends AIRepository
{
    /**
     * Set AI consent for user.
     *
     * @param bool $consented true = NOW(), false = NULL
     * @return bool Whether row updated
     */
    public function setConsent(int $userId, bool $consented): bool
    {
        try {
            $pdo = $this->connection();
            $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

            if ($driver === 'sqlite') {
                // SQLite fallback for tests
                $stmt = $pdo->prepare(
                    'UPDATE users SET ai_consent_at = :consent_at WHERE id = :id'
                );
                $consentAt = $consented ? gmdate('Y-m-d H:i:s') : null;
                $stmt->bindValue(':consent_at', $consentAt);
                $stmt->bindValue(':id', $userId, \PDO::PARAM_INT);
                $stmt->execute();
                return $stmt->rowCount() > 0;
            }

            if ($consented) {
                $stmt = $pdo->prepare(
                    'UPDATE users SET ai_consent_at = NOW() WHERE id = :id'
                );
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE users SET ai_consent_at = NULL WHERE id = :id'
                );
            }
            $stmt->bindValue(':id', $userId, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            // If column missing, log and return false — migration not yet applied
            error_log('[VELORA_AI_CONSENT] setConsent failed: ' . $e->getMessage());
            return false;
        }
    }

    public function hasConsent(int $userId): bool
    {
        try {
            $stmt = $this->connection()->prepare(
                'SELECT ai_consent_at FROM users WHERE id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $userId]);
            $row = $stmt->fetch();
            if ($row === false) {
                return false;
            }
            return $row['ai_consent_at'] !== null && $row['ai_consent_at'] !== '';
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getConsentAt(int $userId): ?string
    {
        try {
            $stmt = $this->connection()->prepare(
                'SELECT ai_consent_at FROM users WHERE id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $userId]);
            $row = $stmt->fetch();
            return $row === false ? null : ($row['ai_consent_at'] ?? null);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
