<?php

declare(strict_types=1);

namespace Velora\Core;

/**
 * سرویس ارسال ایمیل — پیشرفته با پشتیبانی کامل از SMTP و کدگذاری ایمن RFC 2047
 *
 * سه حالت (تنظیم در .env با MAIL_DRIVER):
 *  1. MAIL_DRIVER=log   → لینک در فایل خصوصی logs/mail.log ثبت می‌شود (برای تست/عیب‌یابی)
 *  2. MAIL_DRIVER=mail  → mail() استاندارد PHP (پیش‌فرض)
 *  3. MAIL_DRIVER=smtp  → ارسال واقعی از طریق SMTP (Gmail, Zoho, Exim, ...)
 *
 * تنظیمات SMTP در .env:
 *   MAIL_HOST=mail.veloratrade.ir
 *   MAIL_PORT=587
 *   MAIL_USER=no-reply@veloratrade.ir
 *   MAIL_PASS=...
 *   MAIL_FROM=no-reply@veloratrade.ir
 *   MAIL_FROM_NAME=VELORA TRADE
 */
final class Mailer
{

    /** آخرین خطای ارسال (برای ثبت در email_notifications.error_message) */
    public static ?string $lastError = null;

    public static function send(string $to, string $subject, string $htmlBody): bool
    {
        self::$lastError = null;
        $driver = Config::env('MAIL_DRIVER', 'mail');

        switch ($driver) {
            case 'log':
                return self::logEmail($to, $subject, $htmlBody);
            case 'smtp':
                return self::sendSmtp($to, $subject, $htmlBody);
            case 'mail':
            default:
                return self::sendMail($to, $subject, $htmlBody);
        }
    }

    /** ارسال HTML با تصویرهای embed شده (CID)؛ مناسب Gmail و Outlook. */
    public static function sendWithInlineImages(string $to, string $subject, string $htmlBody, array $images): bool
    {
        self::$lastError = null;
        $driver = Config::env('MAIL_DRIVER', 'mail');
        if ($driver === 'smtp') {
            return self::sendSmtp($to, $subject, $htmlBody, $images);
        }
        return self::send($to, $subject, $htmlBody);
    }

    /**
     * کدگذاری استاندارد و ایمن RFC 2047 بدون خطوط اضافی (\r\n)
     * جلوگیری از Drop شدن ایمیل‌ها توسط فیلتر اسپم Exim/cPanel
     */
    private static function encodeHeader(string $text): string
    {
        if (!preg_match('/[^\x20-\x7E]/', $text)) {
            return $text;
        }
        return '=?UTF-8?B?' . base64_encode(trim($text)) . '?=';
    }

    /** ارسال با mail() استاندارد PHP به همراه تنظیم پارامتر Envelope Sender (-f) */
    private static function sendMail(string $to, string $subject, string $htmlBody): bool
    {
        $from = Config::env('MAIL_FROM', 'no-reply@veloratrade.ir');
        $fromName = Config::env('MAIL_FROM_NAME', 'VELORA TRADE');

        $encodedSubject = self::encodeHeader($subject);
        $encodedFromName = self::encodeHeader($fromName);

        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: ' . $encodedFromName . ' <' . $from . '>',
            'Reply-To: ' . $from,
        ];

