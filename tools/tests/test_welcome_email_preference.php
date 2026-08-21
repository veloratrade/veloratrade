<?php

declare(strict_types=1);

/**
 * TEST-14 (Audit BUG-A8) — Welcome Email Preference Gate.
 *
 * Every non-security transactional email must consult EmailPreferenceRepository
 * before dispatch. New-device / first-trade / achievement emails already do;
 * the WELCOME email does not — it fires even for users who opted out of
 * welcome_email.
 *
 * Contract:
 *   1. sendWelcomeEmail() must ask canSend($userId, 'welcome') — RED pin.
 *   2. With welcome_email disabled, no HTTP dispatch may happen — RED pin.
 *   3. Control: achievements opt-out IS honored today — GREEN pin.
 *   4. Control: security email always dispatches — GREEN pin.
 *
 * Capture architecture: real NotificationService/EmailTemplate/LocaleManager/
 * Mailer with the curl seam shimmed. No provider, no network, no DB, no secrets.
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
    /** @var list<string> */
    public static array $asked = [];
    /** @var list<string> categories the test user has DISABLED */
    public static array $deny = ['welcome', 'achievements'];

    public function canSend(int $userId, string $category = 'security'): bool
    {
        self::$asked[] = $category;
        return !in_array($category, self::$deny, true);
    }
}

final class CurlMock
{
    /** @var list<string> */
    public static array $bodies = [];
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
    return '{"id":"mock-pref-id"}';
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
use Velora\Core\EmailPreferenceRepository;
use Velora\Core\NotificationService;

$assertions = 0;
$failures = [];
$check = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
    $assertions++;
    if (!$condition) {
        $failures[] = $message;
    }
};

// --- Pin 1+2: welcome email must be gated on the 'welcome' preference ------
$before = count(CurlMock::$bodies);
NotificationService::sendWelcomeEmail(
    'welcome.user@example.test',
    'Welcome User',
    'https://test.veloratrade.invalid/dashboard',
    7,
);
$check(
    in_array('welcome', EmailPreferenceRepository::$asked, true),
    'sendWelcomeEmail must consult canSend($userId, \'welcome\') before dispatch (BUG-A8)',
);
$check(
    count(CurlMock::$bodies) === $before,
    'welcome email must NOT be dispatched when the user disabled welcome_email (BUG-A8)',
);

// --- Control 1: achievement opt-out IS honored today ------------------------
$before = count(CurlMock::$bodies);
NotificationService::sendAchievementUnlockedEmail(
    'ach.user@example.test',
    'Ach User',
    'achievements.emailVerified.title',
    'achievements.emailVerified.description',
    'https://test.veloratrade.invalid/profile',
    7,
);
$check(
    count(CurlMock::$bodies) === $before && in_array('achievements', EmailPreferenceRepository::$asked, true),
    'control: achievement email must stay gated on the achievements preference',
);

// --- Control 2: security email always dispatches ---------------------------
$before = count(CurlMock::$bodies);
NotificationService::sendVerificationEmail(
    'sec.user@example.test',
    'Sec User',
    'https://test.veloratrade.invalid/verify-email#token=abc',
    7,
);
$check(
    count(CurlMock::$bodies) === $before + 1,
    'control: security emails (verification) must always dispatch',
);

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    fwrite(STDERR, 'TEST-14 FAILED: ' . count($failures) . "/{$assertions} assertions failed\n");
    exit(1);
}
echo "TEST-14 PASS ({$assertions} assertions)\n";
exit(0);

}
