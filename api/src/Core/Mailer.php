<?php

declare(strict_types=1);

namespace Velora\Core;

/**
 * سرویس ارسال ایمیل — منطبق بر استانداردهای RFC 5322, RFC 2046, RFC 2047
 * با پشتیبانی از چندبخشی خودکار (multipart/alternative)، هدرهای الزامی، و SMTP ایمن.
 *
 * چهار حالت (تنظیم در .env با MAIL_DRIVER):
 *  1. MAIL_DRIVER=log    → لینک در فایل خصوصی logs/mail.log ثبت می‌شود (برای تست/عیب‌یابی)
 *  2. MAIL_DRIVER=mail   → mail() استاندارد PHP (با multipart/alternative)
 *  3. MAIL_DRIVER=smtp   → ارسال واقعی از طریق SMTP با TLS و احراز هویت
 *  4. MAIL_DRIVER=resend → ارسال transactional با Resend HTTPS API و RESEND_API_KEY
 *
 * تنظیمات Resend در محیط خصوصی:
 *   MAIL_DRIVER=resend
 *   RESEND_API_KEY=...
 * فرستندهٔ Resend در کد به VELORA TRADE <no-reply@veloratrade.ir> محدود است.
 *
 * تنظیمات SMTP قدیمی در .env:
 *   MAIL_HOST=mail.veloratrade.ir
 *   MAIL_PORT=587
 *   MAIL_USER=no-reply@veloratrade.ir
 *   MAIL_PASS=...
 *   MAIL_FROM=no-reply@veloratrade.ir
 *   MAIL_FROM_NAME=VELORA TRADE
 */
final class Mailer
{
    private const RESEND_ENDPOINT = 'https://api.resend.com/emails';
    private const RESEND_FROM = 'VELORA TRADE <no-reply@veloratrade.ir>';

    /** آخرین خطای ارسال (برای ثبت در email_notifications.error_message) */
    public static ?string $lastError = null;

    /** شناسهٔ غیرمحرمانهٔ پیام که provider پس از پذیرش برمی‌گرداند. */
    public static ?string $lastMessageId = null;

    public static function send(string $to, string $subject, string $htmlBody, ?string $plainBody = null): bool
    {
        self::$lastError = null;
        self::$lastMessageId = null;
        $driver = strtolower(trim(Config::env('MAIL_DRIVER', 'mail')));

        switch ($driver) {
            case 'log':
                return self::logEmail($to, $subject, $htmlBody);
            case 'resend':
                return self::sendResend($to, $subject, $htmlBody, [], $plainBody);
            case 'smtp':
                return self::sendSmtp($to, $subject, $htmlBody, [], $plainBody);
            case 'mail':
            default:
                return self::sendMail($to, $subject, $htmlBody, $plainBody);
        }
    }

    /** ارسال HTML با تصویرهای embed شده (CID)؛ مناسب Gmail و Outlook. */
    public static function sendWithInlineImages(
        string $to,
        string $subject,
        string $htmlBody,
        array $images,
        ?string $plainBody = null
    ): bool {
        self::$lastError = null;
        self::$lastMessageId = null;
        $driver = strtolower(trim(Config::env('MAIL_DRIVER', 'mail')));
        if ($driver === 'resend') {
            return self::sendResend($to, $subject, $htmlBody, $images, $plainBody);
        }
        if ($driver === 'smtp') {
            return self::sendSmtp($to, $subject, $htmlBody, $images, $plainBody);
        }
        return self::send($to, $subject, $htmlBody, $plainBody);
    }

