<?php

declare(strict_types=1);

/**
 * Phase 4A — MetaApi OFFLINE end-to-end + realtime lifecycle + partial-close
 * semantics audit.
 *
 * LOCAL ONLY. No network, no real MetaApi, no credentials, no schema change.
 * Deterministic synthetic payloads built from DOCUMENTED MetaApi shapes
 * (history-deals: time=absolute ISO Z, brokerTime=naive; entryType IN/OUT;
 * positionId correlation). These are SYNTHETIC — they do NOT constitute real
 * payload verification (see report status table).
 *
 * Focus areas:
 *   - historical ingestion end-to-end (assembler -> canonical persistence)
 *   - REALTIME cross-event lifecycle (IN webhook then OUT webhook) — audited
 *     for whether pending state survives; if not, reported as a data-model
 *     blocker (no ad-hoc state, no schema change in this phase)
 *   - partial-close boundary semantics (open=earliest IN, close=LATEST OUT,
 *     anchored to Velora's existing trade_exits containment invariant)
 *   - fill-level vs position-level idempotency; duplicates; out-of-order;
 *     worker retry/restart; historical/realtime overlap
 *   - timestamp safety, timezone/calendar firewall, legacy compat, session.
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

// ---- Minimal SQLite environment (same harness as Phase 4). ----
$root = sys_get_temp_dir() . '/velora-p4a-' . bin2hex(random_bytes(5));
@mkdir($root . '/config', 0700, true);
@mkdir($root . '/data', 0700, true);
@mkdir($root . '/logs', 0700, true);
$dbPath = $root . '/data/velora.sqlite';
putenv('APP_ENV=local');
putenv('VELORA_PRIVATE_ROOT=' . $root);
putenv('VELORA_DOCUMENT_ROOT=' . dirname(__DIR__, 2));
putenv('METAAPI_TOKEN=dummy-not-used-for-network');
file_put_contents($root . '/config/velora.env', implode("\n", [
    'APP_ENV=local', 'APP_DEBUG=true', 'DB_DRIVER=sqlite', 'DB_DATABASE=' . $dbPath,
    'JWT_SECRET=' . str_repeat('j', 48), 'APP_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)),
    'CORS_ALLOWED_ORIGINS=http://localhost', 'FRONTEND_URL=http://localhost', 'MAIL_DRIVER=log',
]) . "\n");

require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

use Velora\Accounts\MetaApiService;
use Velora\Accounts\SyncJobRepository;
use Velora\Core\Database;
use Velora\Trades\MetaApiDealAssembler;
use Velora\Trades\MetaApiInstantResolver;
use Velora\Trades\TradeService;

$pdo = Database::connection();
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, timezone TEXT DEFAULT 'UTC', locale TEXT DEFAULT 'fa', full_name TEXT DEFAULT '')");
$pdo->exec("CREATE TABLE trading_accounts (
  id INTEGER PRIMARY KEY, user_id INTEGER, provider TEXT NOT NULL, platform TEXT NOT NULL,
  broker TEXT, server TEXT, timezone TEXT, timezone_source TEXT NOT NULL DEFAULT 'unknown',
  mt_login TEXT, account_type TEXT NOT NULL DEFAULT 'STANDARD',
  metaapi_account_id TEXT, sync_status TEXT NOT NULL DEFAULT 'DISCONNECTED',
  last_synced_at TEXT, disconnected_at TEXT, connection_credentials_encrypted TEXT, connected_at TEXT,
  auto_sync_enabled INTEGER NOT NULL DEFAULT 1, last_incremental_at TEXT, connection_checked_at TEXT,
  consecutive_errors INTEGER NOT NULL DEFAULT 0, last_error TEXT, starting_balance TEXT NOT NULL DEFAULT '0.00',
  current_balance TEXT NOT NULL DEFAULT '0.00', label TEXT NOT NULL DEFAULT '', account_number_masked TEXT NOT NULL DEFAULT '',
  currency TEXT NOT NULL DEFAULT 'USD', leverage TEXT, status TEXT NOT NULL DEFAULT 'disconnected',
  balance TEXT NOT NULL DEFAULT '0.00', equity TEXT NOT NULL DEFAULT '0.00',
  created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE trades (
  id INTEGER PRIMARY KEY, user_id INTEGER, account_id INTEGER, external_deal_id TEXT,
  symbol TEXT, direction TEXT, entry_price TEXT, exit_price TEXT, volume TEXT,
  contract_size TEXT DEFAULT '1', commission TEXT DEFAULT '0', swap TEXT DEFAULT '0',
  profit_loss TEXT, r_multiple TEXT, stop_loss TEXT, take_profit TEXT,
  open_time TEXT NOT NULL, close_time TEXT NOT NULL,
  occurred_open_at_utc TEXT, occurred_close_at_utc TEXT, time_status TEXT NOT NULL DEFAULT 'unresolved',
  source_timezone TEXT, source_timezone_source TEXT NOT NULL DEFAULT 'unknown', source_calendar TEXT NOT NULL DEFAULT 'unknown',
  raw_open_text TEXT, raw_close_text TEXT, strategy_tag TEXT, emotional_score INTEGER, notes TEXT,
  source TEXT NOT NULL DEFAULT 'manual', created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (account_id, external_deal_id))");
$pdo->exec("CREATE TABLE trade_exits (
  id INTEGER PRIMARY KEY, trade_id INTEGER, exit_type TEXT DEFAULT 'manual',
  exit_price TEXT NOT NULL, volume TEXT NOT NULL, pnl TEXT NOT NULL DEFAULT '0',
  exited_at TEXT NOT NULL, notes TEXT)");
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
$pdo->exec("INSERT INTO users (id,email,timezone) VALUES (1,'p4a@example.test','America/New_York')");
// Account carries a broker/source timezone AND a broker/server name: the
// MetaApi instant path must ignore ALL of these for canonical time.
$pdo->exec("INSERT INTO trading_accounts
  (id,user_id,provider,platform,broker,server,timezone,timezone_source,mt_login,metaapi_account_id,sync_status,account_type,label)
  VALUES (1,1,'MT5','mt5','Vittaverse','Vittaverse-Server','Europe/Paris','user_config','123','acct-4a','SYNCING','STANDARD','t')");

// ---- Helpers --------------------------------------------------------------
$jobSeq = 0;
function enqueueJob(): void
{
    global $pdo, $jobSeq;
    $jobSeq++;
    $pdo->exec("INSERT INTO sync_jobs (account_id,user_id,type,status,payload,max_attempts,available_at,dedupe_key,created_at,updated_at)
      VALUES (1,1,'HISTORICAL','PENDING','{\"months\":12}',5,CURRENT_TIMESTAMP,'j4a-{$jobSeq}-" . bin2hex(random_bytes(3)) . "',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
}

/** Build a raw MetaApi history-deal (documented shape). time=absolute Z, broker=naive. */
function d(string $id, ?string $pos, mixed $entry, string $type, string $symbol, float $price, float $vol, float $profit, ?string $time, ?string $broker = null, float $comm = 0.0, float $swap = 0.0): array
{
    return ['id' => $id, 'positionId' => $pos, 'entryType' => $entry, 'type' => $type,
        'symbol' => $symbol, 'volume' => $vol, 'price' => $price, 'profit' => $profit,
        'commission' => $comm, 'swap' => $swap, 'time' => $time, 'brokerTime' => $broker];
}

