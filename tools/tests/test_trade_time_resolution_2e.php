<?php

declare(strict_types=1);

/**
 * Phase 2E — Evidence → TimezoneResolver → TradeTimeNormalizer → canonical
 * occurred_*_utc → persistence, with open/close independence.
 *
 * Pure-service cases (no DB) + full persistence cases through TradeService +
 * TradeRepository on SQLite. Runs under multiple PHP default timezones to
 * prove the resolution is TZ-INDEPENDENT (same evidence → same UTC).
 *
 * Rules asserted: AI is never canonical authority; unresolved stays NULL and
 * never fabricates UTC; invalid is a hard error (never downgraded); bare tz
 * text / broker name / symbol / users.timezone are never timezone sources;
 * explicit offset never becomes an IANA zone; legacy open_time/close_time are
 * never reinterpreted; no backfill.
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

// ---- Minimal environment (SQLite) ----
$root = sys_get_temp_dir() . '/velora-tz-2e-' . bin2hex(random_bytes(5));
@mkdir($root . '/config', 0700, true);
@mkdir($root . '/data', 0700, true);
@mkdir($root . '/logs', 0700, true);
$dbPath = $root . '/data/velora.sqlite';
putenv('APP_ENV=local');
putenv('VELORA_PRIVATE_ROOT=' . $root);
putenv('VELORA_DOCUMENT_ROOT=' . dirname(__DIR__, 2));
file_put_contents($root . '/config/velora.env', implode("\n", [
    'APP_ENV=local',
    'APP_DEBUG=true',
    'DB_DRIVER=sqlite',
    'DB_DATABASE=' . $dbPath,
    'JWT_SECRET=' . str_repeat('j', 48),
    'APP_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)),
    'CORS_ALLOWED_ORIGINS=http://localhost',
    'FRONTEND_URL=http://localhost',
    'MAIL_DRIVER=log',
]) . "\n");

require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

use Velora\Accounts\AccountRepository;
use Velora\Core\Exceptions\ValidationException;
use Velora\Core\Database;
use Velora\Trades\TradeService;
use Velora\Trades\TradeTimeResolutionService;
use Velora\Trades\TimezoneResolver;

$pdo = Database::connection();
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, timezone TEXT DEFAULT 'UTC', locale TEXT DEFAULT 'fa')");
$pdo->exec("CREATE TABLE trading_accounts (
    id INTEGER PRIMARY KEY, user_id INTEGER, provider TEXT NOT NULL, platform TEXT NOT NULL,
    broker TEXT, server TEXT, timezone TEXT, timezone_source TEXT NOT NULL DEFAULT 'unknown',
    mt_login TEXT, account_type TEXT NOT NULL DEFAULT 'STANDARD',
    metaapi_account_id TEXT, sync_status TEXT NOT NULL DEFAULT 'DISCONNECTED',
    last_synced_at TEXT, disconnected_at TEXT,
    connection_credentials_encrypted TEXT, connected_at TEXT, auto_sync_enabled INTEGER NOT NULL DEFAULT 1,
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
    strategy_tag TEXT, emotional_score INTEGER, notes TEXT,
    source TEXT NOT NULL DEFAULT 'manual',
    created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE trade_exits (
    id INTEGER PRIMARY KEY, trade_id INTEGER, exit_price TEXT, volume TEXT,
    pnl TEXT, exited_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("INSERT INTO users (id, email, timezone) VALUES (1, 'tz2e@example.test', 'America/New_York')");

$svc = new TradeTimeResolutionService();
$tradeService = new TradeService();
$accounts = new AccountRepository();

// =====================================================================
// PURE-SERVICE CASES
// =====================================================================

// 1. Explicit numeric offset resolves WITHOUT an IANA zone (UTC+03:30).
$r = $svc->resolve([
    'rawOpenText' => '2026-08-31 14:30',
    'rawCloseText' => '2026-08-31 15:42',
    'sourceCalendar' => 'gregorian',
    'dateFormat' => 'YYYY-MM-DD',
    'timezoneOffsetHintMinutes' => 210,
]);
check('1 explicit-offset resolves to UTC', $r['occurred_open_at_utc'] === '2026-08-31 11:00:00', $r['occurred_open_at_utc'] ?? 'null');
check('1 offset does NOT set an IANA source_timezone', $r['source_timezone'] === null, var_export($r['source_timezone'], true));
check('1 status resolved', $r['time_status'] === 'resolved', $r['time_status']);

// 2. Account IANA zone resolves (Europe/London, summer BST = +01:00).
$r = $svc->resolve([
    'rawOpenText' => '2026-08-31 14:30',
    'sourceCalendar' => 'gregorian',
    'dateFormat' => 'YYYY-MM-DD',
    'accountTimezone' => 'Europe/London',
    'accountTimezoneSource' => 'user_config',
]);
check('2 account-IANA resolves London summer 14:30 -> 13:30Z', $r['occurred_open_at_utc'] === '2026-08-31 13:30:00', $r['occurred_open_at_utc'] ?? 'null');
check('2 source_timezone is the IANA zone', $r['source_timezone'] === 'Europe/London');
check('2 provenance reflects account source (user_config)', $r['source_timezone_source'] === 'user_config', $r['source_timezone_source']);

// 3. No tz at all -> unresolved + NULL.
$r = $svc->resolve([
    'rawOpenText' => '2026-08-31 14:30',
    'rawCloseText' => '2026-08-31 15:42',
    'sourceCalendar' => 'gregorian',
    'dateFormat' => 'YYYY-MM-DD',
]);
check('3 no-tz open unresolved NULL', $r['occurred_open_at_utc'] === null);
check('3 no-tz close unresolved NULL', $r['occurred_close_at_utc'] === null);
check('3 status unresolved', $r['time_status'] === 'unresolved', $r['time_status']);

// 4. Jalali + trusted IANA tz resolves (1405/06/09 -> 2026-08-31; Tehran).
$r = $svc->resolve([
    'rawOpenText' => '1405/06/09 14:30',
    'sourceCalendar' => 'jalali',
    'dateFormat' => 'YYYY/MM/DD',
    'accountTimezone' => 'Asia/Tehran',
]);
check('4 Jalali+Tehran 14:30 -> 2026-08-31 11:00Z', $r['occurred_open_at_utc'] === '2026-08-31 11:00:00', $r['occurred_open_at_utc'] ?? 'null');
check('4 calendar recorded jalali', $r['source_calendar'] === 'jalali');

// 5. Ambiguous day/month order -> unresolved.
$r = $svc->resolve([
    'rawOpenText' => '03/04/2026 14:30',
    'sourceCalendar' => 'gregorian',
    'dateFormat' => 'unknown',
    'accountTimezone' => 'Europe/London',
]);
check('5 ambiguous format unresolved NULL', $r['occurred_open_at_utc'] === null, $r['open_reason'] ?? '');
check('5 status unresolved', $r['time_status'] === 'unresolved');

// 6. Invalid date (31 Feb) -> invalid verdict (not unresolved).
$r = $svc->resolve([
    'rawOpenText' => '2026-02-31 14:30',
    'sourceCalendar' => 'gregorian',
    'dateFormat' => 'YYYY-MM-DD',
    'accountTimezone' => 'Europe/London',
]);
check('6 impossible date invalid', $r['open_valid'] === false && $r['occurred_open_at_utc'] === null, $r['open_reason'] ?? '');

// 7. DST gap (spring forward) -> invalid. America/New_York 2026-03-08 02:30.
$r = $svc->resolve([
    'rawOpenText' => '2026-03-08 02:30',
    'sourceCalendar' => 'gregorian',
    'dateFormat' => 'YYYY-MM-DD',
    'accountTimezone' => 'America/New_York',
]);
check('7 DST gap invalid', $r['open_valid'] === false, $r['open_reason'] ?? '');

// 8. DST fold WITHOUT offset -> unresolved/ambiguous. NY 2026-11-01 01:30.
$r = $svc->resolve([
    'rawOpenText' => '2026-11-01 01:30',
    'sourceCalendar' => 'gregorian',
    'dateFormat' => 'YYYY-MM-DD',
    'accountTimezone' => 'America/New_York',
]);
check('8 DST fold no-offset unresolved NULL', $r['occurred_open_at_utc'] === null, $r['open_reason'] ?? '');

// 9. DST fold WITH explicit offset -> resolved (pinned instant).
$r = $svc->resolve([
    'rawOpenText' => '2026-11-01 01:30',
    'sourceCalendar' => 'gregorian',
    'dateFormat' => 'YYYY-MM-DD',
    'timezoneOffsetHintMinutes' => -300, // EST (UTC-5), the second occurrence
]);
check('9 fold+offset resolved', $r['occurred_open_at_utc'] === '2026-11-01 06:30:00', $r['occurred_open_at_utc'] ?? 'null');

// 10. Persian digits preserved as raw but parse via Jalali.
$r = $svc->resolve([
    'rawOpenText' => '۱۴۰۵/۰۶/۰۹ ۱۴:۳۰',
    'sourceCalendar' => 'jalali',
    'dateFormat' => 'YYYY/MM/DD',
    'accountTimezone' => 'Asia/Tehran',
]);
check('10 Persian digits raw preserved', $r['raw_open_text'] === '۱۴۰۵/۰۶/۰۹ ۱۴:۳۰');
check('10 Persian digits resolve UTC', $r['occurred_open_at_utc'] === '2026-08-31 11:00:00', $r['occurred_open_at_utc'] ?? 'null');

// 11. Arabic-Indic digits preserved raw + parse.
$r = $svc->resolve([
    'rawOpenText' => '٢٠٢٦-٠٨-٣١ ١٤:٣٠',
    'sourceCalendar' => 'gregorian',
    'dateFormat' => 'YYYY-MM-DD',
    'timezoneOffsetHintMinutes' => 210,
]);
check('11 Arabic digits raw preserved', $r['raw_open_text'] === '٢٠٢٦-٠٨-٣١ ١٤:٣٠');
check('11 Arabic digits resolve UTC', $r['occurred_open_at_utc'] === '2026-08-31 11:00:00', $r['occurred_open_at_utc'] ?? 'null');

// 12. fa/en UI locale is NOT evidence — locale absent entirely; same input no
//     tz must stay unresolved regardless. (Service has no locale input.)
$r = $svc->resolve([
    'rawOpenText' => '2026-08-31 14:30',
    'sourceCalendar' => 'unknown',   // unknown calendar from a /fa/ UI must NOT imply Jalali
    'dateFormat' => 'YYYY-MM-DD',
]);
check('12 unknown calendar YMD gregorian resolves only with tz; no tz -> NULL', $r['occurred_open_at_utc'] === null);

// 13. users.timezone is never a source: service has no userTz key; prove that
//     passing a "user" tz as account does not happen at call site (persistence
//     test below sets users.timezone=NY yet a tz-less trade stays unresolved).
//     Here: bare America/New_York passed as brokerName-like text is ignored.
$r = $svc->resolve([
    'rawOpenText' => '2026-08-31 14:30',
    'sourceCalendar' => 'gregorian',
    'dateFormat' => 'YYYY-MM-DD',
    'timezoneText' => 'New York (display profile)',
]);
check('13 users/display tz not a source -> NULL', $r['occurred_open_at_utc'] === null);

// 14. broker name alone is not a source.
$r = $svc->resolve([
    'rawOpenText' => '2026-08-31 14:30',
    'sourceCalendar' => 'gregorian',
    'dateFormat' => 'YYYY-MM-DD',
    'timezoneText' => 'Pepperstone',
]);
check('14 broker-name-alone unresolved NULL', $r['occurred_open_at_utc'] === null && $r['source_timezone'] === null);

// 15. bare clock / abbreviation "EST" is NOT auto-mapped to America/New_York.
$r = $svc->resolve([
    'rawOpenText' => '2026-08-31 14:30',
    'sourceCalendar' => 'gregorian',
    'dateFormat' => 'YYYY-MM-DD',
    'timezoneText' => 'EST',
]);
check('15 EST text not auto-mapped -> NULL', $r['occurred_open_at_utc'] === null, $r['open_reason'] ?? '');
check('15 EST provenance unknown', $r['source_timezone_source'] === 'unknown');

// 16. AI openTime hint must NOT override raw evidence: service only reads
//     raw* keys; feed an openTime-style value under a decoy and confirm it is
//     ignored while raw is authoritative.
$r = $svc->resolve([
    'rawOpenText' => '2026-08-31 14:30',
    'sourceCalendar' => 'gregorian',
    'dateFormat' => 'YYYY-MM-DD',
    'accountTimezone' => 'Europe/London',
    // decoy: an AI "normalized" value the service must never read.
    'openTime' => '1999-01-01T00:00:00Z',
]);
check('16 AI openTime decoy ignored; raw wins -> 13:30Z', $r['occurred_open_at_utc'] === '2026-08-31 13:30:00', $r['occurred_open_at_utc'] ?? 'null');

// 17. Open/close independence: resolved open + unresolved close (no close raw).
$r = $svc->resolve([
    'rawOpenText' => '2026-08-31 14:30',
    'rawCloseText' => null,
    'sourceCalendar' => 'gregorian',
    'dateFormat' => 'YYYY-MM-DD',
    'accountTimezone' => 'Europe/London',
]);
check('17 open resolved', $r['occurred_open_at_utc'] === '2026-08-31 13:30:00');
check('17 close NULL (not fabricated)', $r['occurred_close_at_utc'] === null);
check('17 status partial', $r['time_status'] === 'partial', $r['time_status']);

// 18. Resolution priority: explicit > account.
$r = $svc->resolve([
    'rawOpenText' => '2026-08-31 14:30',
    'sourceCalendar' => 'gregorian',
    'dateFormat' => 'YYYY-MM-DD',
    'explicitIana' => 'Asia/Tehran',
    'explicitIanaSource' => 'explicit_source',
    'accountTimezone' => 'America/New_York',
]);
check('18 explicit beats account -> Tehran 11:00Z', $r['occurred_open_at_utc'] === '2026-08-31 11:00:00', $r['occurred_open_at_utc'] ?? 'null');
check('18 provenance explicit_source', $r['source_timezone_source'] === 'explicit_source');

// 19. Offset out of range hint is dropped -> falls back to tz if present.
$r = $svc->resolve([
    'rawOpenText' => '2026-08-31 14:30',
    'sourceCalendar' => 'gregorian',
    'dateFormat' => 'YYYY-MM-DD',
    'timezoneOffsetHintMinutes' => 9999,
    'accountTimezone' => 'Europe/London',
]);
check('19 out-of-range offset ignored; tz used -> 13:30Z', $r['occurred_open_at_utc'] === '2026-08-31 13:30:00', $r['occurred_open_at_utc'] ?? 'null');

// 20. raw text length cap at persistence layer is 64; long raw -> store NULL
//     for evidence (service keeps verbatim; repository column truncation is
//     schema-bound, so assert the cap value via resolver vocab only — here we
//     simply confirm a normal-length raw round-trips; length guard is 2D/DTO).
$r = $svc->resolve(['rawOpenText' => '2026-08-31 14:30', 'sourceCalendar' => 'gregorian', 'dateFormat' => 'YYYY-MM-DD', 'timezoneOffsetHintMinutes' => 0]);
check('20 UTC offset 0 resolves to same wall UTC', $r['occurred_open_at_utc'] === '2026-08-31 14:30:00', $r['occurred_open_at_utc'] ?? 'null');

// =====================================================================
// PERSISTENCE CASES (TradeService -> SQLite)
// =====================================================================

$accountId = $accounts->create([
    'user_id' => 1,
    'provider' => 'MANUAL',
    'platform' => 'MANUAL',
    'label' => 'Journal',
    'account_number_masked' => '',
    'currency' => 'USD',
    'leverage' => null,
    'status' => 'disconnected',
]);

function baseTrade(array $over = []): array
{
    return array_merge([
        'symbol' => 'EURUSD',
        'direction' => 'buy',
        'entryPrice' => '1.080',
        'exitPrice' => '1.090',
        'volume' => '0.1',
        'openTime' => '2026-08-31T14:30',
        'closeTime' => '2026-08-31T15:42',
    ], $over);
}

// 21. Resolved new trade persists canonical UTC (account tz London).
$accounts->updateTimezone($accountId, 'Europe/London', 'user_config');
$created = $tradeService->create(baseTrade([
    'accountId' => (string) $accountId,
    'rawOpenText' => '2026-08-31 14:30',
    'rawCloseText' => '2026-08-31 15:42',
    'sourceCalendar' => 'gregorian',
    'dateFormat' => 'YYYY-MM-DD',
]), 1);
check('21 resolved open persisted UTC', $created['occurred_open_at_utc'] === '2026-08-31 13:30:00', $created['occurred_open_at_utc'] ?? 'null');
check('21 resolved close persisted UTC', $created['occurred_close_at_utc'] === '2026-08-31 14:42:00', $created['occurred_close_at_utc'] ?? 'null');
check('21 time_status resolved', $created['time_status'] === 'resolved');
check('21 source_timezone persisted', $created['source_timezone'] === 'Europe/London');
check('21 raw evidence persisted', $created['raw_open_text'] === '2026-08-31 14:30' && $created['raw_close_text'] === '2026-08-31 15:42');
check('21 legacy open_time untouched (wall value)', $created['open_time'] === '2026-08-31 14:30:00');

// 22. Unresolved trade persists NULL canonical + keeps evidence.
$accounts->updateTimezone($accountId, null, 'unknown');
$created2 = $tradeService->create(baseTrade([
    'accountId' => (string) $accountId,
    'rawOpenText' => '2026-08-31 14:30',
    'rawCloseText' => '2026-08-31 15:42',
    'sourceCalendar' => 'gregorian',
    'dateFormat' => 'YYYY-MM-DD',
]), 1);
check('22 unresolved open canonical NULL', $created2['occurred_open_at_utc'] === null);
check('22 unresolved close canonical NULL', $created2['occurred_close_at_utc'] === null);
check('22 time_status unresolved', $created2['time_status'] === 'unresolved');
check('22 raw evidence preserved', $created2['raw_open_text'] === '2026-08-31 14:30');
check('22 source_timezone_source unknown', $created2['source_timezone_source'] === 'unknown');

// 23. Manual trade with NO evidence at all -> unresolved NULL (legacy path).
$created3 = $tradeService->create(baseTrade(['accountId' => (string) $accountId]), 1);
check('23 bare manual trade canonical NULL', $created3['occurred_open_at_utc'] === null && $created3['occurred_close_at_utc'] === null);
check('23 bare manual status unresolved', $created3['time_status'] === 'unresolved');

// 24. users.timezone (America/New_York) is NOT used: account tz cleared above,
//     yet canonical stays NULL even though user profile tz is NY.
check('24 users.timezone never feeds resolution', $created3['source_timezone'] === null && $created3['occurred_open_at_utc'] === null);

// 25. Invalid raw datetime is a hard validation error (not downgraded).
$threw = false;
try {
    $tradeService->create(baseTrade([
        'accountId' => (string) $accountId,
        'rawOpenText' => '2026-02-31 14:30',
        'sourceCalendar' => 'gregorian',
        'dateFormat' => 'YYYY-MM-DD',
        'timezoneOffsetHintMinutes' => 0,
    ]), 1);
} catch (ValidationException $e) {
    $threw = true;
}
check('25 invalid raw datetime -> ValidationException', $threw);

// 26. No backfill: a pre-existing legacy trade (inserted raw) keeps NULL
//     canonical forever — update without evidence re-resolves to same NULL.
$pdo->prepare("INSERT INTO trades (user_id, symbol, direction, entry_price, exit_price, volume,
        contract_size, commission, swap, profit_loss, open_time, close_time, source,
        occurred_open_at_utc, time_status)
    VALUES (1,'GBPUSD','sell','1.20','1.19','0.1','1','0','0','-10',
        '2020-01-02 09:00:00','2020-01-02 10:00:00','manual', NULL, 'unresolved')")->execute();
$legacyId = (int) $pdo->lastInsertId();
// Edit the legacy trade (price only) — canonical must remain NULL, never filled.
$updated = $tradeService->update($legacyId, baseTrade([
    'symbol' => 'GBPUSD',
    'direction' => 'sell',
    'entryPrice' => '1.21',
    'exitPrice' => '1.19',
    'openTime' => '2020-01-02T09:00',
    'closeTime' => '2020-01-02T10:00',
]), 1);
check('26 legacy trade canonical stays NULL after edit (no backfill)', $updated['occurred_open_at_utc'] === null && $updated['occurred_close_at_utc'] === null);
check('26 legacy time_status stays unresolved', $updated['time_status'] === 'unresolved');
check('26 legacy open_time preserved', $updated['open_time'] === '2020-01-02 09:00:00');

// 27. Serialization exposes canonical fields additively, openTime/closeTime keep legacy meaning.
$s = $tradeService->serialize($created);
check('27 serialize occurredOpenAtUtc present', array_key_exists('occurredOpenAtUtc', $s) && $s['occurredOpenAtUtc'] === '2026-08-31 13:30:00');
check('27 serialize openTime legacy wall value', $s['openTime'] === '2026-08-31 14:30:00');
check('27 serialize timeStatus present', $s['timeStatus'] === 'resolved');

// =====================================================================
// TIMEZONE-INDEPENDENCE: same evidence under 4 PHP default zones.
// =====================================================================
$expected = null;
foreach (['UTC', 'Europe/London', 'America/New_York', 'Asia/Tehran'] as $tz) {
    date_default_timezone_set($tz);
    $rr = $svc->resolve([
        'rawOpenText' => '2026-08-31 14:30',
        'rawCloseText' => '2026-08-31 15:42',
        'sourceCalendar' => 'gregorian',
        'dateFormat' => 'YYYY-MM-DD',
        'accountTimezone' => 'Asia/Tehran',
    ]);
    $pair = [$rr['occurred_open_at_utc'], $rr['occurred_close_at_utc']];
    if ($expected === null) {
        $expected = $pair;
    }
    check("TZ-independence under PHP default $tz", $pair === $expected && $pair === ['2026-08-31 11:00:00', '2026-08-31 12:12:00'], implode(',', array_map('strval', $pair)));
}
date_default_timezone_set('UTC');

// Sanity: resolver IANA guard still rejects non-IANA.
check('guard isValidIana rejects EST', TimezoneResolver::isValidIana('EST') === false);
check('guard isValidIana accepts Asia/Tehran', TimezoneResolver::isValidIana('Asia/Tehran') === true);

echo "\n" . ($failures === 0 ? "ALL PASS" : "$failures FAIL") . "\n";
exit($failures === 0 ? 0 : 1);
