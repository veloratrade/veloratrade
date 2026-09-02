<?php

declare(strict_types=1);

/**
 * Phase 3 — Trade Time & Market Session Infrastructure.
 *
 * Tests (pure, no external services):
 *   - JalaliCalendar: gregorian<->jalali, round-trip, leap, tz-independence.
 *   - TradingSessionEngine: unconfigured (NO invented windows), configured
 *     IANA windows, DST boundary, cross-midnight, overlap, outside, weekday
 *     filter, unresolved/invalid input, config validation (EST rejected),
 *     tz-independence under 4 PHP default zones.
 *   - TradeDisplayService: same UTC instant rendered in different tz +
 *     calendar; en/gregorian vs fa/jalali SAME instant; invalid tz; null.
 *   - Security/forbidden inference: engine/display never read users.timezone
 *     as source, browser/server tz, locale; no strtotime/gmdate in new code.
 *   - API serialization: session block present & derived; canonical additive.
 *
 * NO MetaApi is touched. NO session windows are hardcoded as product truth;
 * configured windows in this test are explicit fixtures to exercise the
 * engine, not shipped defaults.
 */

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($detail !== '' ? " :: $detail" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
}

$root = sys_get_temp_dir() . '/velora-p3-' . bin2hex(random_bytes(5));
@mkdir($root . '/config', 0700, true);
@mkdir($root . '/data', 0700, true);
@mkdir($root . '/logs', 0700, true);
$dbPath = $root . '/data/velora.sqlite';
putenv('APP_ENV=local');
putenv('VELORA_PRIVATE_ROOT=' . $root);
putenv('VELORA_DOCUMENT_ROOT=' . dirname(__DIR__, 2));
file_put_contents($root . '/config/velora.env', implode("\n", [
    'APP_ENV=local', 'APP_DEBUG=true', 'DB_DRIVER=sqlite', 'DB_DATABASE=' . $dbPath,
    'JWT_SECRET=' . str_repeat('j', 48), 'APP_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)),
    'CORS_ALLOWED_ORIGINS=http://localhost', 'FRONTEND_URL=http://localhost', 'MAIL_DRIVER=log',
]) . "\n");

require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

use Velora\Trades\JalaliCalendar;
use Velora\Trades\SessionWindow;
use Velora\Trades\TradingSessionEngine;
use Velora\Trades\TradeDisplayService;
use Velora\Core\Database;
use Velora\Trades\TradeService;

// ---------- JalaliCalendar ----------
[$jy, $jm, $jd] = JalaliCalendar::gregorianToJalali(2026, 8, 31);
check('J1 gregorian->jalali 2026-08-31 = 1405/06/09', "$jy/$jm/$jd" === '1405/6/9', "$jy/$jm/$jd");
[$gy, $gm, $gd] = JalaliCalendar::jalaliToGregorian(1405, 6, 9);
check('J2 jalali->gregorian 1405/06/09 = 2026-08-31', "$gy-$gm-$gd" === '2026-8-31', "$gy-$gm-$gd");

// Round-trip a wide range.
$rtOk = true;
for ($y = 2020; $y <= 2030; $y++) {
    foreach ([3, 6, 9, 12] as $m) {
        $dim = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][$m - 1];
        foreach ([5, 20] as $d) {
            [$a, $b, $c] = JalaliCalendar::gregorianToJalali($y, $m, min($d, $dim));
            [$p, $q, $r] = JalaliCalendar::jalaliToGregorian($a, $b, $c);
            if ($p !== $y || $q !== $m || $r !== min($d, $dim)) {
                $rtOk = false;
            }
        }
    }
}
check('J3 gregorian<->jalali round-trips 2020..2030', $rtOk);

// Leap-year known dates: 2021-03-21 is Jalali new year 1400/01/01.
[$ly, $lm, $ld] = JalaliCalendar::gregorianToJalali(2021, 3, 21);
check('J4 2021-03-21 = 1400/01/01 (Nowruz)', "$ly/$lm/$ld" === '1400/1/1', "$ly/$lm/$ld");

// Jalali leap day: 1403 is leap -> 1403/12/30 exists; 1404 month12 = 29.
check('J5 isJalaliLeap(1403)=true', JalaliCalendar::isJalaliLeap(1403) === true);
check('J6 isJalaliLeap(1404)=false', JalaliCalendar::isJalaliLeap(1404) === false);

// format helper
check('J7 formatJalaliDate padded', JalaliCalendar::formatJalaliDate(2026, 8, 31) === '1405/06/09', JalaliCalendar::formatJalaliDate(2026, 8, 31));

