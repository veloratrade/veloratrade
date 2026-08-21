<?php

declare(strict_types=1);

namespace Velora\Core;

use PDO;
use Throwable;

/**
 * ثبت لاگ و وضعیت ایمیل‌های ارسال‌شده در جدول email_notifications
 * هماهنگ با ساختار دقیق دیتابیس piknet_velora:
 * (user_id, event_type, recipient_email, subject, payload_json, status, sent_at, failed_at, error_message, created_at)
 */
final class EmailNotificationRepository
{
    public function log(
        ?int $userId,
        string $recipientEmail,
        string $eventType,
        string $subject,
        string $status = 'sent',
        ?string $errorMessage = null,
        ?array $payload = null,
    ): void {
        // BUG-A12: هر اعلان باید مالکِ کاربرِ واقعی داشته باشد. ارجاع نامعتبر هرگز
        // با تبدیل به 0 یا بلعِ سکوت جا نمی‌افتد — بلند و زود شکست می‌خورد تا
        // در رخداد اصلی (ارسال) یا audit trail لحظه‌ای گم نشود.
        $uid = $userId ?? 0;
        if ($uid <= 0) {
            throw new \InvalidArgumentException('EmailNotificationRepository::log requires a valid owning user id.');
        }

        $db = Database::connection();
        $owner = $db->prepare('SELECT 1 FROM users WHERE id = :id LIMIT 1');
        $owner->execute(['id' => $uid]);
        if ($owner->fetchColumn() === false) {
            throw new \DomainException('EmailNotificationRepository::log references a non-existent user (id=' . $uid . ').');
        }

        try {
            $payloadJson = $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null;
            $now = gmdate('Y-m-d H:i:s');

            $sentAt = $status === 'sent' ? $now : null;
            $failedAt = $status !== 'sent' ? $now : null;

            $stmt = $db->prepare(
                'INSERT INTO email_notifications (user_id, event_type, recipient_email, subject, payload_json, status, sent_at, failed_at, error_message, created_at)
                 VALUES (:user_id, :event_type, :recipient_email, :subject, :payload_json, :status, :sent_at, :failed_at, :error_message, :now)'
            );

            $stmt->execute([
                'user_id' => $uid,
                'event_type' => mb_substr($eventType, 0, 50),
                'recipient_email' => mb_substr($recipientEmail, 0, 255),
                'subject' => mb_substr($subject, 0, 255),
                'payload_json' => $payloadJson,
                'status' => mb_substr($status, 0, 20),
                'sent_at' => $sentAt,
                'failed_at' => $failedAt,
                'error_message' => $errorMessage !== null ? mb_substr($errorMessage, 0, 500) : null,
                'now' => $now,
            ]);
        } catch (Throwable) {
            // خطا در ثبت لاگ اعلان نباید مانع انجام فرآیند اصلی شود
        }
    }
}
