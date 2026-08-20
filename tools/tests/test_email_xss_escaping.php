<?php

declare(strict_types=1);

/**
 * TEST-21 — Email Template XSS Escaping.
 *
 * Every user-controlled value rendered into a transactional email (full name,
 * email address, user agent, IP, trade symbol, achievement title/description)
 * must be HTML-escaped before it reaches the mailbox. A stored
 * `<script>alert(1)</script>` in any of these fields must arrive as inert text.
 *
 * GREEN pin: all values are escaped today — this test guards the invariant.
 *
 * Capture architecture: real NotificationService/EmailTemplate/LocaleManager/
 * Mailer with the curl seam shimmed; payloads carry hostile markup.
 * No provider, no network, no DB, no secrets.
 */

namespace Velora\Core {

final class Config
{
    /** @var array<string,string> */
    public static array $values = [
        'MAIL_DRIVER' => 'resend',
        'RESEND_API_KEY' => 're_mock_never_real',
        'frontend_url' => 'https://test.veloratrade.invalid',
    ];

    public static function env(string $key, string $default = ''): string
    {
        return self::$values[$key] ?? $default;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$values[$key] ?? $default;
    }

    public static function privatePath(string $relativePath): string
    {
        return sys_get_temp_dir() . '/' . $relativePath;
    }
}

final class EmailNotificationRepository
{
    /** @var list<array<int,string>> */
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
    /** @var list<string> */
    public static array $bodies = [];

    public static function htmlOf(int $index): string
    {
        $payload = json_decode(self::$bodies[$index], true, 512, JSON_THROW_ON_ERROR);
        return (string) ($payload['html'] ?? '');
    }
}

function curl_init(?string $url = null): object
{
    return (object) ['mock' => true];
}

/** @param array<int,mixed> $options */
function curl_setopt_array(object $handle, array $options): bool
{
    CurlMock::$bodies[] = (string) ($options[CURLOPT_POSTFIELDS] ?? '');
    return true;
}

function curl_exec(object $handle): string|false
{
    return '{"id":"mock-xss-id"}';
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
        // exit(1) bypasses any swallowing handler; nothing to clean up here.
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$xssName = '<script>alert(1)</script>';
$xssUa = '<img src=x onerror=alert(1)>';
$base = 'https://test.veloratrade.invalid';

// 1-4: full-name driven emails
NotificationService::sendVerificationEmail('xss@example.test', $xssName, $base . '/verify-email#token=abc');
NotificationService::sendWelcomeEmail('xss@example.test', $xssName, $base . '/dashboard');
NotificationService::sendPasswordResetTokenEmail('xss@example.test', $xssName, $base . '/reset-password#token=abc');
NotificationService::sendPasswordChangedEmail('xss@example.test', $xssName);
// 5: device context (user agent)
NotificationService::sendNewDeviceDetectedEmail('xss@example.test', 'Safe Name', '203.0.113.9', $xssUa, '2026-08-21 10:00:00 UTC');
// 6: trade symbol
NotificationService::sendFirstTradeEmail('xss@example.test', 'Safe Name', $xssName, 'buy', $base . '/dashboard');
// 7: achievement title + description
NotificationService::sendAchievementUnlockedEmail('xss@example.test', 'Safe Name', $xssName, $xssName, $base . '/profile');

$labels = ['verification', 'welcome', 'reset-link', 'password-changed', 'new-device', 'first-trade', 'achievement'];
foreach ($labels as $i => $label) {
    $html = CurlMock::htmlOf($i);
    if ($label === 'new-device') {
        $expect(!str_contains($html, '<img src=x'), "[{$label}] hostile user agent must not survive as a live tag");
        $expect(str_contains($html, '&lt;img'), "[{$label}] hostile user agent must be present only as escaped text");
        continue;
    }
    $expect(!str_contains($html, $xssName), "[{$label}] hostile markup must not be rendered raw in the email HTML");
    $expect(str_contains($html, '&lt;script&gt;'), "[{$label}] hostile markup must be HTML-escaped (&lt;script&gt;)");
}

// achievement description is a second independent field in the same email
$achievementHtml = CurlMock::htmlOf(6);
$expect(
    substr_count($achievementHtml, '&lt;script&gt;') >= 2,
    '[achievement] both title and description must be escaped independently',
);
$expect(
    substr_count($achievementHtml, '<script>') === 0,
    '[achievement] no raw <script> occurrence anywhere in the rendered email',
);

echo "TEST-21 PASS ({$assertions} assertions)\n";
exit(0);

}
