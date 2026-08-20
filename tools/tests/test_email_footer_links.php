<?php

declare(strict_types=1);

/**
 * TEST-07 (Audit BUG-A4) — Email footer links must be complete AND point at
 * pages that are genuinely public for a logged-out reader.
 *
 * Two deterministic layers, no network:
 *   A) Render layer (fa + en): extract footer links from the captured outbound
 *      payload for transactional templates; assert terms/privacy/support links
 *      exist, a support@ mailto exists, and no footer href targets the login page.
 *   B) Router contract layer: support/index.html must NOT be listed in
 *      locale-router.php::$protectedRoutes — that listing is exactly what causes
 *      the live 302-to-login observed in the audit.
 *
 * No provider, no database, no secrets. Production code untouched.
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
    return '{"id":"mock-footer-id"}';
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

    /** One representative invocation per transactional template. */
    $senders = [
        'verification' => static function (?string $locale): void {
            NotificationService::sendVerificationEmail('u@example.test', 'User', 'https://test.veloratrade.invalid/verify-email#token=x', null, $locale);
        },
        'welcome' => static function (?string $locale): void {
            NotificationService::sendWelcomeEmail('u@example.test', 'User', 'https://test.veloratrade.invalid/dashboard', null, $locale);
        },
        'password-reset' => static function (?string $locale): void {
            NotificationService::sendPasswordResetTokenEmail('u@example.test', 'User', 'https://test.veloratrade.invalid/reset-password#token=x', null, $locale);
        },
        'password-changed' => static function (?string $locale): void {
            NotificationService::sendPasswordChangedEmail('u@example.test', 'User', null, $locale);
        },
        'security' => static function (?string $locale): void {
            NotificationService::sendNewDeviceDetectedEmail('u@example.test', 'User', '10.0.0.1', 'MockAgent/1.0', gmdate('Y-m-d H:i:s') . ' UTC', null, $locale);
        },
        'first-trade' => static function (?string $locale): void {
            NotificationService::sendFirstTradeEmail('u@example.test', 'User', 'XAUUSD', 'buy', 'https://test.veloratrade.invalid/dashboard', null, $locale);
        },
        'achievement' => static function (?string $locale): void {
            NotificationService::sendAchievementUnlockedEmail('u@example.test', 'User', 'achievements.emailVerified.title', 'achievements.emailVerified.description', 'https://test.veloratrade.invalid/profile', null, $locale);
        },
    ];

    // ---- Layer A: footer links present and never targeting login ----------
    // Post-A10 contract: absolute footer URLs must derive from the CURRENT
    // environment frontend_url (the shimmed value below), never hardcoded.
    $expectedBase = 'https://test.veloratrade.invalid';
    foreach (['fa', 'en'] as $locale) {
        foreach ($senders as $name => $send) {
            $send($locale);
            $payload = CurlMock::takeLastPayload();
            $html = (string) ($payload['html'] ?? '');

            preg_match_all('/href="([^"]+)"/', $html, $m);
            $hrefs = $m[1];
            $expect(in_array($expectedBase . '/terms', $hrefs, true), "[{$locale}/{$name}] footer must link to /terms on the current environment host");
            $expect(in_array($expectedBase . '/privacy', $hrefs, true), "[{$locale}/{$name}] footer must link to /privacy on the current environment host");
            $expect(in_array($expectedBase . '/support', $hrefs, true), "[{$locale}/{$name}] footer must link to /support on the current environment host");
            $expect(array_filter($hrefs, static fn ($h) => str_starts_with($h, 'mailto:support@veloratrade.ir')) !== [], "[{$locale}/{$name}] footer must include the support mailto");
            $expect(array_filter($hrefs, static fn ($h) => str_contains($h, '/login')) === [], "[{$locale}/{$name}] no footer link may target the login page");
        }
    }

    // ---- Layer B: router contract — /support is public for guests --------
    $routerSource = (string) file_get_contents(dirname(__DIR__, 2) . '/locale-router.php');
    preg_match('/\$protectedRoutes\s*=\s*\[(.*?)\];/s', $routerSource, $block);
    preg_match_all("/'([^']+)'/", $block[1] ?? '', $entries);
    $protected = $entries[1] ?? [];

    $expect($protected !== [], 'router contract parse must find the protected route list');
    $expect(in_array('dashboard/index.html', $protected, true), 'dashboard must stay protected (parse sanity check)');
    $expect(
        !in_array('support/index.html', $protected, true),
        '/support must be public for logged-out readers — it must NOT be in $protectedRoutes (BUG-A4)',
    );
    $expect(!in_array('terms/index.html', $protected, true), '/terms must stay public');
    $expect(!in_array('privacy/index.html', $protected, true), '/privacy must stay public');

    echo "TEST-07 email footer link + router contract: PASS ({$assertions} assertions)\n";
}
