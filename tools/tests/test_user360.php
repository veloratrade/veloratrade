<?php

declare(strict_types=1);

use Velora\Admin\UserManagementService;
use Velora\Admin\UserManagementController;
use Velora\Auth\Role;
use Velora\Auth\AuthMiddleware;
use Velora\Core\Request;

/**
 * Phase E — Users 360° backend + security tests.
 *
 * Covers: per-user trading accounts (never the credential blob), paginated
 * trades, session activity (no tokens/hashes), user-scoped audit history
 * (sensitive fields gated by audit.view_sensitive), session revocation,
 * suspend/activate, role vs plan separation, privilege escalation, self-action
 * and IDOR denial, and secret hygiene on every new User-360 endpoint.
 *
 * Convention (mirrors test_admin_panel): Response::json()/error() terminate the
 * process, so each controller/middleware case runs in a dedicated child process
 * over one shared temp SQLite DB. No real secrets, no network.
 *
 * Run: php tools/tests/test_user360.php
 */

$SELF = __FILE__;
$ROOT = sys_get_temp_dir() . '/velora-user360-test-' . bin2hex(random_bytes(5));

function spawn(string $self, string $root, string $case, array $extraEnv = []): array
{
    $cmd = 'php ' . escapeshellarg($self) . ' --child ' . escapeshellarg($case) . ' ' . escapeshellarg($root);
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $env = array_merge(getenv(), ['VELORA_TEST_CHILD' => '1'], $extraEnv);
    $p = proc_open($cmd, $spec, $pipes, null, $env);
    fwrite($pipes[0], ''); fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($p);
    return ['code' => $code, 'out' => $out, 'err' => $err];
}

if (!in_array(getenv('VELORA_TEST_CHILD'), ['1', 'true'], true)) {
    $failures = 0; $checks = 0;
    function check(bool $c, string $l): void { global $failures, $checks; $checks++; echo ($c ? '  PASS: ' : '  FAIL: ') . $l . "\n"; if (!$c) { $failures++; } }
    function decode(array $r): array { $j = json_decode($r['out'], true); return isset($j['data']) && is_array($j['data']) ? $j['data'] : (is_array($j) ? $j : []); }

    @mkdir($ROOT . '/config', 0700, true);
    @mkdir($ROOT . '/data', 0700, true);
    @mkdir($ROOT . '/logs', 0700, true);
    spawn($SELF, $ROOT, 'setup');

    // ===== Trading accounts: safe metadata, never credentials =====
    $r = spawn($SELF, $ROOT, 'accounts'); $j = decode($r);
    check(isset($j['accounts']) && count($j['accounts']) >= 1, 'accounts returns a list');
    check(!str_contains($r['out'], 'connection_credentials_encrypted') && !str_contains($r['out'], 'credential') && !str_contains($r['out'], 'password') && !str_contains($r['out'], 'secret'), 'accounts never expose credential blob / secrets');
    check(str_contains($r['out'], 'CONNECTED'), 'accounts expose sync status');

    // ===== Trades: paginated via canonical repo =====
    $r = spawn($SELF, $ROOT, 'trades'); $j = decode($r);
    check(($j['total'] ?? 0) === 2 && count($j['items'] ?? []) === 2, 'trades paginated, returns all');
    check(isset($j['items'][0]['symbol']) && isset($j['items'][0]['profit_loss']), 'trades carries safe trade fields');

    // ===== Activity: no tokens/hashes =====
    $r = spawn($SELF, $ROOT, 'activity'); $j = decode($r);
    check(($j['total'] ?? 0) >= 1, 'activity returns entries');
    check(!str_contains($r['out'], 'refresh_token') && !str_contains($r['out'], 'access_token') && !str_contains($r['out'], 'token_hash'), 'activity exposes NO session token/hash');
    check(str_contains($r['out'], 'session.created') || str_contains($r['out'], 'session.revoked'), 'activity event type present');

    // ===== Audit: target-scoped + sensitive gating =====
    $r = spawn($SELF, $ROOT, 'audit_target'); $j = decode($r);
    check(($j['total'] ?? 0) === 2 && count($j['items'] ?? []) === 2, 'user-scoped audit returns both target rows');
    check(array_key_exists('ipAddress', $j['items'][0] ?? []), 'super_admin sees sensitive audit ip');
    $r = spawn($SELF, $ROOT, 'audit_target_admin'); $j = decode($r);
    check(!array_key_exists('ipAddress', $j['items'][0] ?? []), 'admin audit omits sensitive ip (gated)');

    // ===== Session revocation =====
    $r = spawn($SELF, $ROOT, 'revoke'); $j = decode($r);
    check(($j['revoked'] ?? 0) >= 1, 'revokeAll revokes >=1 session');
    $r = spawn($SELF, $ROOT, 'revoke_idempotent'); $j = decode($r);
    check(($j['revoked'] ?? 0) === 0, 'revoke is idempotent (second call revokes 0)');

    // ===== Suspend / activate (audited) =====
    $r = spawn($SELF, $ROOT, 'suspend'); $j = decode($r);
    check(($j['user']['status'] ?? '') === 'suspended', 'admin suspends ordinary user');
    $r = spawn($SELF, $ROOT, 'activate'); $j = decode($r);
    check(($j['user']['status'] ?? '') === 'active', 'admin activates ordinary user');

    // ===== Role vs Plan separation =====
    $r = spawn($SELF, $ROOT, 'role_keeps_plan'); $j = decode($r);
    check(($j['role'] ?? '') === 'admin' && ($j['plan'] ?? '') === 'free', 'role change applied but plan untouched');
    $r = spawn($SELF, $ROOT, 'plan_keeps_role'); $j = decode($r);
    check(($j['plan'] ?? '') === 'pro' && ($j['role'] ?? '') === 'user', 'plan upgrade keeps role unchanged (role != plan)');

    // ===== Privilege escalation / self / IDOR =====
    $r = spawn($SELF, $ROOT, 'admin_role_denied'); check(str_contains($r['out'], 'PERMISSION_DENIED'), 'plain admin role change -> PERMISSION_DENIED');
    $r = spawn($SELF, $ROOT, 'self_status'); check(str_contains($r['out'], 'SELF_ACTION_DENIED'), 'self suspend denied');
    $r = spawn($SELF, $ROOT, 'self_revoke'); check(str_contains($r['out'], 'SELF_ACTION_DENIED'), 'self session revoke denied');
    $r = spawn($SELF, $ROOT, 'rbac_view_denied'); check(str_contains($r['out'], 'PERMISSION_DENIED'), 'ordinary user denied users.view (RBAC)');
    $r = spawn($SELF, $ROOT, 'rbac_suspend_denied'); check(str_contains($r['out'], 'PERMISSION_DENIED'), 'ordinary user denied users.suspend (RBAC)');
    $r = spawn($SELF, $ROOT, 'rbac_revoke_denied'); check(str_contains($r['out'], 'PERMISSION_DENIED'), 'ordinary user denied session revoke (RBAC)');

    // ===== Secret hygiene across detail =====
    $r = spawn($SELF, $ROOT, 'detail'); $j = decode($r);
    check(!str_contains($r['out'], 'password_hash') && !str_contains($r['out'], 'accessToken'), 'detail leaks no password/token');

    echo "\nuser360: " . ($failures === 0 ? 'PASS' : 'FAIL') . " ($checks checks, $failures failures)\n";
    exit($failures === 0 ? 0 : 1);
}

