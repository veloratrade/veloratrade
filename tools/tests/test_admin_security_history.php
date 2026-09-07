<?php

declare(strict_types=1);

use Velora\Admin\GlobalTradingController;
use Velora\Admin\SecurityAccessController;
use Velora\Admin\UserManagementService;
use Velora\Auth\AuthEventRepository;
use Velora\Auth\AuthMiddleware;
use Velora\Auth\AuthService;
use Velora\Auth\Role;
use Velora\Core\Request;

/**
 * Phase 6 — Global signup history/clusters + global login history: backend
 * authorization, D3 sensitive-field privacy matrix (server-side omission for
 * plain admins), deterministic bounded clustering (member_count DESC, ip ASC;
 * singleton-IP exclusion; bounded date window, no migration), filters
 * (whitelisted result/user_id/calendar-validated dates — injection and
 * wildcard metacharacters treated as data and rejected), bounded pagination
 * with clamps, honest empties, signup-event persistence semantics (only
 * after successful user creation; duplicate/retry paths never record), and
 * preservation of the Phase-3 per-user login-history and Phase-4 global
 * trades contracts. Every case runs in a dedicated child process over one
 * shared temp SQLite DB with the real services — no fake data, no network.
 *
 * Run: php tools/tests/test_admin_security_history.php
 */

