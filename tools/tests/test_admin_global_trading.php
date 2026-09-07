<?php

declare(strict_types=1);

use Velora\Admin\GlobalTradingController;
use Velora\Auth\AuthMiddleware;
use Velora\Auth\Role;
use Velora\Core\Request;

/**
 * Phase 4 — Global Trading Accounts + Global Trades: backend, authorization,
 * filters, pagination, determinism, secret exclusion and per-user behavior
 * preservation tests.
 *
 * Covers: global accounts listing (real stats, safe projection incl. masked
 * account fallback, credential blob never exposed, provider error text
 * omitted), global trades listing (camelCase projection, user identity via a
 * single per-page query), filter whitelist validation (status/platform/
 * direction/order/pnl/id), bounded pagination + clamps, deterministic
 * ordering with id tiebreaker, q scoping (symbol/strategy_tag — NOT the
 * users' private notes), honest empty results for nonexistent filter ids,
 * unauth/RBAC/plain-admin authorization, and preservation of the existing
 * per-user repository semantics. Every case runs in a dedicated child
 * process over one shared temp SQLite DB with the real services — no fake
 * data, no network.
 *
 * Run: php tools/tests/test_admin_global_trading.php
 */

$SELF = __FILE__;
$ROOT = sys_get_temp_dir() . '/velora-p4-test-' . bin2hex(random_bytes(5));

function spawn(string $self, string $root, string $case): array
{
    $cmd = 'php ' . escapeshellarg($self) . ' --child ' . escapeshellarg($case) . ' ' . escapeshellarg($root);
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $env = array_merge(getenv(), ['VELORA_TEST_CHILD' => '1']);
    $p = proc_open($cmd, $spec, $pipes, null, $env);
    fwrite($pipes[0], '');
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($p);
    return ['code' => $code, 'out' => $out, 'err' => $err];
}