// Global fixture state read by the shared-service transport.
$GLOBALS['FIX_DEALS'] = [];
$GLOBALS['FIX_FAILS'] = 0;
/**
 * Build a service whose fixture transport reads $GLOBALS['FIX_DEALS'].
 * Mutate the global between sync() calls to simulate provider data changing.
 * @return MetaApiService
 */
function makeService(): MetaApiService
{
    $transport = static function (string $method, string $url, array $headers, ?array $body, int $timeout): array {
        if (str_contains($url, '/history-deals/time/')) {
            if ($GLOBALS['FIX_FAILS'] > 0) {
                $GLOBALS['FIX_FAILS']--;
                return ['status' => 500, 'body' => json_encode(['message' => 'synthetic transient failure'])];
            }
            return ['status' => 200, 'body' => json_encode(['deals' => $GLOBALS['FIX_DEALS']], JSON_UNESCAPED_SLASHES)];
        }
        if (str_contains($url, '/account-information')) {
            return ['status' => 200, 'body' => json_encode([
                'balance' => 10000, 'equity' => 10000, 'margin' => 100, 'freeMargin' => 9900,
                'marginLevel' => 1000, 'leverage' => 500, 'currency' => 'USD',
                'broker' => 'Vittaverse', 'server' => 'Vittaverse-Server'])];
        }
        return ['status' => 404, 'body' => '{}'];
    };
    return new MetaApiService(null, new SyncJobRepository(), null, $transport);
}
/** Point the shared fixture transport at a deal set (and optional fail count). */
function fixture(array $deals, int $fails = 0): void
{
    $GLOBALS['FIX_DEALS'] = $deals;
    $GLOBALS['FIX_FAILS'] = $fails;
}

