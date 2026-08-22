<?php

declare(strict_types=1);

/**
 * TEST-09 (Audit BUG-A6) — Email localization regression (PR-05: all flows).
 *
 * Every transactional email must honor the requested locale:
 *   locale=fa  -> Persian copy, dir="rtl", Persian subject + footer
 *   locale=en  -> English copy, dir="ltr", English subject + footer, no Persian
 *
 * PR-05 extends the original verification-only pin to all seven senders
 * (verification, welcome, password-reset, password-changed, new-device,
 * first-trade, achievement), all of which now render from the existing
 * fa/en catalogs.
 *
 * Capture architecture: real NotificationService/EmailTemplate/LocaleManager/
 * Mailer with the HTTP transport shimmed; the outbound payload is asserted.
 * No provider, no network, no DB, no secrets. Production code untouched.
 */

namespace Velora\Core {

final class Config
{
    /** @var array<string,string> */
    public static array $values = [
        'MAIL_DRIVER' => 'resend',
        'RESEND_API_KEY' => 're_mock_never_real',
    ];

    public static function env(string $key, string $default = ''): string
    {
        return self::$values[$key] ?? $default;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $key === 'frontend_url' ? 'https://test.veloratrade.invalid' : $default;
    }

    public static function privatePath(string $relativePath): string
    {
        return sys_get_temp_dir() . '/' . $relativePath;
    }
}

final class EmailNotificationRepository
{
    public static array $rows = [];

    public function log(?int $userId, string $recipientEmail, string $eventType, string $subject, string $status = 'sent', ?string $errorMessage = null, ?array $payload = null): void
    {
        self::$rows[] = [$eventType, $recipientEmail, $subject, $status];
    }
}

final class EmailPreferenceRepository
{
    public function canSend(int $userId, string $category = 'security'): bool
    {
        return true;
    }
}

final class CurlMock
{
    /** @var array<int,string> */
    public static array $bodies = [];

    public static function takeLastPayload(): array
    {
        $body = end(self::$bodies);
        return json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
    }
}

function curl_init(?string $url = null): object
{
    return (object) ['mock' => true];
}

/** @param array<int,mixed> $options */
function curl_setopt_array(object $handle, array $options): bool
{
    CurlMock::$bodies[] = (string) $options[CURLOPT_POSTFIELDS];
    return true;
}

function curl_exec(object $handle): string|false
{
    return '{"id":"mock-locale-id"}';
}

function curl_error(object $handle): string
{
    return '';
}

function curl_getinfo(object $handle, ?int $option = null): mixed
{
    return $option === CURLINFO_RESPONSE_CODE ? 200 : [];
}

function curl_close(object $handle): void
{
}

}


namespace {

require dirname(__DIR__, 2) . '/api/src/Core/Locale/LocaleManager.php';
require dirname(__DIR__, 2) . '/api/src/Core/EmailTemplate.php';
require dirname(__DIR__, 2) . '/api/src/Core/Mailer.php';
require dirname(__DIR__, 2) . '/api/src/Core/NotificationService.php';

    use Velora\Core\CurlMock;
    use Velora\Core\NotificationService;

    $assertions = 0;
    $expect = static function (bool $condition, string $message) use (&$assertions): void {
        $assertions++;
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    };

    $hasPersian = static fn (string $text): bool => (bool) preg_match('/[\x{0600}-\x{06FF}]/u', $text);

    $base = 'https://test.veloratrade.invalid';

    // One representative invocation per transactional template (PR-05: all flows).
    /** @var array<string, callable(string, ?string): bool> $senders */
    $senders = [
        'verification' => static fn (string $name, ?string $locale): bool =>
            NotificationService::sendVerificationEmail('u@example.test', $name, $base . '/verify-email#token=deadbeef', null, $locale),
        'welcome' => static fn (string $name, ?string $locale): bool =>
            NotificationService::sendWelcomeEmail('u@example.test', $name, $base . '/dashboard', null, $locale),
        'password-reset' => static fn (string $name, ?string $locale): bool =>
            NotificationService::sendPasswordResetTokenEmail('u@example.test', $name, $base . '/reset-password#token=deadbeef', null, $locale),
        'password-changed' => static fn (string $name, ?string $locale): bool =>
            NotificationService::sendPasswordChangedEmail('u@example.test', $name, null, $locale),
        'new-device' => static fn (string $name, ?string $locale): bool =>
            NotificationService::sendNewDeviceDetectedEmail('u@example.test', $name, '203.0.113.9', 'TestAgent/1.0', '2026-08-21 10:00:00 UTC', null, $locale),
        'first-trade' => static fn (string $name, ?string $locale): bool =>
            NotificationService::sendFirstTradeEmail('u@example.test', $name, 'XAUUSD', 'buy', $base . '/dashboard', null, $locale),
        'achievement' => static fn (string $name, ?string $locale): bool =>
            NotificationService::sendAchievementUnlockedEmail('u@example.test', $name, 'achievements.emailVerified.title', 'achievements.emailVerified.description', $base . '/profile', null, $locale),
    ];

    $faName = 'کاربر نمونه';
    $enName = 'Sample User';

    foreach ($senders as $name => $send) {
        // ---- locale=fa ---------------------------------------------------
        $send($faName, 'fa');
        $fa = CurlMock::takeLastPayload();
        $faHtml = (string) ($fa['html'] ?? '');
        $faSubject = (string) ($fa['subject'] ?? '');
        $expect(str_contains($faHtml, 'dir="rtl"'), "[fa/{$name}] email must render right-to-left");
        $expect($hasPersian($faSubject), "[fa/{$name}] subject must be Persian");
        $expect($hasPersian($faHtml), "[fa/{$name}] body must contain Persian copy");
        $expect(str_contains($faHtml, 'قوانین'), "[fa/{$name}] footer must contain the Persian terms label");
        $expect(str_contains($faHtml, 'حریم خصوصی'), "[fa/{$name}] footer must contain the Persian privacy label");

        // ---- locale=en ---------------------------------------------------
        $send($enName, 'en');
        $en = CurlMock::takeLastPayload();
        $enHtml = (string) ($en['html'] ?? '');
        $enSubject = (string) ($en['subject'] ?? '');
        $expect(str_contains($enHtml, 'dir="ltr"'), "[en/{$name}] email must render left-to-right");
        $expect(!$hasPersian($enSubject), "[en/{$name}] subject must not be Persian");
        $expect(!$hasPersian($enHtml), "[en/{$name}] body must not contain Persian copy");
        $expect(str_contains($enHtml, 'Terms'), "[en/{$name}] footer must contain the English terms label");
        $expect(str_contains($enHtml, 'Privacy'), "[en/{$name}] footer must contain the English privacy label");
    }

    echo "TEST-09 email localization fa/en (all flows): PASS ({$assertions} assertions)\n";
}
