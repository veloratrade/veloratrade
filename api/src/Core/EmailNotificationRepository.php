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
        try {
            $db = Database::connection();

            // در صورتی که شناسه کاربر در دسترس نباشد، با 0 ثبت می‌شود
            $uid = ($userId !== null && $userId > 0) ? $userId : 0;
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
