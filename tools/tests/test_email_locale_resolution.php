<?php

declare(strict_types=1);

/**
 * PR-05 — Email locale resolution contract.
 *
 * users.locale is the PRIMARY email-locale source; notificationLocale is only a
 * fallback for flows without a persisted user (fresh registration or
 * anti-enumeration paths). This test pins:
 *
 *   1. resolveEmailLocale() behavior: the saved locale wins; the client hint is
 *      used only when the saved locale is absent/unsupported; null when neither
 *      candidate is usable (caller then falls back to the manifest default).
 *   2. The three calling services (AuthService, PasswordService, TradeService)
 *      resolve the saved locale from the user row (users.locale).
 *   3. All seven NotificationService senders accept a trailing locale argument.
 *
 * Deterministic: real LocaleManager (manifest-backed) + static source checks.
 * No HTTP, no DB, no secrets.
 */

namespace {

require dirname(__DIR__, 2) . '/api/src/Core/Locale/LocaleManager.php';
require dirname(__DIR__, 2) . '/api/src/Core/NotificationService.php';

use Velora\Core\NotificationService;

$repoRoot = dirname(__DIR__, 2);
$assertions = 0;
$failures = [];
$check = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
    $assertions++;
    if (!$condition) {
        $failures[] = $message;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};

// ---- Pin 1: resolveEmailLocale behavior ----------------------------------
$check(
    NotificationService::resolveEmailLocale('en', 'fa') === 'en',
    'saved users.locale=en wins over notificationLocale=fa',
);
$check(
    NotificationService::resolveEmailLocale('fa', 'en') === 'fa',
    'saved users.locale=fa wins over notificationLocale=en',
);
$check(
    NotificationService::resolveEmailLocale(null, 'fa') === 'fa',
    'notificationLocale is used when no saved locale exists',
);
$check(
    NotificationService::resolveEmailLocale('de', 'en') === 'en',
    'unsupported saved locale is skipped in favour of the client hint',
);
$check(
    NotificationService::resolveEmailLocale(null, null) === null,
    'null when neither saved nor client locale is usable (caller falls back to default)',
);
$check(
    NotificationService::resolveEmailLocale('FA-IR', 'en') === 'fa',
    'regional/case variant normalizes to the canonical saved locale',
);

// ---- Pin 2: calling services resolve from the user row (users.locale) ----
$authService = (string) file_get_contents($repoRoot . '/api/src/Auth/AuthService.php');
$passwordService = (string) file_get_contents($repoRoot . '/api/src/Auth/PasswordService.php');
$tradeService = (string) file_get_contents($repoRoot . '/api/src/Trades/TradeService.php');

$check(
    str_contains($authService, "\$user['locale'] ?? null") && str_contains($authService, "\$existing['locale'] ?? null"),
    'AuthService resolves the saved locale from the user row',
);
$check(
    str_contains($passwordService, "\$user['locale'] ?? null"),
    'PasswordService resolves the saved locale from the user row',
);
$check(
    str_contains($tradeService, "\$user['locale'] ?? null"),
    'TradeService resolves the saved locale from the user row',
);

// ---- Pin 3: every sender accepts a trailing locale argument --------------
$notif = (string) file_get_contents($repoRoot . '/api/src/Core/NotificationService.php');
$senderMethods = [
    'sendVerificationEmail',
    'sendWelcomeEmail',
    'sendPasswordResetTokenEmail',
    'sendPasswordChangedEmail',
    'sendNewDeviceDetectedEmail',
    'sendFirstTradeEmail',
    'sendAchievementUnlockedEmail',
];
foreach ($senderMethods as $method) {
    $check(str_contains($notif, $method), "NotificationService declares {$method}");
}
$check(
    substr_count($notif, '?string $notificationLocale = null') >= 7,
    'all seven senders accept a trailing nullable locale argument',
);

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    fwrite(STDERR, 'PR-05 locale resolution TEST FAILED: ' . count($failures) . "/{$assertions} assertions failed\n");
    exit(1);
}
echo "PR-05 email locale resolution contract: PASS ({$assertions} assertions)\n";
exit(0);
}
