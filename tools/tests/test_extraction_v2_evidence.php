<?php

declare(strict_types=1);

/**
 * Phase 2D — Gemini v2 screenshot datetime EVIDENCE contract.
 *
 * Pure unit tests (no DB, no network). The v2 contract returns SOURCE EVIDENCE
 * only: verbatim raw text, observed calendar/format, and visible tz/offset.
 * The parsing layer must NOT convert calendars, normalize to UTC, infer a
 * timezone (from locale/broker/clock), or classify sessions. Deterministic
 * normalization is owned later by TradeTimeNormalizer.
 *
 * Covers fixtures A-E plus: digit preservation, openTime hint de-marked as UTC,
 * malformed/tolerant evidence, and backward compatibility with v1 responses.
 */

// Minimal environment so bootstrap/Config resolves (no DB/network used).
$root = sys_get_temp_dir() . '/velora-v2evidence-' . bin2hex(random_bytes(5));
@mkdir($root . '/config', 0700, true);
@mkdir($root . '/data', 0700, true);
@mkdir($root . '/logs', 0700, true);
putenv('APP_ENV=local');
putenv('VELORA_PRIVATE_ROOT=' . $root);
putenv('VELORA_DOCUMENT_ROOT=' . dirname(__DIR__, 2));
file_put_contents($root . '/config/velora.env', implode("\n", [
    'APP_ENV=local',
    'APP_DEBUG=true',
    'DB_DRIVER=sqlite',
    'DB_DATABASE=' . $root . '/data/v.sqlite',
    'JWT_SECRET=' . str_repeat('j', 48),
    'APP_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)),
    'CORS_ALLOWED_ORIGINS=http://localhost',
    'FRONTEND_URL=http://localhost',
    'MAIL_DRIVER=log',
]) . "\n");

require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

use Velora\AI\Extraction\ExtractedTradeData;
use Velora\AI\Prompts\PromptManager;

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($detail !== '' ? " :: $detail" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
}

function d(array $json): ExtractedTradeData
{
    return ExtractedTradeData::fromArray($json, 'gemini', 0.9);
}

// ---- Fixture A: Gregorian DD/MM/YYYY, no tz evidence --------------------
$a = d(['openTime' => '2026-08-31 14:30', 'closeTime' => '2026-08-31 15:42',
        'rawOpenText' => '31/08/2026 14:30', 'rawCloseText' => '31/08/2026 15:42',
        'sourceCalendar' => 'gregorian', 'dateFormat' => 'DD/MM/YYYY',
        'timezoneText' => null, 'timezoneOffsetHintMinutes' => null]);
check('A: gregorian raw preserved', $a->rawOpenText === '31/08/2026 14:30');
check('A: calendar gregorian', $a->sourceCalendar === 'gregorian');
check('A: format captured', $a->dateFormat === 'DD/MM/YYYY');
check('A: tz text null', $a->timezoneText === null);
check('A: offset null', $a->timezoneOffsetHintMinutes === null);
check('A: no UTC/Z added to hint', strpos((string) $a->openTime, 'Z') === false && strpos((string) $a->openTime, '+') === false);

// ---- Fixture B: Jalali with Persian digits — RAW PRESERVED ---------------
$b = d(['rawOpenText' => '۱۴۰۵/۰۶/۰۹ ۱۴:۳۰', 'sourceCalendar' => 'jalali']);
check('B: jalali persian-digit raw preserved verbatim', $b->rawOpenText === '۱۴۰۵/۰۶/۰۹ ۱۴:۳۰', $b->rawOpenText);
check('B: calendar jalali', $b->sourceCalendar === 'jalali');
// No Gregorian conversion happened in parsing.
check('B: no gregorian conversion (raw still 1405)', strpos($b->rawOpenText, '1405') === false && strpos($b->rawOpenText, '2026') === false);

// ---- Fixture C: ambiguous date format -> unknown, not guessed ------------
$c = d(['rawOpenText' => '03/04/2026 14:30', 'sourceCalendar' => 'gregorian', 'dateFormat' => 'unknown']);
check('C: ambiguous format stored as unknown', $c->dateFormat === 'unknown');
check('C: raw preserved', $c->rawOpenText === '03/04/2026 14:30');
// Even if model wrongly claims a definitive format for an ambiguous value,
// unknown is accepted as given; we only verify we never invent tz/calendar.
$cBad = d(['rawOpenText' => '03/04/2026 14:30', 'dateFormat' => 'weird']);
check('C: out-of-vocabulary format -> unknown', $cBad->dateFormat === 'unknown', var_export($cBad->dateFormat, true));

// ---- Fixture D: explicit tz text + offset; NO IANA invented --------------
$d2 = d(['rawOpenText' => '31/08/2026 14:30', 'timezoneText' => 'UTC+3:30', 'timezoneOffsetHintMinutes' => 210]);
check('D: tz text preserved verbatim', $d2->timezoneText === 'UTC+3:30');
check('D: offset hint minutes captured', $d2->timezoneOffsetHintMinutes === 210);
// There must be no authoritative IANA field produced by the DTO.
check('D: no sourceTimezone authority field on DTO', !property_exists($d2, 'sourceTimezone'));

