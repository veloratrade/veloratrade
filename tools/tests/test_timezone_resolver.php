<?php

declare(strict_types=1);

/**
 * Phase 2B — deterministic TimezoneResolver foundation.
 *
 * Pure unit tests (no DB, no network): IANA validation, trust priority,
 * same-priority conflict -> unknown, and hard guarantees that UI language,
 * browser timezone, server timezone, IP, broker name alone, a bare clock, and
 * unvalidated AI guesses can NEVER establish a trusted timezone. Unknown must be
 * a first-class state (UTC is never invented).
 */

require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

use Velora\Trades\TimezoneResolver;

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($detail !== '' ? " :: $detail" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
}

$r = new TimezoneResolver();

// ---- IANA validation -----------------------------------------------------
foreach (['UTC', 'Europe/London', 'America/New_York', 'Asia/Tehran'] as $valid) {
    check("valid IANA accepted: $valid", TimezoneResolver::isValidIana($valid) === true);
}
foreach (['London', 'New York', 'GMT+3', '+03:30', 'UTC+4', 'Iran', 'browser', 'local', '', '  ', 'Europe/NotAZone', null, 42] as $invalid) {
    $label = is_string($invalid) ? ($invalid === '' ? '(empty)' : $invalid) : gettype($invalid);
    check("invalid tz rejected: $label", TimezoneResolver::isValidIana($invalid) === false);
}

// ---- Priority order ------------------------------------------------------
$res = $r->resolve([
    'explicitSource' => 'America/New_York',
    'brokerMetadata' => 'Europe/London',
    'accountConfig' => 'Asia/Tehran',
    'importConfig' => 'UTC',
]);
check('explicit source wins over all lower levels',
    $res['timezone'] === 'America/New_York' && $res['source'] === TimezoneResolver::SOURCE_EXPLICIT
    && $res['confidence'] === 'explicit' && $res['ambiguous'] === false,
    json_encode($res));

$res = $r->resolve(['brokerMetadata' => 'Europe/London', 'accountConfig' => 'Asia/Tehran']);
check('verified broker metadata beats account config',
    $res['timezone'] === 'Europe/London' && $res['source'] === TimezoneResolver::SOURCE_BROKER_METADATA,
    json_encode($res));

$res = $r->resolve(['accountConfig' => 'Asia/Tehran', 'importConfig' => 'UTC']);
check('account config beats import config',
    $res['timezone'] === 'Asia/Tehran' && $res['source'] === TimezoneResolver::SOURCE_ACCOUNT_CONFIG,
    json_encode($res));

$res = $r->resolve(['importConfig' => 'UTC']);
check('import config usable when higher levels absent',
    $res['timezone'] === 'UTC' && $res['source'] === TimezoneResolver::SOURCE_IMPORT_CONFIG,
    json_encode($res));

// ---- Unresolved stays unresolved ----------------------------------------
$res = $r->resolve([]);
check('no evidence -> first-class unknown (no fabricated UTC)',
    $res['timezone'] === null && $res['source'] === TimezoneResolver::SOURCE_UNKNOWN
    && $res['confidence'] === 'unknown' && $res['ambiguous'] === false,
    json_encode($res));

$res = $r->resolve(['explicitSource' => 'London', 'accountConfig' => 'Europe/London']);
check('invalid explicit label ignored; falls to valid account config',
    $res['timezone'] === 'Europe/London' && $res['source'] === TimezoneResolver::SOURCE_ACCOUNT_CONFIG,
    json_encode($res));

$res = $r->resolve(['explicitSource' => 'GMT+3']);
check('bare fixed offset is not trusted -> unknown',
    $res['timezone'] === null && $res['source'] === TimezoneResolver::SOURCE_UNKNOWN,
    json_encode($res));

// ---- Same-priority conflict -> ambiguous/unknown (no arbitrary pick) ------
$res = $r->resolve(['explicitSource' => ['Europe/London', 'America/New_York']]);
check('conflicting same-priority explicit zones -> unresolved/ambiguous',
    $res['timezone'] === null && $res['source'] === TimezoneResolver::SOURCE_UNKNOWN && $res['ambiguous'] === true,
    json_encode($res));

// ---- Inference is never auto-promoted ------------------------------------
$res = $r->resolve(['inferred' => 'Europe/London']);
check('inferred_pending alone is never trusted -> unknown',
    $res['timezone'] === null && $res['source'] === TimezoneResolver::SOURCE_UNKNOWN,
    json_encode($res));

$res = $r->resolve(['inferred' => 'Europe/London', 'accountConfig' => 'Asia/Tehran']);
check('configured zone wins; inference never overrides',
    $res['timezone'] === 'Asia/Tehran' && $res['source'] === TimezoneResolver::SOURCE_ACCOUNT_CONFIG,
    json_encode($res));

// ---- Forbidden sources are simply not inputs ------------------------------
// The resolver API has no parameter for locale/browser/server/IP/clock, so they
// can never influence the result. Demonstrate passing them is ignored.
$res = $r->resolve([
    'uiLocale' => 'fa',
    'browserTimezone' => 'Asia/Tehran',
    'serverTimezone' => 'UTC',
    'ipCountry' => 'IR',
    'brokerName' => 'Some Broker',
    'bareClock' => '14:30',
]);
check('locale/browser/server/IP/broker-name/clock are not timezone sources',
    $res['timezone'] === null && $res['source'] === TimezoneResolver::SOURCE_UNKNOWN,
    json_encode($res));

echo $failures === 0 ? "\nALL TIMEZONE RESOLVER TESTS PASSED\n" : "\n$failures TEST(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
