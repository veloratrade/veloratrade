<?php

declare(strict_types=1);

/**
 * TEST-19 — FA/EN Email Translation Key Parity.
 *
 * public/locales/fa.json and public/locales/en.json wrap their copy in a
 * top-level "messages" object with flat dot-notation keys. The two catalogs
 * must expose the exact same message key set: a key that exists in only one
 * catalog silently degrades that locale (fallback copy or a raw key in the
 * user's inbox). Manifest sanity is part of the contract: defaultLocale and
 * fallbackLocale must both be declared locales.
 *
 * GREEN pin: catalogs are in parity today — this test blocks future drift.
 *
 * Deterministic: pure filesystem reads of the committed catalogs.
 * No services, no DB, no secrets.
 */

$repoRoot = dirname(__DIR__, 2);
$assertions = 0;
$failures = [];
$check = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
    $assertions++;
    if (!$condition) {
        $failures[] = $message;
    }
};

$loadMessages = static function (string $path): array {
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        return [];
    }
    // Catalog format: {"$schema":…, "_meta":…, "messages": {"email.…": "…"}}
    $messages = $data['messages'] ?? $data;
    return is_array($messages) ? array_filter($messages, 'is_string') : [];
};

$fa = $loadMessages($repoRoot . '/public/locales/fa.json');
$en = $loadMessages($repoRoot . '/public/locales/en.json');
$manifestRaw = json_decode((string) file_get_contents($repoRoot . '/public/locales/manifest.json'), true);
$manifest = is_array($manifestRaw) ? $manifestRaw : [];

$check($fa !== [], 'fa.json must expose a non-empty messages catalog');
$check($en !== [], 'en.json must expose a non-empty messages catalog');

$faKeys = array_keys($fa);
$enKeys = array_keys($en);
$missingInEn = array_values(array_diff($faKeys, $enKeys));
$missingInFa = array_values(array_diff($enKeys, $faKeys));

$check(
    $missingInEn === [],
    'keys present in fa.json but missing in en.json: ' . ($missingInEn === [] ? 'none' : implode(', ', array_slice($missingInEn, 0, 8))),
);
$check(
    $missingInFa === [],
    'keys present in en.json but missing in fa.json: ' . ($missingInFa === [] ? 'none' : implode(', ', array_slice($missingInFa, 0, 8))),
);

$faEmail = array_values(array_filter($faKeys, static fn (string $k): bool => str_starts_with($k, 'email.')));
$enEmail = array_values(array_filter($enKeys, static fn (string $k): bool => str_starts_with($k, 'email.')));
$check(
    count($faEmail) === count($enEmail) && count($faEmail) >= 60,
    'email.* key counts must match across catalogs (fa=' . count($faEmail) . ', en=' . count($enEmail) . ', expected >= 60 and equal)',
);

// Sanity: translatable email subjects must actually be translated (brand tokens
// excluded — a subject identical in both catalogs that contains only the brand
// name segment is still meaningful, so require at least one divergent segment).
$subjects = array_values(array_filter($faEmail, static fn (string $k): bool => str_ends_with($k, '.subject')));
$identical = [];
foreach ($subjects as $key) {
    if (($fa[$key] ?? null) === ($en[$key] ?? null)) {
        $identical[] = $key;
    }
}
// Up to 1 identical subject is tolerated only if it is a pure brand string.
$brandOnly = array_values(array_filter($identical, static function (string $key) use ($fa): bool {
    $value = preg_replace('/VELORA|TRADE|[|\s·-]/u', '', (string) $fa[$key]);
    return $value !== '';
}));
$check(
    $brandOnly === [],
    'email subjects must be translated per locale (identical fa/en subjects): ' . ($brandOnly === [] ? 'none' : implode(', ', $brandOnly)),
);

// Manifest sanity.
$locales = array_keys($manifest['locales'] ?? []);
$check(
    in_array($manifest['defaultLocale'] ?? '', $locales, true),
    'manifest defaultLocale must be a declared locale',
);
$check(
    in_array($manifest['fallbackLocale'] ?? '', $locales, true),
    'manifest fallbackLocale must be a declared locale',
);
$check(
    ($manifest['defaultLocale'] ?? null) !== ($manifest['fallbackLocale'] ?? null),
    'defaultLocale and fallbackLocale must be different catalogs for a meaningful fallback chain',
);

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    fwrite(STDERR, 'TEST-19 FAILED: ' . count($failures) . "/{$assertions} assertions failed\n");
    exit(1);
}
echo "TEST-19 PASS ({$assertions} assertions)\n";
exit(0);