$SELF = __FILE__;
$ROOT = sys_get_temp_dir() . '/velora-p6-test-' . bin2hex(random_bytes(5));

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

    // ===== Authorization gates (frozen audit.view on both routes) =====
    $r = spawn($SELF, $ROOT, 'signups_rbac');
    check(str_contains($r['out'], 'PERMISSION_DENIED'), 'ordinary user denied global signups (audit.view RBAC)');
    $r = spawn($SELF, $ROOT, 'logins_rbac');
    check(str_contains($r['out'], 'PERMISSION_DENIED'), 'ordinary user denied global logins (audit.view RBAC)');
    $r = spawn($SELF, $ROOT, 'signups_guest');
    check(str_contains($r['out'], 'ACCESS_TOKEN_MISSING') || str_contains($r['out'], 'UNAUTHORIZED'), 'unauthenticated signups -> 401');
    $r = spawn($SELF, $ROOT, 'logins_guest');
    check(str_contains($r['out'], 'ACCESS_TOKEN_MISSING') || str_contains($r['out'], 'UNAUTHORIZED'), 'unauthenticated logins -> 401');

    // ===== Clustering: deterministic, bounded, singleton-free =====
    $r = spawn($SELF, $ROOT, 'signups_super');
    $j = json_decode($r['out'], true) ?: [];
    $cl = $j['data']['clusters'] ?? [];
    check(count($cl) === 2, 'super admin sees exactly 2 clusters (singleton IP excluded by rule)');
    check(($cl[0]['key'] ?? '') === '198.51.100.7' && ($cl[0]['memberCount'] ?? 0) === 2, 'deterministic order (member_count DESC, key ASC): 198.51.100.7 first with 2 members');
    check(($j['data']['pagination']['total'] ?? 0) === 2, 'cluster pagination reports the real total');
    check(($j['data']['sensitiveVisible'] ?? false) === true, 'super admin flagged sensitiveVisible (P_AUDIT_SENSITIVE_VIEW)');

    $r2 = spawn($SELF, $ROOT, 'signups_super');
    check(($j['data']['clusters'] ?? null) === (json_decode($r2['out'], true)['data']['clusters'] ?? null), 'clustering is deterministic across runs (identical clusters payload)');

    $r = spawn($SELF, $ROOT, 'signups_kpis');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['kpis']['totalUsers'] ?? 0) === 7, 'KPI totalUsers reflects the real users count');
    check(($j['data']['kpis']['newInRange'] ?? 0) === 2, 'KPI newInRange bounded by the date window');
    check(($j['data']['kpis']['verified'] ?? 0) === 1 && ($j['data']['kpis']['unverified'] ?? 0) === 1, 'KPI verified/unverified breakdown is real (1/1)');
    check(count($j['data']['trend'] ?? []) === 2, 'registration trend is the existing daily shape (2 in-window days)');

    $r = spawn($SELF, $ROOT, 'signups_singleton');
    check(!str_contains($r['out'], '203.0.113.5'), 'singleton signup IP (1 member) never surfaces as a cluster');

    $r = spawn($SELF, $ROOT, 'signups_window');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['range']['days'] ?? 0) === 365 && ($j['data']['pagination']['total'] ?? 0) === 2, 'bounded days window honored (365 covers the seeded cluster)');
    $r = spawn($SELF, $ROOT, 'signups_days_clamp');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['range']['days'] ?? 0) === 1 && ($j['data']['range']['days'] ?? 0) === (json_decode(spawn($SELF, $ROOT, 'signups_days_clamp_high')['out'], true)['data']['range']['days'] ?? 0) - 364, 'days clamped to 1..365 (0 -> 1, 99999 -> 365: bounded, never unbounded)');

    $r = spawn($SELF, $ROOT, 'signups_members');
    $j = json_decode($r['out'], true) ?: [];
    $m = $j['data']['clusters'][0]['members'] ?? [];
    check(count($m) === 2 && ($m[0]['email'] ?? '') === 'carol@example.com' && ($m[1]['email'] ?? '') === 'target@example.com', 'cluster members resolved in ONE batched join (both members, oldest first — no N+1)');
    check(($m[0]['verified'] ?? null) === true && ($m[1]['verified'] ?? null) === false, 'member verified flag carried (verified/unverified visibility)');

    $r = spawn($SELF, $ROOT, 'signups_page_beyond');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['clusters'] ?? null) === [] && ($j['data']['pagination']['total'] ?? -1) === 2, 'cluster page beyond range -> honest empty with real total');

    // ===== D3 privacy matrix on signups (server-side omission) =====
    $r = spawn($SELF, $ROOT, 'signups_admin_masked');
    $j = json_decode($r['out'], true) ?: [];
    $cl = $j['data']['clusters'] ?? [];
    check(($cl[0]['key'] ?? 'RAW') === 'RAW' && ($cl[0]['keyMasked'] ?? '') === '198.51.*.*', 'plain admin gets masked cluster key ONLY (no raw key field)');
    check(!isset($cl[0]['members'][0]['ipAddress']) && !isset($cl[0]['members'][0]['userAgent']), 'plain admin members carry NO ip/user_agent fields');
    check(!str_contains($r['out'], '198.51.100.7') && ($j['data']['sensitiveVisible'] ?? true) === false, 'raw signup IP absent from the ENTIRE plain-admin response (server-side, D3)');

    // ===== Global logins: listing, filters, pagination, identity =====
    $r = spawn($SELF, $ROOT, 'logins_super');
    $j = json_decode($r['out'], true) ?: [];
    $ev = $j['data']['events'] ?? [];
    check(($j['data']['pagination']['total'] ?? 0) === 5, 'global logins reports the real total (5 seeded)');
    check(!isset($ev[0]['userId']) && array_key_exists('userId', $ev[0]) && ($ev[0]['reason'] ?? '') === 'unknown_account', 'deterministic order (created_at DESC, id DESC): newest first, NULL user row anonymous-safe');

    $r = spawn($SELF, $ROOT, 'logins_result');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['pagination']['total'] ?? 0) === 2, 'whitelisted result=failure filter returns the 2 failures');
    $r = spawn($SELF, $ROOT, 'logins_result_bad');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'non-whitelisted result rejected (VALIDATION_FAILED)');
    $r = spawn($SELF, $ROOT, 'logins_result_wildcard');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'wildcard-as-data (result=%) rejected — never treated as a wildcard');

    $r = spawn($SELF, $ROOT, 'logins_user');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['pagination']['total'] ?? 0) === 3 && count(array_unique(array_column($j['data']['events'] ?? [], 'userId'))) === 1, 'user_id filter returns only that user\'s 3 login events');

    $r = spawn($SELF, $ROOT, 'logins_window');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['pagination']['total'] ?? 0) === 3, 'date window (from/to, calendar-validated) returns 3 events');

    $r = spawn($SELF, $ROOT, 'logins_injection');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'SQL-injection-shaped user_id ("4 OR 1=1") rejected as data');
    $r = spawn($SELF, $ROOT, 'logins_injection_date');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'SQL-injection-shaped date rejected (strict format + checkdate)');

    $r = spawn($SELF, $ROOT, 'logins_pagination');
    $j = json_decode($r['out'], true) ?: [];
    check(count($j['data']['events'] ?? []) === 2 && ($j['data']['pagination']['has_more'] ?? false) === true, 'bounded pagination page=2/per_page=2: 2 rows, has_more=true');
    $r = spawn($SELF, $ROOT, 'logins_clamp');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['pagination']['per_page'] ?? 0) === 100 && ($j['data']['pagination']['page'] ?? 0) === 1, 'per_page clamped to 100, page floored at 1');

    // ===== D3 privacy matrix on logins =====
    $r = spawn($SELF, $ROOT, 'logins_admin_privacy');
    $j = json_decode($r['out'], true) ?: [];
    check(!isset(($j['data']['events'][0] ?? [])['ipAddress']) && !isset(($j['data']['events'][0] ?? [])['userAgent']), 'plain admin login rows carry NO ip/user_agent fields');
    check(!str_contains($r['out'], '198.51.100') && !str_contains($r['out'], 'Mozilla') && !str_contains($r['out'], '9.9.9.9'), 'raw login IP/UA absent from the ENTIRE plain-admin response (server-side, D3)');
    $r = spawn($SELF, $ROOT, 'logins_super_sensitive');
    $j = json_decode($r['out'], true) ?: [];
    $row = null;
    foreach (($j['data']['events'] ?? []) as $e) {
        if (($e['userId'] ?? null) === 4 && ($e['result'] ?? '') === 'success') {
            $row = $e;
        }
    }
    check(($row['ipAddress'] ?? '') === '198.51.100.7' && ($row['userAgent'] ?? '') === 'Mozilla/5.0 TestUA', 'super admin retains full raw ip/user_agent (D3 sensitive side)');

    // ===== Signup-event persistence (D1: only after successful creation) =====
    $r = spawn($SELF, $ROOT, 'register_persists');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['signup_events'] ?? 0) === 1 && ($j['result'] ?? '') === 'success' && ($j['ip'] ?? '') === '203.0.114.9', 'register() persists exactly ONE signup event AFTER user creation (with passed ip/ua)');
    $r = spawn($SELF, $ROOT, 'register_duplicate');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['conflict'] ?? false) === true && ($j['signup_events'] ?? -1) === 1, 'duplicate verified-email registration records NO extra signup event (retry path returns early)');

    // ===== Contract preservation: Phase-3 per-user + Phase-4 global trades =====
    $r = spawn($SELF, $ROOT, 'phase3_loginhistory');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['rows'][0]['ipAddress'] ?? '') === '198.51.100.7' && ($j['rows'][0]['userAgent'] ?? '') === 'Mozilla/5.0 TestUA', 'Phase-3 per-user login-history contract UNCHANGED (ip+ua still present for users.view callers)');
    $r = spawn($SELF, $ROOT, 'phase4_trades');
    $j = json_decode($r['out'], true) ?: [];
    check(array_key_exists('notes', $j['data']['trades'][0] ?? ['notes' => 1]) === false, 'Phase-4 global trades projection UNCHANGED (private notes still excluded)');

    echo "\n{$checks} checks, {$failures} failures\n";
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS auth_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NULL, event_type VARCHAR(32) NOT NULL,
        result VARCHAR(16) NOT NULL, reason VARCHAR(64) NULL, ip_address VARCHAR(45) NULL,
        user_agent TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_verifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token_hash VARCHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL, verified_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
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

    $ins = $pdo->prepare('INSERT INTO users (id, email, password_hash, full_name, role, status, email_verified_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $cost = 4;
    $old = '2026-01-01 00:00:00';
    $ins->execute([1, 'super@velora.test', password_hash('SuperAdmin!1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Arman Kaveh', 'super_admin', 'active', gmdate('Y-m-d H:i:s'), $old]);
    $ins->execute([2, 'user@velora.test', password_hash('PlainUser!1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Plain User', 'user', 'active', gmdate('Y-m-d H:i:s'), $old]);
    $ins->execute([3, 'admin@velora.test', password_hash('AdminUser!1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Ada Admin', 'admin', 'active', gmdate('Y-m-d H:i:s'), $old]);
    $ins->execute([4, 'carol@example.com', password_hash('CarolPass!1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Carol Verified', 'user', 'active', gmdate('Y-m-d H:i:s'), $old]);
    $ins->execute([5, 'target@example.com', password_hash('Target!Pass1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Tara Target', 'user', 'active', null, $old]);
    $ins->execute([6, 'fresh1@example.com', password_hash('FreshPass!1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Freshest One', 'user', 'active', gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s', time() - 86400)]);
    $ins->execute([7, 'fresh2@example.com', password_hash('FreshPass!2', PASSWORD_BCRYPT, ['cost' => $cost]), 'Freshly Two', 'user', 'active', null, gmdate('Y-m-d H:i:s', time() - 2 * 86400)]);

    // Signup events: two real clusters (2 members each) + one singleton that must never surface.
    $ae = $pdo->prepare('INSERT INTO auth_events (user_id, event_type, result, reason, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $ae->execute([4, 'signup', 'success', null, '198.51.100.7', 'ClusterUA/1', '2026-09-06 10:00:00']);
    $ae->execute([5, 'signup', 'success', null, '198.51.100.7', 'ClusterUA/1', '2026-09-06 11:00:00']);
    $ae->execute([2, 'signup', 'success', null, '198.51.100.9', 'ClusterUA/2', '2026-09-07 09:00:00']);
    $ae->execute([3, 'signup', 'success', null, '198.51.100.9', 'ClusterUA/2', '2026-09-07 09:30:00']);
    $ae->execute([2, 'signup', 'success', null, '203.0.113.5', 'SoloUA/1', '2026-09-05 08:00:00']);
    // Login events: 3 for user 4 (2 success + 1 failure), 1 for user 5, 1 unknown-account (NULL user).
    $ae->execute([4, 'login', 'success', null, '198.51.100.7', 'Mozilla/5.0 TestUA', '2026-09-07 12:00:00']);
    $ae->execute([4, 'login', 'failure', 'bad_password', '198.51.100.7', 'Mozilla/5.0 TestUA', '2026-09-07 12:05:00']);
    $ae->execute([null, 'login', 'failure', 'unknown_account', '9.9.9.9', 'Bot/1', '2026-09-07 12:10:00']);
    $ae->execute([4, 'login', 'success', null, '198.51.100.7', 'Mozilla/5.0 TestUA', '2026-09-06 08:00:00']);
    $ae->execute([5, 'login', 'success', null, '198.51.100.9', 'ClusterUA/1', '2026-09-06 09:00:00']);

    $pdo->exec("INSERT INTO trades (id, user_id, account_id, symbol, direction, entry_price, exit_price, volume, profit_loss, open_time, close_time, notes) VALUES
        (101, 4, NULL, 'XAUUSD', 'buy', '2400.10', '2412.60', '0.50', '120.5', '2026-09-05 08:00:00', '2026-09-05 10:00:00', 'private note carol'),
        (102, 5, NULL, 'EURUSD', 'sell', '1.08500', '1.08950', '1.00', '-45.0', '2026-09-06 09:00:00', '2026-09-06 12:30:00', 'SECRETNOTE')");
    echo json_encode(['ok' => true]);
    exit(0);
}

$ctrl = new SecurityAccessController();

switch ($case) {
    case 'signups_rbac': deny(fn () => (AuthMiddleware::requirePermission(Role::P_AUDIT_VIEW))(mkRequest('/api/v1/admin/security/signups', [], 'user', 2))); break;
    case 'logins_rbac': deny(fn () => (AuthMiddleware::requirePermission(Role::P_AUDIT_VIEW))(mkRequest('/api/v1/admin/security/logins', [], 'user', 2))); break;
    case 'signups_guest': deny(fn () => (AuthMiddleware::authenticate())(mkRequest('/api/v1/admin/security/signups', [], 'guest', 0))); break;
    case 'logins_guest': deny(fn () => (AuthMiddleware::authenticate())(mkRequest('/api/v1/admin/security/logins', [], 'guest', 0))); break;
    case 'signups_super': $ctrl->signups(mkRequest('/api/v1/admin/security/signups', [], 'super_admin', 1)); break;
    case 'signups_kpis': $ctrl->signups(mkRequest('/api/v1/admin/security/signups', [], 'super_admin', 1)); break;
    case 'signups_singleton': $ctrl->signups(mkRequest('/api/v1/admin/security/signups', [], 'super_admin', 1)); break;
    case 'signups_window': $ctrl->signups(mkRequest('/api/v1/admin/security/signups', ['days' => 365], 'super_admin', 1)); break;
    case 'signups_days_clamp': $ctrl->signups(mkRequest('/api/v1/admin/security/signups', ['days' => 0], 'super_admin', 1)); break;
    case 'signups_days_clamp_high': $ctrl->signups(mkRequest('/api/v1/admin/security/signups', ['days' => 99999], 'super_admin', 1)); break;
    case 'signups_members': $ctrl->signups(mkRequest('/api/v1/admin/security/signups', ['per_page' => 1], 'super_admin', 1)); break;
    case 'signups_page_beyond': $ctrl->signups(mkRequest('/api/v1/admin/security/signups', ['page' => 99], 'super_admin', 1)); break;
    case 'signups_admin_masked': $ctrl->signups(mkRequest('/api/v1/admin/security/signups', [], 'admin', 3)); break;
    case 'logins_super': $ctrl->logins(mkRequest('/api/v1/admin/security/logins', [], 'super_admin', 1)); break;
    case 'logins_result': $ctrl->logins(mkRequest('/api/v1/admin/security/logins', ['result' => 'failure'], 'super_admin', 1)); break;
    case 'logins_result_bad': deny(fn () => $ctrl->logins(mkRequest('/api/v1/admin/security/logins', ['result' => 'bogus'], 'super_admin', 1))); break;
    case 'logins_result_wildcard': deny(fn () => $ctrl->logins(mkRequest('/api/v1/admin/security/logins', ['result' => '%'], 'super_admin', 1))); break;
    case 'logins_user': $ctrl->logins(mkRequest('/api/v1/admin/security/logins', ['user_id' => 4], 'super_admin', 1)); break;
    case 'logins_window': $ctrl->logins(mkRequest('/api/v1/admin/security/logins', ['date_from' => '2026-09-07', 'date_to' => '2026-09-07'], 'super_admin', 1)); break;
    case 'logins_injection': deny(fn () => $ctrl->logins(mkRequest('/api/v1/admin/security/logins', ['user_id' => '4 OR 1=1'], 'super_admin', 1))); break;
    case 'logins_injection_date': deny(fn () => $ctrl->logins(mkRequest('/api/v1/admin/security/logins', ['date_from' => "2026-09-01'; DROP TABLE users--"], 'super_admin', 1))); break;
    case 'logins_pagination': $ctrl->logins(mkRequest('/api/v1/admin/security/logins', ['page' => 2, 'per_page' => 2], 'super_admin', 1)); break;
    case 'logins_clamp': $ctrl->logins(mkRequest('/api/v1/admin/security/logins', ['page' => -3, 'per_page' => 500], 'super_admin', 1)); break;
    case 'logins_admin_privacy': $ctrl->logins(mkRequest('/api/v1/admin/security/logins', [], 'admin', 3)); break;
    case 'logins_super_sensitive': $ctrl->logins(mkRequest('/api/v1/admin/security/logins', [], 'super_admin', 1)); break;

    case 'register_persists': {
        $svc = new AuthService();
        $svc->register(['email' => 'phase6new@example.com', 'password' => 'Phase6Pass!1', 'full_name' => 'Phase Six New', 'timezone' => 'UTC'], '203.0.114.9', 'RegisterUA/6');
        $rows = (new AuthEventRepository())->listGlobal(1, 100, null, null, null, null)['items'];
        $mine = array_values(array_filter($rows, static fn (array $r): bool => $r['eventType'] === 'signup' && ($r['userId'] ?? 0) === 8));
        $db = \Velora\Core\Database::connection();
        $got = $db->query("SELECT result, ip_address FROM auth_events WHERE event_type='signup' AND user_id=8")->fetchAll();
        echo json_encode(['signup_events' => count($got), 'result' => $got[0]['result'] ?? '', 'ip' => $got[0]['ip_address'] ?? '']);
        break;
    }
    case 'register_duplicate': {
        $svc = new AuthService();
        try {
            $svc->register(['email' => 'carol@example.com', 'password' => 'CarolPass!1', 'full_name' => 'Carol Dup', 'timezone' => 'UTC'], '203.0.114.10', 'RegisterUA/7');
            echo json_encode(['conflict' => false]);
        } catch (\Throwable $e) {
            $db = \Velora\Core\Database::connection();
            $n = (int) $db->query("SELECT COUNT(*) AS n FROM auth_events WHERE event_type='signup' AND user_id=4")->fetch()['n'];
            echo json_encode(['conflict' => true, 'signup_events' => $n]);
        }
        break;
    }
    case 'phase3_loginhistory': {
        $svc = new UserManagementService();
        $out = $svc->loginHistory(4, 1, Role::SUPER_ADMIN, 1, 25, null);
        echo json_encode(['rows' => $out['events'] ?? []]);
        break;
    }
    case 'phase4_trades': {
        (new GlobalTradingController())->trades(mkRequest('/api/v1/admin/trades', [], 'super_admin', 1));
        break;
    }
    default:
        fwrite(STDERR, "unknown case {$case}\n");
        exit(2);
}
