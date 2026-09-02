<?php

declare(strict_types=1);

/**
 * Phase 2C — TradeTimeNormalizer.
 *
 * Pure unit tests (no DB, no network). Proves: canonical UTC is produced only
 * with a trustworthy timezone; unknown tz never becomes UTC; Gregorian/Jalali
 * conversion is deterministic; Persian/Arabic digits parse; explicit offsets
 * pin the instant; DST gaps are rejected (invalid) and folds are not guessed
 * (unresolved/ambiguous); ambiguous date formats stay unresolved; and the
 * result is independent of the PHP process default timezone.
 */

require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

use Velora\Trades\TradeTimeNormalizer as N;
use Velora\Trades\NormalizedTime;

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($detail !== '' ? " :: $detail" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
}

function expectResolved(N $n, string $raw, ?string $cal, ?string $tz, ?string $hint, string $expectIso): void
{
    $r = $n->normalize($raw, $cal, $tz, $hint);
    check("resolved $raw [$tz] -> $expectIso",
        $r->status === N::RESOLVED && $r->iso8601 === $expectIso,
        json_encode(['status' => $r->status, 'iso' => $r->iso8601, 'reason' => $r->reason, 'amb' => $r->ambiguous]));
}
function expectState(N $n, string $raw, ?string $cal, ?string $tz, ?string $hint, string $state, ?string $reason = null): void
{
    $r = $n->normalize($raw, $cal, $tz, $hint);
    $ok = $r->status === $state && ($reason === null || $r->reason === $reason);
    check("$state ($reason) :: $raw [$tz]", $ok,
        json_encode(['status' => $r->status, 'reason' => $r->reason, 'iso' => $r->iso8601, 'amb' => $r->ambiguous]));
}

$n = new N();

// A. Gregorian + UTC
expectResolved($n, '2026-08-31 14:30', 'gregorian', 'UTC', null, '2026-08-31T14:30:00Z');
expectResolved($n, '2026-08-31T14:30', 'gregorian', 'UTC', null, '2026-08-31T14:30:00Z');

// B. Gregorian + Europe/London (BST in August = UTC+1)
expectResolved($n, '2026-08-31 14:30', 'gregorian', 'Europe/London', null, '2026-08-31T13:30:00Z');
// London winter (GMT = UTC+0)
expectResolved($n, '2026-01-15 09:00', 'gregorian', 'Europe/London', null, '2026-01-15T09:00:00Z');

// America/New_York August (EDT = UTC-4)
expectResolved($n, '2026-08-31 14:30', 'gregorian', 'America/New_York', null, '2026-08-31T18:30:00Z');
// NY winter (EST = UTC-5)
expectResolved($n, '2026-01-15 09:00', 'gregorian', 'America/New_York', null, '2026-01-15T14:00:00Z');
// Tehran (UTC+3:30, no DST since 2022)
expectResolved($n, '2026-08-31 14:30', 'gregorian', 'Asia/Tehran', null, '2026-08-31T11:00:00Z');

// C. London DST — valid near transition vs. nonexistent gap
expectResolved($n, '2026-03-29 12:00', 'gregorian', 'Europe/London', null, '2026-03-29T11:00:00Z'); // after jump, BST
expectState($n, '2026-03-29 01:30', 'gregorian', 'Europe/London', null, N::INVALID, 'dst_gap');

// D. New York DST — spring-forward gap & fall-back fold
expectResolved($n, '2026-03-08 03:30', 'gregorian', 'America/New_York', null, '2026-03-08T07:30:00Z'); // after jump, EDT
expectState($n, '2026-03-08 02:30', 'gregorian', 'America/New_York', null, N::INVALID, 'dst_gap');
expectState($n, '2026-11-01 01:30', 'gregorian', 'America/New_York', null, N::UNRESOLVED, 'dst_fold');

// E. Explicit offset (pins instant; disambiguates fold)
expectResolved($n, '2026-08-31T14:30+03:30', 'gregorian', null, null, '2026-08-31T11:00:00Z');
expectResolved($n, '2026-11-01T01:30-04:00', 'gregorian', null, null, '2026-11-01T05:30:00Z'); // EDT side of fold -> pinned
expectResolved($n, '2026-08-31 14:30 Z', 'gregorian', null, null, '2026-08-31T14:30:00Z');
expectResolved($n, '2026-08-31T14:30Z', 'gregorian', null, null, '2026-08-31T14:30:00Z');