// Calendar conversion is tz-independent (operates on components).
$same = true;
$baseline = null;
foreach (['UTC', 'Europe/London', 'America/New_York', 'Asia/Tehran'] as $z) {
    date_default_timezone_set($z);
    $v = JalaliCalendar::formatJalaliDate(2026, 8, 31);
    $baseline ??= $v;
    $same = $same && ($v === $baseline);
}
check('J8 jalali conversion independent of PHP default tz', $same);
date_default_timezone_set('UTC');

// ---------- TradingSessionEngine: unconfigured ----------
$empty = new TradingSessionEngine();
check('S1 unconfigured: hasConfiguredWindows()=false', $empty->hasConfiguredWindows() === false);
$r = $empty->classify('2026-08-31 13:00:00');
check('S2 unconfigured engine returns unconfigured (NO invented windows)', $r['status'] === 'unconfigured' && $r['sessions'] === [], $r['status']);
check('S3 unconfigured still derives hourUtc/dayOfWeekUtc', $r['hourUtc'] === 13 && $r['dayOfWeekUtc'] === 1, "h={$r['hourUtc']} dow={$r['dayOfWeekUtc']}");
check('S4 unresolved canonical (null) -> unresolved', ($empty->classify(null)['status']) === 'unresolved');
check('S5 invalid canonical -> invalid', ($empty->classify('not-a-date')['status']) === 'invalid');
check('S6 engine version exposed', $empty->engineVersion() === TradingSessionEngine::ENGINE_VERSION);

// ---------- Configured engine fixtures (NOT shipped defaults) ----------
// Monday..Friday windows defined in their reference IANA zone, local time.
$engine = new TradingSessionEngine([
    ['id' => 'london', 'label' => 'London', 'timezone' => 'Europe/London', 'start' => '08:00', 'end' => '16:30', 'daysOfWeek' => [1, 2, 3, 4, 5]],
    ['id' => 'newyork', 'label' => 'New York', 'timezone' => 'America/New_York', 'start' => '08:00', 'end' => '17:00', 'daysOfWeek' => [1, 2, 3, 4, 5]],
]);
check('S7 configured: has windows', $engine->hasConfiguredWindows() && $engine->windowCount() === 2);

// 2026-08-31 is Monday. 12:00Z -> London 13:00 BST (open), NY 08:00 EDT (open).
$ov = $engine->classify('2026-08-31 12:00:00');
$ids = array_map(fn($s) => $s['id'], $ov['sessions']);
check('S8 overlap 12:00Z opens both london+newyork', $ov['status'] === 'open' && $ids === ['london', 'newyork'], implode(',', $ids));

// Boundary: London opens 08:00 BST = 07:00Z -> at exactly 07:00Z open; 06:59 closed.
check('S9 London open boundary 07:00Z (DST summer)', in_array('london', array_map(fn($s) => $s['id'], $engine->classify('2026-08-31 07:00:00')['sessions']), true));
check('S10 London closed just before open 06:59Z', !in_array('london', array_map(fn($s) => $s['id'], $engine->classify('2026-08-31 06:59:00')['sessions']), true));

// DST WINTER: London 08:00 GMT = 08:00Z (not 07:00Z). 2026-01-05 Monday.
$win = $engine->classify('2026-01-05 08:15:00');
check('S11 winter London open at 08:15Z (GMT, DST-correct)', in_array('london', array_map(fn($s) => $s['id'], $win['sessions']), true));
$winBefore = $engine->classify('2026-01-05 07:59:00');
check('S12 winter London closed at 07:59Z (would be wrong if fixed UTC offset)', !in_array('london', array_map(fn($s) => $s['id'], $winBefore['sessions']), true));

// New York summer: 08:00 EDT = 12:00Z (handled in S8). NY winter 08:00 EST = 13:00Z.
$nyw = $engine->classify('2026-01-05 13:15:00');
check('S13 winter NY open 13:15Z (EST)', in_array('newyork', array_map(fn($s) => $s['id'], $nyw['sessions']), true));

// Weekday filter: Sunday 2026-08-30 12:00Z -> outside.
$sunday = $engine->classify('2026-08-30 12:00:00');
check('S14 Sunday outside weekday windows', $sunday['status'] === 'outside' && $sunday['sessions'] === [], $sunday['status']);

