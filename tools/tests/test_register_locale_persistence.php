<?php

declare(strict_types=1);

/**
 * R4 — registration captures the UI locale so new users who sign up from an
 * English page keep English after their first login.
 *
 * Contract under test (source-level, no DB / no network):
 *   1. The register HTML (source + both generated locales) submits a `locale`
 *      field equal to `VeloraLocale.locale`.
 *   2. AuthController::register() whitelists the `locale` body field.
 *   3. AuthService::register() validates the candidate through LocaleManager
 *      and threads it into UserRepository::create() with locale_source='user'.
 *   4. UserRepository::create() writes both `locale` and `locale_source`.
 *
 * Deterministic: pure filesystem reads. Mirrors the static-contract style used
 * by test_user_locale_preference_endpoint.php.
 */

$repoRoot = dirname(__DIR__, 2);
$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($detail !== '' ? ' :: ' . $detail : '') . "\n";
    if (!$ok) {
        $failures++;
    }
}

$read = static fn (string $rel): string => (string) file_get_contents($repoRoot . '/' . $rel);

// ---- 1. Register pages include locale in the POST body --------------------

foreach (['register/index.html', 'localized/en/register/index.html', 'localized/fa/register/index.html'] as $rel) {
    $html = $read($rel);
    check(
        "register payload includes locale ($rel)",
        (bool) preg_match('/["\']?locale["\']?\s*:\s*VeloraLocale\\.locale/', $html),
        'locale field missing from register POST body'
    );
}

// ---- 2. AuthController accepts the locale field ---------------------------

$controller = $read('api/src/Auth/AuthController.php');
$blockOk = false;
if (preg_match("/public function register\b.*?\n    \}/s", $controller, $m)) {
    $block = $m[0];
    $blockOk = str_contains($block, "'locale'") && str_contains($block, "'notificationLocale'");
}
check(
    'AuthController::register whitelists locale',
    $blockOk,
    'locale rule missing in Validation::assert for register'
);

// ---- 3. AuthService resolves and threads the locale -----------------------

$service = $read('api/src/Auth/AuthService.php');

check(
    'AuthService::register reads $data[\'locale\']',
    (bool) preg_match("/\\\$uiLocale\s*=\s*trim\(\(string\)\s*\\(\\\$data\['locale'\]/", $service),
    'UI locale not extracted from request body'
);

check(
    'AuthService validates locale through LocaleManager::supports/resolve',
    str_contains($service, 'LocaleManager::getInstance')
        && str_contains($service, '$i18n->supports($uiLocale)')
        && str_contains($service, '$i18n->resolve($uiLocale)'),
    'locale candidate is not canonicalized via LocaleManager'
);

check(
    'AuthService falls back to notificationLocale',
    str_contains($service, '$uiLocale === null && $notificationLocale !== null'),
    'notificationLocale fallback for UI locale missing'
);

check(
    'AuthService passes locale + locale_source=user to UserRepository::create',
    (bool) preg_match("/\\\$createData\['locale'\]\s*=\s*\\\$uiLocale/", $service)
        && (bool) preg_match("/\\\$createData\['locale_source'\]\s*=\s*'user'/", $service),
    'locale/locale_source not attached to create data'
);

// ---- 4. UserRepository writes both columns --------------------------------

$repo = $read('api/src/Auth/UserRepository.php');
check(
    'UserRepository::create INSERT lists locale and locale_source',
    (bool) preg_match('/INSERT\s+INTO\s+users\s*\([^)]*locale[^)]*locale_source/is', $repo),
    'INSERT column list missing locale_source'
);
check(
    'UserRepository::create binds :locale and :source',
    str_contains($repo, "'locale' => \$locale") && str_contains($repo, "'source' => \$source"),
    'parameter binding missing'
);

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "REGISTER_LOCALE_PERSISTENCE_TEST failed failures=$failures\n");
    exit(1);
}
echo "REGISTER_LOCALE_PERSISTENCE_TEST_OK\n";
