<?php

declare(strict_types=1);

/**
 * Phase 4 — MetaApi real timestamp integration + canonical UTC persistence.
 *
 * Covers the whole MetaApi ingestion path WITHOUT any live token/network:
 *   - MetaApiInstantResolver: offset-explicit `time` -> canonical UTC; naive
 *     brokerTime -> unresolved (never default-tz parsed); invalid -> invalid;
 *     PHP-default-timezone independence (run under UTC/London/NY/Tehran).
 *   - MetaApiDealAssembler: deals paired by positionId into closed trades;
 *     open/close derived INDEPENDENTLY (never one deal duplicated); half-pairs
 *     and naive-time positions skipped (no fabricated instant); VWAP + sums.
 *   - MetaApiService end-to-end on SQLite via an injected fixture transport:
 *     historical sync writes canonical occurred_*_utc + metaapi_instant
 *     provenance; webhook single fills are NOT fabricated into trades; naive
 *     timestamps never corrupt canonical columns.
 *
 * Rules asserted: canonical UTC comes ONLY from offset-explicit MetaApi
 * instants; brokerTime/MetaStats naive times never produce UTC; no IANA zone is
 * inferred; open/close independent; no backfill; canonical resolver/normalizer
 * untouched.
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

// ---- Minimal environment (SQLite), same harness as the 2E suite. ----
$root = sys_get_temp_dir() . '/velora-p4-' . bin2hex(random_bytes(5));
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
    // Deliberately NO METAAPI_TOKEN: forces the injected transport + proves the
    // code path never performs real network calls in tests.
]) . "\n");

require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

use Velora\Accounts\AccountRepository;
use Velora\Accounts\MetaApiService;
use Velora\Accounts\SyncJobRepository;
use Velora\Core\Database;
use Velora\Trades\MetaApiDealAssembler;
use Velora\Trades\MetaApiInstantResolver;

$pdo = Database::connection();
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, timezone TEXT DEFAULT 'UTC', locale TEXT DEFAULT 'fa', full_name TEXT DEFAULT '')");
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
    created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (account_id, external_deal_id))");
$pdo->exec("CREATE TABLE sync_jobs (
    id INTEGER PRIMARY KEY, account_id INTEGER NOT NULL, user_id INTEGER, type TEXT NOT NULL DEFAULT 'HISTORICAL',
    payload TEXT, status TEXT NOT NULL DEFAULT 'PENDING', attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 5, available_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    dedupe_key TEXT, locked_at TEXT, locked_by TEXT, lease_token TEXT,
    started_at TEXT, completed_at TEXT, dead_lettered_at TEXT, last_error TEXT,
    range_from TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE metaapi_fills (
    id INTEGER PRIMARY KEY, account_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
    external_deal_id TEXT NOT NULL, position_id TEXT, order_id TEXT, entry_type TEXT, direction TEXT,
    symbol TEXT, volume TEXT, price TEXT, profit TEXT, commission TEXT, swap TEXT,
    occurred_at_utc TEXT, time_status TEXT NOT NULL DEFAULT 'unresolved',
    raw_time_text TEXT, broker_time_text TEXT, ingestion_source TEXT NOT NULL DEFAULT 'unknown',
    event_ref TEXT, processing_state TEXT NOT NULL DEFAULT 'received', processed_trade_id INTEGER,
    skip_reason TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (account_id, external_deal_id))");
$pdo->exec("INSERT INTO users (id, email) VALUES (1, 'p4@example.test')");
$pdo->exec("INSERT INTO trading_accounts
    (id, user_id, provider, platform, server, mt_login, metaapi_account_id, sync_status, account_type, label)
    VALUES (1, 1, 'MT5', 'mt5', 'Vittaverse-Server', '123456', 'acct-test-001', 'SYNCING', 'STANDARD', 't')");

// =====================================================================
// A. MetaApiInstantResolver
// =====================================================================
$resolver = new MetaApiInstantResolver();

$r = $resolver->resolve('2020-04-20T05:30:04.361Z');
check('A1 Z instant resolves to UTC', $r->isResolved() && $r->canonicalUtc === '2020-04-20 05:30:04', $r->canonicalUtc ?? 'null');
check('A1 iso8601 form', $r->iso8601 === '2020-04-20T05:30:04Z', $r->iso8601 ?? 'null');
check('A1 used explicit offset', $r->usedExplicitOffset === true);

$r = $resolver->resolve('2020-04-20T08:30:04.000+03:00');
check('A2 explicit +03:00 resolves to 05:30:04Z', $r->isResolved() && $r->canonicalUtc === '2020-04-20 05:30:04', $r->canonicalUtc ?? 'null');

$r = $resolver->resolve('2020-04-20T05:30:04Z');
check('A3 no-millis Z instant', $r->isResolved() && $r->canonicalUtc === '2020-04-20 05:30:04');

// Naive brokerTime: MUST be unresolved, never parsed by default tz.
$r = $resolver->resolve('2020-04-20 08:30:04.361');
check('A4 naive brokerTime is unresolved (NOT a default-tz instant)', $r->status === 'unresolved' && $r->canonicalUtc === null, $r->status);
check('A4 naive reason naive_no_offset', $r->reason === 'naive_no_offset', (string) $r->reason);

$r = $resolver->resolve('2020-09-08 22:21:36.000'); // MetaStats-style naive
check('A5 MetaStats naive openTime unresolved', $r->status === 'unresolved' && $r->canonicalUtc === null, $r->status);

// Empty / non-string / control chars.
check('A6 empty string unresolved', $resolver->resolve('')->status === 'unresolved');
check('A6 null unresolved', $resolver->resolve(null)->status === 'unresolved');
check('A6 integer unresolved', $resolver->resolve(12345)->status === 'unresolved');

// Invalid: offset present but impossible/malformed.
$r = $resolver->resolve('2020-13-40T99:99:99Z');
check('A7 impossible datetime with Z is invalid (not a fabricated instant)', $r->status === 'invalid', $r->status);
$r = $resolver->resolve('not-a-timeZ');
check('A7 garbage with trailing Z is invalid', $r->status === 'invalid', $r->status);

// DST instants are unaffected by any local wall-clock interpretation.
$r = $resolver->resolve('2026-03-29T01:30:00Z'); // London spring-forward day
check('A8 London DST-gap-day instant still resolves (offset pins it)', $r->isResolved() && $r->canonicalUtc === '2026-03-29 01:30:00');
$r = $resolver->resolve('2026-11-01T01:30:00Z'); // NY fall-back day
check('A8 NY DST-fold-day instant still resolves', $r->isResolved() && $r->canonicalUtc === '2026-11-01 01:30:00');

// Negative offset.
$r = $resolver->resolve('2020-04-20T00:30:04-05:00');
check('A9 negative offset -> 05:30:04Z', $r->isResolved() && $r->canonicalUtc === '2020-04-20 05:30:04', $r->canonicalUtc ?? 'null');

// =====================================================================
// B. MetaApiDealAssembler
// =====================================================================
$asm = new MetaApiDealAssembler();
$deal = static function (string $id, ?string $pos, string $entry, string $type, string $symbol, string $price, string $vol, string $profit, string $time, ?string $broker = null): array {
    return [
        'external_deal_id' => $id,
        'position_id' => $pos,
        'order_id' => $id . '-ord',
        'entry_type' => $entry,
        'symbol' => $symbol,
        'direction' => $type === 'buy' ? 'buy' : 'sell',
        'price' => $price,
        'volume' => $vol,
        'profit' => $profit,
        'commission' => '0',
        'swap' => '0',
        'time_raw' => $time,
        'time_utc' => (new MetaApiInstantResolver())->canonicalUtc($time),
        'broker_time' => $broker,
    ];
};

// B1: full round-trip paired by positionId.
$deals = [
    $deal('d-in', 'pos-1', 'in', 'buy', 'XAU/USD', '2025.5', '0.1', '0', '2026-08-31T10:00:00.000Z', '2026-08-31 13:00:00.000'),
    $deal('d-out', 'pos-1', 'out', 'buy', 'XAU/USD', '2035.2', '0.1', '97.00', '2026-08-31T14:00:00.000Z', '2026-08-31 17:00:00.000'),
];
$res = $asm->assemble($deals);
check('B1 one assembled trade', count($res['trades']) === 1, 'trades=' . count($res['trades']));
$t = $res['trades'][0] ?? [];
check('B1 open canonical = IN time UTC', ($t['occurred_open_at_utc'] ?? null) === '2026-08-31 10:00:00', $t['occurred_open_at_utc'] ?? 'null');
check('B1 close canonical = OUT time UTC (independent)', ($t['occurred_close_at_utc'] ?? null) === '2026-08-31 14:00:00', $t['occurred_close_at_utc'] ?? 'null');
check('B1 legacy open_time is same true UTC', ($t['open_time'] ?? null) === '2026-08-31 10:00:00');
check('B1 status resolved', ($t['time_status'] ?? null) === 'resolved');
check('B1 source_timezone NULL (no IANA inferred)', array_key_exists('source_timezone', $t) && $t['source_timezone'] === null, var_export($t['source_timezone'] ?? 'missing', true));
check('B1 provenance metaapi_instant', ($t['source_timezone_source'] ?? null) === 'metaapi_instant');
check('B1 calendar gregorian', ($t['source_calendar'] ?? null) === 'gregorian');
check('B1 direction buy', ($t['direction'] ?? null) === 'buy');
check('B1 profit summed 97.00', ($t['profit_loss'] ?? null) === '97.00000000', $t['profit_loss'] ?? 'null');
check('B1 raw open = verbatim Z string', ($t['raw_open_text'] ?? null) === '2026-08-31T10:00:00.000Z');

// B2: open position (IN only) -> skipped, never fabricated.
$res = $asm->assemble([$deal('o-in', 'pos-open', 'in', 'buy', 'EUR/USD', '1.08', '0.1', '0', '2026-08-31T10:00:00Z')]);
check('B2 open position produces no trade', count($res['trades']) === 0);
check('B2 skipped reason position_still_open', ($res['skipped'][0]['reason'] ?? '') === 'position_still_open', $res['skipped'][0]['reason'] ?? '');

// B3: OUT without IN -> missing_open_fill.
$res = $asm->assemble([$deal('x-out', 'pos-x', 'out', 'sell', 'GBP/USD', '1.27', '0.1', '5', '2026-08-31T10:00:00Z')]);
check('B3 OUT-only skipped (missing_open_fill)', count($res['trades']) === 0 && ($res['skipped'][0]['reason'] ?? '') === 'missing_open_fill');

// B4: deal with positionId but NAIVE time -> boundary unresolved -> skipped.
$naiveIn = $deal('n-in', 'pos-n', 'in', 'buy', 'USD/JPY', '150.0', '0.1', '0', '2026-08-31 13:00:00.000');
$naiveOut = $deal('n-out', 'pos-n', 'out', 'buy', 'USD/JPY', '150.5', '0.1', '5', '2026-08-31 17:00:00.000');
$res = $asm->assemble([$naiveIn, $naiveOut]);
check('B4 naive broker-time pair produces NO trade', count($res['trades']) === 0, 'trades=' . count($res['trades']));
check('B4 skipped for unresolved open instant', in_array(($res['skipped'][0]['reason'] ?? ''), ['open_instant_unresolved', 'close_instant_unresolved'], true), $res['skipped'][0]['reason'] ?? '');

// B5: open resolvable but close naive -> close_instant_unresolved (independence).
$bad = [
    $deal('b-in', 'pos-b', 'in', 'buy', 'AUD/USD', '0.66', '0.1', '0', '2026-08-31T10:00:00Z'),
    $deal('b-out', 'pos-b', 'out', 'buy', 'AUD/USD', '0.67', '0.1', '5', '2026-08-31 17:00:00.000'),
];
$res = $asm->assemble($bad);
check('B5 resolved-open + naive-close -> no trade, close_instant_unresolved', count($res['trades']) === 0 && ($res['skipped'][0]['reason'] ?? '') === 'close_instant_unresolved', $res['skipped'][0]['reason'] ?? '');

// B6: VWAP across two partial fills; open = earliest IN instant.
$res = $asm->assemble([
    $deal('p-in1', 'pos-p', 'in', 'buy', 'XAU/USD', '2000.0', '0.1', '0', '2026-08-31T09:00:00Z'),
    $deal('p-in2', 'pos-p', 'in', 'buy', 'XAU/USD', '2010.0', '0.3', '0', '2026-08-31T10:00:00Z'),
    $deal('p-out', 'pos-p', 'out', 'buy', 'XAU/USD', '2030.0', '0.4', '120', '2026-08-31T15:00:00Z'),
]);
check('B6 one trade from 2 IN + 1 OUT', count($res['trades']) === 1);
$t = $res['trades'][0] ?? [];
// VWAP entry = (2000*0.1 + 2010*0.3)/0.4 = (200+603)/0.4 = 2007.5
check('B6 entry VWAP 2007.5', bccomp((string)($t['entry_price'] ?? '0'), '2007.5', 4) === 0, $t['entry_price'] ?? 'null');
check('B6 volume = sum IN 0.4', bccomp((string)($t['volume'] ?? '0'), '0.4', 8) === 0, $t['volume'] ?? 'null');
check('B6 open = earliest IN (09:00)', ($t['occurred_open_at_utc'] ?? '') === '2026-08-31 09:00:00', $t['occurred_open_at_utc'] ?? '');

// B7: close before open chronology rejected.
$res = $asm->assemble([
    $deal('c-in', 'pos-c', 'in', 'buy', 'ETH/USD', '3000', '0.1', '0', '2026-08-31T15:00:00Z'),
    $deal('c-out', 'pos-c', 'out', 'buy', 'ETH/USD', '3100', '0.1', '10', '2026-08-31T09:00:00Z'),
]);
check('B7 close-before-open skipped', count($res['trades']) === 0 && ($res['skipped'][0]['reason'] ?? '') === 'close_before_open', $res['skipped'][0]['reason'] ?? '');

// B8: balance/credit deal (no entry type) ignored entirely.
$bal = ['external_deal_id' => 'bal', 'position_id' => null, 'entry_type' => null, 'symbol' => 'USD/USD',
        'direction' => 'buy', 'price' => '1', 'volume' => '1', 'profit' => '100', 'commission' => '0', 'swap' => '0',
        'time_raw' => '2026-08-31T10:00:00Z', 'time_utc' => '2026-08-31 10:00:00', 'broker_time' => null];
$res = $asm->assemble([$bal]);
check('B8 balance deal ignored (no trades, no skip-as-position)', count($res['trades']) === 0 && count($res['skipped']) === 0);

// =====================================================================
// C. MetaApiService end-to-end (injected fixture transport, SQLite)
// =====================================================================

// Fixture history-deals response: two closed positions + one open position +
// one naive-time position. Mirrors real MetaApi shape (time Z + brokerTime).
$historyDeals = [
    // Closed MT5 buy, fully offset-explicit.
    ['id' => 'h-in-1', 'positionId' => 'P1', 'entryType' => 'DEAL_ENTRY_IN', 'type' => 'DEAL_TYPE_BUY',
     'symbol' => 'XAU/USD', 'volume' => 0.1, 'price' => 2025.5, 'profit' => 0, 'commission' => 0, 'swap' => 0,
     'time' => '2026-08-30T10:00:00.000Z', 'brokerTime' => '2026-08-30 13:00:00.000'],
    ['id' => 'h-out-1', 'positionId' => 'P1', 'entryType' => 'DEAL_ENTRY_OUT', 'type' => 'DEAL_TYPE_BUY',
     'symbol' => 'XAU/USD', 'volume' => 0.1, 'price' => 2035.2, 'profit' => 97.0, 'commission' => 0.5, 'swap' => 0,
     'time' => '2026-08-30T14:00:00.000Z', 'brokerTime' => '2026-08-30 17:00:00.000'],
    // Closed MT4-style sell with numeric entryType 0/1.
    ['id' => 'h-in-2', 'positionId' => 'P2', 'entryType' => 0, 'type' => 'DEAL_TYPE_SELL',
     'symbol' => 'EUR/USD', 'volume' => 0.2, 'price' => 1.0850, 'profit' => 0, 'commission' => 0, 'swap' => 0,
     'time' => '2026-08-29T08:00:00.000Z', 'brokerTime' => '2026-08-29 11:00:00.000'],
    ['id' => 'h-out-2', 'positionId' => 'P2', 'entryType' => 1, 'type' => 'DEAL_TYPE_SELL',
     'symbol' => 'EUR/USD', 'volume' => 0.2, 'price' => 1.0820, 'profit' => 60.0, 'commission' => 0.5, 'swap' => 1.0,
     'time' => '2026-08-29T12:00:00.000Z', 'brokerTime' => '2026-08-29 15:00:00.000'],
    // Open position (IN only) -> must NOT be inserted.
    ['id' => 'h-in-3', 'positionId' => 'P3', 'entryType' => 'DEAL_ENTRY_IN', 'type' => 'DEAL_TYPE_BUY',
     'symbol' => 'GBP/USD', 'volume' => 0.1, 'price' => 1.2700, 'profit' => 0, 'commission' => 0, 'swap' => 0,
     'time' => '2026-08-31T09:00:00.000Z', 'brokerTime' => '2026-08-31 12:00:00.000'],
    // Naive-time closed position (only brokerTime-style, no Z) -> NOT inserted.
    ['id' => 'h-in-4', 'positionId' => 'P4', 'entryType' => 'DEAL_ENTRY_IN', 'type' => 'DEAL_TYPE_BUY',
     'symbol' => 'USD/JPY', 'volume' => 0.1, 'price' => 150.0, 'profit' => 0, 'commission' => 0, 'swap' => 0,
     'time' => '2026-08-28 13:00:00.000', 'brokerTime' => '2026-08-28 13:00:00.000'],
    ['id' => 'h-out-4', 'positionId' => 'P4', 'entryType' => 'DEAL_ENTRY_OUT', 'type' => 'DEAL_TYPE_BUY',
     'symbol' => 'USD/JPY', 'volume' => 0.1, 'price' => 150.5, 'profit' => 5.0, 'commission' => 0, 'swap' => 0,
     'time' => '2026-08-28 17:00:00.000', 'brokerTime' => '2026-08-28 17:00:00.000'],
];

// Transport returns the fixture for the history-deals GET.
$transport = static function (string $method, string $url, array $headers, ?array $body, int $timeout) use ($historyDeals): array {
    if (str_contains($url, '/history-deals/time/')) {
        return ['status' => 200, 'body' => json_encode(['deals' => $historyDeals], JSON_UNESCAPED_SLASHES)];
    }
    if (str_contains($url, '/account-information')) {
        return ['status' => 200, 'body' => json_encode([
            'balance' => 10432.5, 'equity' => 10510.2, 'margin' => 120, 'freeMargin' => 10390.2,
            'marginLevel' => 8758.5, 'leverage' => 500, 'currency' => 'USD',
            'broker' => 'Vittaverse', 'server' => 'Vittaverse-Server',
        ])];
    }
    return ['status' => 404, 'body' => '{}'];
};

// Force the service to treat the account as real even without a token: set a
// token env so dev-mock short-circuits are skipped, but the injected transport
// intercepts every request (no real network).
putenv('METAAPI_TOKEN=test-token-not-used-for-network');
$service = new MetaApiService(null, new SyncJobRepository(), null, $transport);

// Enqueue and run a historical sync job directly through the service.
$jobs = new SyncJobRepository();
$job = $jobs->enqueue(1, 1, 'HISTORICAL', ['months' => 12]);
$result = $service->runNextSyncJob('p4-test-worker');

check('C1 sync ran', is_array($result), var_export($result, true));
check('C1 inserted 2 closed positions', ($result['inserted'] ?? -1) === 2, 'inserted=' . ($result['inserted'] ?? '?'));
check('C1 assembled 2', ($result['assembled'] ?? -1) === 2, 'assembled=' . ($result['assembled'] ?? '?'));
// Phase 5 fill-ledger: open P3 / naive P4 are DURABLY LEDGERED as 'received'
// (waiting), not returned as terminal 'skipped' — they persist so a later fill
// can complete them. Terminal skips here = 0; persistence assertions below prove
// they never produced trades. fills = all 7 deal fills normalized.
check('C1 fills ledgered (7)', ($result['fills'] ?? -1) === 7, 'fills=' . ($result['fills'] ?? '?'));
check('C1 open/naive positions not terminal-skipped (await ledger completion)', ($result['skipped'] ?? -1) === 0, 'skipped=' . ($result['skipped'] ?? '?'));

// Inspect persisted rows.
$rows = $pdo->query("SELECT * FROM trades WHERE source='auto_sync' ORDER BY external_deal_id")->fetchAll(PDO::FETCH_ASSOC);
check('C2 two auto_sync rows persisted', count($rows) === 2, 'rows=' . count($rows));
$byPos = [];
foreach ($rows as $row) {
    $byPos[$row['external_deal_id']] = $row;
}
$p1 = $byPos['pos-P1'] ?? null;
check('C2 P1 keyed by positionId', $p1 !== null);
if ($p1) {
    check('C2 P1 occurred_open canonical UTC', $p1['occurred_open_at_utc'] === '2026-08-30 10:00:00', $p1['occurred_open_at_utc']);
    check('C2 P1 occurred_close canonical UTC', $p1['occurred_close_at_utc'] === '2026-08-30 14:00:00', $p1['occurred_close_at_utc']);
    check('C2 P1 time_status resolved', $p1['time_status'] === 'resolved', $p1['time_status']);
    check('C2 P1 source_timezone NULL', $p1['source_timezone'] === null, var_export($p1['source_timezone'], true));
    check('C2 P1 provenance metaapi_instant', $p1['source_timezone_source'] === 'metaapi_instant', $p1['source_timezone_source']);
    check('C2 P1 calendar gregorian', $p1['source_calendar'] === 'gregorian');
    check('C2 P1 raw_open is Z instant', $p1['raw_open_text'] === '2026-08-30T10:00:00.000Z', $p1['raw_open_text']);
    check('C2 P1 legacy open_time = UTC (not broker 13:00)', $p1['open_time'] === '2026-08-30 10:00:00' && strpos($p1['open_time'], '13:00') === false, $p1['open_time']);
    check('C2 P1 commission stored as cost (-0.5)', bccomp((string)$p1['commission'], '-0.5', 2) === 0, $p1['commission']);
}
$p2 = $byPos['pos-P2'] ?? null;
if ($p2) {
    check('C3 P2 sell numeric entryType paired', $p2['direction'] === 'sell', $p2['direction'] ?? 'null');
    check('C3 P2 open 08:00 / close 12:00 UTC', $p2['occurred_open_at_utc'] === '2026-08-29 08:00:00' && $p2['occurred_close_at_utc'] === '2026-08-29 12:00:00');
    check('C3 P2 swap stored (-1)', bccomp((string)$p2['swap'], '-1', 2) === 0, $p2['swap']);
}

// Naive P4 must NOT exist and must not have fabricated canonical times.
$p4 = $byPos['pos-P4'] ?? null;
check('C4 naive-time P4 not persisted (no fabricated UTC)', $p4 === null);
// Open P3 must NOT exist.
$p3 = $byPos['pos-P3'] ?? null;
check('C4 open-position P3 not persisted (no close fabrication)', $p3 === null);

// Idempotency: re-running the SAME history window must not duplicate.
// (enqueue() has a 60s cooldown, so insert a claimable job directly to model a
// later re-sync of the same already-persisted positions.)
$pdo->exec("INSERT INTO sync_jobs (account_id, user_id, type, status, payload, max_attempts, available_at, dedupe_key, created_at, updated_at)
    VALUES (1, 1, 'HISTORICAL', 'PENDING', '{\"months\":12}', 5, CURRENT_TIMESTAMP, 'manual-resync-1', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
$beforeCount = (int) $pdo->query("SELECT COUNT(*) FROM trades WHERE source='auto_sync'")->fetchColumn();
$second = $service->runNextSyncJob('p4-test-worker-2');
$afterCount = (int) $pdo->query("SELECT COUNT(*) FROM trades WHERE source='auto_sync'")->fetchColumn();
check('C5 re-sync inserts 0 (idempotent by position key)', ($second['inserted'] ?? -1) === 0, 'inserted=' . ($second['inserted'] ?? 'null-result'));
check('C5 row count unchanged after re-sync', $beforeCount === $afterCount, "before=$beforeCount after=$afterCount");

// ---- Webhook: a single OUT fill must NOT fabricate a trade. ----
$webhookSingle = [
    'accountId' => 'acct-test-001',
    'type' => 'deal',
    'deal' => [
        'id' => 'w-out-1', 'positionId' => 'W1', 'entryType' => 'DEAL_ENTRY_OUT', 'type' => 'DEAL_TYPE_BUY',
        'symbol' => 'XAU/USD', 'volume' => 0.1, 'price' => 2040.0, 'profit' => 10,
        'time' => '2026-08-31T15:00:00.000Z', 'brokerTime' => '2026-08-31 18:00:00.000',
    ],
];
$wres = $service->processWebhook($webhookSingle);
check('C6 single webhook fill inserts 0 (no fabricated closed trade)', ($wres['inserted'] ?? -1) === 0, 'inserted=' . ($wres['inserted'] ?? '?'));
// Phase 5 ledger: the lone fill is DURABLY stored (waiting for its pair), not a
// terminal skip and never fabricated into a trade. fills=1 ledgered.
check('C6 single webhook fill is ledgered (fills=1) awaiting its pair', ($wres['fills'] ?? -1) === 1, 'fills=' . ($wres['fills'] ?? '?'));
check('C6 single fill not terminal-skipped (ledgered received)', ($wres['skipped'] ?? -1) === 0, 'skipped=' . ($wres['skipped'] ?? '?'));

// ---- Webhook: a paired IN+OUT batch DOES persist a canonical trade. ----
$webhookPair = [
    'accountId' => 'acct-test-001',
    'type' => 'history',
    'deals' => [
        ['id' => 'w-in-2', 'positionId' => 'W2', 'entryType' => 'DEAL_ENTRY_IN', 'type' => 'DEAL_TYPE_SELL',
         'symbol' => 'EUR/USD', 'volume' => 0.1, 'price' => 1.0900, 'profit' => 0,
         'time' => '2026-08-31T09:00:00.000Z', 'brokerTime' => '2026-08-31 12:00:00.000'],
        ['id' => 'w-out-2', 'positionId' => 'W2', 'entryType' => 'DEAL_ENTRY_OUT', 'type' => 'DEAL_TYPE_SELL',
         'symbol' => 'EUR/USD', 'volume' => 0.1, 'price' => 1.0870, 'profit' => 30,
         'time' => '2026-08-31T13:00:00.000Z', 'brokerTime' => '2026-08-31 16:00:00.000'],
    ],
];
$wres2 = $service->processWebhook($webhookPair);
check('C7 paired webhook batch inserts 1', ($wres2['inserted'] ?? -1) === 1, 'inserted=' . ($wres2['inserted'] ?? '?'));
$wrow = $pdo->query("SELECT * FROM trades WHERE external_deal_id='pos-W2'")->fetch(PDO::FETCH_ASSOC);
check('C7 webhook trade canonical open/close UTC', $wrow && $wrow['occurred_open_at_utc'] === '2026-08-31 09:00:00' && $wrow['occurred_close_at_utc'] === '2026-08-31 13:00:00', var_export($wrow ? [$wrow['occurred_open_at_utc'], $wrow['occurred_close_at_utc']] : null, true));
check('C7 webhook provenance metaapi_instant', $wrow && $wrow['source_timezone_source'] === 'metaapi_instant');

// ---- Serialization: session engine runs off canonical UTC (unconfigured). ----
$tradeSvc = new \Velora\Trades\TradeService();
$serialized = $tradeSvc->serialize($p1);
check('C8 serialized canonical open present', ($serialized['occurredOpenAtUtc'] ?? null) === '2026-08-30 10:00:00');
check('C8 session derived (unconfigured until product approval)', is_array($serialized['session'] ?? null) && ($serialized['session']['status'] ?? '') === 'unconfigured', var_export($serialized['session'] ?? null, true));

// =====================================================================
// D. PHP default-timezone independence (resolver + assembler).
//    Re-run a parse under different default tz; canonical UTC must be
//    identical (the explicit offset in the string carries the instant).
// =====================================================================
foreach (['UTC', 'Europe/London', 'America/New_York', 'Asia/Tehran', 'Asia/Tokyo'] as $tz) {
    date_default_timezone_set($tz);
    $inst = (new MetaApiInstantResolver())->canonicalUtc('2020-04-20T08:30:04.000+03:00');
    check("D9 instant identical under PHP tz $tz", $inst === '2020-04-20 05:30:04', "$inst");
    $naive = (new MetaApiInstantResolver())->canonicalUtc('2020-04-20 08:30:04.000');
    check("D9 naive stays null under PHP tz $tz", $naive === null, var_export($naive, true));
}
date_default_timezone_set('UTC');

echo "\n" . ($failures === 0 ? "ALL PASS" : "$failures FAILURE(S)") . "\n";
exit($failures === 0 ? 0 : 1);