function sync(MetaApiService $svc, ?array &$handle = null): array
{
    if ($handle !== null) {
        // no-op: caller keeps handle state; sync just runs a job.
    }
    enqueueJob();
    try {
        return $svc->runNextSyncJob('w4a') ?? ['inserted' => null];
    } catch (\Throwable $e) {
        $c = $e;
        $chain = [];
        while ($c) { $chain[] = get_class($c) . ': ' . $c->getMessage() . ' @ ' . basename($c->getFile()) . ':' . $c->getLine(); $c = $c->getPrevious(); }
        fwrite(STDERR, "SYNC-ERROR " . implode(' | ', $chain) . "\n");
        throw $e;
    }
}
function resetBackoff(): void
{
    global $pdo;
    $pdo->exec("UPDATE sync_jobs SET available_at=CURRENT_TIMESTAMP, locked_at=NULL, lease_token=NULL, status='PENDING' WHERE status='PENDING'");
}
function wh(MetaApiService $svc, array $payloadDeals, string $type = 'deal'): array
{
    // Single deal -> {deal:{...}}; multiple -> {deals:[...]}; both mirror ingress.
    $payload = ['accountId' => 'acct-4a', 'type' => $type];
    if (array_is_list($payloadDeals)) {
        $payload['deals'] = $payloadDeals;
    } else {
        $payload['deal'] = $payloadDeals;
    }
    return $svc->processWebhook($payload);
}
function autoTrades(): array
{
    global $pdo;
    return $pdo->query("SELECT * FROM trades WHERE source='auto_sync'")->fetchAll(PDO::FETCH_ASSOC);
}
function tradeFor(string $pos): ?array
{
    global $pdo;
    $st = $pdo->prepare("SELECT * FROM trades WHERE external_deal_id=? LIMIT 1");
    $st->execute(['pos-' . $pos]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}
function autoCount(): int { return count(autoTrades()); }

$resolver = new MetaApiInstantResolver();
$assembler = new MetaApiDealAssembler();

// =====================================================================
// A. TIMESTAMP SAFETY (Step 9)
// =====================================================================
check('A1 Z instant -> UTC', $resolver->canonicalUtc('2026-08-31T14:30:00.000Z') === '2026-08-31 14:30:00');
check('A2 +03:30 -> 11:00:00 UTC', $resolver->canonicalUtc('2026-08-31T14:30:00+03:30') === '2026-08-31 11:00:00', (string) $resolver->canonicalUtc('2026-08-31T14:30:00+03:30'));
check('A3 brokerTime naive stays unresolved', $resolver->resolve('2026-08-31 14:30:00.000')->status === 'unresolved');
$missing = $resolver->resolve(null);
check('A4 time missing (only brokerTime) -> unresolved', $missing->status === 'unresolved');
$bad = $resolver->resolve('2026-08-31T25:61:00Z');
check('A5 invalid offset-stamped value -> invalid (never fabricated)', $bad->status === 'invalid', $bad->status);
foreach (['UTC', 'Europe/London', 'America/New_York', 'Asia/Tehran', 'Asia/Tokyo'] as $tz) {
    date_default_timezone_set($tz);
    check("A6 absolute identical under PHP tz $tz", $resolver->canonicalUtc('2026-08-31T14:30:00+03:30') === '2026-08-31 11:00:00');
    check("A6 naive stays null under PHP tz $tz", $resolver->canonicalUtc('2026-08-31 14:30:00.000') === null);
}
date_default_timezone_set('UTC');

// =====================================================================
// B. ENTRY-TYPE SHAPES (Step 2 A/B/C; MT4 = documentation-derived)
// =====================================================================
$svc = makeService();

// B1 MT5 string entryType.
fixture([
    d('b1-in', 'B1', 'DEAL_ENTRY_IN', 'DEAL_TYPE_BUY', 'XAU/USD', 2000, 0.1, 0, '2026-09-01T10:00:00.000Z', '2026-09-01 13:00:00.000'),
    d('b1-out', 'B1', 'DEAL_ENTRY_OUT', 'DEAL_TYPE_BUY', 'XAU/USD', 2010, 0.1, 10, '2026-09-01T14:00:00.000Z', '2026-09-01 17:00:00.000'),
]);
$r = sync($svc);
check('B1 MT5 string DEAL_ENTRY_IN/OUT pairs -> 1 trade', $r['inserted'] === 1, json_encode($r));

// B2 MT5 numeric entryType 0/1.
fixture([
    d('b2-in', 'B2', 0, 'DEAL_TYPE_SELL', 'EUR/USD', 1.09, 0.2, 0, '2026-09-01T08:00:00.000Z', '2026-09-01 11:00:00.000'),
    d('b2-out', 'B2', 1, 'DEAL_TYPE_SELL', 'EUR/USD', 1.08, 0.2, 20, '2026-09-01T12:00:00.000Z', '2026-09-01 15:00:00.000'),
]);
$r = sync($svc);
check('B2 MT5 numeric entryType 0/1 pairs -> 1 trade', $r['inserted'] === 1, json_encode($r));
check('B2 numeric mapping direction sell', ($t = tradeFor('B2')) && $t['direction'] === 'sell', $t['direction'] ?? 'null');

// B3 MT4 shape — SYNTHETIC / documentation-derived (unified MetaApi model; real
// MT4 payload NOT captured). Uses only documented fields.
fixture([
    d('b3-in', 'B3', 'DEAL_ENTRY_IN', 'DEAL_TYPE_BUY', 'GBP/USD', 1.27, 0.1, 0, '2026-09-01T09:00:00.000Z', '2026-09-01 12:00:00.000'),
    d('b3-out', 'B3', 'DEAL_ENTRY_OUT', 'DEAL_TYPE_BUY', 'GBP/USD', 1.28, 0.1, 10, '2026-09-01T13:00:00.000Z', '2026-09-01 16:00:00.000'),
]);
$r = sync($svc);
check('B3 MT4 (documentation-derived synthetic) pairs -> 1 trade', $r['inserted'] === 1, json_encode($r));

// B4 unknown entryType -> ignored.
fixture([
    d('b4-a', 'B4', 'DEAL_ENTRY_WHATEVER', 'DEAL_TYPE_BUY', 'USD/JPY', 150, 0.1, 0, '2026-09-01T09:00:00Z'),
    d('b4-b', 'B4', 9, 'DEAL_TYPE_BUY', 'USD/JPY', 151, 0.1, 5, '2026-09-01T13:00:00Z'),
]);
$r = sync($svc);
check('B4 unknown entryType (string/9) produces no trade', $r['inserted'] === 0 && tradeFor('B4') === null, json_encode($r));

// B5 INOUT / balance / credit -> ignored (not trade fills).
fixture([
    d('b5-inout', 'B5', 'DEAL_ENTRY_INOUT', 'DEAL_TYPE_BUY', 'XAU/USD', 2000, 0.1, 0, '2026-09-01T10:00:00Z'),
    ['id' => 'b5-bal', 'positionId' => null, 'entryType' => null, 'type' => 'DEAL_TYPE_BALANCE',
     'symbol' => 'USD', 'volume' => 1, 'price' => 1, 'profit' => 100, 'commission' => 0, 'swap' => 0,
     'time' => '2026-09-01T10:00:00Z', 'brokerTime' => null],
]);
$r = sync($svc);
check('B5 INOUT/balance/credit ignored -> no trade', $r['inserted'] === 0 && tradeFor('B5') === null, json_encode($r));

// =====================================================================
// C. POSITION PAIRING + BOUNDARIES + PARTIAL CLOSE (Steps 3, 7)
// =====================================================================
// C1 single round-trip boundaries.
fixture([
    d('c1-in', 'C1', 'DEAL_ENTRY_IN', 'DEAL_TYPE_BUY', 'XAU/USD', 2000, 0.1, 0, '2026-09-01T10:00:00Z'),
    d('c1-out', 'C1', 'DEAL_ENTRY_OUT', 'DEAL_TYPE_BUY', 'XAU/USD', 2010, 0.1, 10, '2026-09-01T14:00:00Z'),
]);
sync($svc);
$t = tradeFor('C1');
check('C1 open = IN instant 10:00', $t && $t['occurred_open_at_utc'] === '2026-09-01 10:00:00');
check('C1 close = OUT instant 14:00', $t && $t['occurred_close_at_utc'] === '2026-09-01 14:00:00');

// C2 multiple IN + multiple OUT (position 2001-equivalent).
fixture([
    d('c2-in1', 'C2', 'DEAL_ENTRY_IN', 'DEAL_TYPE_BUY', 'XAU/USD', 2000, 0.4, 0, '2026-09-01T09:00:00Z'),
    d('c2-in2', 'C2', 'DEAL_ENTRY_IN', 'DEAL_TYPE_BUY', 'XAU/USD', 2010, 0.6, 0, '2026-09-01T09:30:00Z'),
    d('c2-out1', 'C2', 'DEAL_ENTRY_OUT', 'DEAL_TYPE_BUY', 'XAU/USD', 2030, 0.3, 30, '2026-09-01T15:00:00Z', null, 0.5),
    d('c2-out2', 'C2', 'DEAL_ENTRY_OUT', 'DEAL_TYPE_BUY', 'XAU/USD', 2040, 0.7, 70, '2026-09-01T16:00:00Z', null, 0.5, 1.0),
]);
sync($svc);
$t = tradeFor('C2');
check('C2 volume = sum IN (1.0)', $t && bccomp($t['volume'], '1.0', 8) === 0, $t['volume'] ?? 'null');
check('C2 entry VWAP = 2006.00', $t && bccomp($t['entry_price'], '2006.00000000', 4) === 0, $t['entry_price'] ?? 'null');
check('C2 exit VWAP = 2037.00', $t && bccomp($t['exit_price'], '2037.00000000', 4) === 0, $t['exit_price'] ?? 'null');
check('C2 open = earliest IN 09:00', $t && $t['occurred_open_at_utc'] === '2026-09-01 09:00:00', $t['occurred_open_at_utc'] ?? '');
check('C2 close = LATEST OUT 16:00 (not earliest 15:00)', $t && $t['occurred_close_at_utc'] === '2026-09-01 16:00:00', $t['occurred_close_at_utc'] ?? '');
check('C2 profit summed = 100', $t && bccomp($t['profit_loss'], '100', 4) === 0, $t['profit_loss'] ?? 'null');
check('C2 commission cost summed negative (-1.0)', $t && bccomp($t['commission'], '-1.0', 4) === 0, $t['commission'] ?? 'null');
check('C2 swap cost summed negative (-1.0)', $t && bccomp($t['swap'], '-1.0', 4) === 0, $t['swap'] ?? 'null');

// C3 PARTIAL CLOSE audit (Step 3): IN 1.0@10:00, OUT 0.4@11:00, OUT 0.6@12:00.
fixture([
    d('c3-in', 'C3', 'DEAL_ENTRY_IN', 'DEAL_TYPE_BUY', 'XAU/USD', 2000, 1.0, 0, '2026-09-01T10:00:00Z'),
    d('c3-o1', 'C3', 'DEAL_ENTRY_OUT', 'DEAL_TYPE_BUY', 'XAU/USD', 2010, 0.4, 4, '2026-09-01T11:00:00Z'),
    d('c3-o2', 'C3', 'DEAL_ENTRY_OUT', 'DEAL_TYPE_BUY', 'XAU/USD', 2020, 0.6, 12, '2026-09-01T12:00:00Z'),
]);
sync($svc);
$t = tradeFor('C3');
check('C3 partial close: close = LATEST OUT 12:00', $t && $t['occurred_close_at_utc'] === '2026-09-01 12:00:00', $t['occurred_close_at_utc'] ?? 'null');
check('C3 close is NOT earliest OUT 11:00', $t && $t['occurred_close_at_utc'] !== '2026-09-01 11:00:00');
check('C3 open = earliest IN 10:00', $t && $t['occurred_open_at_utc'] === '2026-09-01 10:00:00');
check('C3 close >= open enforced', $t && $t['occurred_close_at_utc'] >= $t['occurred_open_at_utc']);
// Containment invariant (mirrors TradeRepository::update): close must contain
// every exit — 12:00 contains 11:00 AND 12:00; earliest(11:00) would NOT.
check('C3 latest-OUT satisfies trade_exits containment (close >= MAX exit)', $t && $t['occurred_close_at_utc'] >= '2026-09-01 12:00:00' && $t['occurred_close_at_utc'] >= '2026-09-01 11:00:00');
check('C3 exit VWAP over partial outs (2016)', $t && bccomp($t['exit_price'], '2016.00000000', 4) === 0, $t['exit_price'] ?? 'null');

// F open position (IN only).
fixture([d('f-in', 'F', 'DEAL_ENTRY_IN', 'DEAL_TYPE_BUY', 'XAU/USD', 2000, 0.1, 0, '2026-09-01T10:00:00Z')]);
$r = sync($svc);
// Open position (IN only): ledgered as 'received' and WAITS for its OUT; it is
// not terminal-skipped and never produces a fabricated trade.
check('F open position (IN only) -> ledgered waiting, no trade', $r['inserted'] === 0 && tradeFor('F') === null && ($r['skipped'] ?? -1) === 0 && ($r['fills'] ?? 0) === 1, json_encode($r));

// G OUT-only.
fixture([d('g-out', 'G', 'DEAL_ENTRY_OUT', 'DEAL_TYPE_BUY', 'XAU/USD', 2010, 0.1, 5, '2026-09-01T14:00:00Z')]);
$r = sync($svc);
check('G OUT-only -> skipped (missing_open_fill), no trade', $r['inserted'] === 0 && tradeFor('G') === null, json_encode($r));

// H missing positionId.
fixture([
    d('h-in', null, 'DEAL_ENTRY_IN', 'DEAL_TYPE_BUY', 'XAU/USD', 2000, 0.1, 0, '2026-09-01T10:00:00Z'),
    d('h-out', null, 'DEAL_ENTRY_OUT', 'DEAL_TYPE_BUY', 'XAU/USD', 2010, 0.1, 5, '2026-09-01T14:00:00Z'),
]);
$r = sync($svc);
check('H missing positionId -> unpaired, no fabricated open=close', $r['inserted'] === 0, json_encode($r));

// K naive-time position (brokerTime only).
fixture([
    d('k-in', 'K', 'DEAL_ENTRY_IN', 'DEAL_TYPE_BUY', 'USD/JPY', 150, 0.1, 0, null, '2026-09-01 13:00:00.000'),
    d('k-out', 'K', 'DEAL_ENTRY_OUT', 'DEAL_TYPE_BUY', 'USD/JPY', 151, 0.1, 5, null, '2026-09-01 17:00:00.000'),
]);
$r = sync($svc);
check('K naive brokerTime-only position -> no trade (no UTC fabricated)', $r['inserted'] === 0 && tradeFor('K') === null, json_encode($r));

// L mixed: IN absolute, OUT naive -> independent, close unresolved -> skip.
fixture([
    d('l-in', 'L', 'DEAL_ENTRY_IN', 'DEAL_TYPE_BUY', 'AUD/USD', 0.66, 0.1, 0, '2026-09-01T10:00:00Z'),
    d('l-out', 'L', 'DEAL_ENTRY_OUT', 'DEAL_TYPE_BUY', 'AUD/USD', 0.67, 0.1, 5, null, '2026-09-01 17:00:00.000'),
]);
$r = sync($svc);
check('L IN-absolute + OUT-naive -> no trade (close independent/unresolved)', $r['inserted'] === 0 && tradeFor('L') === null, json_encode($r));

// N duplicate fills within one batch -> fill-level dedup.
fixture([
    d('n-in', 'N', 'DEAL_ENTRY_IN', 'DEAL_TYPE_BUY', 'XAU/USD', 2000, 0.1, 0, '2026-09-01T10:00:00Z'),
    d('n-in', 'N', 'DEAL_ENTRY_IN', 'DEAL_TYPE_BUY', 'XAU/USD', 2000, 0.1, 0, '2026-09-01T10:00:00Z'), // identical id
    d('n-out', 'N', 'DEAL_ENTRY_OUT', 'DEAL_TYPE_BUY', 'XAU/USD', 2010, 0.1, 10, '2026-09-01T14:00:00Z'),
    d('n-out', 'N', 'DEAL_ENTRY_OUT', 'DEAL_TYPE_BUY', 'XAU/USD', 2010, 0.1, 10, '2026-09-01T14:00:00Z'),
]);
$r = sync($svc);
$t = tradeFor('N');
check('N duplicate fills dedup -> 1 trade, volume 0.1 (not 0.2)', $r['inserted'] === 1 && $t && bccomp($t['volume'], '0.1', 8) === 0, json_encode(['ins' => $r['inserted'], 'vol' => $t['volume'] ?? 'null']));

// =====================================================================
// D. REALTIME CROSS-EVENT LIFECYCLE (Phase 5 durable fill ledger)
//    The ledger persists every fill by (account_id, external_deal_id), so IN
//    and OUT arriving in SEPARATE webhooks now pair durably — across events,
//    out-of-order delivery, retries and process restarts. A position waits
//    until it is fully closed (open=earliest IN, close=latest OUT).
// =====================================================================
$inFill  = d('rt-in', 'RT', 'DEAL_ENTRY_IN', 'DEAL_TYPE_BUY', 'XAU/USD', 2000, 0.1, 0, '2026-09-01T10:00:00.000Z', '2026-09-01 13:00:00.000');
$outFill = d('rt-out', 'RT', 'DEAL_ENTRY_OUT', 'DEAL_TYPE_BUY', 'XAU/USD', 2010, 0.1, 10, '2026-09-01T14:00:00.000Z', '2026-09-01 17:00:00.000');
$before = autoCount();

// Scenario 1: IN webhook, then OUT webhook (separate events) -> one closed trade.
$w1 = wh($svc, $inFill);
check('RT-1 IN-only webhook: no trade yet (fill ledgered, waiting)', $w1['inserted'] === 0 && tradeFor('RT') === null && $w1['fills'] === 1, json_encode($w1));
$w2 = wh($svc, $outFill);
check('RT-1 separate OUT webhook completes the position -> 1 trade', $w2['inserted'] === 1 && tradeFor('RT') !== null, json_encode($w2));
$rt = tradeFor('RT');
check('RT-1 canonical open 10:00 / close 14:00', $rt && $rt['occurred_open_at_utc'] === '2026-09-01 10:00:00' && $rt['occurred_close_at_utc'] === '2026-09-01 14:00:00');

// Scenario 3: restart between events (brand-new service instance) — durability
// lives in the DB, not process memory.

$svc2 = makeService();
wh($svc2, d('rs-in', 'RS', 'DEAL_ENTRY_IN', 'DEAL_TYPE_BUY', 'XAU/USD', 2000, 0.1, 0, '2026-09-01T10:00:00.000Z'));
$svc3 = makeService(); // simulate worker/process restart
$wrs = wh($svc3, d('rs-out', 'RS', 'DEAL_ENTRY_OUT', 'DEAL_TYPE_BUY', 'XAU/USD', 2010, 0.1, 10, '2026-09-01T14:00:00.000Z'));
check('RT-3 across process restart: IN then OUT -> 1 trade (durability in DB)', $wrs['inserted'] === 1 && tradeFor('RS') !== null, json_encode($wrs));

// Scenario 2: OUT then IN (out of order), separate -> one closed trade.
wh($svc, d('bo-out', 'BO', 'DEAL_ENTRY_OUT', 'DEAL_TYPE_BUY', 'EUR/USD', 1.08, 0.1, 5, '2026-09-01T12:00:00Z'));
$wb2 = wh($svc, d('bo-in', 'BO', 'DEAL_ENTRY_IN', 'DEAL_TYPE_BUY', 'EUR/USD', 1.09, 0.1, 0, '2026-09-01T08:00:00Z'));
check('RT-2 OUT-then-IN out-of-order -> 1 trade (open=earliest IN 08:00)', $wb2['inserted'] === 1 && tradeFor('BO') !== null, json_encode($wb2));
$bo = tradeFor('BO');
check('RT-2 boundaries correct despite delivery order', $bo && $bo['occurred_open_at_utc'] === '2026-09-01 08:00:00' && $bo['occurred_close_at_utc'] === '2026-09-01 12:00:00');

// Scenario 4: IN, duplicate IN, OUT -> no double-counting.
wh($svc, d('dd-in', 'DD', 'DEAL_ENTRY_IN', 'DEAL_TYPE_BUY', 'XAU/USD', 2000, 0.1, 0, '2026-09-01T10:00:00Z'));
wh($svc, d('dd-in', 'DD', 'DEAL_ENTRY_IN', 'DEAL_TYPE_BUY', 'XAU/USD', 2000, 0.1, 0, '2026-09-01T10:00:00Z')); // duplicate deal id
$wdd = wh($svc, d('dd-out', 'DD', 'DEAL_ENTRY_OUT', 'DEAL_TYPE_BUY', 'XAU/USD', 2010, 0.1, 10, '2026-09-01T14:00:00Z'));
$dd = tradeFor('DD');
check('RT-4 duplicate IN fill -> 1 trade, volume 0.1 (not 0.2)', $wdd['inserted'] === 1 && $dd && bccomp($dd['volume'], '0.1', 8) === 0, ($dd['volume'] ?? 'null'));

// Scenario 5: IN then separate PARTIAL OUTs -> one closed position at final OUT.
wh($svc, d('pc-in', 'PC', 'DEAL_ENTRY_IN', 'DEAL_TYPE_BUY', 'XAU/USD', 2000, 1.0, 0, '2026-09-01T10:00:00Z'));
$wpc1 = wh($svc, d('pc-o1', 'PC', 'DEAL_ENTRY_OUT', 'DEAL_TYPE_BUY', 'XAU/USD', 2010, 0.4, 4, '2026-09-01T11:00:00Z'));
check('RT-5 partial OUT (0.4<1.0): position stays open, no trade yet', $wpc1['inserted'] === 0 && tradeFor('PC') === null, json_encode($wpc1));
$wpc2 = wh($svc, d('pc-o2', 'PC', 'DEAL_ENTRY_OUT', 'DEAL_TYPE_BUY', 'XAU/USD', 2020, 0.6, 12, '2026-09-01T12:00:00Z'));
$pc = tradeFor('PC');
check('RT-5 remaining OUT closes position -> 1 trade, close = final 12:00', $wpc2['inserted'] === 1 && $pc && $pc['occurred_close_at_utc'] === '2026-09-01 12:00:00', json_encode($wpc2));

// Scenario 9: retry/redelivery of the SAME events -> exactly-once (no dupes).
$redeliver = wh($svc, $outFill);
check('RT-9 redelivered event produces no duplicate', autoCount() === $before + 5 && $redeliver['inserted'] === 0, 'autoSyncCount=' . autoCount());

// Scenario 6/7: historical sync overlapping realtime fills -> no duplicate.
fixture([$inFill, $outFill]); // same RT fills now arrive via a historical window
$r = sync($svc);
check('RT-6/7 historical sync over already-ledgered realtime fills: 0 new', ($r['inserted'] ?? -1) === 0 && tradeFor('RT') !== null, json_encode($r));
check('RT-6/7 no duplicate trade after historical overlap', autoCount() === $before + 5, 'autoSyncCount=' . autoCount());

// Invalid/unknown fill (no recognizable entryType/identity) is rejected and
// never fabricated into a trade.
$wbad = wh($svc, ['id' => 'bad-1', 'positionId' => 'BADD', 'entryType' => 'DEAL_ENTRY_WAT', 'type' => 'DEAL_TYPE_BUY',
    'symbol' => 'XAU/USD', 'volume' => 0.1, 'price' => 2000, 'profit' => 0, 'time' => '2026-09-01T10:00:00Z']);
check('RT-10 unknown/invalid fill -> no trade, no fabricated row', $wbad['inserted'] === 0 && tradeFor('BADD') === null, json_encode($wbad));

// =====================================================================
// E. WORKER RETRY / RESTART (Step 4 scenario E; Step 10)
// =====================================================================
$retryDeals = [
    d('e-in', 'E', 'DEAL_ENTRY_IN', 'DEAL_TYPE_BUY', 'XAU/USD', 2000, 0.1, 0, '2026-09-01T10:00:00Z'),
    d('e-out', 'E', 'DEAL_ENTRY_OUT', 'DEAL_TYPE_BUY', 'XAU/USD', 2010, 0.1, 10, '2026-09-01T14:00:00Z'),
];
$svcRetry = makeService(); fixture($retryDeals, 1);
$firstThrew = false;
enqueueJob();
try {
    $svcRetry->runNextSyncJob('w4a-retry');
} catch (\Throwable) {
    $firstThrew = true; // expected: transient 500 -> job failed back to PENDING
}
$jobStatus = $pdo->query("SELECT status, attempts, last_error FROM sync_jobs WHERE account_id=1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check('E1 failed attempt leaves job PENDING (retryable), not lost', isset($jobStatus['status']) && $jobStatus['status'] === 'PENDING', json_encode($jobStatus));
check('E1 no trade written on failed attempt', tradeFor('E') === null);
// Simulate worker restart/retry after backoff expires.
resetBackoff();
$svcRetry2 = makeService(); fixture($retryDeals, 0); // fresh instance = restart
$second = sync($svcRetry2);
check('E2 retry after backoff+restart creates the trade exactly once', ($second['inserted'] ?? null) === 1 && tradeFor('E') !== null, json_encode($second));
// No duplicate from the failed first attempt: exactly one trade for position E.
check('E2 no duplicate trade for position E after retry', (int) $pdo->query("SELECT COUNT(*) FROM trades WHERE external_deal_id='pos-E'")->fetchColumn() === 1);

// Dead-letter: a job that exhausts attempts is retained (terminal), not recycled.
$svcDead = makeService(); fixture([], 99);
enqueueJob();
$deadId = (int) $pdo->query("SELECT id FROM sync_jobs ORDER BY id DESC LIMIT 1")->fetchColumn();
for ($i = 0; $i < 6; $i++) {
    try {
        // force-run the target job by making only it claimable each time.
        $svcDead->runNextSyncJob('dead-w');
    } catch (\Throwable) {
    }
    // reset backoff only for OUR job, and clear any stale running leases on it.
    $pdo->exec("UPDATE sync_jobs SET available_at=CURRENT_TIMESTAMP WHERE id={$deadId} AND status='PENDING'");
}
$dead = $pdo->query("SELECT status, attempts FROM sync_jobs WHERE id={$deadId}")->fetch(PDO::FETCH_ASSOC);
$deadStatus = $dead['status'] ?? '';
$deadAttempts = (int) ($dead['attempts'] ?? 0);
check('E3 exhausted job terminal (DEAD_LETTER) after using attempts, retained', $deadStatus === 'DEAD_LETTER', "status={$deadStatus} attempts={$deadAttempts}");

// =====================================================================
// F. IDEMPOTENCY MATRIX (Step 6)
// =====================================================================
$idDeals = [
    d('id-in', 'ID', 'DEAL_ENTRY_IN', 'DEAL_TYPE_BUY', 'XAU/USD', 2000, 0.1, 0, '2026-09-01T10:00:00Z'),
    d('id-out', 'ID', 'DEAL_ENTRY_OUT', 'DEAL_TYPE_BUY', 'XAU/USD', 2010, 0.1, 10, '2026-09-01T14:00:00Z'),
    d('id2-in', 'ID2', 'DEAL_ENTRY_IN', 'DEAL_TYPE_SELL', 'EUR/USD', 1.09, 0.2, 0, '2026-09-01T09:00:00Z'),
    d('id2-out', 'ID2', 'DEAL_ENTRY_OUT', 'DEAL_TYPE_SELL', 'EUR/USD', 1.08, 0.2, 20, '2026-09-01T13:00:00Z'),
];
$svcId = makeService(); fixture($idDeals);
sync($svcId);
$countAfterFirst = autoCount();
check('F1 two distinct positions both created', tradeFor('ID') !== null && tradeFor('ID2') !== null);
// Re-sync the same window (position-level idempotency).
$r = sync($svcId);
check('F2 same window re-synced -> 0 new (position unique key)', $r['inserted'] === 0 && autoCount() === $countAfterFirst, json_encode($r));
// Fill-level: distinct deal ids within a position are both counted; identical id deduped.
$fillDeals = [
    d('f-a', 'FL', 'DEAL_ENTRY_IN', 'DEAL_TYPE_BUY', 'XAU/USD', 2000, 0.1, 0, '2026-09-01T09:00:00Z'),
    d('f-b', 'FL', 'DEAL_ENTRY_IN', 'DEAL_TYPE_BUY', 'XAU/USD', 2010, 0.1, 0, '2026-09-01T09:30:00Z'),
    d('f-a', 'FL', 'DEAL_ENTRY_IN', 'DEAL_TYPE_BUY', 'XAU/USD', 2000, 0.1, 0, '2026-09-01T09:00:00Z'), // dup id f-a
    d('f-c', 'FL', 'DEAL_ENTRY_OUT', 'DEAL_TYPE_BUY', 'XAU/USD', 2020, 0.2, 20, '2026-09-01T14:00:00Z'),
];
$svcFl = makeService(); fixture($fillDeals);
sync($svcFl);
$t = tradeFor('FL');
check('F3 fill-level: distinct fills f-a+f-b sum volume 0.2; duplicate f-a not double-counted', $t && bccomp($t['volume'], '0.2', 8) === 0, $t['volume'] ?? 'null');
check('F3 fill-level: entry VWAP over 2 distinct IN = 2005', $t && bccomp($t['entry_price'], '2005.00000000', 4) === 0, $t['entry_price'] ?? 'null');

// =====================================================================
// G. TIMEZONE / CALENDAR FIREWALL + LEGACY + SESSION (Steps 10/11/12)
// =====================================================================
$t = tradeFor('C2');
check('G1 source_calendar = gregorian', $t['source_calendar'] === 'gregorian', $t['source_calendar']);
check('G1 source_timezone = NULL (no IANA inferred despite account tz Europe/Paris)', $t['source_timezone'] === null, var_export($t['source_timezone'], true));
check('G1 provenance = metaapi_instant', $t['source_timezone_source'] === 'metaapi_instant', $t['source_timezone_source']);
check('G1 legacy open_time populated (= true UTC, NOT broker 12:00/13:00)', $t['open_time'] === '2026-09-01 09:00:00', $t['open_time']);
check('G1 legacy close_time populated (= true UTC 16:00)', $t['close_time'] === '2026-09-01 16:00:00', $t['close_time']);
check('G1 no naive brokerTime leaked into legacy (no 12:00/13:00 wall clock)', strpos($t['open_time'], '12:00') === false && strpos($t['close_time'], '13:00') === false);
check('G1 time_status = resolved', $t['time_status'] === 'resolved');
check('G1 raw_open_text is the absolute Z string', $t['raw_open_text'] === '2026-09-01T09:00:00Z', $t['raw_open_text']);

// Legacy manual/screenshot trade stays unresolved and is NOT backfilled.
$pdo->exec("INSERT INTO trades (user_id,account_id,symbol,direction,entry_price,exit_price,volume,open_time,close_time,source,time_status)
  VALUES (1,NULL,'MANUAL','buy','100','101','1','2026-01-01 10:00:00','2026-01-01 11:00:00','manual','unresolved')");
$manual = $pdo->query("SELECT occurred_open_at_utc, time_status FROM trades WHERE source='manual' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check('G2 legacy manual trade remains unresolved (no backfill)', $manual['occurred_open_at_utc'] === null && $manual['time_status'] === 'unresolved', json_encode($manual));

// Session derived from canonical UTC only; Phase 3G unconfigured => unconfigured.
$tradeSvc = new TradeService();
$ser = $tradeSvc->serialize($t);
check('G3 session.status = unconfigured (Phase 3G not approved)', is_array($ser['session']) && $ser['session']['status'] === 'unconfigured', json_encode($ser['session'] ?? null));
check('G3 serialized canonical open present', $ser['occurredOpenAtUtc'] === '2026-09-01 09:00:00');

// Static firewall: the MetaApi canonical components must not reference any
// forbidden tz/source signal or banned parse call.
$asmSrc = file_get_contents(dirname(__DIR__, 2) . '/api/src/Trades/MetaApiDealAssembler.php');
$resSrc = file_get_contents(dirname(__DIR__, 2) . '/api/src/Trades/MetaApiInstantResolver.php');
$codeOnly = static function (string $src): string {
    $src = preg_replace('!/\*.*?\*/!s', '', $src) ?? $src;
    $src = preg_replace('!//[^\n]*!', '', $src) ?? $src;
    return $src;
};
foreach (['assembler' => $codeOnly($asmSrc), 'resolver' => $codeOnly($resSrc)] as $name => $code) {
    check("G4 $name: no strtotime/gmdate/date_default_timezone_set", !preg_match('/strtotime|gmdate|date_default_timezone_set/', $code));
    check("G4 $name: no users.timezone/locale/broker/server reads", !preg_match('/users\.timezone|->locale|brokerName|serverName|\bbroker\b|\bserver\b/i', $code));
}

// =====================================================================
// H. HISTORICAL vs REALTIME(BATCH) EQUIVALENCE (Step 13)
//    Separate realtime events cannot pair (proven in D). A realtime BATCH
//    carrying both fills is the realtime equivalent of a historical sync and
//    must produce an identical canonical result.
// =====================================================================
$pairIn  = d('eq-in', 'EQ', 'DEAL_ENTRY_IN', 'DEAL_TYPE_BUY', 'XAU/USD', 2000, 0.1, 0, '2026-09-01T10:00:00.000Z', '2026-09-01 13:00:00.000', 0.5);
$pairOut = d('eq-out', 'EQ', 'DEAL_ENTRY_OUT', 'DEAL_TYPE_BUY', 'XAU/USD', 2010, 0.1, 10, '2026-09-01T14:00:00.000Z', '2026-09-01 17:00:00.000', 0.5);
$svcEqH = makeService(); fixture([$pairIn, $pairOut]);
sync($svcEqH);
$hist = tradeFor('EQ');
// Realtime batch of the SAME fills but a different position id (avoid unique key).
$pairIn2  = d('eq2-in', 'EQ2', 'DEAL_ENTRY_IN', 'DEAL_TYPE_BUY', 'XAU/USD', 2000, 0.1, 0, '2026-09-01T10:00:00.000Z', '2026-09-01 13:00:00.000', 0.5);
$pairOut2 = d('eq2-out', 'EQ2', 'DEAL_ENTRY_OUT', 'DEAL_TYPE_BUY', 'XAU/USD', 2010, 0.1, 10, '2026-09-01T14:00:00.000Z', '2026-09-01 17:00:00.000', 0.5);
$svcEqR = makeService();
$wr = wh($svcEqR, [$pairIn2, $pairOut2], 'history');
$real = tradeFor('EQ2');
check('H1 realtime batch creates the trade', $wr['inserted'] === 1 && $real !== null, json_encode($wr));
$sameCanonical = $hist && $real
    && $hist['occurred_open_at_utc'] === $real['occurred_open_at_utc']
    && $hist['occurred_close_at_utc'] === $real['occurred_close_at_utc']
    && $hist['time_status'] === $real['time_status']
    && $hist['source_timezone_source'] === $real['source_timezone_source']
    && $hist['source_calendar'] === $real['source_calendar']
    && bccomp($hist['entry_price'], $real['entry_price'], 8) === 0
    && bccomp($hist['exit_price'], $real['exit_price'], 8) === 0
    && bccomp($hist['volume'], $real['volume'], 8) === 0
    && bccomp($hist['profit_loss'], $real['profit_loss'], 8) === 0;
check('H2 historical vs realtime-batch: identical canonical/financial result', $sameCanonical, $hist ? json_encode(['h' => [$hist['occurred_open_at_utc'], $hist['occurred_close_at_utc']], 'r' => $real ? [$real['occurred_open_at_utc'], $real['occurred_close_at_utc']] : null]) : 'no hist');

echo "\n" . ($failures === 0 ? "ALL PASS (Phase 4A offline)" : "$failures FAILURE(S)") . "\n";
exit($failures === 0 ? 0 : 1);