// Outside-hours: Monday 03:00Z -> both closed (London 04:00 BST, NY 23:00 prev day).
$out = $engine->classify('2026-08-31 03:00:00');
check('S15 03:00Z outside both', $out['status'] === 'outside' && $out['sessions'] === [], $out['status']);

// Cross-midnight window: NY 22:00 -> 02:00 local. 03:00Z = NY 23:00 (in).
$cross = new TradingSessionEngine([
    ['id' => 'late', 'label' => 'Late', 'timezone' => 'America/New_York', 'start' => '22:00', 'end' => '02:00'],
]);
$cx = $cross->classify('2026-08-31 03:00:00');
check('S16 cross-midnight: 03:00Z (NY 23:00) inside late', in_array('late', array_map(fn($s) => $s['id'], $cx['sessions']), true));
$cx2 = $cross->classify('2026-08-31 07:30:00'); // NY 03:30 -> after 02:00 close, outside
check('S17 cross-midnight: 07:30Z (NY 03:30) outside late', !in_array('late', array_map(fn($s) => $s['id'], $cx2['sessions']), true), $cx2['status']);

// Config validation: abbreviation EST must be rejected (not an IANA zone).
$estRejected = false;
try {
    SessionWindow::fromArray(['id' => 'x', 'label' => 'X', 'timezone' => 'EST', 'start' => '08:00', 'end' => '16:00']);
} catch (\Throwable) {
    $estRejected = true;
}
check('S18 window with EST timezone rejected', $estRejected);

$badMinute = false;
try {
    SessionWindow::fromArray(['id' => 'x', 'timezone' => 'Europe/London', 'start' => '25:00', 'end' => '16:00']);
} catch (\Throwable) {
    $badMinute = true;
}
check('S19 out-of-range time rejected', $badMinute);

$equalRejected = false;
try {
    new SessionWindow('x', 'X', 'Europe/London', 480, 480);
} catch (\Throwable) {
    $equalRejected = true;
}
check('S20 start==end rejected', $equalRejected);

// DST gap/fold inputs are canonical UTC -> never ambiguous (proves no fold/gap).
// The engine compares UTC epochs; a gap instant in a zone simply isn't in the
// window. (Gap/fold only matter in source->canonical, covered by 2C.)

// TZ-inependence: same instant classified identically under 4 PHP defaults.
$exp = null; $tzOk = true;
foreach (['UTC', 'Europe/London', 'America/New_York', 'Asia/Tehran'] as $z) {
    date_default_timezone_set($z);
    $v = $engine->classify('2026-08-31 12:00:00');
    $key = json_encode([$v['status'], array_map(fn($s) => $s['id'], $v['sessions']), $v['hourUtc'], $v['dayOfWeekUtc']]);
    $exp ??= $key;
    $tzOk = $tzOk && ($key === $exp);
}
check('S21 session classification independent of PHP default tz', $tzOk);
date_default_timezone_set('UTC');

// ---------- TradeDisplayService ----------
$disp = new TradeDisplayService();

// Same UTC instant in two display timezones: instant (iso8601) identical.
$london = $disp->formatInstant('2026-08-31 11:00:00', 'Europe/London', 'gregorian');
$tehran = $disp->formatInstant('2026-08-31 11:00:00', 'Asia/Tehran', 'jalali');
check('D1 same instant iso8601 regardless of display tz/calendar', $london['iso8601'] === $tehran['iso8601'] && $london['iso8601'] === '2026-08-31T11:00:00Z');
check('D2 London display wall (BST) = 12:00', $london['date'] === '2026-08-31' && $london['time'] === '12:00', "{$london['date']} {$london['time']}");
check('D3 Tehran display wall = 14:30 + Jalali date 1405/06/09', $tehran['time'] === '14:30' && $tehran['date'] === '1405/06/09', "{$tehran['date']} {$tehran['time']}");

// English/Gregorian vs Persian/Jalali SAME instant, different presentation.
$en = $disp->formatInstant('2026-08-31 11:00:00', 'UTC', 'gregorian');
$fa = $disp->formatInstant('2026-08-31 11:00:00', 'Asia/Tehran', 'jalali');
check('D4 EN/gregorian vs FA/jalali represent same UTC instant', $en['iso8601'] === $fa['iso8601']);
check('D5 gregorian date correct at UTC', $en['date'] === '2026-08-31');
check('D6 jalali date differs but maps to same day', $fa['date'] === '1405/06/09');

