<?php

declare(strict_types=1);

namespace Velora\Core;

use PDO;
use Throwable;

/**
 * مدیریت دستاوردهای کاربر در جدول user_achievements
 * هماهنگ با ستون‌های دقیق دیتابیس piknet_velora:
 * (id, user_id, achievement_key, achieved_at, metadata_json)
 */
final class UserAchievementRepository
{
    /**
     * ثبت دستاورد جدید برای کاربر؛ در صورتی که برای اولین بار باز شده باشد true برمی‌گرداند.
     */
    public function unlock(int $userId, string $key, string $titleKey, string $descriptionKey): bool
    {
        $key = mb_strtoupper(trim($key));
        $now = gmdate('Y-m-d H:i:s');

        try {
            $db = Database::connection();
            $stmt = $db->prepare(
                'SELECT id FROM user_achievements WHERE user_id = :uid AND achievement_key = :key LIMIT 1'
            );
            $stmt->execute(['uid' => $userId, 'key' => $key]);
            if ($stmt->fetch() !== false) {
                return false; // قبلاً باز شده است
            }

            $metadataJson = json_encode([
                'titleKey' => $titleKey,
                'descriptionKey' => $descriptionKey,
                'unlockedAt' => gmdate('c'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            $insert = $db->prepare(
                'INSERT INTO user_achievements (user_id, achievement_key, achieved_at, metadata_json)
                 VALUES (:uid, :key, :now, :metadata)'
            );
            $insert->execute([
                'uid' => $userId,
                'key' => $key,
                'now' => $now,
                'metadata' => $metadataJson,
            ]);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function listForUser(int $userId): array
    {
        try {
            $db = Database::connection();
            $stmt = $db->prepare(
                'SELECT * FROM user_achievements WHERE user_id = :uid ORDER BY achieved_at DESC'
            );
            $stmt->execute(['uid' => $userId]);
            return $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }
}
