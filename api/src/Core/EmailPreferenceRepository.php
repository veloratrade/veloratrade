<?php

declare(strict_types=1);

namespace Velora\Core;

use PDO;
use Throwable;

/**
 * بررسی و مدیریت ترجیحات اعلان ایمیل کاربر در جدول email_preferences
 * هماهنگ با ستون‌های دقیق دیتابیس piknet_velora:
 * (welcome_email, security_alerts, trade_notifications, weekly_report, monthly_report, achievement_notifications)
 */
final class EmailPreferenceRepository
{
    /**
     * بررسی مجاز بودن ارسال ایمیل برای دسته مشخص:
     * - 'welcome': ایمیل خوش‌آمدگویی
     * - 'security': همیشه مجاز است (امنیت حساب، تغییر رمز، دستگاه جدید، تأیید ایمیل)
     * - 'trades': گزارش‌های معاملاتی و اولین معامله
     * - 'achievements': دستاوردهای جدید
     */
    public function canSend(int $userId, string $category = 'security'): bool
    {
        if ($category === 'security' || $userId <= 0) {
            return true;
        }

        try {
            $db = Database::connection();
            $column = match ($category) {
                'welcome' => 'welcome_email',
                'trades' => 'trade_notifications',
                'achievements' => 'achievement_notifications',
                'weekly' => 'weekly_report',
                'monthly' => 'monthly_report',
                default => 'security_alerts',
            };

            $stmt = $db->prepare("SELECT {$column} FROM email_preferences WHERE user_id = :user_id LIMIT 1");
            $stmt->execute(['user_id' => $userId]);
            $val = $stmt->fetchColumn();

            if ($val === false) {
                return true; // در صورتی که کاربری رکوردی نداشت، پیش‌فرض فعال است
            }
            return (bool) $val;
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * BUG-A9: خواندن ترجیحات کاربر برای API مدیریت اعلان‌ها.
     * در نبود رکورد، همه دسته‌ها (به‌جز منطق always-on امنیتی) مقدار پیش‌فرض فعال می‌گیرند.
     *
     * @return array<string,int>
     */
    public function getForUser(int $userId): array
    {
        $defaults = [
            'welcome_email' => 1,
            'security_alerts' => 1,
            'trade_notifications' => 1,
            'weekly_report' => 1,
            'monthly_report' => 1,
            'achievement_notifications' => 1,
        ];

        try {
            $stmt = Database::connection()->prepare(
                'SELECT welcome_email, security_alerts, trade_notifications, weekly_report, monthly_report, achievement_notifications
                 FROM email_preferences WHERE user_id = :user_id LIMIT 1'
            );
            $stmt->execute(['user_id' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!is_array($row)) {
                return $defaults;
            }
            return array_map('intval', array_merge($defaults, $row));
        } catch (Throwable) {
            return $defaults;
        }
    }

    public function setPreferences(int $userId, array $prefs): void
    {
        try {
            $db = Database::connection();
            $isSqlite = $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';

            $sql = $isSqlite
                ? 'INSERT INTO email_preferences
                    (user_id, welcome_email, security_alerts, trade_notifications, weekly_report, monthly_report, achievement_notifications)
                 VALUES
                    (:uid, :welcome, :sec, :trade, :weekly, :monthly, :achieve)
                 ON CONFLICT(user_id) DO UPDATE SET
                    welcome_email = excluded.welcome_email,
                    security_alerts = excluded.security_alerts,
                    trade_notifications = excluded.trade_notifications,
                    weekly_report = excluded.weekly_report,
                    monthly_report = excluded.monthly_report,
                    achievement_notifications = excluded.achievement_notifications'
                : 'INSERT INTO email_preferences
                    (user_id, welcome_email, security_alerts, trade_notifications, weekly_report, monthly_report, achievement_notifications)
                 VALUES
                    (:uid, :welcome, :sec, :trade, :weekly, :monthly, :achieve)
                 ON DUPLICATE KEY UPDATE
                    welcome_email = VALUES(welcome_email),
                    security_alerts = VALUES(security_alerts),
                    trade_notifications = VALUES(trade_notifications),
                    weekly_report = VALUES(weekly_report),
                    monthly_report = VALUES(monthly_report),
                    achievement_notifications = VALUES(achievement_notifications)';

            $stmt = $db->prepare($sql);
            $stmt->execute([
                'uid' => $userId,
                'welcome' => (int) ($prefs['welcome_email'] ?? 1),
                'sec' => (int) ($prefs['security_alerts'] ?? 1),
                'trade' => (int) ($prefs['trade_notifications'] ?? 1),
                'weekly' => (int) ($prefs['weekly_report'] ?? 1),
                'monthly' => (int) ($prefs['monthly_report'] ?? 1),
                'achieve' => (int) ($prefs['achievement_notifications'] ?? 1),
            ]);
        } catch (Throwable) {
            // نادیده‌گرفتن خطا
        }
    }
}
