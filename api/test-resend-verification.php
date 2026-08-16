<?php

declare(strict_types=1);

/**
 * VELORA — ابزار تست ارسال مجدد ایمیل تأیید (Resend Verification)
 * نحوه استفاده:
 *   https://veloratrade.ir/api/test-resend-verification.php?email=you@example.com
 */
header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/src/bootstrap.php';

use Velora\Auth\AuthService;
use Velora\Core\Exceptions\ValidationException;

$email = isset($_GET['email']) ? trim($_GET['email']) : '';

echo "══════ تست قابلیت ارسال مجدد ایمیل تأیید (Resend Verification) ══════\n";
echo "ایمیل درخواستی: " . ($email !== '' ? $email : '❌ تعیین نشده!') . "\n";
echo "═══════════════════════════════════════════════════════════════════════\n\n";

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo "❌ لطفاً آدرس ایمیل معتبر را به صورت پارامتر ارسال کنید:\n";
    echo "   https://veloratrade.ir/api/test-resend-verification.php?email=you@example.com\n";
    exit;
}

try {
    $service = new AuthService();
    $startTime = microtime(true);
    $result = $service->resendVerification(['email' => $email]);
    $duration = round((microtime(true) - $startTime) * 1000, 2);

    echo "نتیجه درخواست (زمان اجرا: {$duration} میلی‌ثانیه):\n\n";

    if (!empty($result['alreadyVerified'])) {
        echo "ℹ️ وضعیت: حساب قبلاً تأیید شده است.\n";
        echo "پیام سیستم: {$result['message']}\n";
    } elseif (!empty($result['sent'])) {
        echo "✅✅✅ وضعیت: لینک تأیید جدید با موفقیت ارسال شد!\n";
        echo "پیام سیستم: {$result['message']}\n";
        echo "\nلطفاً اینباکس یا پوشه Spam ایمیل ($email) را بررسی کنید.\n";
    } else {
        echo "⚠️ وضعیت نامشخص:\n";
        print_r($result);
    }
} catch (ValidationException $e) {
    echo "⏳ خطا / محدودیت زمانی (Rate Limit):\n";
    echo "پیام سیستم: " . $e->getMessage() . "\n";
    echo "\n(برای جلوگیری از اسپم، بین هر درخواست ارسال مجدد حداقل ۲ دقیقه وقفه الزامی است).\n";
} catch (\Throwable $e) {
    echo "❌ خطای سیستمی:\n";
    echo $e->getMessage() . "\n";
}
