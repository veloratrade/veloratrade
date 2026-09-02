<?php

declare(strict_types=1);

/**
 * Phase 5 (Objective A) — MetaApi durable FILL LEDGER.
 *
 * Verifies the metaapi_fills ledger gives exactly-once fill identity and
 * durable cross-event pairing:
 *   - fill identity = (account_id, external_deal_id) UNIQUE; a fill delivered
 *     via webhook AND historical sync (or redelivered) is stored once;
 *   - IN/OUT arriving in separate webhooks pair across events AND restarts
 *     (durability is in the DB, not process memory);
 *   - fill processing_state transitions received -> aggregated (linked to a
 *     trade) or terminal skipped; open/partial positions stay 'received';
 *   - multi-worker concurrency: two services concurrently redeliver the same
 *     fill set -> one trade, no duplicate fill rows;
 *   - canonical time rule on the ledger: only offset-explicit `time` populates
 *     occurred_at_utc; naive brokerTime -> time_status='unresolved', evidence only;
 *   - no naive brokerTime becomes an instant.
 *
 * Local only; injected fixture transport; SQLite; no network/credentials.
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

$root = sys_get_temp_dir() . '/velora-p5-' . bin2hex(random_bytes(5));
@mkdir($root . '/config', 0700, true);
@mkdir($root . '/data', 0700, true);
@mkdir($root . '/logs', 0700, true);
putenv('APP_ENV=local');
putenv('VELORA_PRIVATE_ROOT=' . $root);
putenv('VELORA_DOCUMENT_ROOT=' . dirname(__DIR__, 2));
putenv('METAAPI_TOKEN=dummy-not-used-for-network');
file_put_contents($root . '/config/velora.env', implode("\n", [
    'APP_ENV=local', 'APP_DEBUG=true', 'DB_DRIVER=sqlite', 'DB_DATABASE=' . $root . '/data/v.sqlite',
    'JWT_SECRET=' . str_repeat('j', 48), 'APP_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)),
    'CORS_ALLOWED_ORIGINS=http://localhost', 'FRONTEND_URL=http://localhost', 'MAIL_DRIVER=log',
]) . "\n");
require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

use Velora\Accounts\MetaApiService;
use Velora\Accounts\MetaApiFillRepository;
use Velora\Accounts\SyncJobRepository;
use Velora\Core\Database;

$pdo = Database::connection();
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, timezone TEXT DEFAULT 'UTC', locale TEXT DEFAULT 'fa', full_name TEXT DEFAULT '')");
$pdo->exec("CREATE TABLE trading_accounts (id INTEGER PRIMARY KEY, user_id INTEGER, provider TEXT NOT NULL, platform TEXT NOT NULL, broker TEXT, server TEXT, timezone TEXT, timezone_source TEXT NOT NULL DEFAULT 'unknown', mt_login TEXT, account_type TEXT NOT NULL DEFAULT 'STANDARD', metaapi_account_id TEXT, sync_status TEXT NOT NULL DEFAULT 'DISCONNECTED', last_synced_at TEXT, disconnected_at TEXT, connection_credentials_encrypted TEXT, connected_at TEXT, auto_sync_enabled INTEGER NOT NULL DEFAULT 1, last_incremental_at TEXT, connection_checked_at TEXT, consecutive_errors INTEGER NOT NULL DEFAULT 0, last_error TEXT, starting_balance TEXT NOT NULL DEFAULT '0.00', current_balance TEXT NOT NULL DEFAULT '0.00', label TEXT NOT NULL DEFAULT '', account_number_masked TEXT NOT NULL DEFAULT '', currency TEXT NOT NULL DEFAULT 'USD', leverage TEXT, status TEXT NOT NULL DEFAULT 'disconnected', balance TEXT NOT NULL DEFAULT '0.00', equity TEXT NOT NULL DEFAULT '0.00', created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE trades (id INTEGER PRIMARY KEY, user_id INTEGER, account_id INTEGER, external_deal_id TEXT, symbol TEXT, direction TEXT, entry_price TEXT, exit_price TEXT, volume TEXT, contract_size TEXT DEFAULT '1', commission TEXT DEFAULT '0', swap TEXT DEFAULT '0', profit_loss TEXT, r_multiple TEXT, stop_loss TEXT, take_profit TEXT, open_time TEXT NOT NULL, close_time TEXT NOT NULL, occurred_open_at_utc TEXT, occurred_close_at_utc TEXT, time_status TEXT NOT NULL DEFAULT 'unresolved', source_timezone TEXT, source_timezone_source TEXT NOT NULL DEFAULT 'unknown', source_calendar TEXT NOT NULL DEFAULT 'unknown', raw_open_text TEXT, raw_close_text TEXT, strategy_tag TEXT, emotional_score INTEGER, notes TEXT, source TEXT NOT NULL DEFAULT 'manual', created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE (account_id, external_deal_id))");
$pdo->exec("CREATE TABLE sync_jobs (id INTEGER PRIMARY KEY, account_id INTEGER NOT NULL, user_id INTEGER, type TEXT NOT NULL DEFAULT 'HISTORICAL', payload TEXT, status TEXT NOT NULL DEFAULT 'PENDING', attempts INTEGER NOT NULL DEFAULT 0, max_attempts INTEGER NOT NULL DEFAULT 5, available_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, dedupe_key TEXT, locked_at TEXT, locked_by TEXT, lease_token TEXT, started_at TEXT, completed_at TEXT, dead_lettered_at TEXT, last_error TEXT, range_from TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE metaapi_fills (
  id INTEGER PRIMARY KEY, account_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
  external_deal_id TEXT NOT NULL, position_id TEXT, order_id TEXT, entry_type TEXT, direction TEXT,
  symbol TEXT, volume TEXT, price TEXT, profit TEXT, commission TEXT, swap TEXT,
  occurred_at_utc TEXT, time_status TEXT NOT NULL DEFAULT 'unresolved',
  raw_time_text TEXT, broker_time_text TEXT, ingestion_source TEXT NOT NULL DEFAULT 'unknown',
  event_ref TEXT, processing_state TEXT NOT NULL DEFAULT 'received', processed_trade_id INTEGER,
  skip_reason TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (account_id, external_deal_id))");
$pdo->exec("INSERT INTO users (id,email) VALUES (1,'p5@example.test')");
$pdo->exec("INSERT INTO trading_accounts (id,user_id,provider,platform,server,mt_login,metaapi_account_id,sync_status,account_type,label) VALUES (1,1,'MT5','mt5','S','1','acct-p5','SYNCING','STANDARD','t')");

$fillRepo = new MetaApiFillRepository();

function svc(): array
{
    $handle = ['deals' => [], 'fails' => 0];
    $transport = static function (string $m, string $url, array $h, ?array $body, int $t) use (&$handle): array {
        if (str_contains($url, '/history-deals/time/')) {
            return ['status' => 200, 'body' => json_encode(['deals' => $handle['deals']])];
        }
        if (str_contains($url, '/account-information')) {
            return ['status' => 200, 'body' => json_encode(['balance' => 1, 'equity' => 1, 'margin' => 1, 'freeMargin' => 1, 'marginLevel' => 1, 'leverage' => 500, 'currency' => 'USD', 'broker' => 'V', 'server' => 'S'])];
        }
        return ['status' => 404, 'body' => '{}'];
    };
    return [new MetaApiService(null, new SyncJobRepository(), null, $transport), &$handle];
}
function wh(MetaApiService $s, array $deals): array
{
    return $s->processWebhook(['accountId' => 'acct-p5', 'type' => 'history', 'eventId' => bin2hex(random_bytes(6)), 'deals' => $deals]);
}
function fillRow(string $dealId): ?array
{
    $st = Database::connection()->prepare('SELECT * FROM metaapi_fills WHERE account_id=1 AND external_deal_id=? LIMIT 1');
    $st->execute([$dealId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}
function tradeFor(string $pos): ?array
{
    $st = Database::connection()->prepare('SELECT * FROM trades WHERE account_id=1 AND external_deal_id=? LIMIT 1');
    $st->execute(['pos-' . $pos]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

// --- L1/L2: separate webhooks pair durably; ledger rows persisted. ---------
$in  = ['id' => 'L-in', 'positionId' => 'L', 'entryType' => 'DEAL_ENTRY_IN', 'type' => 'DEAL_TYPE_BUY', 'symbol' => 'XAU/USD', 'volume' => 0.1, 'price' => 2000, 'profit' => 0, 'commission' => 0, 'swap' => 0, 'time' => '2026-09-01T10:00:00.000Z', 'brokerTime' => '2026-09-01 13:00:00.000'];
$out = ['id' => 'L-out', 'positionId' => 'L', 'entryType' => 'DEAL_ENTRY_OUT', 'type' => 'DEAL_TYPE_BUY', 'symbol' => 'XAU/USD', 'volume' => 0.1, 'price' => 2010, 'profit' => 10, 'commission' => 0.5, 'swap' => 0, 'time' => '2026-09-01T14:00:00.000Z', 'brokerTime' => '2026-09-01 17:00:00.000'];
[$s1] = svc();
$r1 = wh($s1, [$in]);
check('L1 IN webhook: 0 trades, fill ledgered received', $r1['inserted'] === 0 && tradeFor('L') === null, json_encode($r1));
$fIn = fillRow('L-in');
check('L1 IN fill persisted with canonical instant + resolved', $fIn && $fIn['occurred_at_utc'] === '2026-09-01 10:00:00' && $fIn['time_status'] === 'resolved' && $fIn['processing_state'] === 'received', json_encode($fIn ? ['st' => $fIn['processing_state'], 'utc' => $fIn['occurred_at_utc'], 'ts' => $fIn['time_status']] : null));
check('L1 brokerTime stored as evidence, not instant', $fIn && $fIn['broker_time_text'] === '2026-09-01 13:00:00.000' && strpos((string) $fIn['occurred_at_utc'], '13:00') === false);
check('L1 ingestion_source=webhook, event_ref present', $fIn && $fIn['ingestion_source'] === 'webhook' && !empty($fIn['event_ref']));

// OUT arrives in a SEPARATE process (new service instance) -> pairs via DB.
[$s2] = svc();
$r2 = wh($s2, [$out]);
check('L2 separate OUT webhook (new process) completes trade', $r2['inserted'] === 1 && tradeFor('L') !== null, json_encode($r2));
$fOut = fillRow('L-out');
check('L2 both fills aggregated + linked to trade', $fIn && $fOut && $fOut['processing_state'] === 'aggregated' && fillRow('L-in')['processing_state'] === 'aggregated' && $fOut['processed_trade_id'] !== null);

// --- L3: same fill via historical sync AND webhook = one ledger row. -------
[$s3, &$h] = svc();
$h['deals'] = [$in, $out];
$pdo->exec("INSERT INTO sync_jobs (account_id,user_id,type,status,payload,max_attempts,available_at,dedupe_key,created_at,updated_at) VALUES (1,1,'HISTORICAL','PENDING','{}',5,CURRENT_TIMESTAMP,'p5-hist-".bin2hex(random_bytes(3))."',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
$hist = $s3->runNextSyncJob('p5-hist');
check('L3 historical overlap inserts 0 (fills already ledgered, trade present)', ($hist['inserted'] ?? -1) === 0, json_encode($hist));
$cnt = (int) $pdo->query("SELECT COUNT(*) FROM metaapi_fills WHERE external_deal_id IN ('L-in','L-out')")->fetchColumn();
check('L3 fill rows not duplicated by historical sync (2 total)', $cnt === 2, "rows=$cnt");

// --- L4: redelivery / duplicate webhook of same fill id = no dup. ----------
$before = (int) $pdo->query("SELECT COUNT(*) FROM trades WHERE external_deal_id='pos-L'")->fetchColumn();
[$s4] = svc();
wh($s4, [$in]);
wh($s4, [$out]);
wh($s4, [$in, $out]);
$after = (int) $pdo->query("SELECT COUNT(*) FROM trades WHERE external_deal_id='pos-L'")->fetchColumn();
check('L4 repeated webhook deliveries -> single trade', $before === 1 && $after === 1, "before=$before after=$after");
check('L4 ledger still has exactly one row per fill', (int) $pdo->query("SELECT COUNT(*) FROM metaapi_fills WHERE position_id='L'")->fetchColumn() === 2);

// --- L5: open position stays 'received' (not terminal), no trade. ----------
[$s5] = svc();
wh($s5, [['id' => 'O-in', 'positionId' => 'O', 'entryType' => 'DEAL_ENTRY_IN', 'type' => 'DEAL_TYPE_BUY', 'symbol' => 'EUR/USD', 'volume' => 0.2, 'price' => 1.09, 'profit' => 0, 'time' => '2026-09-01T09:00:00Z']]);
$oFill = fillRow('O-in');
check('L5 open-position fill stays received (waits), no trade', $oFill && $oFill['processing_state'] === 'received' && tradeFor('O') === null, $oFill['processing_state'] ?? 'null');

// --- L6: partial close keeps position received until volume covered. -------
[$s6] = svc();
wh($s6, [
    ['id' => 'P-in', 'positionId' => 'P', 'entryType' => 'DEAL_ENTRY_IN', 'type' => 'DEAL_TYPE_BUY', 'symbol' => 'XAU/USD', 'volume' => 1.0, 'price' => 2000, 'profit' => 0, 'time' => '2026-09-01T10:00:00Z'],
    ['id' => 'P-o1', 'positionId' => 'P', 'entryType' => 'DEAL_ENTRY_OUT', 'type' => 'DEAL_TYPE_BUY', 'symbol' => 'XAU/USD', 'volume' => 0.4, 'price' => 2010, 'profit' => 4, 'time' => '2026-09-01T11:00:00Z'],
]);
check('L6 partial close (0.4<1.0): no trade yet, fills received', tradeFor('P') === null && fillRow('P-o1')['processing_state'] === 'received');
wh($s6, [
    ['id' => 'P-o2', 'positionId' => 'P', 'entryType' => 'DEAL_ENTRY_OUT', 'type' => 'DEAL_TYPE_BUY', 'symbol' => 'XAU/USD', 'volume' => 0.6, 'price' => 2020, 'profit' => 12, 'time' => '2026-09-01T12:00:00Z'],
]);
$pTrade = tradeFor('P');
check('L6 final partial closes position: 1 trade, close=12:00, volume 1.0', $pTrade && $pTrade['occurred_close_at_utc'] === '2026-09-01 12:00:00' && bccomp($pTrade['volume'], '1.0', 8) === 0, $pTrade ? $pTrade['occurred_close_at_utc'] : 'null');

// --- L7: naive brokerTime-only fill -> unresolved instant on the ledger. ---
[$s7] = svc();
wh($s7, [
    ['id' => 'N-in', 'positionId' => 'N', 'entryType' => 'DEAL_ENTRY_IN', 'type' => 'DEAL_TYPE_BUY', 'symbol' => 'USD/JPY', 'volume' => 0.1, 'price' => 150, 'profit' => 0, 'time' => '2026-09-01 13:00:00.000', 'brokerTime' => '2026-09-01 13:00:00.000'],
    ['id' => 'N-out', 'positionId' => 'N', 'entryType' => 'DEAL_ENTRY_OUT', 'type' => 'DEAL_TYPE_BUY', 'symbol' => 'USD/JPY', 'volume' => 0.1, 'price' => 151, 'profit' => 5, 'time' => '2026-09-01 17:00:00.000', 'brokerTime' => '2026-09-01 17:00:00.000'],
]);
$nFill = fillRow('N-in');
check('L7 naive-time fill ledgered but occurred_at_utc NULL/unresolved', $nFill && $nFill['occurred_at_utc'] === null && $nFill['time_status'] === 'unresolved', json_encode($nFill ? ['utc' => $nFill['occurred_at_utc'], 'ts' => $nFill['time_status']] : null));
check('L7 naive position never becomes a fabricated trade', tradeFor('N') === null);

// --- L8: multi-worker concurrency — two services race the same fills. ------
$raceIn = ['id' => 'R-in', 'positionId' => 'R', 'entryType' => 'DEAL_ENTRY_IN', 'type' => 'DEAL_TYPE_BUY', 'symbol' => 'GBP/USD', 'volume' => 0.1, 'price' => 1.27, 'profit' => 0, 'time' => '2026-09-01T10:00:00Z'];
$raceOut = ['id' => 'R-out', 'positionId' => 'R', 'entryType' => 'DEAL_ENTRY_OUT', 'type' => 'DEAL_TYPE_BUY', 'symbol' => 'GBP/USD', 'volume' => 0.1, 'price' => 1.28, 'profit' => 10, 'time' => '2026-09-01T14:00:00Z'];
[$wA] = svc();
[$wB] = svc();
// Both "workers" process the same complete fill batch concurrently.
$ra = wh($wA, [$raceIn, $raceOut]);
$rb = wh($wB, [$raceIn, $raceOut]);
check('L8 concurrent workers: exactly one trade for position R', (int) $pdo->query("SELECT COUNT(*) FROM trades WHERE external_deal_id='pos-R'")->fetchColumn() === 1, json_encode(['a' => $ra['inserted'], 'b' => $rb['inserted']]));
check('L8 concurrent workers: fill unique constraint held (2 fills)', (int) $pdo->query("SELECT COUNT(*) FROM metaapi_fills WHERE position_id='R'")->fetchColumn() === 2);

echo "\n" . ($failures === 0 ? "ALL PASS (Phase 5 fill ledger)" : "$failures FAILURE(S)") . "\n";
exit($failures === 0 ? 0 : 1);