// Locale independence: display calendar is explicit, NOT derived from language.
// Asking jalali with an English tz still gives jalali; gregorian with Tehran tz still gregorian.
$gCal = $disp->formatInstant('2026-08-31 11:00:00', 'Asia/Tehran', 'gregorian');
$jCal = $disp->formatInstant('2026-08-31 11:00:00', 'UTC', 'jalali');
check('D7 calendar is explicit: Tehran tz + gregorian = gregorian', $gCal['calendar'] === 'gregorian' && $gCal['date'] === '2026-08-31');
check('D8 calendar is explicit: UTC tz + jalali = jalali', $jCal['calendar'] === 'jalali' && $jCal['date'] === '1405/06/09');

// Invalid/unknown display tz -> explicit status, never PHP default fallback.
$badTz = $disp->formatInstant('2026-08-31 11:00:00', 'EST', 'gregorian');
check('D9 invalid display tz rejected (no server-tz fallback)', $badTz['available'] === false && $badTz['status'] === 'invalid_timezone');

// Null canonical -> unavailable (do NOT display legacy as UTC).
$nullDisp = $disp->formatInstant(null, 'UTC', 'gregorian');
check('D10 null canonical -> unavailable, no fabricated instant', $nullDisp['available'] === false && $nullDisp['iso8601'] === null);

// Display is DST-aware: London Jan 08:00Z displays 08:00 (GMT), Aug 08:00Z -> 09:00 (BST).
$winDisp = $disp->formatInstant('2026-01-05 08:00:00', 'Europe/London', 'gregorian');
$sumDisp = $disp->formatInstant('2026-08-31 08:00:00', 'Europe/London', 'gregorian');
check('D11 DST-aware London winter 08:00Z->08:00', $winDisp['time'] === '08:00', $winDisp['time']);
check('D12 DST-aware London summer 08:00Z->09:00', $sumDisp['time'] === '09:00', $sumDisp['time']);

// Display tz-independence under PHP default zones.
$dispOk = true; $dispBase = null;
foreach (['UTC', 'Europe/London', 'America/New_York', 'Asia/Tehran'] as $z) {
    date_default_timezone_set($z);
    $v = $disp->formatInstant('2026-08-31 11:00:00', 'Asia/Tehran', 'jalali');
    $k = $v['date'] . '|' . $v['time'] . '|' . $v['iso8601'];
    $dispBase ??= $k;
    $dispOk = $dispOk && ($k === $dispBase);
}
check('D13 display output independent of PHP default tz', $dispOk);
date_default_timezone_set('UTC');

// forTrade block: resolved vs unresolved + legacy fallback flag.
$block = $disp->forTrade(['occurred_open_at_utc' => '2026-08-31 11:00:00', 'occurred_close_at_utc' => null, 'time_status' => 'partial', 'open_time' => 'X', 'close_time' => 'Y'], 'UTC', 'gregorian');
check('D14 forTrade resolved open available', $block['open']['available'] === true);
check('D15 forTrade unresolved close unavailable but legacy exposed', $block['close']['available'] === false && $block['legacyFallback']['closeTime'] === 'Y');
check('D16 forTrade canonicalAvailable true when one side resolved', $block['canonicalAvailable'] === true);

