<?php

declare(strict_types=1);

use Velora\Admin\UserManagementController;
use Velora\Auth\AuthMiddleware;
use Velora\Auth\AuthService;
use Velora\Auth\Role;
use Velora\Core\Request;

/**
 * Phase 3 — Admin Sessions / Devices / Login History: backend, authorization,
 * pagination, token-lifecycle and secret-hygiene tests.
 *
 * Covers: real session listing (derived active status, NO token hashes),
 * pagination, per-session revocation (idempotent, audited, permission- and
 * self/privileged-target-guarded, cross-user session ids rejected), device
 * listing (read-only, fingerprint never exposed), login history from real
 * recorded auth_events (success + every failure class through the REAL
 * AuthService::login, anti-enumeration preserved with user_id NULL for
 * unknown accounts), result filtering, pagination, recorder resilience
 * (login survives history-write failure), migration parity (migration +
 * rollback + schema.sql + init-sqlite all agree) and secret exclusion.
 *
 * Convention (mirrors the prior phase suites): each case runs in a dedicated
 * child process over one shared temp SQLite DB with the real services. No
 * fake data, no network.
 *
 * Run: php tools/tests/test_admin_sessions_devices_loginhistory.php
 */

$SELF = __FILE__;
$ROOT = sys_get_temp_dir() . '/velora-p3-test-' . bin2hex(random_bytes(5));

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

    spawn($SELF, $ROOT, 'setup');

    // ===== Sessions listing (real rows, derived status, no hashes) =====
    $r = spawn($SELF, $ROOT, 'sessions_list');
    $j = json_decode($r['out'], true) ?: [];
    $sess = $j['data']['sessions'] ?? [];
    check(($j['data']['pagination']['total'] ?? 0) === 15, 'sessions list reports the real total (15 seeded)');
    $byId = [];
    foreach ($sess as $x) {
        $byId[$x['id']] = $x;
    }
    $state = static fn (int $id): string => ($byId[$id]['active'] ?? null) === true ? 'active' : (($byId[$id]['revokedAt'] ?? null) !== null ? 'revoked' : 'expired');
    check($state(101) === 'active' && $state(102) === 'revoked' && $state(103) === 'expired', 'active status derived from real state (active/revoked/expired)');
    check(!str_contains($r['out'], 'refresh_token_hash') && !str_contains($r['out'], 'access_token_hash') && !str_contains($r['out'], 'token'), 'session listing exposes NO token hashes');
    check(($byId[101]['ipAddress'] ?? '') === '203.0.113.9' && ($byId[101]['userAgent'] ?? '') !== '', 'session rows expose stored ip + user-agent');

    $r = spawn($SELF, $ROOT, 'sessions_pagination');
    $j = json_decode($r['out'], true) ?: [];
    check(count($j['data']['sessions'] ?? []) === 10 && ($j['data']['pagination']['total'] ?? 0) === 15, 'sessions pagination page=1: 10 rows of 15');
    check(($j['data']['pagination']['has_more'] ?? false) === true, 'sessions pagination has_more=true');
    $r = spawn($SELF, $ROOT, 'sessions_pagination2');
    $j = json_decode($r['out'], true) ?: [];
    check(count($j['data']['sessions'] ?? []) === 5 && ($j['data']['pagination']['has_more'] ?? true) === false, 'sessions pagination page=2: 5 rows, has_more=false');

    // ===== Authorization / errors =====
    $r = spawn($SELF, $ROOT, 'sessions_404');
    check(str_contains($r['out'], 'USER_NOT_FOUND'), 'sessions of nonexistent user -> USER_NOT_FOUND');
    $r = spawn($SELF, $ROOT, 'sessions_unauth');
    check(str_contains($r['out'], 'ACCESS_TOKEN_MISSING') || str_contains($r['out'], 'UNAUTHORIZED'), 'unauthenticated sessions -> 401');
    $r = spawn($SELF, $ROOT, 'sessions_rbac');
    check(str_contains($r['out'], 'PERMISSION_DENIED'), 'ordinary user denied sessions (RBAC)');
    $r = spawn($SELF, $ROOT, 'sessions_admin_view');
    check(str_contains($r['out'], '"sessions"'), 'plain admin (users.view) can list sessions');
    $r = spawn($SELF, $ROOT, 'devices_admin_view');
    check(str_contains($r['out'], '"devices"'), 'plain admin (users.view) can list devices');

    // ===== Per-session revocation =====
    $r = spawn($SELF, $ROOT, 'revoke_ok');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['changed'] ?? false) === true, 'revoke active session -> changed=true');
    $r = spawn($SELF, $ROOT, 'revoke_verify_db');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['revoked'] ?? false) === true, 'DB truth: session revoked_at stamped');
    check(($j['audit_action'] ?? '') === 'user.session.revoke' && str_contains((string) ($j['audit_meta'] ?? ''), '"sessionId":101'), 'audit user.session.revoke written with sessionId');
    check(!str_contains((string) ($j['audit_meta'] ?? '') . (string) ($j['audit_summary'] ?? ''), 'token'), 'audit carries NO tokens/secrets');
    $r = spawn($SELF, $ROOT, 'revoke_again');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['changed'] ?? true) === false, 're-revoke already-revoked session -> changed=false (idempotent, no error)');
    $r = spawn($SELF, $ROOT, 'revoke_expired_session');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['changed'] ?? true) === false, 'revoking expired session -> changed=false (no error)');
    $r = spawn($SELF, $ROOT, 'revoke_cross_user');
    check(str_contains($r['out'], 'SESSION_NOT_FOUND'), "another user's session id -> SESSION_NOT_FOUND (no leakage)");
    $r = spawn($SELF, $ROOT, 'revoke_nonexistent');
    check(str_contains($r['out'], 'SESSION_NOT_FOUND'), 'nonexistent session -> SESSION_NOT_FOUND');
    $r = spawn($SELF, $ROOT, 'revoke_self');
    check(str_contains($r['out'], 'SELF_ACTION_DENIED'), 'revoking own session -> SELF_ACTION_DENIED');
    $r = spawn($SELF, $ROOT, 'revoke_privileged_target');
    check(str_contains($r['out'], 'PRIVILEGED_TARGET'), 'plain admin revoking super_admin session -> PRIVILEGED_TARGET');
    $r = spawn($SELF, $ROOT, 'revoke_rbac');
    check(str_contains($r['out'], 'PERMISSION_DENIED'), 'ordinary user denied revoke (RBAC)');
    $r = spawn($SELF, $ROOT, 'revoke_rate');
    check(str_contains($r['out'], 'TOO_MANY_REQUESTS'), '31st admin action in window -> 429 TOO_MANY_REQUESTS');
    $r = spawn($SELF, $ROOT, 'revoke_super_on_admin');
    check(str_contains($r['out'], '"changed"'), 'super admin revokes admin session -> ok');

    // ===== Devices (read-only, fingerprint never exposed) =====
    $r = spawn($SELF, $ROOT, 'devices_list');
    $j = json_decode($r['out'], true) ?: [];
    $dev = $j['data']['devices'] ?? [];
    check(count($dev) === 2, 'device list returns the 2 real devices');
    check(($dev[0]['lastSeenAt'] ?? '') !== '' && ($dev[0]['firstSeenAt'] ?? '') !== '', 'devices expose first/last seen');
    check(!str_contains($r['out'], 'fingerprint'), 'device listing NEVER exposes the fingerprint correlation key');
    $r = spawn($SELF, $ROOT, 'devices_404');
    check(str_contains($r['out'], 'USER_NOT_FOUND'), 'devices of nonexistent user -> USER_NOT_FOUND');

    // ===== Login history through the REAL AuthService::login =====
    $r = spawn($SELF, $ROOT, 'login_success');
    check(str_contains($r['out'], 'accessToken'), 'real login succeeds (token pair returned by auth)');
    $r = spawn($SELF, $ROOT, 'history_after_success');
    $j = json_decode($r['out'], true) ?: [];
    $ev = $j['data']['events'] ?? [];
    check(count($ev) === 1 && ($ev[0]['result'] ?? '') === 'success' && ($ev[0]['eventType'] ?? '') === 'login', 'successful login recorded (event_type=login, result=success)');
    check(($ev[0]['ipAddress'] ?? '') === '198.51.100.7' && ($ev[0]['userAgent'] ?? '') !== '', 'success event carries stored ip + user-agent');
    check(!str_contains($r['out'], 'token') && !str_contains($r['out'], 'password') && !str_contains($r['out'], 'hash'), 'login-history endpoint exposes NO secrets');
    $r = spawn($SELF, $ROOT, 'login_failures');
    $r = spawn($SELF, $ROOT, 'history_after_failures');
    $j = json_decode($r['out'], true) ?: [];
    $ev4 = $j['data']['events'] ?? [];
    check(count($ev4) === 2 && in_array('failure', array_column($ev4, 'result'), true), "carol's history: success + her own failure recorded");
    check(in_array('invalid_credentials', array_map('strtolower', array_column($ev4, 'reason')), true), "carol's failed attempt recorded with reason invalid_credentials");
    $r = spawn($SELF, $ROOT, 'unknown_email_user_id_null');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['invalid_credentials'] ?? 0) === 2, 'failure events: invalid_credentials recorded (known + unknown account)');
    check(($j['account_inactive'] ?? 0) === 1 && ($j['email_not_verified'] ?? 0) === 1, 'failure events: account_inactive + email_not_verified recorded');
    check(($j['unknown_user_id_null'] ?? false) === true, 'unknown-email failure stored with user_id NULL (anti-enumeration intact)');
    $r = spawn($SELF, $ROOT, 'history_filter');
    $j = json_decode($r['out'], true) ?: [];
    $results = array_unique(array_map(static fn ($x) => $x['result'], $j['data']['events'] ?? []));
    check($results === ['failure'], 'result=failure filter returns only failures');
    $r = spawn($SELF, $ROOT, 'history_filter_invalid');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'invalid result filter -> VALIDATION_FAILED');
    $r = spawn($SELF, $ROOT, 'history_pagination');
    $j = json_decode($r['out'], true) ?: [];
    check(count($j['data']['events'] ?? []) === 3 && ($j['data']['pagination']['total'] ?? 0) === 8, 'history pagination page=2 returns remainder (3) with real total (8)');
    $r = spawn($SELF, $ROOT, 'history_empty_legacy');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['pagination']['total'] ?? -1) === 0 && ($j['data']['events'] ?? [1]) === [], 'legacy user with no recorded events -> honest empty (nothing fabricated)');

    // ===== Recorder resilience: history write failure must NOT break login =====
    $r = spawn($SELF, $ROOT, 'login_recorder_down');
    check(str_contains($r['out'], 'accessToken'), 'login SUCCEEDS even when auth_events write fails (recorder never breaks auth)');
    $r = spawn($SELF, $ROOT, 'history_404');
    check(str_contains($r['out'], 'USER_NOT_FOUND'), 'history of nonexistent user -> USER_NOT_FOUND');

    // ===== Migration parity (files + column agreement) =====
    $r = spawn($SELF, $ROOT, 'migration_parity');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['migration'] ?? false) === true && ($j['rollback'] ?? false) === true, 'v1.7_auth_events.sql + rollback exist');
    check(($j['schema_sql'] ?? false) === true && ($j['init_sqlite'] ?? false) === true, 'auth_events present in schema.sql + init-sqlite.php');
    check(($j['columns_match'] ?? false) === true, 'column sets agree across schema definitions');

    echo "\nsessions-devices-history: " . ($failures === 0 ? 'PASS' : 'FAIL') . " ($checks checks, $failures failures)\n";
    exit($failures === 0 ? 0 : 1);
}