if (!in_array(getenv('VELORA_TEST_CHILD'), ['1', 'true'], true)) {
    $failures = 0;
    $checks = 0;
    function check(bool $c, string $l): void
    {
        global $failures, $checks;
        $checks++;
        echo ($c ? '  PASS: ' : '  FAIL: ') . $l . "\n";
        if (!$c) {
            $failures++;
        }
    }

    $r = spawn($SELF, $ROOT, 'setup');
    check($r['code'] === 0 && str_contains($r['out'], '"ok"'), 'test setup child completed cleanly');

    // ===== Global trades: real rows, identity, determinism =====
    $r = spawn($SELF, $ROOT, 'trades_list');
    $j = json_decode($r['out'], true) ?: [];
    $tr = $j['data']['trades'] ?? [];
    check(($j['data']['pagination']['total'] ?? 0) === 6, 'global trades reports the real total (6 seeded)');
    check(($tr[0]['id'] ?? 0) === 104, 'deterministic default order (close_time DESC, id DESC): first = newest close');
    check(($tr[0]['userEmail'] ?? '') === 'target@example.com' && ($tr[0]['userFullName'] ?? '') === 'Tara Target', 'per-page user identity resolved (email + full name)');
    check((float) ($tr[0]['profitLoss'] ?? 0) === -310.25, 'camelCase projection carries the real stored P/L');

    $r = spawn($SELF, $ROOT, 'trades_page3');
    $j = json_decode($r['out'], true) ?: [];
    check(count($j['data']['trades'] ?? []) === 2 && ($j['data']['pagination']['has_more'] ?? true) === false, 'pagination page=3/per_page=2: 2 remaining rows, has_more=false');
    $r = spawn($SELF, $ROOT, 'trades_clamp');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['pagination']['per_page'] ?? 0) === 100 && ($j['data']['pagination']['page'] ?? 1) === 1, 'per_page clamped to 100, page floor at 1');

    $r = spawn($SELF, $ROOT, 'trades_symbol');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['pagination']['total'] ?? 0) === 2, 'symbol filter (exact match) returns the 2 XAUUSD trades');
    $r = spawn($SELF, $ROOT, 'trades_direction');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['pagination']['total'] ?? 0) === 2, 'direction filter (sell) returns the 2 sell trades');
    $r = spawn($SELF, $ROOT, 'trades_user');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['pagination']['total'] ?? 0) === 3 && count(array_unique(array_column($j['data']['trades'] ?? [], 'userId'))) === 1, 'user filter returns only that user\'s 3 trades');
    $r = spawn($SELF, $ROOT, 'trades_account');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['pagination']['total'] ?? 0) === 2, 'account filter returns the 2 trades of that account');
    $r = spawn($SELF, $ROOT, 'trades_window');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['pagination']['total'] ?? 0) === 2, 'date window (from/to on close_time) returns 2 trades');
    $r = spawn($SELF, $ROOT, 'trades_pnl_band');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['pagination']['total'] ?? 0) === 3, 'P/L band (-100 <= pnl <= 100) returns 3 trades');
    $r = spawn($SELF, $ROOT, 'trades_q');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['pagination']['total'] ?? 0) === 2, 'global q matches symbol prefix (XAU -> 2 trades)');
    $r = spawn($SELF, $ROOT, 'trades_q_notes');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['pagination']['total'] ?? 0) === 0, 'global q does NOT search the users\' private notes (documented scoping)');

    $r = spawn($SELF, $ROOT, 'trades_order_pnl');
    $j = json_decode($r['out'], true) ?: [];
    check((float) ($j['data']['trades'][0]['profitLoss'] ?? 999) === 260.0, 'whitelisted order=profit_loss sorts by real P/L DESC');

    // ===== Validation whitelist / errors =====
    $r = spawn($SELF, $ROOT, 'trades_bad_direction');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'invalid direction rejected (VALIDATION_FAILED)');
    $r = spawn($SELF, $ROOT, 'trades_bad_order');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'invalid sort field rejected (whitelist enforced)');
    $r = spawn($SELF, $ROOT, 'trades_bad_pnl');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'non-numeric pnl_min rejected');
    $r = spawn($SELF, $ROOT, 'trades_bad_user');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'non-numeric user_id rejected');

    // ===== Authorization =====
    $r = spawn($SELF, $ROOT, 'trades_unauth');
    check(str_contains($r['out'], 'ACCESS_TOKEN_MISSING') || str_contains($r['out'], 'UNAUTHORIZED'), 'unauthenticated global trades -> 401');
    $r = spawn($SELF, $ROOT, 'trades_rbac');
    check(str_contains($r['out'], 'PERMISSION_DENIED'), 'ordinary user denied global trades (RBAC)');
    $r = spawn($SELF, $ROOT, 'trades_admin_view');
    check(str_contains($r['out'], '"trades"'), 'plain admin (users.view) can list global trades');
    $r = spawn($SELF, $ROOT, 'accounts_rbac');
    check(str_contains($r['out'], 'PERMISSION_DENIED'), 'ordinary user denied global accounts (RBAC)');
    $r = spawn($SELF, $ROOT, 'accounts_admin_view');
    check(str_contains($r['out'], '"accounts"'), 'plain admin (users.view) can list global accounts');

    // ===== Honest emptiness for filter ids that match nothing =====
    $r = spawn($SELF, $ROOT, 'trades_ghost_user');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['pagination']['total'] ?? -1) === 0 && ($j['data']['trades'] ?? null) === [], 'nonexistent user_id filter -> honest empty (no fabrication, no 404)');

    // ===== Secret exclusion =====
    $r = spawn($SELF, $ROOT, 'trades_list');
    check(!str_contains($r['out'], 'password') && !str_contains($r['out'], 'token') && !str_contains($r['out'], 'credential'), 'global trades expose NO secret material');
    $r = spawn($SELF, $ROOT, 'accounts_list');
    check(!str_contains($r['out'], 'deadbeef') && !str_contains($r['out'], 'connection_credentials_encrypted') && !str_contains($r['out'], 'credential'), 'global accounts NEVER expose the encrypted credential blob');
    check(!str_contains($r['out'], 'MetaAPI 500 boom'), 'provider error text (last_error) omitted from global account rows');

    // ===== Global accounts: projection, stats, filters, pagination =====
    $r = spawn($SELF, $ROOT, 'accounts_list');
    $j = json_decode($r['out'], true) ?: [];
    $ac = $j['data']['accounts'] ?? [];
    check(($j['data']['pagination']['total'] ?? 0) === 5, 'global accounts reports the real total (5 seeded)');
    check(($ac[0]['broker'] ?? '') === 'GammaMarkets', 'deterministic default order (created_at DESC, id DESC)');
    $byBroker = [];
    foreach ($ac as $x) {
        $byBroker[$x['broker']] = $x;
    }
    check(($byBroker['BetaFX']['accountNumber'] ?? null) === '777001', 'masked account falls back to mt_login when no masked number exists');
    check(($byBroker['AlphaMarkets-Live']['accountNumber'] ?? $byBroker['AlphaMarkets']['accountNumber'] ?? null) === '***1234', 'masked account number shown where stored');
    $stats = $j['data']['stats']['byStatus'] ?? [];
    check(($stats['CONNECTED'] ?? 0) === 3 && ($stats['ERROR'] ?? 0) === 1 && ($stats['DISCONNECTED'] ?? 0) === 1, 'platform summary stats are the real GROUP BY counts');

    $r = spawn($SELF, $ROOT, 'accounts_status');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['pagination']['total'] ?? 0) === 3, 'status filter (CONNECTED) returns the 3 connected accounts');
    $r = spawn($SELF, $ROOT, 'accounts_platform');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['pagination']['total'] ?? 0) === 3, 'platform filter (MT5) returns the 3 MT5 accounts');
    $r = spawn($SELF, $ROOT, 'accounts_user');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['pagination']['total'] ?? 0) === 2, 'user filter returns only that user\'s 2 accounts');
    $r = spawn($SELF, $ROOT, 'accounts_q');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['pagination']['total'] ?? 0) === 2, 'accounts q matches broker text (AlphaMarkets -> 2)');
    $r = spawn($SELF, $ROOT, 'accounts_page2');
    $j = json_decode($r['out'], true) ?: [];
    check(count($j['data']['accounts'] ?? []) === 2 && ($j['data']['pagination']['has_more'] ?? false) === false, 'accounts pagination page=2/per_page=3: remainder + has_more=false');
    $r = spawn($SELF, $ROOT, 'accounts_order_balance');
    $j = json_decode($r['out'], true) ?: [];
    check((float) ($j['data']['accounts'][0]['balance'] ?? 0) === 5000.0, 'whitelisted order=balance sorts by real balance DESC');
    $r = spawn($SELF, $ROOT, 'accounts_bad_status');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'invalid status rejected (ENUM whitelist)');
    $r = spawn($SELF, $ROOT, 'accounts_bad_platform');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'invalid platform rejected (ENUM whitelist)');
    $r = spawn($SELF, $ROOT, 'accounts_unauth');
    check(str_contains($r['out'], 'ACCESS_TOKEN_MISSING') || str_contains($r['out'], 'UNAUTHORIZED'), 'unauthenticated global accounts -> 401');

    // ===== Existing per-user behavior preserved (canonical methods untouched) =====
    $r = spawn($SELF, $ROOT, 'legacy_per_user');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['searchTotal'] ?? 0) === 3 && ($j['allOwned'] ?? false) === true && ($j['accountsForUser'] ?? 0) === 2, 'existing per-user search/listByUser semantics preserved after the extension');

    echo "\nglobal-trading: " . ($failures === 0 ? 'PASS' : 'FAIL') . " ($checks checks, $failures failures)\n";
    exit($failures === 0 ? 0 : 1);
}

