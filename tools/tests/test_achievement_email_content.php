<?php

declare(strict_types=1);

/**
 * TEST-06 (Audit BUG-A3) — Achievement email must render TRANSLATED text,
 * never raw i18n keys, in both fa and en.
 *
 * Capture architecture (house contract-test style): the real NotificationService,
 * EmailTemplate, LocaleManager and Mailer are loaded, while only the HTTP
 * transport and the notification-log/preferences seams are shimmed. The outbound
 * Resend payload is captured and asserted on-subject/on-body — no provider, no
 * network, no secrets, no production code touched.
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

/** Captures email_notifications writes without a database. */
final class EmailNotificationRepository
{
    /** @var array<int,array{string,string,string,string}> */
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

/** HTTP transport mock — captures the exact outbound JSON payload. */
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
    return '{"id":"mock-achievement-id"}';
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
    use Velora\Core\Locale\LocaleManager;
    use Velora\Core\NotificationService;

    $assertions = 0;
    $expect = static function (bool $condition, string $message) use (&$assertions): void {
        $assertions++;
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    };

    $i18n = LocaleManager::getInstance();

    foreach (['fa', 'en'] as $locale) {
        $expectedTitle = $i18n->translateFor($locale, 'achievements.emailVerified.title');
        $expectedDesc = $i18n->translateFor($locale, 'achievements.emailVerified.description');

        NotificationService::sendAchievementUnlockedEmail(
            'achievement.user@example.test',
            'Achievement User',
            'achievements.emailVerified.title',
            'achievements.emailVerified.description',
            'https://test.veloratrade.invalid/profile',
            null,
            $locale,
        );
        $payload = CurlMock::takeLastPayload();
        $html = (string) ($payload['html'] ?? '');

        $expect(
            !str_contains($html, 'achievements.emailVerified'),
            "[{$locale}] achievement email must not render the raw i18n key",
        );
        $expect(
            str_contains($html, $expectedTitle),
            "[{$locale}] achievement email must contain translated title '{$expectedTitle}'",
        );
        $expect(
            str_contains($html, $expectedDesc),
            "[{$locale}] achievement email must contain translated description",
        );
    }

    echo "TEST-06 achievement email translated content: PASS ({$assertions} assertions)\n";
}
