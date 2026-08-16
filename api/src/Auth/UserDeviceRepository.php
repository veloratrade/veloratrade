<?php

declare(strict_types=1);

namespace Velora\Auth;

use PDO;
use Throwable;
use Velora\Core\Database;

/**
 * مدیریت دستگاه‌های کاربر در جدول user_devices و تشخیص ورود از دستگاه جدید
 * هماهنگ با ستون‌های دقیق دیتابیس piknet_velora:
 * (id, user_id, fingerprint, ip_address, user_agent, first_seen_at, last_seen_at)
 */
final class UserDeviceRepository
{
    /**
     * بررسی دستگاه فعلی؛ در صورتی که دستگاه جدید باشد، در جدول ثبت شده و true برمی‌گرداند.
     */
    public function recordAndCheckNewDevice(int $userId, ?string $ip, ?string $userAgent): bool
    {
        $ipStr = mb_substr(trim((string) ($ip ?? '0.0.0.0')), 0, 45);
        $uaStr = mb_substr(trim((string) ($userAgent ?? 'Unknown Device')), 0, 250);

        // تولید شناسه یکتا برای ترکیب IP و User-Agent کاربر
        $fingerprint = hash('sha256', $ipStr . '|' . $uaStr);
        $now = gmdate('Y-m-d H:i:s');

        try {
            $db = Database::connection();
            $stmt = $db->prepare(
                'SELECT id FROM user_devices WHERE user_id = :user_id AND fingerprint = :fingerprint LIMIT 1'
            );
            $stmt->execute(['user_id' => $userId, 'fingerprint' => $fingerprint]);
            $row = $stmt->fetch();

            if ($row === false) {
                // دستگاه جدید است -> ثبت در جدول
                $insertStmt = $db->prepare(
                    'INSERT INTO user_devices (user_id, fingerprint, ip_address, user_agent, first_seen_at, last_seen_at)
                     VALUES (:user_id, :fingerprint, :ip, :ua, :now, :now)'
                );
                $insertStmt->execute([
                    'user_id' => $userId,
                    'fingerprint' => $fingerprint,
                    'ip' => $ipStr,
                    'ua' => $uaStr,
                    'now' => $now,
                ]);
                return true;
            }

            // دستگاه قبلاً ثبت شده است -> به‌روزرسانی زمان آخرین فعالیت
            $updateStmt = $db->prepare(
                'UPDATE user_devices SET last_seen_at = :now, ip_address = :ip, user_agent = :ua WHERE id = :id'
            );
            $updateStmt->execute([
                'now' => $now,
                'ip' => $ipStr,
                'ua' => $uaStr,
                'id' => (int) $row['id'],
            ]);
            return false;
        } catch (Throwable) {
            return false;
        }
    }

    public function listForUser(int $userId): array
    {
        try {
            $db = Database::connection();
            $stmt = $db->prepare(
                'SELECT id, ip_address, user_agent, first_seen_at, last_seen_at FROM user_devices WHERE user_id = :uid ORDER BY last_seen_at DESC'
            );
            $stmt->execute(['uid' => $userId]);
            return $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }
}
