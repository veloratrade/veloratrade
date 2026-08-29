<?php

declare(strict_types=1);

/**
 * Admin AI settings UI contract tests — no HTTP server needed.
 *
 * Verifies the wiring contract between admin/index.html, the
 * velora-admin-ai.js asset, and the localization catalogs:
 *   - panel element ids referenced by the asset exist in the page,
 *   - every data-i18n key on the page resolves in BOTH catalogs,
 *   - every catalog pages.admin.ai.* key is actually referenced (no orphans),
 *   - the asset is loaded with the manifest version suffix,
 *   - feature chunks + feature-manifest stay byte-consistent.
 *
 * Run: php tools/tests/test_admin_ai_ui.php
 */

$root = dirname(__DIR__, 2);
$failures = 0;
$checks = 0;
function check(bool $cond, string $label): void
{
    global $failures, $checks;
    $checks++;
    echo ($cond ? '  PASS: ' : '  FAIL: ') . $label . "\n";
    if (!$cond) {
        $failures++;
    }
}

$html = (string) file_get_contents($root . '/admin/index.html');
$js = (string) file_get_contents($root . '/public/assets/velora-admin-ai.js');
$manifest = json_decode((string) file_get_contents($root . '/public/locales/manifest.json'), true);
$version = (string) ($manifest['version'] ?? '');

echo "== Panel wiring ==\n";
check(strpos($html, 'id="aiSettingsPanel"') !== false, 'panel container present');
foreach (['aiProviders', 'aiFeatures', 'aiRoutingMeta', 'aiRefresh', 'aiErrorBox', 'aiErrorText'] as $id) {
    check(strpos($html, 'id="' . $id . '"') !== false, "element #{$id} present");
    check(strpos($js, "'" . $id . "'") !== false || strpos($js, '$("' . $id . '")') !== false, "asset references #{$id}");
}
check(strpos($html, '/public/assets/velora-admin-ai.js?v=' . $version) !== false, 'asset loaded with manifest version suffix');
check(strpos($js, "'/api/v1/admin/ai'") !== false, 'asset targets the real admin AI API');
check(strpos($js, '/overview') !== false, 'asset fetches the authoritative overview');

echo "== No optimistic UI contract ==\n";
check(substr_count($js, 'loadOverview()') >= 3, 'mutations re-fetch the overview (no client-side optimistic state)');
check(strpos($js, 'input.value = \'\';') !== false, 'credential input cleared immediately after submit (value never kept in client state)');
check(strpos($js, 'type = \'password\'') !== false, 'credential field is a password input');
check(strpos($js, 'textContent') !== false && strpos($js, 'innerHTML') === false, 'rendering is textContent-only (no HTML injection of provider data)');

echo "== Localization contract ==\n";
$en = json_decode((string) file_get_contents($root . '/public/locales/en.json'), true)['messages'] ?? [];
$fa = json_decode((string) file_get_contents($root . '/public/locales/fa.json'), true)['messages'] ?? [];
preg_match_all('/data-i18n(?:-[a-z-]+)?="([^"]+)"/', $html, $m);
$htmlKeys = array_unique($m[1]);
$missing = array_values(array_filter($htmlKeys, fn (string $k): bool => !isset($en[$k]) || !isset($fa[$k])));
check($missing === [], 'every data-i18n key on the page exists in BOTH catalogs (' . count($missing) . ' missing)');

/* Canonical chunk-coverage contract: dynamic-UI keys live as literals in the
 * TEMPLATE (inline VeloraAdminAIKeys map); the external asset resolves them
 * through K('stem') — never by embedding key literals. */
preg_match("/window\.VeloraAdminAIKeys = \{(.*?)\};/s", $html, $mapMatch);
check(!empty($mapMatch[1]), 'inline VeloraAdminAIKeys map present in template');
$keyMap = [];
if (!empty($mapMatch[1])) {
    preg_match_all("/'([^']+)':\s*'(admin\.ai\.[A-Za-z0-9_.]+)'/", $mapMatch[1], $mm, PREG_SET_ORDER);
    foreach ($mm as $set) { $keyMap[$set[1]] = $set[2]; }
}
check(count($keyMap) === 39, 'key map carries all 39 admin.ai keys (' . count($keyMap) . ')');
$mapMissing = array_values(array_filter($keyMap, fn (string $k): bool => !isset($en[$k]) || !isset($fa[$k])));
check($mapMissing === [], 'every mapped key exists in BOTH catalogs');
preg_match_all("/K\('([^']+)'\)/", $js, $km);
$assetStems = array_unique($km[1]);
$unmapped = array_values(array_diff($assetStems, array_keys($keyMap)));
check($unmapped === [], 'every K(stem) used by the asset exists in the template map');
preg_match_all("/'(admin\.ai\.[A-Za-z0-9_.]+)'/", $js, $mj);
check($mj[1] === [], 'asset embeds NO raw catalog key literals (single source: template map)');

$usedKeys = array_unique(array_merge($htmlKeys, array_values($keyMap)));
$catalogAi = array_filter(array_keys($en), fn (string $k): bool => str_starts_with($k, 'admin.ai.'));
$orphan = array_values(array_diff($catalogAi, $usedKeys));
check($orphan === [], 'no orphan admin.ai.* catalog keys (' . count($orphan) . ' orphan)');

$placeholderBroken = array_values(array_filter($catalogAi, fn (string $k): bool =>
    substr_count((string) $en[$k], '{count}') !== substr_count((string) $fa[$k], '{count}')));
$identical = array_values(array_filter($catalogAi, fn (string $k): bool => $en[$k] === $fa[$k]));
check($identical === [], 'no admin.ai.* key has identical EN/FA text (fa.en.identical group)');
check($placeholderBroken === [], '{count} placeholder parity en/fa intact');

echo "== Feature chunk integrity ==\n";
$featureManifest = json_decode((string) file_get_contents($root . '/public/locales/feature-manifest.json'), true);
$integrity = true;
foreach (['fa', 'en'] as $locale) {
    foreach (glob($root . "/public/locales/chunks/{$locale}/*.json") ?: [] as $file) {
        $rendered = (string) file_get_contents($file);
        $payload = json_decode($rendered, true);
        $entry = $featureManifest['locales'][$locale][$payload['feature']] ?? null;
        if ($entry === null
            || $entry['sha256'] !== hash('sha256', $rendered)
            || $entry['messages'] !== count($payload['messages'])
            || $entry['bytes'] !== strlen($rendered)) {
            $integrity = false;
        }
    }
}
check($integrity, 'every chunk matches its feature-manifest sha256/bytes/count');
$enChunk = json_decode((string) file_get_contents($root . '/public/locales/chunks/en/admin.json'), true)['messages'];
$faChunk = json_decode((string) file_get_contents($root . '/public/locales/chunks/fa/admin.json'), true)['messages'];
check(array_keys($enChunk) === array_keys($faChunk), 'admin chunk en/fa keysets identical');
$diff = array_values(array_diff(array_keys($enChunk), array_keys($en)));
check($diff === [], 'admin chunk is a strict subset of the en catalog');

echo "\nadmin-ai-ui: " . ($failures === 0 ? 'PASS' : 'FAIL') . " ($checks checks, $failures failures)\n";
exit($failures === 0 ? 0 : 1);
