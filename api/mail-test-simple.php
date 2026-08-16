<?php

declare(strict_types=1);

/**
 * VELORA — ابزار عیب‌یابی و مقایسه مستقیم ارسال ایمیل‌های «تأیید ثبت‌نام» و «فراموشی رمز»
 * نحوه استفاده:
 *   https://veloratrade.ir/api/mail-test-simple.php?email=you@example.com
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

echo "══════ تست و مقایسه مستقیم ارسال ایمیل‌های VELORA ══════\n";
echo "درایور فعلی: $driver\n";
echo "هاست SMTP:   " . ($host !== '' ? $host : '---') . "\n";
echo "کاربر SMTP:  " . ($user !== '' ? $user : '---') . "\n";
echo "فرستنده:     $from\n";
echo "گیرنده:      $targetEmail\n";
echo "════════════════════════════════════════════════════════════\n\n";

if (!filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
    echo "❌ فرمت ایمیل گیرنده نامعتبر است! لطفاً با پارامتر ?email=you@example.com تست کنید.\n";
    exit;
}

$resetUrl = rtrim((string) Config::get('frontend_url', 'https://veloratrade.ir'), '/') . '/reset-password?token=test-' . time();
$verifyUrl = rtrim((string) Config::get('frontend_url', 'https://veloratrade.ir'), '/') . '/verify-email?token=test-' . time();

echo "--- تست ۱: ارسال ایمیل بازیابی رمز (همان قالبی که برای شما کار کرد) ---\n";
$t1 = microtime(true);
$res1 = NotificationService::sendPasswordResetTokenEmail($targetEmail, 'کاربر تستی ۱', $resetUrl, 0);
$d1 = round((microtime(true) - $t1) * 1000, 2);
echo "نتیجه تست ۱: " . ($res1 ? "✅ موفق ($d1 ms)" : "❌ ناموفق ($d1 ms)") . "\n\n";

echo "--- تست ۲: ارسال ایمیل تأیید ثبت‌نام (با انکودینگ ایمن RFC 2047) ---\n";
$t2 = microtime(true);
$res2 = NotificationService::sendVerificationEmail($targetEmail, 'کاربر تستی ۲', $verifyUrl, 0);
$d2 = round((microtime(true) - $t2) * 1000, 2);
echo "نتیجه تست ۲: " . ($res2 ? "✅ موفق ($d2 ms)" : "❌ ناموفق ($d2 ms)") . "\n\n";

echo "══════════════════════ راهنمای تحلیل نتیجه ══════════════════════\n";
if ($res1 && $res2) {
    echo "🎉 هر دو ایمیل (بازیابی رمز + تأیید ثبت‌نام) با موفقیت ارسال شدند!\n";
    echo "لطفاً اینباکس و پوشه Spam ایمیل ($targetEmail) را بررسی کنید.\n";
} elseif ($res1 && !$res2) {
    echo "⚠️ ایمیل بازیابی رمز رفت اما تأیید ثبت‌نام نرفت!\n";
    echo "این وضعیت نشان‌دهنده محدودیت یا فیلتر خاص سرور روی عنوان یا متن است.\n";
} else {
    echo "❌ هیچ‌کدام از ایمیل‌ها ارسال نشدند!\n";
    echo "لطفاً تنظیمات فایل api/.env (مخصوصاً MAIL_DRIVER=smtp و MAIL_PASS) را چک کنید.\n";
}
