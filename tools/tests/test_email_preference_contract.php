<?php

declare(strict_types=1);

/**
 * TEST-15 (Audit BUG-A9) — Email Preference / Unsubscribe Contract.
 *
 * Users must be able to manage email preferences, and marketing-class emails
 * must carry a working unsubscribe mechanism (RFC 2369 List-Unsubscribe header
 * or an in-email manage/unsubscribe link).
 *
 * Contract:
 *   1. An authenticated API endpoint for email preferences must exist — RED pin.
 *   2. The Mailer must emit a List-Unsubscribe header — RED pin.
 *   3. Rendered transactional HTML must expose a manage/unsubscribe link — RED pin.
 *   4. Controls: EmailPreferenceRepository exists with canSend/setPreferences
 *      and covers welcome/trades/achievements categories — GREEN pins.
 *
 * Deterministic: static source contract + one rendered template via the curl
 * seam. No provider, no network, no DB, no secrets.
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

    public static function lastPayload(): array
    {
        return json_decode((string) end(self::$bodies), true, 512, JSON_THROW_ON_ERROR);
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
    return '{"id":"mock-pref-contract"}';
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

$repoRoot = dirname(__DIR__, 2);
$assertions = 0;
$failures = [];
$check = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
    $assertions++;
    if (!$condition) {
        $failures[] = $message;
    }
};

// --- Pin 1: a preferences API endpoint must exist ---------------------------
$router = (string) file_get_contents($repoRoot . '/api/index.php');
$check(
    (bool) preg_match("/preferences/i", $router),
    'an authenticated /auth/email-preferences (or equivalent) endpoint must exist for preference management (BUG-A9)',
);

// --- Pin 2: outbound mail must carry RFC 2369 List-Unsubscribe ---------------
$mailer = (string) file_get_contents($repoRoot . '/api/src/Core/Mailer.php');
$check(
    str_contains($mailer, 'List-Unsubscribe'),
    'Mailer must emit a List-Unsubscribe header so mailbox providers can offer one-click unsubscribe (BUG-A9)',
);

// --- Pin 3: rendered emails must expose a manage/unsubscribe link ------------
NotificationService::sendWelcomeEmail(
    'welcome.user@example.test',
    'Welcome User',
    'https://test.veloratrade.invalid/dashboard',
    7,
);
$html = (string) (CurlMock::lastPayload()['html'] ?? '');
$check(
    (bool) preg_match('/unsubscribe|email-preferences|manage[^\s<]*preferences/i', $html),
    'transactional emails must include a manage-preferences/unsubscribe link in the footer (BUG-A9)',
);

// --- Controls: the preference repository seam exists and is complete ---------
$prefSource = (string) file_get_contents($repoRoot . '/api/src/Core/EmailPreferenceRepository.php');
$check(
    str_contains($prefSource, 'function canSend'),
    'control: EmailPreferenceRepository::canSend must exist',
);
$check(
    str_contains($prefSource, 'function setPreferences'),
    'control: EmailPreferenceRepository::setPreferences must exist',
);
$check(
    str_contains($prefSource, "'welcome'") && str_contains($prefSource, "'trades'") && str_contains($prefSource, "'achievements'"),
    'control: preference categories welcome/trades/achievements must be supported',
);

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    fwrite(STDERR, 'TEST-15 FAILED: ' . count($failures) . "/{$assertions} assertions failed\n");
    exit(1);
}
echo "TEST-15 PASS ({$assertions} assertions)\n";
exit(0);

}