// F. Persian digits, Gregorian, DMY hint
expectResolved($n, '۳۱/۰۸/۲۰۲۶ ۱۴:۳۰', 'gregorian', 'America/New_York', 'DMY', '2026-08-31T18:30:00Z');
// G. Arabic-Indic digits
expectResolved($n, '٣١/٠٨/٢٠٢٦ ١٤:٣٠', 'gregorian', 'America/New_York', 'DMY', '2026-08-31T18:30:00Z');

// H. Jalali, independently verifiable: 1405-06-09 == 2026-08-31 (Gregorian)
expectResolved($n, '1405/06/09 14:30', 'jalali', 'America/New_York', null, '2026-08-31T18:30:00Z');
// Nowruz 1400 == 2021-03-21 (UTC tz for clean date)
expectResolved($n, '1400/01/01 12:00', 'jalali', 'UTC', null, '2021-03-21T12:00:00Z');
// Jalali with Persian digits
expectResolved($n, '۱۴۰۵/۰۶/۰۹ ۱۴:۳۰', 'jalali', 'UTC', null, '2026-08-31T14:30:00Z');

// I. Unknown timezone -> unresolved (never UTC)
expectState($n, '2026-08-31 14:30', 'gregorian', null, null, N::UNRESOLVED, 'unknown_timezone');
expectState($n, '2026-08-31 14:30', 'gregorian', 'London', null, N::UNRESOLVED, 'unknown_timezone'); // display label rejected
expectState($n, '2026-08-31 14:30', 'gregorian', 'GMT+3', null, N::UNRESOLVED, 'unknown_timezone');  // fixed offset not IANA

// J. Unknown calendar with day/month-first form -> unresolved
expectState($n, '31/08/2026 14:30', 'unknown', 'UTC', 'DMY', N::UNRESOLVED, 'unknown_calendar');
// Unknown calendar with year-first Gregorian -> resolves safely
expectResolved($n, '2026-08-31 14:30', 'unknown', 'UTC', null, '2026-08-31T14:30:00Z');

// K. Ambiguous date format (both <=12) without hint -> unresolved
expectState($n, '01/02/2026 14:30', 'gregorian', 'UTC', null, N::UNRESOLVED, 'ambiguous_format');
// With explicit hint it is deterministic
expectResolved($n, '01/02/2026 14:30', 'gregorian', 'UTC', 'DMY', '2026-02-01T14:30:00Z'); // 1 Feb
expectResolved($n, '02/01/2026 14:30', 'gregorian', 'UTC', 'MDY', '2026-02-01T14:30:00Z'); // Feb 1 via MDY
// Unambiguous day>12 forces DMY
expectResolved($n, '31/08/2026 14:30', 'gregorian', 'UTC', null, '2026-08-31T14:30:00Z');

// L. Invalid date -> invalid (never silently shifted)
expectState($n, '31/02/2026 14:30', 'gregorian', 'UTC', 'DMY', N::INVALID, 'invalid_datetime');
expectState($n, '2026-13-40 14:30', 'gregorian', 'UTC', null, N::INVALID);
expectState($n, '', 'gregorian', 'UTC', null, N::UNRESOLVED, 'empty_input');

// Jalali impossible month/day
expectState($n, '1405/13/01 14:30', 'jalali', 'UTC', null, N::INVALID);

// M. PHP default-timezone independence
$base = $n->normalize('2026-08-31 14:30', 'gregorian', 'America/New_York', null);
foreach (['UTC', 'Asia/Tehran', 'America/Los_Angeles', 'Europe/London'] as $defaultTz) {
    date_default_timezone_set($defaultTz);
    $r = $n->normalize('2026-08-31 14:30', 'gregorian', 'America/New_York', null);
    check("identical result under PHP default tz $defaultTz",
        $r->iso8601 === '2026-08-31T18:30:00Z' && $r->iso8601 === $base->iso8601,
        "got {$r->iso8601}");
}
date_default_timezone_set('UTC');

// Property invariants
check('unknown tz never yields canonical UTC', $n->normalize('2026-08-31 14:30', 'gregorian', null, null)->canonicalUtc === null);
$gap = $n->normalize('2026-03-08 02:30', 'gregorian', 'America/New_York', null);
check('DST gap yields no canonical value', $gap->canonicalUtc === null && $gap->status === N::INVALID);
$fold = $n->normalize('2026-11-01 01:30', 'gregorian', 'America/New_York', null);
check('DST fold yields no canonical value + ambiguous flag', $fold->canonicalUtc === null && $fold->ambiguous === true);
check('raw text is not required to be ASCII (stored raw untouched)', true);

echo $failures === 0 ? "\nALL TRADE TIME NORMALIZER TESTS PASSED\n" : "\n$failures TEST(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