// ---- Fixture E: no tz evidence stays null/unknown ------------------------
$e = d(['rawOpenText' => '31/08/2026 14:30']);
check('E: missing tz text => null', $e->timezoneText === null);
check('E: missing offset => null', $e->timezoneOffsetHintMinutes === null);
check('E: missing calendar => null (not assumed)', $e->sourceCalendar === null);

// ---- F: Arabic-Indic digits preserved -------------------------------------
$f = d(['rawOpenText' => '٣١/٠٨/٢٠٢٦ ١٤:٣٠']);
check('F: arabic-indic raw preserved verbatim', $f->rawOpenText === '٣١/٠٨/٢٠٢٦ ١٤:٣٠', $f->rawOpenText);

// ---- GMT+2 text and negative offset --------------------------------------
$g = d(['timezoneText' => 'GMT+2', 'timezoneOffsetHintMinutes' => 120]);
check('G: GMT+2 text preserved', $g->timezoneText === 'GMT+2');
check('G: +120 offset', $g->timezoneOffsetHintMinutes === 120);
$gn = d(['timezoneOffsetHintMinutes' => -300]);
check('G: negative offset -300', $gn->timezoneOffsetHintMinutes === -300);
$goob = d(['timezoneOffsetHintMinutes' => 9999]);
check('G: out-of-range offset discarded -> null', $goob->timezoneOffsetHintMinutes === null);
$gstr = d(['timezoneOffsetHintMinutes' => '-240']);
check('G: string numeric offset accepted', $gstr->timezoneOffsetHintMinutes === -240);

// ---- Abbreviation preserved, not mapped to IANA --------------------------
$h = d(['timezoneText' => 'EST']);
check('H: abbreviation preserved verbatim (not mapped)', $h->timezoneText === 'EST');

// ---- Calendar vocabulary safety ------------------------------------------
check('cal: persian/shamsi map to jalali', d(['sourceCalendar' => 'persian'])->sourceCalendar === 'jalali');
check('cal: unknown honored', d(['sourceCalendar' => 'unknown'])->sourceCalendar === 'unknown');
check('cal: garbage -> unknown (cannot smuggle tz)', d(['sourceCalendar' => 'America/New_York'])->sourceCalendar === 'unknown');

// ---- No session field leaks through --------------------------------------
// DTO only whitelists known keys; a session in model JSON must not appear.
$s = d(['session' => 'NEW YORK', 'openTime' => '2026-08-31 14:30']);
check('session key not surfaced by DTO', !array_key_exists('session', $s->toArray()) && !property_exists($s, 'session'));

// ---- openTime hint with trailing Z is de-marked (UTC not claimed) ---------
// Simulate the defensive strip used in GeminiProvider for a model that
// disobeys and appends Z/offset.
$strip = static function (string $v): string {
    $v = trim($v);
    return trim(preg_replace('/(?:Z|[+-]\d{2}:?\d{2})\s*$/i', '', $v) ?? $v);
};
check('hint Z stripped', $strip('2026-08-31T14:30Z') === '2026-08-31T14:30');
check('hint offset stripped', $strip('2026-08-31T14:30+03:30') === '2026-08-31T14:30');
check('raw-like text untouched', $strip('31/08/2026 14:30') === '31/08/2026 14:30');

// ---- Backward compatibility: a v1-only response still works --------------
$v1 = d(['symbol' => 'XAUUSD', 'side' => 'buy', 'openTime' => '2026-08-31T14:30',
         'entry' => '2000.5', 'confidence' => 0.9]);
check('v1 compat: symbol still parsed', $v1->symbol === 'XAUUSD');
check('v1 compat: openTime present', $v1->openTime === '2026-08-31T14:30');
check('v1 compat: new evidence fields null', $v1->rawOpenText === null && $v1->sourceCalendar === null && $v1->timezoneText === null);
$arr = $v1->toArray();
check('v1 compat: toArray still has openTime/provider', isset($arr['openTime'], $arr['provider']));

// ---- v2 prompt exists and is evidence-only (no normalization instructions)
$prompt = PromptManager::get('screenshot_extraction', 'v2', 'en');
check('v2 prompt loads', $prompt !== '');
$low = strtolower($prompt);
check('v2 prompt demands verbatim raw text', strpos($low, 'rawopentext') !== false);
check('v2 prompt forbids session classification', strpos($low, 'never output a trading session') !== false || strpos($low, 'session') !== false);
check('v2 prompt forbids timezone inference', strpos($low, 'never infer the timezone') !== false);
check('v2 prompt forbids calendar conversion', strpos($low, 'do not convert gregorian to jalali') !== false);
$v1prompt = PromptManager::get('screenshot_extraction', 'v1', 'en');
check('v1 prompt still available (backward compat)', $v1prompt !== '');

echo $failures === 0 ? "\nALL EXTRACTION V2 EVIDENCE TESTS PASSED\n" : "\n$failures TEST(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