        // پارامتر پنجم (-f) برای عبور از فیلترهای SPF و DKIM سرورهای مقصد ضروری است
        $ok = @mail($to, $encodedSubject, $htmlBody, implode("\r\n", $headers), "-f{$from}");
        if (!$ok) {
            self::$lastError = 'mail() returned false';
        }
        return $ok;
    }

    /** ارسال با SMTP — بدون وابستگی به کتابخانه خارجی با پشتیبانی از هدرهای UTF-8 استاندارد */
    private static function sendSmtp(string $to, string $subject, string $htmlBody, array $inlineImages = []): bool
    {
        $host = Config::env('MAIL_HOST', '');
        $port = (int) Config::env('MAIL_PORT', '587');
        $user = Config::env('MAIL_USER', '');
        $pass = Config::env('MAIL_PASS', '');
        $from = Config::env('MAIL_FROM', 'no-reply@veloratrade.ir');
        $fromName = Config::env('MAIL_FROM_NAME', 'VELORA TRADE');

        if ($host === '' || $user === '' || $pass === '') {
            self::logEmail($to, $subject, $htmlBody . "\n[SMTP not configured]");
            self::$lastError = 'SMTP not configured in .env';
            return false;
        }

        $socketHost = $port === 465 ? 'ssl://' . $host : $host;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
            ],
            'socket' => ['timeout' => 2.0]
        ]);
        $sock = @stream_socket_client(
            $socketHost . ':' . $port,
            $errno,
            $errstr,
            2.0,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!$sock) {
            self::logEmail($to, $subject, $htmlBody . "\n[SMTP connection failed: $errstr]");
            self::$lastError = 'SMTP connection failed: ' . $errstr;
            return false;
        }
        stream_set_timeout($sock, 2);

        if (!str_starts_with(self::smtpRead($sock), '220')) {
            fclose($sock);
            self::$lastError = 'SMTP banner invalid (expected 220)';
            return false;
        }
        if (!str_starts_with(self::smtpCommand($sock, 'EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost')), '250')) {
            fclose($sock);
            self::$lastError = 'SMTP EHLO rejected';
            return false;
        }

        // Port 465 is implicit TLS. Every other SMTP port must successfully
        // upgrade with STARTTLS before credentials are transmitted.
        if ($port !== 465) {
            if (!str_starts_with(self::smtpCommand($sock, 'STARTTLS'), '220') ||
                !stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) ||
                !str_starts_with(self::smtpCommand($sock, 'EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost')), '250')) {
                fclose($sock);
                self::$lastError = 'SMTP STARTTLS failed';
                return false;
            }
        }

        if (!str_starts_with(self::smtpCommand($sock, 'AUTH LOGIN'), '334') ||
            !str_starts_with(self::smtpCommand($sock, base64_encode($user)), '334') ||
            !str_starts_with(self::smtpCommand($sock, base64_encode($pass)), '235')) {
            fclose($sock);
            self::$lastError = 'SMTP AUTH failed (wrong user/pass)';
            return false;
        }

        if (!str_starts_with(self::smtpCommand($sock, "MAIL FROM: <$from>"), '250') ||
            !str_starts_with(self::smtpCommand($sock, "RCPT TO: <$to>"), '250') ||
            !str_starts_with(self::smtpCommand($sock, 'DATA'), '354')) {
            fclose($sock);
            self::$lastError = 'SMTP MAIL FROM/RCPT/DATA rejected';
            return false;
        }

        $encodedSubject = self::encodeHeader($subject);
        $encodedFromName = self::encodeHeader($fromName);

        $headers = "From: $encodedFromName <$from>\r\nTo: <$to>\r\nSubject: $encodedSubject\r\nMIME-Version: 1.0\r\n";
        $encodedHtml = self::smtpDataEncode($htmlBody);

        if ($inlineImages === []) {
            $message = $headers . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . $encodedHtml . "\r\n.\r\n";
        } else {
            $boundary = '=_Velora_' . bin2hex(random_bytes(12));
            $message = $headers . "Content-Type: multipart/related; boundary=\"$boundary\"\r\n\r\n";
            $message .= "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n$encodedHtml\r\n";
            foreach ($inlineImages as $cid => $path) {
                $binary = @file_get_contents($path);
                if ($binary === false) {
                    continue;
                }
                $mime = str_ends_with(strtolower((string) $path), '.jpg') || str_ends_with(strtolower((string) $path), '.jpeg') ? 'image/jpeg' : 'image/png';
                $name = basename((string) $path);
                $encoded = chunk_split(base64_encode($binary));
                $message .= "--$boundary\r\nContent-Type: $mime; name=\"$name\"\r\nContent-Transfer-Encoding: base64\r\nContent-ID: <$cid>\r\nContent-Disposition: inline; filename=\"$name\"\r\n\r\n$encoded\r\n";
            }
            $message .= "--$boundary--\r\n.\r\n";
        }
        fwrite($sock, $message);
        $sent = str_starts_with(self::smtpRead($sock), '250');
        self::smtpCommand($sock, 'QUIT');
        fclose($sock);
        if (!$sent) {
            self::$lastError = 'SMTP final send rejected (expected 250)';
        }
        return $sent;
    }

    /** MIME quoted-printable + dot-stuffing برای محدودیت خط SMTP. */
    private static function smtpDataEncode(string $html): string
    {
        $encoded = quoted_printable_encode($html);
        return (string) preg_replace('/(?m)^\./', '..', $encoded);
    }

    /** یک پاسخ SMTP را کامل می‌خواند، از جمله پاسخ‌های چندخطی. */
    private static function smtpRead($sock): string
    {
        $last = '';
        do {
            $line = fgets($sock, 515);
            if ($line === false) {
                break;
            }
            $last = trim($line);
        } while (strlen($line) >= 4 && $line[3] === '-');
        return $last;
    }

    private static function smtpCommand($sock, string $command): string
    {
        fwrite($sock, $command . "\r\n");
        return self::smtpRead($sock);
    }

    /** ثبت ایمیل در فایل لاگ (برای تست/عیب‌یابی) */
    private static function logEmail(string $to, string $subject, string $htmlBody): bool
    {
        $dir = Config::privatePath('logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        @chmod($dir, 0700);

        $link = null;
        if (preg_match('/href="([^"]+)"/', $htmlBody, $m)) {
            $link = $m[1];
            // Capability tokens now travel in URL fragments, but retain query
            // support for older templates. Never persist either form in logs.
            $link = preg_replace('/([?&#](?:token|code)=)[^&#\s]+/i', '$1[REDACTED]', $link);
        }

        $entry = sprintf(
            "[%s] TO: %s | SUBJECT: %s | LINK: %s\n",
            gmdate('c'),
            $to,
            $subject,
            $link ?? '[NONE]'
        );

        $path = $dir . '/mail.log';
        $handle = @fopen($path, 'ab');
        if ($handle === false) {
            return false;
        }
        @chmod($path, 0600);
        $written = false;
        if (flock($handle, LOCK_EX)) {
            $written = fwrite($handle, $entry) !== false;
            fflush($handle);
            flock($handle, LOCK_UN);
        }
        fclose($handle);
        return $written;
    }
}
