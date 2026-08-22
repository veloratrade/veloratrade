<?php

declare(strict_types=1);

/**
 * TEST-18 (Audit BUG-A10) — Environment-Aware Email URLs.
 *
 * Every absolute URL inside a transactional email must follow the configured
 * FRONTEND_URL of the CURRENT environment:
 *   - never localhost / 127.0.0.1,
 *   - never a production host baked into a staging render (cross-env leak).
 *
 * The logo URL already complies (env-aware); the footer links are hardcoded to
 * https://veloratrade.ir/... — pinned RED until fixed.
 *
 * Capture architecture: real NotificationService/EmailTemplate/LocaleManager/
 * Mailer with the curl seam shimmed and FRONTEND_URL pointed at a staging host.
 * No provider, no network, no DB, no secrets.
 */

namespace Velora\Core {

final class Config
{
    /** @var array<string,string> */
    public static array $values = [
        'MAIL_DRIVER' => 'resend',
        'RESEND_API_KEY' => 're_mock_never_real',
        // Simulated STAGING environment:
        'frontend_url' => 'https://staging.veloratrade.invalid',
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

    public static function payloads(): array
    {
        return array_map(
            static fn (string $body): array => json_decode($body, true, 512, JSON_THROW_ON_ERROR),
            self::$bodies,
        );
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
    return '{"id":"mock-env-id"}';
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
$failures = [];
$check = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
    $assertions++;
    if (!$condition) {
        $failures[] = $message;
    }
};

NotificationService::sendVerificationEmail(
    'env.user@example.test',
    'Env User',
    'https://staging.veloratrade.invalid/verify-email#token=abc',
    7,
);
NotificationService::sendWelcomeEmail(
    'env.user@example.test',
    'Env User',
    'https://staging.veloratrade.invalid/dashboard',
    7,
);

$envHost = 'staging.veloratrade.invalid';
foreach (CurlMock::payloads() as $payload) {
    $html = (string) ($payload['html'] ?? '');
    $subject = (string) ($payload['subject'] ?? '?');

    // Only real fetch targets count: href/src attribute URLs. XML/SVG namespace
    // identifiers (xmlns="http://www.w3.org/…") are inert strings, not links.
    preg_match_all('~(?:href|src)="https?://([^/"\'<>\s)]+)~i', $html, $m);
    $hosts = array_values(array_unique($m[1]));

    $check(
        !preg_match('/localhost|127\.0\.0\.1/i', $html),
        "[{$subject}] no localhost/127.0.0.1 URL may appear in email HTML",
    );

    $offEnv = array_values(array_filter($hosts, static fn (string $h): bool => $h !== $envHost));
    $check(
        $offEnv === [],
        "[{$subject}] every absolute URL must target {$envHost} in this environment; off-environment host(s): "
            . implode(', ', $offEnv) . ' (BUG-A10: hardcoded production links)',
    );

    $check(
        str_contains($html, 'src="cid:velora-logo"'),
        "[{$subject}] logo must be embedded as cid:velora-logo (inline, not an environment-specific remote URL)",
    );
}

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    fwrite(STDERR, 'TEST-18 FAILED: ' . count($failures) . "/{$assertions} assertions failed\n");
    exit(1);
}
echo "TEST-18 PASS ({$assertions} assertions)\n";
exit(0);

}