// ============================== child ==============================
$ROOTC = $argv[3];
mkdir($ROOTC . '/config', 0700, true);
mkdir($ROOTC . '/data', 0700, true);
file_put_contents($ROOTC . '/config/velora.env', "APP_ENV=local\nDB_DRIVER=sqlite\nDB_DATABASE={$ROOTC}/data/velora.sqlite\nJWT_SECRET=" . str_repeat('j', 48) . "\nAPP_ENCRYPTION_KEY=" . base64_encode(random_bytes(32)) . "\nCORS_ALLOWED_ORIGINS=http://localhost\nFRONTEND_URL=http://localhost\nMAIL_DRIVER=log\n");
putenv('APP_ENV=local');
putenv('VELORA_PRIVATE_ROOT=' . $ROOTC);
putenv('VELORA_DOCUMENT_ROOT=' . dirname(__DIR__, 2));
putenv('DB_DRIVER=sqlite');
putenv('DB_DATABASE=' . $ROOTC . '/data/velora.sqlite');
require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

$pdo = \Velora\Core\Database::connection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

function deny(callable $fn): void
{
    try {
        $fn();
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        http_response_code($e instanceof \Velora\Core\Exceptions\ApiException ? $e->httpStatus() : 500);
        echo json_encode(['error' => [
            'code' => $e instanceof \Velora\Core\Exceptions\ApiException ? $e->errorCode() : 'X',
            'class' => get_class($e),
        ]]);
    }
}

