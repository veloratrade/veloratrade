<?php

declare(strict_types=1);

/**
 * Phase 2A — canonical trade-time foundation (schema/domain compatibility).
 *
 * Runs against an in-memory-style SQLite database. Verifies:
 *   - new canonical/provenance columns are nullable and default unresolved
 *   - legacy open_time/close_time remain mandatory and are NOT reinterpreted
 *   - new trades can be created with canonical columns left NULL (unresolved)
 *   - a resolved trade may carry canonical UTC + IANA source timezone
 *   - account timezone column is nullable and validated
 *   - TradeService serialization exposes new fields additively while
 *     openTime/closeTime keep their existing (legacy) meaning
 *
 * No production data is touched. No timestamps are backfilled or converted.
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
$root = sys_get_temp_dir() . '/velora-tz-foundation-' . bin2hex(random_bytes(5));
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
use Velora\Core\Database;
use Velora\Trades\TimezoneResolver;

$pdo = Database::connection();

// ---- Minimal schema mirroring the v1.0 canonical columns ----
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
$pdo->exec("INSERT INTO users (id, email) VALUES (1, 'tz@example.test')");

// ---- Account timezone nullable + default unknown ----
$repo = new AccountRepository();
$accountId = $repo->create([
    'user_id' => 1,
    'provider' => 'MANUAL',
    'platform' => 'MANUAL',
    'label' => 'Journal',
    'account_number_masked' => '',
    'currency' => 'USD',
    'leverage' => null,
    'status' => 'disconnected',
]);
$account = $repo->findByIdForUser($accountId, 1);
check('account timezone defaults to NULL', $account['timezone'] === null, 'tz=' . var_export($account['timezone'], true));
check('account timezone_source defaults to unknown', $account['timezone_source'] === 'unknown');

$repo->updateTimezone($accountId, 'Europe/London', 'user_config');
$account = $repo->findByIdForUser($accountId, 1);
check('account timezone set to validated IANA', $account['timezone'] === 'Europe/London');
check('account timezone_source recorded', $account['timezone_source'] === 'user_config');

$repo->updateTimezone($accountId, null, 'unknown');
$account = $repo->findByIdForUser($accountId, 1);
check('account timezone cleared back to NULL/unknown', $account['timezone'] === null && $account['timezone_source'] === 'unknown');

// ---- Trade: legacy columns present; canonical defaults unresolved ----
$pdo->prepare("INSERT INTO trades (user_id, symbol, direction, entry_price, exit_price, volume,
        contract_size, commission, swap, profit_loss, open_time, close_time, source)
    VALUES (1,'EURUSD','buy','1.080','1.090','0.1','1','0','0','10',
        '2026-08-31 14:30:00','2026-08-31 15:42:00','manual')")->execute();
$legacyId = (int) $pdo->lastInsertId();

$stmt = $pdo->prepare('SELECT occurred_open_at_utc, occurred_close_at_utc, time_status,
        source_timezone, source_timezone_source, source_calendar, raw_open_text, raw_close_text,
        open_time, close_time FROM trades WHERE id=:id');
$stmt->execute(['id' => $legacyId]);
$legacy = $stmt->fetch();

check('legacy open_time unchanged', $legacy['open_time'] === '2026-08-31 14:30:00');
check('legacy close_time unchanged', $legacy['close_time'] === '2026-08-31 15:42:00');
check('canonical open defaults to NULL (no fabricated UTC)', $legacy['occurred_open_at_utc'] === null);
check('canonical close defaults to NULL (no fabricated UTC)', $legacy['occurred_close_at_utc'] === null);
check('time_status defaults to unresolved', $legacy['time_status'] === 'unresolved');
check('source_timezone defaults to NULL', $legacy['source_timezone'] === null);
check('source_timezone_source defaults to unknown', $legacy['source_timezone_source'] === 'unknown');
check('source_calendar defaults to unknown', $legacy['source_calendar'] === 'unknown');
check('raw_open_text defaults to NULL', $legacy['raw_open_text'] === null);

// ---- Resolved trade: canonical + IANA tz coexist with raw evidence ----
$pdo->prepare("INSERT INTO trades (user_id, symbol, direction, entry_price, exit_price, volume,
        contract_size, commission, swap, profit_loss, open_time, close_time,
        occurred_open_at_utc, occurred_close_at_utc, time_status,
        source_timezone, source_timezone_source, source_calendar, raw_open_text, raw_close_text, source)
    VALUES (1,'XAUUSD','sell','2000','1990','0.1','1','0','0','10',
        '2026-08-31 14:30:00','2026-08-31 15:42:00',
        '2026-08-31 18:30:00','2026-08-31 19:42:00','resolved',
        'America/New_York','account_config','gregorian',
        '31/08/2026 14:30','31/08/2026 15:42','manual')")->execute();
$resolvedId = (int) $pdo->lastInsertId();
$stmt->execute(['id' => $resolvedId]);
$resolved = $stmt->fetch();
check('resolved trade stores canonical open UTC', $resolved['occurred_open_at_utc'] === '2026-08-31 18:30:00');
check('resolved trade time_status = resolved', $resolved['time_status'] === 'resolved');
check('resolved trade keeps IANA source timezone', $resolved['source_timezone'] === 'America/New_York');
check('resolved trade keeps verbatim raw text', $resolved['raw_open_text'] === '31/08/2026 14:30');

// ---- Resolver -> canonical linkage uses valid IANA (NY example) ----
$resolver = new TimezoneResolver();
$r = $resolver->resolve(['accountConfig' => 'America/New_York']);
check('resolver yields account-configured IANA zone', $r['timezone'] === 'America/New_York' && $r['source'] === 'account_config');
$r2 = $resolver->resolve(['accountConfig' => 'New York']);
check('resolver rejects display label "New York" -> unknown', $r2['timezone'] === null);

// ---- Serialization compatibility (additive fields) ----
// Build a synthetic row the way TradeService::serialize consumes it.
$row = [
    'id' => $resolvedId, 'symbol' => 'XAUUSD', 'direction' => 'sell',
    'entry_price' => '2000', 'exit_price' => '1990', 'volume' => '0.10000000',
    'contract_size' => '1', 'commission' => '0', 'swap' => '0', 'profit_loss' => '10',
    'r_multiple' => null, 'stop_loss' => null, 'take_profit' => null,
    'account_id' => null, 'open_time' => '2026-08-31 14:30:00', 'close_time' => '2026-08-31 15:42:00',
    'occurred_open_at_utc' => '2026-08-31 18:30:00', 'occurred_close_at_utc' => '2026-08-31 19:42:00',
    'time_status' => 'resolved', 'source_timezone' => 'America/New_York',
    'source_timezone_source' => 'account_config', 'source_calendar' => 'gregorian',
    'raw_open_text' => '31/08/2026 14:30', 'raw_close_text' => '31/08/2026 15:42',
    'strategy_tag' => null, 'emotional_score' => null, 'notes' => null, 'source' => 'manual',
    'created_at' => '2026-09-01 00:00:00', 'updated_at' => '2026-09-01 00:00:00',
];
// Invoke the serializer to confirm additive keys exist and legacy
// openTime/closeTime are unchanged.
$svc = new \Velora\Trades\TradeService();
$out = $svc->serialize($row);
if (true) {
    check('legacy openTime still present and unchanged', ($out['openTime'] ?? null) === '2026-08-31 14:30:00', json_encode($out['openTime'] ?? null));
    check('legacy closeTime still present and unchanged', ($out['closeTime'] ?? null) === '2026-08-31 15:42:00');
    check('occurredOpenAtUtc exposed additively', ($out['occurredOpenAtUtc'] ?? null) === '2026-08-31 18:30:00');
    check('timeStatus exposed additively', ($out['timeStatus'] ?? null) === 'resolved');
    check('sourceTimezone exposed additively', ($out['sourceTimezone'] ?? null) === 'America/New_York');
    check('sourceCalendar exposed additively', ($out['sourceCalendar'] ?? null) === 'gregorian');
}

echo $failures === 0 ? "\nALL TRADE-TIME FOUNDATION TESTS PASSED\n" : "\n$failures TEST(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