// ---------------------------------------------------------------------------
// Child: setup + dispatch
// ---------------------------------------------------------------------------
$case = ($argv[1] ?? '') === '--child' ? ($argv[2] ?? '') : '';
$ROOTC = $argv[3] ?? $ROOT;

putenv('APP_ENV=local');
putenv('APP_DEBUG=true');
putenv('VELORA_PRIVATE_ROOT=' . $ROOTC);
putenv('VELORA_DOCUMENT_ROOT=' . dirname(__DIR__, 2));
putenv('DB_DRIVER=sqlite');
putenv('DB_DATABASE=' . $ROOTC . '/data/velora.sqlite');
if (!is_file($ROOTC . '/config/velora.env')) {
    @mkdir($ROOTC . '/config', 0700, true);
    file_put_contents($ROOTC . '/config/velora.env', implode("\n", [
        'APP_ENV=local', 'APP_DEBUG=true', 'DB_DRIVER=sqlite', 'DB_DATABASE=' . $ROOTC . '/data/velora.sqlite',
        'JWT_SECRET=' . str_repeat('j', 48), 'APP_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)),
        'CORS_ALLOWED_ORIGINS=http://localhost', 'FRONTEND_URL=http://localhost', 'MAIL_DRIVER=log',
        'METAAPI_TOKEN=',
    ]) . "\n");
}
@mkdir($ROOTC . '/data', 0700, true);
ini_set('error_log', $ROOTC . '/logs/php-error.log');
require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

$pdo = \Velora\Core\Database::connection();