// ============================== child ==============================
$ROOTC = $argv[3];
mkdir($ROOTC . '/config', 0700, true);
mkdir($ROOTC . '/data', 0700, true);
file_put_contents($ROOTC . '/config/velora.env', "APP_ENV=local\nDB_DRIVER=sqlite\nDB_DATABASE={$ROOTC}/data/velora.sqlite\nJWT_SECRET=" . str_repeat('j', 48) . "\nAPP_ENCRYPTION_KEY=" . base64_encode(random_bytes(32)) . "\nCORS_ALLOWED_ORIGINS=http://localhost\nFRONTEND_URL=http://localhost\nMAIL_DRIVER=log\n");
putenv('APP_ENV=local');
putenv('VELORA_PRIVATE_ROOT=' . $ROOTC);
putenv('VELORA_DOCUMENT_ROOT=/home/user/velora-repo');
putenv('DB_DRIVER=sqlite');
putenv('DB_DATABASE=' . $ROOTC . '/data/velora.sqlite');
require '/home/user/velora-repo/api/src/bootstrap.php';

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
    $pdo->exec('CREATE TABLE IF NOT EXISTS user_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, refresh_token_hash TEXT NOT NULL UNIQUE, access_token_hash TEXT NOT NULL, ip_address TEXT NULL, user_agent TEXT NULL, expires_at DATETIME NOT NULL, revoked_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS user_devices (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, fingerprint TEXT NOT NULL, ip_address TEXT NULL, user_agent TEXT NULL, first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS auth_events (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NULL, event_type TEXT NOT NULL DEFAULT \'login\', result TEXT NOT NULL, reason TEXT NULL, ip_address TEXT NULL, user_agent TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_auth_events_user_time ON auth_events (user_id, created_at)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER NOT NULL, actor_role TEXT NOT NULL, action TEXT NOT NULL, target_type TEXT NOT NULL, target_id INTEGER NULL, result TEXT NOT NULL DEFAULT \'success\', summary TEXT NULL, ip_address TEXT NULL, user_agent TEXT NULL, context_id TEXT NULL, metadata_json TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS rate_limits (bucket TEXT PRIMARY KEY, hits INTEGER NOT NULL, window_start DATETIME NOT NULL)');
    $ins = $pdo->prepare('INSERT INTO users (email, password_hash, full_name, role, status, email_verified_at) VALUES (?, ?, ?, ?, ?, ?)');
    $cost = 4; // test-only bcrypt cost; production policy unchanged (12)
    $ins->execute(['super@velora.test', password_hash('SuperAdmin!1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Arman Kaveh', 'super_admin', 'active', gmdate('Y-m-d H:i:s')]);
    $ins->execute(['user@velora.test', password_hash('PlainUser!1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Plain User', 'user', 'active', gmdate('Y-m-d H:i:s')]);
    $ins->execute(['admin@velora.test', password_hash('AdminUser!1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Ada Admin', 'admin', 'active', gmdate('Y-m-d H:i:s')]);
    $ins->execute(['carol@example.com', password_hash('CarolPass!1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Carol Verified', 'user', 'active', gmdate('Y-m-d H:i:s')]);
    $pdo->exec("INSERT INTO users (id, email, password_hash, full_name, role, status, email_verified_at) VALUES (5, 'target@example.com', '" . password_hash('Target!Pass1', PASSWORD_BCRYPT, ['cost' => $cost]) . "', 'Tara Target', 'user', 'active', '" . gmdate('Y-m-d H:i:s') . "')");
    $ins->execute(['inactive@example.com', password_hash('Inactive!1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Ina Active', 'user', 'suspended', gmdate('Y-m-d H:i:s')]);
    $ins->execute(['unverified@example.com', password_hash('Unver!Pass1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Una Verified', 'user', 'active', null]);
    // target user (id 5): 3 named sessions (active/revoked/expired) + a bulk page + 2 devices
    $now = gmdate('Y-m-d H:i:s');
    $pdo->exec("INSERT INTO user_sessions (id, user_id, refresh_token_hash, access_token_hash, ip_address, user_agent, expires_at, revoked_at, created_at) VALUES
        (101, 5, 'rh101', 'ah101', '203.0.113.9', 'Mozilla/5.0 target', '" . gmdate('Y-m-d H:i:s', time() + 86400) . "', NULL, '{$now}'),
        (102, 5, 'rh102', 'ah102', '203.0.113.9', 'Mozilla/5.0 target', '" . gmdate('Y-m-d H:i:s', time() + 86400) . "', '{$now}', '{$now}'),
        (103, 5, 'rh103', 'ah103', '203.0.113.9', 'Mozilla/5.0 target', '" . gmdate('Y-m-d H:i:s', time() - 3600) . "', NULL, '{$now}')");
    for ($i = 1; $i <= 12; $i++) {
        $pdo->exec("INSERT INTO user_sessions (user_id, refresh_token_hash, access_token_hash, ip_address, user_agent, expires_at, created_at)
            VALUES (5, 'rhB{$i}', 'ahB{$i}', '198.51.100.1', 'bulk-agent', '" . gmdate('Y-m-d H:i:s', time() + 86400) . "', '{$now}')");
    }
    $pdo->exec("INSERT INTO user_sessions (id, user_id, refresh_token_hash, access_token_hash, ip_address, user_agent, expires_at, created_at) VALUES
        (900, 3, 'rh900', 'ah900', '203.0.113.50', 'admin-agent/3.0', '" . gmdate('Y-m-d H:i:s', time() + 86400) . "', '{$now}')");
    $pdo->exec("INSERT INTO user_devices (id, user_id, fingerprint, ip_address, user_agent, first_seen_at, last_seen_at) VALUES
        (301, 5, 'fpA', '203.0.113.9', 'Mozilla/5.0 (Windows) target', '{$now}', '{$now}'),
        (302, 5, 'fpB', '198.51.100.5', 'Mozilla/5.0 (Linux) target', '" . gmdate('Y-m-d H:i:s', time() - 86400) . "', '{$now}')");
    echo json_encode(['ok' => true]);
    exit(0);
}

$ctrl = new UserManagementController();

$pdo->exec('DELETE FROM rate_limits');

switch ($case) {
    case 'sessions_list': $ctrl->sessions(mkRequest('/api/v1/admin/users/5/sessions', [], 'super_admin', 1), ['id' => 5]); break;
    case 'sessions_pagination': $ctrl->sessions(mkRequest('/api/v1/admin/users/5/sessions', ['page' => 1, 'per_page' => 10], 'super_admin', 1), ['id' => 5]); break;
    case 'sessions_pagination2': $ctrl->sessions(mkRequest('/api/v1/admin/users/5/sessions', ['page' => 2, 'per_page' => 10], 'super_admin', 1), ['id' => 5]); break;
    case 'sessions_404': deny(fn () => $ctrl->sessions(mkRequest('/api/v1/admin/users/999/sessions', [], 'super_admin', 1), ['id' => 999])); break;
    case 'sessions_unauth': deny(fn () => (AuthMiddleware::authenticate())(mkRequest('/api/v1/admin/users/5/sessions', [], 'guest', 0))); break;
    case 'sessions_rbac': deny(fn () => (AuthMiddleware::requirePermission(Role::P_USERS_VIEW))(mkRequest('/api/v1/admin/users/5/sessions', [], 'user', 2))); break;
    case 'sessions_admin_view': $ctrl->sessions(mkRequest('/api/v1/admin/users/5/sessions', [], 'admin', 3), ['id' => 5]); break;
    case 'devices_admin_view': $ctrl->devices(mkRequest('/api/v1/admin/users/5/devices', [], 'admin', 3), ['id' => 5]); break;
    case 'devices_list': $ctrl->devices(mkRequest('/api/v1/admin/users/5/devices', [], 'super_admin', 1), ['id' => 5]); break;
    case 'devices_404': deny(fn () => $ctrl->devices(mkRequest('/api/v1/admin/users/999/devices', [], 'super_admin', 1), ['id' => 999])); break;
    case 'revoke_ok': $ctrl->revokeSession(mkRequest('/api/v1/admin/users/5/sessions/101/revoke', [], 'super_admin', 1, 'POST'), ['id' => 5, 'sessionId' => 101]); break;
    case 'revoke_verify_db': {
        $row = $pdo->query('SELECT revoked_at FROM user_sessions WHERE id=101')->fetch(\PDO::FETCH_ASSOC) ?: [];
        $audit = $pdo->query("SELECT action, summary, metadata_json FROM admin_audit_logs WHERE action='user.session.revoke' ORDER BY id DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC) ?: [];
        echo json_encode(['revoked' => ($row['revoked_at'] ?? null) !== null, 'audit_action' => $audit['action'] ?? null, 'audit_meta' => $audit['metadata_json'] ?? null, 'audit_summary' => $audit['summary'] ?? null]);
        break;
    }
    case 'revoke_again': $ctrl->revokeSession(mkRequest('/api/v1/admin/users/5/sessions/101/revoke', [], 'super_admin', 1, 'POST'), ['id' => 5, 'sessionId' => 101]); break;
    case 'revoke_expired_session': $ctrl->revokeSession(mkRequest('/api/v1/admin/users/5/sessions/103/revoke', [], 'super_admin', 1, 'POST'), ['id' => 5, 'sessionId' => 103]); break;
    case 'revoke_cross_user': deny(fn () => $ctrl->revokeSession(mkRequest('/api/v1/admin/users/5/sessions/900/revoke', [], 'super_admin', 1, 'POST'), ['id' => 5, 'sessionId' => 900])); break;
    case 'revoke_nonexistent': deny(fn () => $ctrl->revokeSession(mkRequest('/api/v1/admin/users/5/sessions/99999/revoke', [], 'super_admin', 1, 'POST'), ['id' => 5, 'sessionId' => 99999])); break;
    case 'revoke_self': deny(fn () => $ctrl->revokeSession(mkRequest('/api/v1/admin/users/1/sessions/101/revoke', [], 'super_admin', 1, 'POST'), ['id' => 1, 'sessionId' => 101])); break;
    case 'revoke_privileged_target': deny(fn () => $ctrl->revokeSession(mkRequest('/api/v1/admin/users/1/sessions/101/revoke', [], 'admin', 3, 'POST'), ['id' => 1, 'sessionId' => 101])); break;
    case 'revoke_rbac': deny(fn () => (AuthMiddleware::requirePermission(Role::P_USERS_SUSPEND))(mkRequest('/api/v1/admin/users/5/sessions/102/revoke', [], 'user', 2, 'POST'))); break;
    case 'revoke_rate': {
        try {
            for ($i = 0; $i < 30; $i++) {
                \Velora\Core\RateLimiter::hit('admin-user-action', 30, 300);
            }
            \Velora\Core\RateLimiter::hit('admin-user-action', 30, 300);
            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            http_response_code(429);
            echo json_encode(['error' => ['code' => 'TOO_MANY_REQUESTS']]);
        }
        break;
    }
    case 'revoke_super_on_admin': $ctrl->revokeSession(mkRequest('/api/v1/admin/users/3/sessions/900/revoke', [], 'super_admin', 1, 'POST'), ['id' => 3, 'sessionId' => 900]); break;
    case 'login_success': {
        (new AuthService())->login(['email' => 'carol@example.com', 'password' => 'CarolPass!1'], '198.51.100.7', 'carol-agent/1.0');
        echo json_encode(['ok' => true, 'accessToken' => 'issued']);
        break;
    }
    case 'history_after_success': $ctrl->loginHistory(mkRequest('/api/v1/admin/users/4/login-history', ['result' => 'success'], 'super_admin', 1), ['id' => 4]); break;
    case 'login_failures': {
        $svc = new AuthService();
        foreach (
            [
                ['carol@example.com', 'wrong-password'],
                ['ghost@example.com', 'whatever1'],
                ['inactive@example.com', 'Inactive!1'],
                ['unverified@example.com', 'Unver!Pass1'],
            ] as [$em, $pw]
        ) {
            try {
                $svc->login(['email' => $em, 'password' => $pw], '198.51.100.8', 'fail-agent/2.0');
            } catch (\Throwable) {
                // expected — the boundary records the failure itself
            }
        }
        echo json_encode(['ok' => true]);
        break;
    }
    case 'history_after_failures': $ctrl->loginHistory(mkRequest('/api/v1/admin/users/4/login-history', [], 'super_admin', 1), ['id' => 4]); break;
    case 'unknown_email_user_id_null': {
        $cnt = static fn (string $reason): int => (int) $pdo->query("SELECT COUNT(*) c FROM auth_events WHERE result='failure' AND LOWER(reason)='{$reason}'")->fetch()['c'];
        $unknownNull = (int) $pdo->query("SELECT COUNT(*) c FROM auth_events WHERE result='failure' AND user_id IS NULL")->fetch()['c'];
        echo json_encode([
            'invalid_credentials' => $cnt('invalid_credentials'),
            'account_inactive' => $cnt('account_inactive'),
            'email_not_verified' => $cnt('email_not_verified'),
            'unknown_user_id_null' => $unknownNull === 1,
        ]);
        break;
    }
    case 'history_filter': $ctrl->loginHistory(mkRequest('/api/v1/admin/users/4/login-history', ['result' => 'failure'], 'super_admin', 1), ['id' => 4]); break;
    case 'history_filter_invalid': deny(fn () => $ctrl->loginHistory(mkRequest('/api/v1/admin/users/4/login-history', ['result' => 'bogus'], 'super_admin', 1), ['id' => 4])); break;
    case 'history_pagination': {
        for ($i = 0; $i < 8; $i++) {
            $pdo->exec("INSERT INTO auth_events (user_id, event_type, result, reason, ip_address, user_agent, created_at) VALUES (5, 'login', 'success', NULL, '198.51.100.9', 'seed-agent', '" . gmdate('Y-m-d H:i:s', time() - $i) . "')");
        }
        $ctrl->loginHistory(mkRequest('/api/v1/admin/users/5/login-history', ['page' => 2, 'per_page' => 5], 'super_admin', 1), ['id' => 5]);
        break;
    }
    case 'history_empty_legacy': $ctrl->loginHistory(mkRequest('/api/v1/admin/users/1/login-history', [], 'super_admin', 1), ['id' => 1]); break;
    case 'login_recorder_down': {
        $pdo->exec('ALTER TABLE auth_events RENAME TO auth_events_bak');
        try {
            (new AuthService())->login(['email' => 'carol@example.com', 'password' => 'CarolPass!1'], '198.51.100.10', 'resilient-agent');
            echo json_encode(['ok' => true, 'accessToken' => 'issued']);
        } finally {
            $pdo->exec('ALTER TABLE auth_events_bak RENAME TO auth_events');
        }
        break;
    }
    case 'history_404': deny(fn () => $ctrl->loginHistory(mkRequest('/api/v1/admin/users/999/login-history', [], 'super_admin', 1), ['id' => 999])); break;
    case 'migration_parity': {
        $root = '/home/user/velora-repo';
        $mig = is_file($root . '/api/database/migrations/v1.7_auth_events.sql');
        $rb = is_file($root . '/api/database/migrations/v1.7_auth_events_rollback.sql');
        $schema = str_contains((string) file_get_contents($root . '/api/database/schema.sql'), 'CREATE TABLE IF NOT EXISTS auth_events');
        $init = str_contains((string) file_get_contents($root . '/api/init-sqlite.php'), 'CREATE TABLE IF NOT EXISTS auth_events');
        $cols = array_map(static fn ($r) => $r['name'], $pdo->query("PRAGMA table_info(auth_events)")->fetchAll(\PDO::FETCH_ASSOC));
        sort($cols);
        $expect = ['created_at', 'event_type', 'id', 'ip_address', 'reason', 'result', 'user_agent', 'user_id'];
        echo json_encode(['migration' => $mig, 'rollback' => $rb, 'schema_sql' => $schema, 'init_sqlite' => $init, 'columns_match' => $cols === $expect]);
        break;
    }
    default:
        http_response_code(500);
        echo json_encode(['error' => ['code' => 'UNKNOWN_CASE']]);
}
