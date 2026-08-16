<?php

declare(strict_types=1);

/**
 * VELORA — تست مستقیم ارسال ایمیل تأیید ثبت‌نام
 * نحوه استفاده:
 *   https://veloratrade.ir/api/test-verify-email.php?email=you@example.com
 */
header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/src/bootstrap.php';

use Velora\Core\Config;
use Velora\Core\NotificationService;

$driver = Config::env('MAIL_DRIVER', 'mail');
$host = Config::env('MAIL_HOST', '');
$user = Config::env('MAIL_USER', '');
$from = Config::env('MAIL_FROM', 'no-reply@veloratrade.ir');
$targetEmail = isset($_GET['email']) && trim($_GET['email']) !== '' ? trim($_GET['email']) : $from;

echo "══════ تست ارسال ایمیل تأیید ثبت‌نام VELORA ══════\n";
echo "درایور فعلی: $driver\n";
echo "هاست SMTP:   " . ($host !== '' ? $host : '---') . "\n";
echo "کاربر SMTP:  " . ($user !== '' ? $user : '---') . "\n";
echo "فرستنده:     $from\n";
echo "گیرنده:      $targetEmail\n";
echo "═══════════════════════════════════════════════════\n\n";

if (!filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
    echo "❌ فرمت ایمیل گیرنده نامعتبر است! لطفاً با پارامتر ?email=you@example.com تست کنید.\n";
    exit;
}

echo "در حال ساخت قالب و ارسال ایمیل تأیید...\n";
$verifyUrl = rtrim((string) Config::get('frontend_url', 'https://veloratrade.ir'), '/') . '/verify-email?token=test-' . time();

$startTime = microtime(true);
$result = NotificationService::sendVerificationEmail(
    $targetEmail,
    'کاربر آزمایشی',
    $verifyUrl,
    0
);
$duration = round((microtime(true) - $startTime) * 1000, 2);

if ($result) {
    echo "\n✅✅✅ ارسال ایمیل تأیید با موفقیت انجام شد! (زمان: {$duration} میلی‌ثانیه)\n";
    echo "لطفاً اینباکس یا پوشه Spam ایمیل ($targetEmail) را بررسی کنید.\n";
} else {
    echo "\n❌ ارسال ایمیل ناموفق بود! (زمان: {$duration} میلی‌ثانیه)\n";
    echo "راهنما جهت بررسی:\n";
    echo " 1. مطمئن شوید در فایل api/.env مقدار MAIL_DRIVER=smtp تنظیم شده است.\n";
    echo " 2. اطلاعات MAIL_USER و MAIL_PASS را در .env کنترل کنید.\n";
    echo " 3. فایل api/mail-test.php را در مرورگر باز کنید تا خطا و بنر دقیق SMTP را ببینید.\n";
}
