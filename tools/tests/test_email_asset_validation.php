<?php

declare(strict_types=1);

/**
 * TEST-08 — Email asset validation (logo + icons), fa and en.
 *
 * For every rendered transactional email:
 *   - the logo is an absolute HTTPS URL, not localhost / 127.0.0.1 / relative,
 *   - no `src=`/`href=` references any broken scheme (http://, //, /assets, file:),
 *   - every `cid:` image referenced in the HTML has a matching attachment whose
 *     decoded bytes equal the on-disk PNG (no broken/missing assets),
 *   - the full icon mapping: 7 event icons exist on disk as valid PNG files.
 *
 * Capture architecture, no provider, no network, no DB, no secrets.
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
    return '{"id":"mock-asset-id"}';
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
    $expect = static function (bool $condition, string $message) use (&$assertions): void {
        $assertions++;
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    };

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

    foreach (['fa', 'en'] as $locale) {
        foreach ($senders as $name => $send) {
            $send($locale);
            $payload = CurlMock::takeLastPayload();
            $html = (string) ($payload['html'] ?? '');

            // Logo: exactly one absolute HTTPS logo reference per email.
            preg_match_all('/src="([^"]+)"/', $html, $srcs);
            $httpSrcs = array_values(array_filter($srcs[1], static fn ($s) => !str_starts_with($s, 'cid:')));
            $expect($httpSrcs !== [], "[{$locale}/{$name}] logo must be present");
            foreach ($httpSrcs as $src) {
                $expect(str_starts_with($src, 'https://'), "[{$locale}/{$name}] logo URL must be absolute HTTPS: {$src}");
                $expect(!str_contains($src, 'localhost') && !str_contains($src, '127.0.0.1'), "[{$locale}/{$name}] logo URL must not point at localhost/127.0.0.1");
                $expect(str_ends_with($src, '.png'), "[{$locale}/{$name}] logo URL must reference a PNG asset");
                $path = parse_url($src, PHP_URL_PATH);
                $expect(is_string($path) && !str_starts_with($path, '/assets/'), "[{$locale}/{$name}] logo must not use the legacy /assets relative route");
            }

            // No broken schemes anywhere in the HTML body.
            $expect(!preg_match('/(?:src|href)="http:\/\//', $html), "[{$locale}/{$name}] no insecure http:// resource");
            $expect(!preg_match('/(?:src|href)="\/\//', $html), "[{$locale}/{$name}] no protocol-relative resource");
            $expect(!preg_match('/(?:src|href)="(?!cid:|https:\/\/|mailto:)/', $html), "[{$locale}/{$name}] no relative asset paths");

            // Every cid: reference must resolve to a real attachment of real bytes.
            preg_match_all('/cid:([a-z0-9-]+)/', $html, $cids);
            $attachments = [];
            foreach (($payload['attachments'] ?? []) as $att) {
                $attachments[$att['content_id']] = base64_decode((string) $att['content'], true);
            }
            foreach (array_unique($cids[1]) as $cid) {
                $expect(isset($attachments[$cid]), "[{$locale}/{$name}] cid '{$cid}' must have a matching attachment");
                $pngPath = $repoRoot . '/public/assets/email-icons/' . substr($cid, strlen('velora-')) . '.png';
                if (isset($attachments[$cid]) && is_file($pngPath)) {
                    $expect($attachments[$cid] === file_get_contents($pngPath), "[{$locale}/{$name}] attachment bytes for '{$cid}' must match the on-disk PNG");
                }
            }
        }
    }

    // Icon mapping completeness: 7 dedicated icons, valid PNGs on disk.
    foreach (['verification', 'welcome', 'password-reset', 'password-changed', 'security', 'first-trade', 'achievement'] as $icon) {
        $path = $repoRoot . '/public/assets/email-icons/' . $icon . '.png';
        $expect(is_file($path) && filesize($path) > 0, "icon '{$icon}.png' must exist on disk");
        $raw = (string) @file_get_contents($path);
        $expect(substr($raw, 0, 8) === "\x89PNG\x0d\x0a\x1a\x0a", "icon '{$icon}.png' must be a valid PNG");
        $expect(is_file($repoRoot . '/tools/email-icons/' . $icon . '.svg'), "source SVG for '{$icon}' must exist");
    }
    $logoBytes = (string) @file_get_contents($repoRoot . '/public/assets/velora-email-logo.png');
    $expect(substr($logoBytes, 0, 8) === "\x89PNG\x0d\x0a\x1a\x0a", 'email logo PNG must be a valid image');

    echo "TEST-08 email asset validation: PASS ({$assertions} assertions)\n";
}