// ---------- API serialization: derived session block ----------
$pdo = Database::connection();
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, timezone TEXT DEFAULT 'UTC', locale TEXT DEFAULT 'fa')");
$pdo->exec("CREATE TABLE trading_accounts (
    id INTEGER PRIMARY KEY, user_id INTEGER, provider TEXT NOT NULL, platform TEXT NOT NULL,
    broker TEXT, server TEXT, timezone TEXT, timezone_source TEXT NOT NULL DEFAULT 'unknown',
    mt_login TEXT, account_type TEXT NOT NULL DEFAULT 'STANDARD',
    metaapi_account_id TEXT, sync_status TEXT NOT NULL DEFAULT 'DISCONNECTED',
    last_synced_at TEXT, disconnected_at TEXT, connection_credentials_encrypted TEXT,
    connected_at TEXT, auto_sync_enabled INTEGER NOT NULL DEFAULT 1,
    last_incremental_at TEXT, connection_checked_at TEXT, consecutive_errors INTEGER NOT NULL DEFAULT 0,
    last_error TEXT, starting_balance TEXT NOT NULL DEFAULT '0.00', current_balance TEXT NOT NULL DEFAULT '0.00',
    label TEXT NOT NULL DEFAULT '', account_number_masked TEXT NOT NULL DEFAULT '',
    currency TEXT NOT NULL DEFAULT 'USD', leverage TEXT, status TEXT NOT NULL DEFAULT 'disconnected',
    balance TEXT NOT NULL DEFAULT '0.00', equity TEXT NOT NULL DEFAULT '0.00',
    created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE trades (
    id INTEGER PRIMARY KEY, user_id INTEGER, account_id INTEGER, external_deal_id TEXT,
    symbol TEXT, direction TEXT, entry_price TEXT, exit_price TEXT, volume TEXT,
    contract_size TEXT DEFAULT '1', commission TEXT DEFAULT '0', swap TEXT DEFAULT '0',
    profit_loss TEXT, r_multiple TEXT, stop_loss TEXT, take_profit TEXT,
    open_time TEXT NOT NULL, close_time TEXT NOT NULL,
    occurred_open_at_utc TEXT, occurred_close_at_utc TEXT,
    time_status TEXT NOT NULL DEFAULT 'unresolved',
    source_timezone TEXT, source_timezone_source TEXT NOT NULL DEFAULT 'unknown',
    source_calendar TEXT NOT NULL DEFAULT 'unknown',
    raw_open_text TEXT, raw_close_text TEXT,
    strategy_tag TEXT, emotional_score INTEGER, notes TEXT, source TEXT NOT NULL DEFAULT 'manual',
    created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("INSERT INTO users (id, email) VALUES (1, 'p3@example.test')");

$svc = new TradeService();
$created = $svc->create([
    'symbol' => 'EURUSD', 'direction' => 'buy', 'entryPrice' => '1.08', 'exitPrice' => '1.09',
    'volume' => '0.1', 'openTime' => '2026-08-31T14:30', 'closeTime' => '2026-08-31T15:42',
    'rawOpenText' => '2026-08-31 14:30', 'rawCloseText' => '2026-08-31 15:42',
    'sourceCalendar' => 'gregorian', 'dateFormat' => 'YYYY-MM-DD',
    'timezoneOffsetHintMinutes' => 210,
], 1);
$s = $svc->serialize($created);
check('API1 serialize includes session block', isset($s['session']) && is_array($s['session']));
check('API2 session derived from canonical UTC (unconfigured => unconfigured)', ($s['session']['status'] ?? '') === 'unconfigured', $s['session']['status'] ?? '');
check('API3 canonical fields present additively', isset($s['occurredOpenAtUtc'], $s['timeStatus'], $s['sourceCalendar']));
check('API4 legacy openTime/closeTime still present', isset($s['openTime'], $s['closeTime']));

// ---------- Security / forbidden inference (static + behavioral) ----------
$newFiles = [
    '/tmp/velora-audit/api/src/Trades/JalaliCalendar.php',
    '/tmp/velora-audit/api/src/Trades/TradingSessionEngine.php',
    '/tmp/velora-audit/api/src/Trades/SessionWindow.php',
    '/tmp/velora-audit/api/src/Trades/TradeDisplayService.php',
];
$forbiddenPattern = '/\b(strtotime|gmdate|date_default_timezone_set)\s*\(/';
$clean = true;
foreach ($newFiles as $f) {
    foreach (file($f) as $i => $line) {
        // Strip comments.
        $code = preg_replace('/(^|\s)(\/\/|#|\/\*|\*).*$/', '', $line);
        if (preg_match($forbiddenPattern, $code)) {
            $clean = false;
            echo "   forbidden call in $f:$i: " . trim($line) . "\n";
        }
    }
}
check('SEC1 no strtotime/gmdate/date_default_timezone_set in new code (code lines)', $clean);

// users.timezone is never read as a SOURCE: engine/display only accept explicit
// arguments; there is no DB/user lookup in these classes.
$codeOnly = static function (string $path): string {
    $out = '';
    foreach (file($path) as $line) {
        $out .= preg_replace('~(^|\s)(?://|#).*~', '', $line);
    }
    return preg_replace('~/\*.*?\*/~s', '', $out);
};
$displayCode = $codeOnly('/tmp/velora-audit/api/src/Trades/TradeDisplayService.php');
$engineCode = $codeOnly('/tmp/velora-audit/api/src/Trades/TradingSessionEngine.php');
check('SEC2 executable code never queries users table / AccountRepository / Database for tz',
    !preg_match('/\busers\b|AccountRepository|Database::connection/', $displayCode . $engineCode));
check('SEC3 executable engine has no symbol/broker/locale/browser inference',
    !preg_match('/\b(symbol|broker|locale|browser)\b/i', $engineCode));

echo "\n" . ($failures === 0 ? "ALL PASS" : "$failures FAIL") . "\n";
exit($failures === 0 ? 0 : 1);