function mkRequest(string $path, array $query, string $role, int $uid, string $method = 'GET'): Request
{
    $headers = ['user-agent' => 'test-agent', 'x-request-id' => 'ctx-123', 'authorization' => 'Bearer ' . str_repeat('j', 48)];
    if ($role === 'guest') {
        unset($headers['authorization']);
    }
    $rq = new Request($method, $path, $query, [], $headers);
    $rq->attributes['user_role'] = $role === 'guest' ? '' : $role;
    $rq->attributes['user_id'] = $uid;
    return $rq;
}

$case = $argv[2];

if ($case === 'setup') {
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, password_hash TEXT DEFAULT \'\', full_name TEXT DEFAULT \'\', role TEXT NOT NULL DEFAULT \'user\', status TEXT NOT NULL DEFAULT \'active\', email_verified_at DATETIME NULL, locale TEXT DEFAULT \'fa\', locale_source TEXT DEFAULT \'auto\', timezone TEXT DEFAULT \'UTC\', plan TEXT NOT NULL DEFAULT \'free\', subscription_status TEXT NOT NULL DEFAULT \'none\', plan_started_at DATETIME NULL, plan_expires_at DATETIME NULL, plan_updated_at DATETIME NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS trading_accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
        provider TEXT NOT NULL DEFAULT 'MANUAL', platform TEXT NOT NULL DEFAULT 'MANUAL',
        broker TEXT NULL, server TEXT NULL, timezone TEXT NULL, timezone_source TEXT NOT NULL DEFAULT 'unknown',
        mt_login TEXT NULL, account_type TEXT NOT NULL DEFAULT 'STANDARD', metaapi_account_id TEXT NULL,
        sync_status TEXT NOT NULL DEFAULT 'DISCONNECTED', last_synced_at DATETIME NULL,
        connection_credentials_encrypted BLOB NULL, connected_at DATETIME NULL, disconnected_at DATETIME NULL,
        auto_sync_enabled INTEGER NOT NULL DEFAULT 1, last_incremental_at DATETIME NULL,
        connection_checked_at DATETIME NULL, consecutive_errors INTEGER NOT NULL DEFAULT 0,
        last_error TEXT NULL, dev_force_error INTEGER NOT NULL DEFAULT 0,
        starting_balance REAL NOT NULL DEFAULT 0, current_balance REAL NOT NULL DEFAULT 0,
        label TEXT NOT NULL DEFAULT '', account_number_masked TEXT NOT NULL DEFAULT '',
        currency TEXT NOT NULL DEFAULT 'USD', leverage TEXT NULL,
        status TEXT NOT NULL DEFAULT 'disconnected', balance REAL NOT NULL DEFAULT 0,
        equity REAL NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS trades (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, account_id INTEGER NULL,
        external_deal_id TEXT NULL, symbol TEXT NOT NULL, direction TEXT NOT NULL,
        entry_price TEXT NOT NULL, exit_price TEXT NOT NULL, volume TEXT NOT NULL,
        contract_size TEXT NOT NULL DEFAULT '1.00000000', commission TEXT NOT NULL DEFAULT '0.00000000',
        swap TEXT NOT NULL DEFAULT '0.00000000', profit_loss REAL NOT NULL, r_multiple REAL NULL,
        stop_loss TEXT NULL, take_profit TEXT NULL, open_time DATETIME NOT NULL, close_time DATETIME NOT NULL,
        occurred_open_at_utc DATETIME NULL, occurred_close_at_utc DATETIME NULL, time_status TEXT NULL,
        source_timezone TEXT NULL, source_timezone_source TEXT NULL, source_calendar TEXT NULL,
        raw_open_text TEXT NULL, raw_close_text TEXT NULL,
        strategy_tag TEXT NULL, emotional_score INTEGER NULL, notes TEXT NULL,
        source TEXT NOT NULL DEFAULT 'manual', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");

    $ins = $pdo->prepare('INSERT INTO users (email, password_hash, full_name, role, status, email_verified_at) VALUES (?, ?, ?, ?, ?, ?)');
    $cost = 4;
    $ins->execute(['super@velora.test', password_hash('SuperAdmin!1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Arman Kaveh', 'super_admin', 'active', gmdate('Y-m-d H:i:s')]);
    $ins->execute(['user@velora.test', password_hash('PlainUser!1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Plain User', 'user', 'active', gmdate('Y-m-d H:i:s')]);
    $ins->execute(['admin@velora.test', password_hash('AdminUser!1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Ada Admin', 'admin', 'active', gmdate('Y-m-d H:i:s')]);
    $ins->execute(['carol@example.com', password_hash('CarolPass!1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Carol Verified', 'user', 'active', gmdate('Y-m-d H:i:s')]);
    $ins->execute(['target@example.com', password_hash('Target!Pass1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Tara Target', 'user', 'active', gmdate('Y-m-d H:i:s')]);

    // 5 accounts across 3 users; A3 carries a real encrypted-blob value to prove non-exposure
    $pdo->exec("INSERT INTO trading_accounts (id, user_id, provider, platform, broker, server, mt_login, account_number_masked, sync_status, balance, equity, label, last_error, connection_credentials_encrypted, created_at) VALUES
        (201, 4, 'MT5', 'MT5', 'AlphaMarkets', 'Alpha-Live', '501234', '***1234', 'CONNECTED', '1250.75', '1300.10', 'Carol Main', NULL, NULL, '2026-09-01 08:00:00'),
        (202, 4, 'MT4', 'MT4', 'BetaFX', 'Beta-02', '777001', '', 'DISCONNECTED', '80.00', '80.00', 'Beta small', NULL, NULL, '2026-09-02 09:00:00'),
        (203, 5, 'MT5', 'MT5', 'AlphaMarkets', 'Alpha-Live', '900555', '***0555', 'ERROR', '900.00', '880.00', 'Tara Prop', 'MetaAPI 500 boom', X'DEADBEEFCAFE', '2026-09-03 10:00:00'),
        (204, 5, 'MANUAL', 'MANUAL', NULL, NULL, NULL, '', 'CONNECTED', '300.00', '300.00', 'Manual Journal', NULL, NULL, '2026-09-04 11:00:00'),
        (205, 2, 'MT5', 'MT5', 'GammaMarkets', 'Gamma-1', '311000', '***1000', 'CONNECTED', '5000.00', '4950.00', 'Plain big', NULL, NULL, '2026-09-05 12:00:00')");

    // 6 trades; close_time DESC => 104,102,101,103,106,105
    $pdo->exec("INSERT INTO trades (id, user_id, account_id, symbol, direction, entry_price, exit_price, volume, profit_loss, open_time, close_time, strategy_tag, notes, source) VALUES
        (101, 4, 201, 'XAUUSD', 'buy',  '2400.10', 2412.60, '0.50', '120.50000000',  '2026-09-05 08:00:00', '2026-09-05 10:00:00', 'breakout',  'private note carol', 'manual'),
        (102, 4, 201, 'EURUSD', 'sell', '1.08500', '1.08950', '1.00', '-45.00000000', '2026-09-06 09:00:00', '2026-09-06 12:30:00', 'reversal',  NULL, 'manual'),
        (103, 5, 203, 'XAUUSD', 'sell', '2415.00', '2389.00', '1.00', '260.00000000', '2026-09-04 13:00:00', '2026-09-04 15:00:00', 'news-fade', 'SECRETNOTE', 'auto_sync'),
        (104, 5, 203, 'BTCUSD', 'buy',  '58000.0', '57690.0', '0.10', '-310.25000000', '2026-09-06 15:00:00', '2026-09-06 18:45:00', NULL, NULL, 'auto_sync'),
        (105, 2, 205, 'USDJPY', 'buy',  '142.500', '142.850', '0.80', '75.10000000',  '2026-09-01 07:00:00', '2026-09-01 09:15:00', 'trend',     NULL, 'manual'),
        (106, 4, 202, 'GBPUSD', 'buy',  '1.26500', '1.26650', '0.40', '10.00000000',  '2026-09-03 09:30:00', '2026-09-03 11:00:00', NULL, NULL, 'manual')");
    echo json_encode(['ok' => true]);
    exit(0);
}

$ctrl = new GlobalTradingController();

switch ($case) {
    case 'trades_list': $ctrl->trades(mkRequest('/api/v1/admin/trades', [], 'super_admin', 1)); break;
    case 'trades_page3': $ctrl->trades(mkRequest('/api/v1/admin/trades', ['page' => 3, 'per_page' => 2], 'super_admin', 1)); break;
    case 'trades_clamp': $ctrl->trades(mkRequest('/api/v1/admin/trades', ['page' => -3, 'per_page' => 500], 'super_admin', 1)); break;
    case 'trades_symbol': $ctrl->trades(mkRequest('/api/v1/admin/trades', ['symbol' => 'XAUUSD'], 'super_admin', 1)); break;
    case 'trades_direction': $ctrl->trades(mkRequest('/api/v1/admin/trades', ['direction' => 'sell'], 'super_admin', 1)); break;
    case 'trades_user': $ctrl->trades(mkRequest('/api/v1/admin/trades', ['user_id' => 4], 'super_admin', 1)); break;
    case 'trades_account': $ctrl->trades(mkRequest('/api/v1/admin/trades', ['account_id' => 201], 'super_admin', 1)); break;
    case 'trades_window': $ctrl->trades(mkRequest('/api/v1/admin/trades', ['from' => '2026-09-04 00:00:00', 'to' => '2026-09-05 23:59:59'], 'super_admin', 1)); break;
    case 'trades_pnl_band': $ctrl->trades(mkRequest('/api/v1/admin/trades', ['pnl_min' => '-100', 'pnl_max' => '100'], 'super_admin', 1)); break;
    case 'trades_q': $ctrl->trades(mkRequest('/api/v1/admin/trades', ['q' => 'XAU'], 'super_admin', 1)); break;
    case 'trades_q_notes': $ctrl->trades(mkRequest('/api/v1/admin/trades', ['q' => 'private note carol'], 'super_admin', 1)); break;
    case 'trades_order_pnl': $ctrl->trades(mkRequest('/api/v1/admin/trades', ['order' => 'profit_loss'], 'super_admin', 1)); break;
    case 'trades_bad_direction': deny(fn () => $ctrl->trades(mkRequest('/api/v1/admin/trades', ['direction' => 'bogus'], 'super_admin', 1))); break;
    case 'trades_bad_order': deny(fn () => $ctrl->trades(mkRequest('/api/v1/admin/trades', ['order' => 'balance'], 'super_admin', 1))); break;
    case 'trades_bad_pnl': deny(fn () => $ctrl->trades(mkRequest('/api/v1/admin/trades', ['pnl_min' => 'abc'], 'super_admin', 1))); break;
    case 'trades_bad_user': deny(fn () => $ctrl->trades(mkRequest('/api/v1/admin/trades', ['user_id' => 'abc'], 'super_admin', 1))); break;
    case 'trades_unauth': deny(fn () => (AuthMiddleware::authenticate())(mkRequest('/api/v1/admin/trades', [], 'guest', 0))); break;
    case 'trades_rbac': deny(fn () => (AuthMiddleware::requirePermission(Role::P_USERS_VIEW))(mkRequest('/api/v1/admin/trades', [], 'user', 2))); break;
    case 'trades_admin_view': $ctrl->trades(mkRequest('/api/v1/admin/trades', [], 'admin', 3)); break;
    case 'trades_ghost_user': $ctrl->trades(mkRequest('/api/v1/admin/trades', ['user_id' => 9999], 'super_admin', 1)); break;
    case 'accounts_list': $ctrl->accounts(mkRequest('/api/v1/admin/trading-accounts', [], 'super_admin', 1)); break;
    case 'accounts_status': $ctrl->accounts(mkRequest('/api/v1/admin/trading-accounts', ['status' => 'CONNECTED'], 'super_admin', 1)); break;
    case 'accounts_platform': $ctrl->accounts(mkRequest('/api/v1/admin/trading-accounts', ['platform' => 'MT5'], 'super_admin', 1)); break;
    case 'accounts_user': $ctrl->accounts(mkRequest('/api/v1/admin/trading-accounts', ['user_id' => 4], 'super_admin', 1)); break;
    case 'accounts_q': $ctrl->accounts(mkRequest('/api/v1/admin/trading-accounts', ['q' => 'AlphaMarkets'], 'super_admin', 1)); break;
    case 'accounts_page2': $ctrl->accounts(mkRequest('/api/v1/admin/trading-accounts', ['page' => 2, 'per_page' => 3], 'super_admin', 1)); break;
    case 'accounts_order_balance': $ctrl->accounts(mkRequest('/api/v1/admin/trading-accounts', ['order' => 'balance'], 'super_admin', 1)); break;
    case 'accounts_bad_status': deny(fn () => $ctrl->accounts(mkRequest('/api/v1/admin/trading-accounts', ['status' => 'BOGUS'], 'super_admin', 1))); break;
    case 'accounts_bad_platform': deny(fn () => $ctrl->accounts(mkRequest('/api/v1/admin/trading-accounts', ['platform' => 'CTRADER'], 'super_admin', 1))); break;
    case 'accounts_unauth': deny(fn () => (AuthMiddleware::authenticate())(mkRequest('/api/v1/admin/trading-accounts', [], 'guest', 0))); break;
    case 'accounts_rbac': deny(fn () => (AuthMiddleware::requirePermission(Role::P_USERS_VIEW))(mkRequest('/api/v1/admin/trading-accounts', [], 'user', 2))); break;
    case 'accounts_admin_view': $ctrl->accounts(mkRequest('/api/v1/admin/trading-accounts', [], 'admin', 3)); break;
    case 'legacy_per_user': {
        $repo = new \Velora\Trades\TradeRepository();
        $res = $repo->search(['user_id' => 4], ['limit' => 10, 'offset' => 0, 'order' => 'close_time']);
        $allOwned = true;
        foreach ($res['items'] as $row) {
            $allOwned = $allOwned && (int) $row['user_id'] === 4;
        }
        $accounts = (new \Velora\Accounts\AccountRepository())->listByUser(4);
        echo json_encode(['searchTotal' => $res['total'], 'allOwned' => $allOwned, 'accountsForUser' => count($accounts)]);
        break;
    }
    default: echo json_encode(['error' => 'unknown case']); exit(1);
}