if ($case === 'setup') {
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, password_hash TEXT DEFAULT \'\', full_name TEXT DEFAULT \'\', role TEXT NOT NULL DEFAULT \'user\', status TEXT NOT NULL DEFAULT \'active\', email_verified_at DATETIME NULL, locale TEXT DEFAULT \'fa\', locale_source TEXT DEFAULT \'auto\', timezone TEXT DEFAULT \'UTC\', plan TEXT NOT NULL DEFAULT \'free\', subscription_status TEXT NOT NULL DEFAULT \'none\', plan_started_at DATETIME NULL, plan_expires_at DATETIME NULL, plan_updated_at DATETIME NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER NOT NULL, actor_role TEXT NOT NULL, action TEXT NOT NULL, target_type TEXT NOT NULL, target_id INTEGER NULL, result TEXT NOT NULL DEFAULT \'success\', summary TEXT NULL, ip_address TEXT NULL, user_agent TEXT NULL, context_id TEXT NULL, metadata_json TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS user_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, refresh_token_hash TEXT DEFAULT \'\', access_token_hash TEXT DEFAULT \'\', ip_address TEXT NULL, user_agent TEXT NULL, expires_at DATETIME NULL, revoked_at DATETIME NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS user_devices (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS trading_accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, provider TEXT DEFAULT \'MANUAL\', platform TEXT DEFAULT \'MANUAL\', broker TEXT NULL, server TEXT NULL, mt_login TEXT NULL, account_type TEXT DEFAULT \'STANDARD\', sync_status TEXT DEFAULT \'DISCONNECTED\', last_synced_at DATETIME NULL, connection_credentials_encrypted BLOB NULL, connected_at DATETIME NULL, auto_sync_enabled INTEGER DEFAULT 0)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS trades (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, account_id INTEGER NULL, external_deal_id TEXT NULL, symbol TEXT, direction TEXT, entry_price REAL, exit_price REAL, volume REAL, contract_size REAL NULL, commission REAL NULL, swap REAL NULL, profit_loss REAL, r_multiple REAL NULL, stop_loss REAL NULL, take_profit REAL NULL, open_time DATETIME, close_time DATETIME, occurred_open_at_utc DATETIME NULL, occurred_close_at_utc DATETIME NULL, time_status TEXT NULL, source_timezone TEXT NULL, source_timezone_source TEXT NULL, source_calendar TEXT NULL, raw_open_text TEXT NULL, raw_close_text TEXT NULL, strategy_tag TEXT NULL, emotional_score REAL NULL, notes TEXT NULL, source TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ai_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, status TEXT DEFAULT \'success\', tokens_used INTEGER DEFAULT 0)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS rate_limits (bucket TEXT PRIMARY KEY, hits INTEGER NOT NULL, window_start DATETIME NOT NULL)');
    $pdo->exec("INSERT INTO users (email, full_name, role, status, plan, subscription_status) VALUES
        ('eve@example.com','Eve','super_admin','active','free','none'),
        ('alice@example.com','Alice','user','active','free','none'),
        ('bob@example.com','Bob','admin','active','pro','active')");
    $pdo->exec("INSERT INTO trading_accounts (user_id, provider, platform, broker, mt_login, sync_status, connection_credentials_encrypted) VALUES
        (2,'MT5','MT5','IC Markets','52012345','CONNECTED',X'00FF'),(2,'MT4','MT4','Pepperstone','100234','ERROR',X'00')");
    $pdo->exec("INSERT INTO trades (user_id, symbol, direction, entry_price, exit_price, volume, profit_loss, open_time, close_time) VALUES
        (2,'XAUUSD','buy',10,11,1,25,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),(2,'EURUSD','sell',1.08,1.07,1,10,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
    $pdo->exec("INSERT INTO ai_requests (user_id, status, tokens_used) VALUES (2,'success',100),(2,'failed',0)");
    $pdo->exec("INSERT INTO user_sessions (user_id, refresh_token_hash, access_token_hash, ip_address, user_agent, created_at, revoked_at) VALUES
        (2,'rh1','ah1','85.10.20.30','browser A',CURRENT_TIMESTAMP,NULL),(2,'rh2','ah2','92.0.0.1','browser B',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
    $pdo->exec("INSERT INTO admin_audit_logs (actor_user_id, actor_role, action, target_type, target_id, result, summary, ip_address, user_agent, context_id, metadata_json, created_at) VALUES
        (3,'admin','user.suspend','user',2,'success','suspended', '10.0.0.1','admin ua','ctx-1','{}',CURRENT_TIMESTAMP),
        (0,'system','login.failed','user',2,'denied','failed','203.0.113.9','x','ctx-2','{}',CURRENT_TIMESTAMP)");
    echo 'SETUP_OK'; exit(0);
}

function mkRequest(string $path, array $body, string $role, int $uid, string $method = 'POST', array $query = []): Request
{
    $rq = new Request($method, $path, $query, $body,
        ['authorization' => 'Bearer ' . str_repeat('j', 48), 'user-agent' => 'test-agent', 'x-request-id' => 'ctx-123']);
    $rq->attributes['user_role'] = $role;
    $rq->attributes['user_id'] = $uid;
    return $rq;
}
function deny(callable $fn, int $http = 403): void
{
    try { $fn(); echo json_encode(['ok' => true]); } catch (\Throwable $e) {
        http_response_code($http);
        echo json_encode(['error' => ['code' => ($e instanceof \Velora\Core\Exceptions\ApiException ? $e->errorCode() : 'X')]]);
    }
}

ob_start();
register_shutdown_function(function (): void { $o = ob_get_clean(); if ($o !== null && $o !== '') { echo $o; } });

$svc = new UserManagementService();
$ctrl = new UserManagementController();

switch ($case) {
    case 'accounts': $ctrl->accounts(mkRequest('/api/v1/admin/users/2/accounts', [], 'super_admin', 3, 'GET'), ['id' => '2']); break;
    case 'trades': $ctrl->trades(mkRequest('/api/v1/admin/users/2/trades', [], 'super_admin', 3, 'GET', ['page' => '1', 'per_page' => '20']), ['id' => '2']); break;
    case 'activity': $ctrl->activity(mkRequest('/api/v1/admin/users/2/activity', [], 'super_admin', 3, 'GET'), ['id' => '2']); break;
    case 'audit_target': $ctrl->audit(mkRequest('/api/v1/admin/users/2/audit', [], 'super_admin', 3, 'GET'), ['id' => '2']); break;
    case 'audit_target_admin': $ctrl->audit(mkRequest('/api/v1/admin/users/2/audit', [], 'admin', 3, 'GET'), ['id' => '2']); break;
    case 'revoke': $ctrl->revokeSessions(mkRequest('/api/v1/admin/users/2/revoke-sessions', [], 'super_admin', 3), ['id' => '2']); break;
    case 'revoke_idempotent': $ctrl->revokeSessions(mkRequest('/api/v1/admin/users/2/revoke-sessions', [], 'super_admin', 3), ['id' => '2']); break;
    case 'suspend': $ctrl->setStatus(mkRequest('/api/v1/admin/users/2/status', ['status' => 'suspended'], 'super_admin', 3), ['id' => '2']); break;
    case 'activate': $ctrl->setStatus(mkRequest('/api/v1/admin/users/2/status', ['status' => 'active'], 'super_admin', 3), ['id' => '2']); break;
    case 'role_keeps_plan': {
        // alice (id2 role=user plan=free); promote to admin; plan must stay.
        $svc->setRole(2, 'admin', 1, 'super_admin');
        $row = $pdo->query('SELECT role, plan FROM users WHERE id=2')->fetch();
        echo json_encode(['role' => $row['role'], 'plan' => $row['plan']]); break;
    }
    case 'plan_keeps_role': {
        // upgrade alice to pro; role must stay 'user' (re-set to baseline first).
        $svc->setRole(2, 'user', 1, 'super_admin');
        $svc->setSubscription(2, ['plan' => 'pro', 'status' => 'active'], 1, 'super_admin');
        $row = $pdo->query('SELECT role, plan FROM users WHERE id=2')->fetch();
        echo json_encode(['role' => $row['role'], 'plan' => $row['plan']]); break;
    }
    case 'admin_role_denied': deny(fn () => (AuthMiddleware::requirePermission(Role::P_USERS_CHANGE_ROLE))(mkRequest('/', [], 'admin', 3))); break;
    case 'self_status': deny(fn () => $svc->setStatus(2, 'suspended', 2, 'super_admin', 'status')); break;
    case 'self_revoke': deny(fn () => $svc->revokeSessions(2, 2, 'super_admin')); break;
    case 'rbac_view_denied': deny(fn () => (AuthMiddleware::requirePermission(Role::P_USERS_VIEW))(mkRequest('/', [], 'user', 2))); break;
    case 'rbac_suspend_denied': deny(fn () => (AuthMiddleware::requirePermission(Role::P_USERS_SUSPEND))(mkRequest('/', [], 'user', 2))); break;
    case 'rbac_revoke_denied': deny(fn () => (AuthMiddleware::requirePermission(Role::P_USERS_SUSPEND))(mkRequest('/', [], 'user', 2))); break;
    case 'detail': echo json_encode(['user' => $svc->userDetail(2, 1, 'super_admin')]); break;
    default: break;
}