    /**
     * استخراج خودکار نسخه متنی خام (Plain Text) از HTML جهت جلوگیری از جریمه اسپم (MIME_HTML_ONLY)
     */
    public static function htmlToPlain(string $html): string
    {
        // حذف بلوک‌های غیرقابل نمایش (head, style, script)
        $text = preg_replace('/<head\b[^>]*>.*?<\/head>/is', '', $html);
        $text = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $text ?? '');
        $text = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $text ?? '');

        // تبدیل لینک‌ها به فرمت: متن (آدرس)
        $text = preg_replace_callback('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', function ($matches) {
            $url = trim($matches[1]);
            $label = trim(strip_tags($matches[2]));
            if ($label === '' || $label === $url) {
                return $url;
            }
            return $label . ' (' . $url . ')';
        }, $text ?? '');

        // تبدیل تگ‌های ساختاری به خطوط جدید
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text ?? '');
        $text = preg_replace('/<\/(p|div|tr|h[1-6]|table)>/i', "\n\n", $text ?? '');
        $text = preg_replace('/<td[^>]*>/i', ' ', $text ?? '');

        // حذف کلیه تگ‌های HTML باقیمانده
        $text = strip_tags($text ?? '');

        // تبدیل Entityهای HTML به کاراکترهای واقعی
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // مرتب‌سازی فاصله‌ها و خطوط خالی متوالی
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace("/\n\s*\n\s*\n+/", "\n\n", $text ?? '');

        return trim($text ?? '');
    }

    /**
     * کدگذاری استاندارد هدرهای RFC 2047 با رعایت سقف طول ۷۵ کاراکتر برای هر قطعه
     */
    private static function encodeHeader(string $text): string
    {
        $trimmed = trim($text);
        if (!preg_match('/[^\x20-\x7E]/', $trimmed)) {
            return $trimmed;
        }
        if (function_exists('mb_encode_mimeheader')) {
            return mb_encode_mimeheader($trimmed, 'UTF-8', 'B', "\r\n");
        }
        return '=?UTF-8?B?' . base64_encode($trimmed) . '?=';
    }

    /**
     * تولید شناسه یکتای جهانی پیام (RFC 5322 Message-ID) جهت عبور از فیلترهای MISSING_MID
     */
    private static function generateMessageId(string $from): string
    {
        $parts = explode('@', $from, 2);
        $domain = isset($parts[1]) && trim($parts[1]) !== '' ? trim($parts[1]) : 'veloratrade.ir';
        try {
            $entropy = bin2hex(random_bytes(16));
        } catch (\Throwable) {
            $entropy = md5(uniqid((string) mt_rand(), true));
        }
        return '<' . $entropy . '.' . time() . '@' . $domain . '>';
    }

    /**
     * تولید تاریخ استاندارد ارسال پیام (RFC 5322 Date) جهت عبور از فیلترهای MISSING_DATE
     */
    private static function generateDate(): string
    {
        return gmdate('D, d M Y H:i:s +0000');
    }

    /**
     * یکسان‌سازی انتهای خطوط به CRLF استاندارد اینترنت (\r\n)
     */
    private static function normalizeCrLf(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        return str_replace("\n", "\r\n", $text);
    }

    /** ارسال از طریق Resend HTTPS API؛ تنها Secret مجاز RESEND_API_KEY است. */
    private static function sendResend(
        string $to,
        string $subject,
        string $htmlBody,
        array $inlineImages = [],
        ?string $plainBody = null
    ): bool {
        $apiKey = trim(Config::env('RESEND_API_KEY', ''));
        if ($apiKey === '') {
            self::$lastError = 'Resend API key is not configured';
            return false;
        }
        if (!function_exists(__NAMESPACE__ . '\\curl_init') && !function_exists('curl_init')) {
            self::$lastError = 'Resend transport requires PHP cURL';
            return false;
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            self::$lastError = 'Resend recipient email is invalid';
            return false;
        }

        $payload = [
            'from' => self::RESEND_FROM,
            'to' => [$to],
            'subject' => $subject,
            'html' => $htmlBody,
            'text' => $plainBody ?? self::htmlToPlain($htmlBody),
            'reply_to' => 'no-reply@veloratrade.ir',
        ];

        if ($inlineImages !== []) {
            $attachments = [];
            foreach ($inlineImages as $cid => $path) {
                $content = @file_get_contents((string) $path);
                if ($content === false) {
                    continue;
                }
                $attachments[] = [
                    'filename' => basename((string) $path),
                    'content' => base64_encode($content),
                    'content_id' => mb_substr((string) $cid, 0, 127),
                ];
            }
            if ($attachments !== []) {
                $payload['attachments'] = $attachments;
            }
        }

        try {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            self::$lastError = 'Resend request JSON encoding failed';
            return false;
        }

        $curl = curl_init(self::RESEND_ENDPOINT);
        if ($curl === false) {
            self::$lastError = 'Resend HTTP client initialization failed';
            return false;
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: VELORA-Mailer/3.0',
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);

        $response = curl_exec($curl);
        $curlError = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($response === false) {
            self::$lastError = 'Resend HTTP request failed: ' . self::sanitizeProviderError($curlError, $apiKey);
            return false;
        }

        $decoded = json_decode((string) $response, true);
        if ($status < 200 || $status >= 300) {
            $providerMessage = is_array($decoded)
                ? (string) ($decoded['message'] ?? $decoded['name'] ?? 'request rejected')
                : 'request rejected';
            self::$lastError = 'Resend API HTTP ' . $status . ': ' . self::sanitizeProviderError($providerMessage, $apiKey);
            return false;
        }

        $messageId = is_array($decoded) ? trim((string) ($decoded['id'] ?? '')) : '';
        if ($messageId === '') {
            self::$lastError = 'Resend API response did not include a message id';
            return false;
        }

        self::$lastMessageId = mb_substr($messageId, 0, 255);
        return true;
    }

    /** Provider errors may be persisted; aggressively remove credential-shaped values first. */
    private static function sanitizeProviderError(string $message, string $apiKey): string
    {
        $safe = trim(preg_replace('/[\r\n\t]+/', ' ', $message) ?? '');
        if ($apiKey !== '') {
            $safe = str_replace($apiKey, '[REDACTED]', $safe);
        }
        $safe = preg_replace('/\bre_[A-Za-z0-9._-]+\b/', '[REDACTED]', $safe) ?? '';
        $safe = preg_replace('/Bearer\s+[^\s]+/i', 'Bearer [REDACTED]', $safe) ?? '';
        return mb_substr($safe !== '' ? $safe : 'unknown provider error', 0, 300);
    }

    /** ارسال با mail() استاندارد PHP به همراه ساختار چندبخشی (multipart/alternative) و هدرهای استاندارد */
    private static function sendMail(string $to, string $subject, string $htmlBody, ?string $plainBody = null): bool
    {
        $from = Config::env('MAIL_FROM', 'no-reply@veloratrade.ir');
        $fromName = Config::env('MAIL_FROM_NAME', 'VELORA TRADE');

        $encodedSubject = self::encodeHeader($subject);
        $encodedFromName = self::encodeHeader($fromName);
        $messageId = self::generateMessageId($from);
        $date = self::generateDate();
        $plainText = $plainBody ?? self::htmlToPlain($htmlBody);

        $altBoundary = '=_Velora_Alt_' . bin2hex(random_bytes(12));

        $headers = [
            'Date: ' . $date,
            'From: ' . $encodedFromName . ' <' . $from . '>',
            'Reply-To: <' . $from . '>',
            'Message-ID: ' . $messageId,
            'MIME-Version: 1.0',
            'X-Mailer: VELORA Mailer/2.0',
            'Auto-Submitted: auto-generated',
            'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"',
        ];

        $encodedPlain = self::normalizeCrLf(quoted_printable_encode(self::normalizeCrLf($plainText)));
        $encodedHtml = self::normalizeCrLf(quoted_printable_encode(self::normalizeCrLf($htmlBody)));

        $body = "--$altBoundary\r\n"
              . "Content-Type: text/plain; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
              . $encodedPlain . "\r\n\r\n"
              . "--$altBoundary\r\n"
              . "Content-Type: text/html; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
              . $encodedHtml . "\r\n\r\n"
              . "--$altBoundary--";

        // پارامتر پنجم (-f) برای عبور از فیلترهای SPF سرورهای مقصد ضروری است
        $ok = @mail($to, $encodedSubject, $body, implode("\r\n", $headers), "-f{$from}");
        if (!$ok) {
            self::$lastError = 'mail() returned false';
        }
        return $ok;
    }

    /** ارسال با SMTP — کاملاً سازگار با استانداردهای RFC، STARTTLS، هدرهای استاندارد و multipart/alternative */
    private static function sendSmtp(
        string $to,
        string $subject,
        string $htmlBody,
        array $inlineImages = [],
        ?string $plainBody = null
    ): bool {
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
            'socket' => ['timeout' => 5.0]
        ]);
        $sock = @stream_socket_client(
            $socketHost . ':' . $port,
            $errno,
            $errstr,
            5.0,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!$sock) {
            self::logEmail($to, $subject, $htmlBody . "\n[SMTP connection failed: $errstr]");
            self::$lastError = 'SMTP connection failed: ' . $errstr;
            return false;
        }
        stream_set_timeout($sock, 5);

        if (!str_starts_with(self::smtpRead($sock), '220')) {
            fclose($sock);
            self::$lastError = 'SMTP banner invalid (expected 220)';
            return false;
        }

        $ehloHost = !empty($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : 'localhost';
        if (!str_starts_with(self::smtpCommand($sock, 'EHLO ' . $ehloHost), '250')) {
            fclose($sock);
            self::$lastError = 'SMTP EHLO rejected';
            return false;
        }

        // ارتقای امنیتی STARTTLS برای تمامی پورت‌های غیر 465
        if ($port !== 465) {
            if (!str_starts_with(self::smtpCommand($sock, 'STARTTLS'), '220') ||
                !stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) ||
                !str_starts_with(self::smtpCommand($sock, 'EHLO ' . $ehloHost), '250')) {
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
        $messageId = self::generateMessageId($from);
        $date = self::generateDate();
        $plainText = $plainBody ?? self::htmlToPlain($htmlBody);

        $headers = "Date: $date\r\n"
                 . "From: $encodedFromName <$from>\r\n"
                 . "To: <$to>\r\n"
                 . "Subject: $encodedSubject\r\n"
                 . "Message-ID: $messageId\r\n"
                 . "Reply-To: <$from>\r\n"
                 . "MIME-Version: 1.0\r\n"
                 . "X-Mailer: VELORA Mailer/2.0\r\n"
                 . "Auto-Submitted: auto-generated\r\n";

        $encodedHtml = self::smtpDataEncode($htmlBody);
        $encodedPlain = self::smtpDataEncode($plainText);

        $altBoundary = '=_Velora_Alt_' . bin2hex(random_bytes(12));

        if ($inlineImages === []) {
            $message = $headers . "Content-Type: multipart/alternative; boundary=\"$altBoundary\"\r\n\r\n";
            $message .= "--$altBoundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n$encodedPlain\r\n\r\n";
            $message .= "--$altBoundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n$encodedHtml\r\n\r\n";
            $message .= "--$altBoundary--\r\n.\r\n";
        } else {
            $relBoundary = '=_Velora_Rel_' . bin2hex(random_bytes(12));
            $message = $headers . "Content-Type: multipart/related; boundary=\"$relBoundary\"\r\n\r\n";
            $message .= "--$relBoundary\r\nContent-Type: multipart/alternative; boundary=\"$altBoundary\"\r\n\r\n";
            $message .= "--$altBoundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n$encodedPlain\r\n\r\n";
            $message .= "--$altBoundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n$encodedHtml\r\n\r\n";
            $message .= "--$altBoundary--\r\n\r\n";

            foreach ($inlineImages as $cid => $path) {
                $binary = @file_get_contents($path);
                if ($binary === false) {
                    continue;
                }
                $mime = str_ends_with(strtolower((string) $path), '.jpg') || str_ends_with(strtolower((string) $path), '.jpeg') ? 'image/jpeg' : 'image/png';
                $name = basename((string) $path);
                $encoded = chunk_split(base64_encode($binary));
                $message .= "--$relBoundary\r\nContent-Type: $mime; name=\"$name\"\r\nContent-Transfer-Encoding: base64\r\nContent-ID: <$cid>\r\nContent-Disposition: inline; filename=\"$name\"\r\n\r\n$encoded\r\n";
            }
            $message .= "--$relBoundary--\r\n.\r\n";
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

    /** MIME quoted-printable + dot-stuffing برای محدودیت خط SMTP */
    private static function smtpDataEncode(string $text): string
    {
        $normalized = self::normalizeCrLf($text);
        $encoded = quoted_printable_encode($normalized);
        $normalizedEncoded = self::normalizeCrLf($encoded);
        return (string) preg_replace('/(?m)^\./', '..', $normalizedEncoded);
    }

    /** یک پاسخ SMTP را کامل می‌خواند، از جمله پاسخ‌های چندخطی */
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
