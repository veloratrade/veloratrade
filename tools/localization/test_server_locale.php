<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$router = $root . '/locale-router.php';

function run_case(string $router, string $path, string $accept, array $cookies = []): array
{
    $_SERVER = [
        'REQUEST_URI' => $path,
        'REQUEST_METHOD' => 'GET',
        'HTTP_ACCEPT_LANGUAGE' => $accept,
    ];
    $_COOKIE = $cookies;
    http_response_code(200);
    header_remove();
    ob_start();
    include $router;
    $body = (string) ob_get_clean();
    $locale = preg_match('~data-velora-prelocalized="([^"]+)"~', $body, $match) ? $match[1] : null;
    header_remove();
    return [$locale, $body, http_response_code()];
}

$cases = [
    ['/', 'fa-IR,fa;q=0.9,en;q=0.8', [], 'fa', 'rtl'],
    ['/', 'en-GB,en;q=0.9', [], 'en', 'ltr'],
    ['/', 'de-DE,de;q=0.9,fa;q=0.8', [], 'en', 'ltr'],
    ['/', 'ar-SA,ar;q=0.9', [], 'en', 'ltr'],
    ['/', 'fa-IR,fa;q=0.9', ['velora_locale' => 'en'], 'en', 'ltr'],
    ['/', 'en-GB,en;q=0.9', ['velora_locale' => 'fa'], 'fa', 'rtl'],
    ['/en/blog/', '', [], 'en', 'ltr'],
    ['/fa/dashboard/', 'en-GB,en;q=0.9', ['velora_locale' => 'en'], 'fa', 'rtl'],
    ['/en/dashboard/', 'fa-IR,fa;q=0.9', ['velora_locale' => 'fa'], 'en', 'ltr'],
    ['/en/blog/what-is-a-trading-journal/', 'fa-IR,fa;q=0.9', [], 'en', 'ltr'],
    // R2: a manually chosen English cookie must not be overridden by a Persian
    // browser when no user session/DB is present (uses a public route).
    ['/support/', 'fa-IR,fa;q=0.9', ['velora_locale' => 'en'], 'en', 'ltr'],
];
foreach ($cases as $case) {
    [$path, $accept, $cookies, $expectedLocale, $expectedDirection] = $case;
    [$locale, $body, $status] = run_case($router, $path, $accept, $cookies);
    if ($status !== 200 || $locale !== $expectedLocale) {
        throw new RuntimeException("resolver failure for {$path}/{$accept}: status={$status}, locale={$locale}");
    }
    if (!preg_match('~<html[^>]+lang="' . preg_quote($expectedLocale, '~') . '(?:-[^"]+)?"~i', $body)
        || !preg_match('~<html[^>]+dir="' . $expectedDirection . '"~i', $body)) {
        throw new RuntimeException("HTML metadata failure for locale {$expectedLocale}");
    }
    if (!str_contains($body, 'data-velora-prelocalized="' . $expectedLocale . '"')) {
        throw new RuntimeException("prelocalized marker missing for {$expectedLocale}");
    }
}
[$locale, $body, $status] = run_case($router, '/does-not-exist', 'en-GB');
if ($status !== 404 || $locale !== 'en' || !str_contains($body, 'data-velora-prelocalized="en"')) {
    throw new RuntimeException('localized 404 failure');
}
echo "SERVER_LOCALE_TEST_OK cases=" . count($cases) . " unsupported_to_en=true cookie_priority=true explicit_locale_url=true localized_404=true\n";
