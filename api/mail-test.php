<?php
declare(strict_types=1);
/**
 * VELORA — تست پیشرفته ارسال ایمیل (با نمایش خطای دقیق SMTP)
 * این فایل را در پوشه api/ آپلود و در مرورگر باز کنید.
 */
header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/src/bootstrap.php';

use Velora\Core\Config;

$driver = Config::env('MAIL_DRIVER', 'mail');
$host = Config::env('MAIL_HOST', '');
$port = Config::env('MAIL_PORT', '');
$user = Config::env('MAIL_USER', '');
$pass = Config::env('MAIL_PASS', '');
$from = Config::env('MAIL_FROM', 'no-reply@veloratrade.ir');
$to = isset($_GET['to']) ? $_GET['to'] : $from;

echo "══════ تنظیمات فعلی ایمیل ══════\n";
echo "MAIL_DRIVER = " . ($driver !== '' ? $driver : '❌ خالی!') . "\n";
echo "MAIL_HOST    = " . ($host !== '' ? $host : '❌ خالی!') . "\n";
echo "MAIL_PORT    = " . ($port !== '' ? $port : '❌ خالی!') . "\n";
echo "MAIL_USER    = " . ($user !== '' ? $user : '❌ خالی!') . "\n";
echo "MAIL_PASS    = " . ($pass !== '' ? '*** تنظیم شده' : '❌ خالی!') . "\n";
echo "MAIL_FROM    = " . ($from !== '' ? $from : '❌ خالی!') . "\n";
echo "ارسال به     = $to\n\n";

if ($driver !== 'smtp') {
    echo "❌ MAIL_DRIVER=$driver است — باید smtp باشد تا ایمیل واقعی برود!\n";
    echo "در فایل api/.env این خط را بگذارید: MAIL_DRIVER=smtp\n";
    exit;
}

// تست پورتهای مختلف
$portsToTry = array_unique(array_filter([(int)$port, 465, 587]));

echo "══════ تست اتصال به سرور SMTP ══════\n";
$connected = false;
foreach ($portsToTry as $p) {
    $socketHost = $p === 465 ? 'ssl://' . $host : $host;
    $sock = @fsockopen($socketHost, $p, $errno, $errstr, 10);
    if ($sock) {
        $resp = fgets($sock, 515);
        echo "✅ پورت $p : اتصال برقرار شد → " . trim($resp) . "\n";
        fclose($sock);
        $connected = true;
        break;
    } else {
        echo "❌ پورت $p : $errstr (errno: $errno)\n";
    }
}

if (!$connected) {
    echo "\n❌ هیچ پورتی باز نیست! احتمالاً:\n";
    echo "  • آدرس mail.veloratrade.ir اشتباه است (سرویس ایمیل هاست شما این است؟)\n";
    echo "  • یا پورت 465/587 روی هاست مسدود شده\n";
    echo "  → در cPanel بخش Email Accounts ببینید آدرس سرور ایمیل چیست\n";
    exit;
}

echo "\n══════ تست احراز هویت (AUTH LOGIN) ══════\n";
$activePort = (int) ($port ?: 465);
$socketHost = $activePort === 465 ? 'ssl://' . $host : $host;
$sock = @fsockopen($socketHost, $activePort, $errno, $errstr, 10);
if (!$sock) { echo "❌ اتصال ناموفق\n"; exit; }
// بنر SMTP ممکن است چندخطی باشد؛ همه خطوط را مصرف کن.
smtp_read($sock);

fwrite($sock, "EHLO veloratrade.ir\r\n");
$ehlo = smtp_read($sock);
echo "EHLO → " . $ehlo . "\n";

// STARTTLS برای پورت 587
if (($port ?: 465) == 587) {
    fwrite($sock, "STARTTLS\r\n");
    $st = smtp_read($sock);
    echo "STARTTLS → " . $st . "\n";
    if (str_starts_with($st, '220')) {
        stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        fwrite($sock, "EHLO veloratrade.ir\r\n");
        smtp_read($sock);
    }
}

fwrite($sock, "AUTH LOGIN\r\n");
$r1 = smtp_read($sock);
echo "AUTH LOGIN → " . $r1 . "\n";

if (str_starts_with($r1, '334')) {
    fwrite($sock, base64_encode($user) . "\r\n");
    $r2 = smtp_read($sock);
    echo "USER → " . $r2 . "\n";

    if (str_starts_with($r2, '334')) {
        fwrite($sock, base64_encode($pass) . "\r\n");
        $r3 = smtp_read($sock);
        echo "PASS → " . $r3 . "\n";

        if (str_starts_with($r3, '235')) {
            echo "\n✅✅✅ احراز هویت موفق شد! حالا ارسال تست...\n\n";
            fwrite($sock, "MAIL FROM: <$from>\r\n");
            echo "MAIL FROM → " . smtp_read($sock) . "\n";
            fwrite($sock, "RCPT TO: <$to>\r\n");
            echo "RCPT TO → " . smtp_read($sock) . "\n";
            fwrite($sock, "DATA\r\n");
            echo "DATA → " . smtp_read($sock) . "\n";
            $msg = "From: VELORA TRADE <$from>\r\nTo: <$to>\r\nSubject: TEST VELORA\r\n\r\nاین یک ایمیل تست است\r\n.";
            fwrite($sock, $msg . "\r\n");
            echo "ارسال → " . smtp_read($sock) . "\n";
            fwrite($sock, "QUIT\r\n");
        } else {
            echo "\n❌ رمز یا کاربر اشتباه است!\n";
            echo "  → مطمئن شوید MAIL_USER=no-reply@veloratrade.ir و MAIL_PASS همان رمزی است که در cPanel برای این ایمیل ساختید\n";
        }
    } else {
        echo "\n❌ کاربر (MAIL_USER) پیدا نشد!\n";
        echo "  → آیا ایمیل no-reply@veloratrade.ir را در cPanel ساختید؟\n";
    }
} else {
    echo "\n❌ سرور AUTH LOGIN را پشتیبانی نمیکند: " . $r1 . "\n";
}
fclose($sock);

function smtp_read($sock): string {
    $resp = '';
    do {
        $line = fgets($sock, 515);
        if ($line === false) break;
        $resp = trim($line);
    } while (strlen($line) >= 4 && $line[3] === '-');
    return $resp;
}
