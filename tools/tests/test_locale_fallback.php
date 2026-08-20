<?php

declare(strict_types=1);

/**
 * TEST-20 — Localization Fallback Validation.
 *
 * An unknown/unsupported locale must never produce broken UI/email copy:
 *   - it resolves to the manifest fallback locale,
 *   - translations come back as real text (never empty, never a raw key for
 *     keys that exist),
 *   - direction resolution stays safe (ltr default),
 *   - genuinely missing keys degrade deterministically to the key itself
 *     (documented LocaleManager contract) — visible, never a blank string.
 *
 * GREEN pin: fallback works today — this test blocks regressions.
 *
 * Deterministic: real LocaleManager against the committed manifest/catalogs.
 * No services, no DB, no secrets.
 */

require dirname(__DIR__, 2) . '/api/src/Core/Locale/LocaleManager.php';

use Velora\Core\Locale\LocaleManager;

$assertions = 0;
$expect = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$i18n = LocaleManager::getInstance();
// NOTE: a fully translated subject (not a brand string like
// 'email.common.subtitleSecurity', which is intentionally identical in fa/en).
$knownKey = 'email.verification.subject';

// --- Resolution contract ------------------------------------------------------
$expect($i18n->supports('fa'), 'fa must be a supported locale');
$expect($i18n->supports('en'), 'en must be a supported locale');
$expect(!$i18n->supports('de'), 'de must not be a supported locale');
$expect($i18n->resolve('de') === 'en', "unsupported locale 'de' must resolve to the manifest fallback locale");
$expect($i18n->resolve('FA') === 'fa', 'locale resolution must be case-insensitive');
$expect($i18n->resolve('fa-IR') === 'fa', 'regional fa-IR must resolve to base fa');

// --- Direction contract -------------------------------------------------------
$expect($i18n->directionFor('fa') === 'rtl', 'fa direction must be rtl');
$expect($i18n->directionFor('en') === 'ltr', 'en direction must be ltr');
$expect($i18n->directionFor('xx') === 'ltr', 'unknown locale direction must safely default to ltr');

// --- Translation fallback ------------------------------------------------------
$de = $i18n->translateFor('de', $knownKey);
$en = $i18n->translateFor('en', $knownKey);
$fa = $i18n->translateFor('fa', $knownKey);
$expect($de === $en && $de !== '', 'unknown locale must fall back to English copy, not raw keys or empty text');
$expect($de !== $knownKey, 'fallback must yield translated text, never the raw key, for existing keys');
$expect($fa !== '' && $fa !== $en, 'fa and en catalogs must carry distinct translations for the same key');

// --- Missing-key degradation ----------------------------------------------------
$missing = $i18n->translateFor('en', 'email.definitelyMissing.key');
$expect($missing === 'email.definitelyMissing.key', 'a genuinely missing key must degrade deterministically to the key itself (never empty)');
$expect($i18n->translateFor('de', 'email.definitelyMissing.key') === $missing, 'missing keys degrade identically for unknown locales');

// --- init() with garbage input ----------------------------------------------------
$i18n->init(['selected_language' => 'de-AT']);
$expect($i18n->getLanguage() === 'en', 'init() with an unsupported language must settle on the fallback locale');
$expect($i18n->isRTL() === false, 'fallback locale must report a safe LTR direction');

echo "TEST-20 PASS ({$assertions} assertions)\n";
exit(0);
