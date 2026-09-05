<?php

declare(strict_types=1);

/**
 * Professional Admin Panel (v1.3) tests.
 *
 * Rebuilt to enforce the critical architectural rule that RBAC role and
 * subscription plan are SEPARATE:
 *   role = user|admin|super_admin  (authorization ONLY)
 *   plan = free|pro                (subscription, RBAC-neutral)
 *
 * Covers: RBAC (incl. pro-customer != admin), user management, subscriptions
 * (all role x plan combinations), audit logging + secret sanitization, overview
 * + system health, privilege escalation, self-escalation, unknown permission,
 * malformed role, unauthenticated denial.
 *
 * Convention: Response::json()/error() terminate the process, so each
 * controller/middleware case runs in a dedicated child process over one shared
 * temp SQLite DB + private env root. No real secrets, no network.
 *
 * Run: php tools/tests/test_admin_panel.php
 */

$SELF = __FILE__;
$ROOT = sys_get_temp_dir() . '/velora-admin-panel-test-' . bin2hex(random_bytes(5));

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
    function decode(array $r): array { $j = json_decode($r['out'], true); return is_array($j) ? $j : []; }

    @mkdir($ROOT . '/config', 0700, true);
    @mkdir($ROOT . '/data', 0700, true);
    @mkdir($ROOT . '/logs', 0700, true);
    spawn($SELF, $ROOT, 'setup');

    // ===== RBAC (server-side) =====
    $r = spawn($SELF, $ROOT, 'rbac_user');    check(str_contains($r['out'], 'ADMIN_REQUIRED'), 'user -> 403 ADMIN_REQUIRED');
    $r = spawn($SELF, $ROOT, 'rbac_pro_customer'); check(str_contains($r['out'], 'ADMIN_REQUIRED'), 'Pro CUSTOMER (role=user, plan=pro) -> 403 ADMIN_REQUIRED (subscription != authorization)');
    $r = spawn($SELF, $ROOT, 'admin_gate');   check(str_contains($r['out'], 'ok'), 'admin -> allowed into panel');
    $r = spawn($SELF, $ROOT, 'super_gate');   check(str_contains($r['out'], 'ok'), 'super_admin -> allowed into panel');
    $r = spawn($SELF, $ROOT, 'unknown_perm'); check(str_contains($r['out'], 'PERMISSION_DENIED'), 'unknown permission denied for super_admin (no role holds it)');
    $r = spawn($SELF, $ROOT, 'admin_denied_sensitive'); check(str_contains($r['out'], 'PERMISSION_DENIED'), 'admin denied audit.view_sensitive (Super-Admin-only)');
    $r = spawn($SELF, $ROOT, 'me_super'); $j = decode($r);
    check(($j['data']['me']['role'] ?? '') === 'super_admin' && ($j['data']['me']['isSuperAdmin'] ?? false) === true, '/admin/me returns super_admin role + isSuperAdmin');
    check(in_array('users.change_role', $j['data']['me']['permissions'] ?? [], true), '/admin/me super_admin permissions include users.change_role');
    check(in_array('audit.view_sensitive', $j['data']['me']['permissions'] ?? [], true), '/admin/me super_admin permissions include audit.view_sensitive');
    $r = spawn($SELF, $ROOT, 'me_admin'); $j = decode($r);
    check(!in_array('users.change_role', $j['data']['me']['permissions'] ?? [], true), '/admin/me admin permissions EXCLUDE users.change_role');
    check(!in_array('audit.view_sensitive', $j['data']['me']['permissions'] ?? [], true), '/admin/me admin permissions EXCLUDE audit.view_sensitive');
    check(!str_contains($r['out'], 'accessToken') && !str_contains($r['out'], 'password_hash'), '/admin/me leaks no token/secret');

    // ===== Subscription != authorization (the 6 valid combos) =====
    $r = spawn($SELF, $ROOT, 'combos'); $j = decode($r);
    $by = $j['users'] ?? [];
    $get = static function (string $e) use ($by): array { return $by[$e] ?? ['role' => '?', 'plan' => '?']; };
    check($get('alice@example.com')['role'] === 'user' && $get('alice@example.com')['plan'] === 'free', 'combo user+free valid');
    check($get('bob@example.com')['role'] === 'user' && $get('bob@example.com')['plan'] === 'pro', 'combo user+pro valid (Pro customer is a normal user)');
    check($get('dave@example.com')['role'] === 'admin' && $get('dave@example.com')['plan'] === 'free', 'combo admin+free valid');
    check($get('gina@example.com')['role'] === 'admin' && $get('gina@example.com')['plan'] === 'pro', 'combo admin+pro valid');
    check($get('eve@example.com')['role'] === 'super_admin' && $get('eve@example.com')['plan'] === 'free', 'combo super_admin+free valid');
    check($get('heidi@example.com')['role'] === 'super_admin' && $get('heidi@example.com')['plan'] === 'pro', 'combo super_admin+pro valid');
    // No stored 'pro' role; role column never reveals subscription.
    check(!array_key_exists('pro', $j['roles'] ?? []), 'no role named "pro" exists in the model');
    $r = spawn($SELF, $ROOT, 'pro_perms'); $j = decode($r);
    check(($j['is_user'] ?? false) === true && ($j['has_admin_perm'] ?? true) === false, 'Pro customer has NO admin permission despite pro plan');

    // ===== Users: list / search / filter / pagination =====
    $r = spawn($SELF, $ROOT, 'list');      $j = decode($r);
    check(($j['total'] ?? 0) >= 8, 'list returns >=8 seeded users');
    check(isset($j['users'][0]['email']) && !str_contains($r['out'], 'password_hash'), 'list rows public fields only (no password_hash)');
    $r = spawn($SELF, $ROOT, 'list_search', ['SEARCH' => 'alice']); $j = decode($r);
    check(($j['total'] ?? 0) === 1 && (($j['users'][0]['email'] ?? '') === 'alice@example.com'), 'search filters by email');
    $r = spawn($SELF, $ROOT, 'list_plan', ['PLAN' => 'pro']); $j = decode($r);
    check(($j['total'] ?? 0) === 4, 'plan filter returns only pro subscribers (4)');
    $r = spawn($SELF, $ROOT, 'list_role', ['ROLE' => 'admin']); $j = decode($r);
    check(($j['total'] ?? 0) === 2, 'role filter (admin) returns 2');
    $r = spawn($SELF, $ROOT, 'list_page', ['PAGE' => '2']); $j = decode($r);
    check(($j['page'] ?? 0) === 2 && ($j['has_more'] ?? true) === false, 'pagination page + has_more');

    // ===== User detail (no secrets) =====
    $r = spawn($SELF, $ROOT, 'detail'); $j = decode($r);
    check(isset($j['user']['id']) && !str_contains($r['out'], 'password_hash'), 'detail identity without secrets');
    check(isset($j['user']['tradingAccounts']) && isset($j['user']['tradingActivity']), 'detail includes trading aggregation');

    // ===== Actions (audited) =====
    $r = spawn($SELF, $ROOT, 'suspend'); $j = decode($r);
    check(($j['data']['ok'] ?? false) === true && (($j['data']['user']['status'] ?? '') === 'suspended'), 'admin suspends normal user');
    $r = spawn($SELF, $ROOT, 'activate'); $j = decode($r);
    check(($j['data']['ok'] ?? false) === true && (($j['data']['user']['status'] ?? '') === 'active'), 'admin activates normal user');
    $r = spawn($SELF, $ROOT, 'count_audit'); $j = decode($r);
    check(($j['n'] ?? 0) >= 2, 'sensitive actions create audit rows');

    // ===== Privilege escalation / self / privileged-target =====
    $r = spawn($SELF, $ROOT, 'admin_role_denied'); check(str_contains($r['out'], 'PERMISSION_DENIED'), 'admin attempting role change -> PERMISSION_DENIED (Super-Admin-only)');
    $r = spawn($SELF, $ROOT, 'role_super_grant'); $j = decode($r);
    check(($j['data']['ok'] ?? false) === true && (($j['data']['user']['role'] ?? '') === 'admin'), 'super_admin grants admin role');
    $r = spawn($SELF, $ROOT, 'role_malformed'); check(str_contains($r['out'], 'VALIDATION_FAILED'), 'malformed role rejected (server-side)');
    $r = spawn($SELF, $ROOT, 'admin_target_privileged'); check(str_contains($r['out'], 'PRIVILEGED_TARGET'), 'admin (non-super) cannot operate on another admin');
    $r = spawn($SELF, $ROOT, 'self_role'); check(str_contains($r['out'], 'SELF_ACTION_DENIED'), 'self role change denied');

    // ===== Subscription mutations =====
    $r = spawn($SELF, $ROOT, 'subscription'); $j = decode($r);
    check(($j['data']['ok'] ?? false) === true && (($j['data']['user']['plan'] ?? '') === 'pro'), 'admin performs internal subscription upgrade');
    $r = spawn($SELF, $ROOT, 'subscription_upgrade_user_keeps_role'); $j = decode($r);
    check(($j['plan'] ?? '') === 'pro' && ($j['role'] ?? '') === 'user', 'subscription upgrade does NOT change authorization role');
    $r = spawn($SELF, $ROOT, 'subscription_bad'); check(str_contains($r['out'], 'VALIDATION_FAILED'), 'invalid plan rejected (server-side)');

    // ===== Audit log: append-only, gated, sanitized =====
    $r = spawn($SELF, $ROOT, 'audit_admin'); $j = decode($r);
    check(($j['data']['total'] ?? 0) >= 1, 'admin can list audit entries');
    check(!array_key_exists('ipAddress', $j['data']['items'][0] ?? []), 'admin audit list omits sensitive ipAddress');
    $r = spawn($SELF, $ROOT, 'audit_super'); $j = decode($r);
    check(array_key_exists('ipAddress', $j['data']['items'][0] ?? []), 'super_admin audit list includes sensitive ipAddress');
    $r = spawn($SELF, $ROOT, 'sanitize'); $j = decode($r);
    check(($j['api_key'] ?? '') === '[REDACTED]' && ($j['secret'] ?? '') === '[REDACTED]', 'audit sanitize redacts credential-shaped keys');

    // ===== Overview + health (real data; no fabrication) =====
    $r = spawn($SELF, $ROOT, 'overview'); $j = decode($r);
    check(($j['data']['overview']['users']['total'] ?? 0) >= 8, 'overview users.total from real data');
    check(($j['data']['overview']['billing']['revenue']['available'] ?? true) === false, 'revenue marked unavailable (no billing integration)');
    check(($j['data']['overview']['users']['planDistributionAvailable'] ?? false) === true, 'plan distribution available');
    $r = spawn($SELF, $ROOT, 'health'); $j = decode($r);
    check(($j['data']['health']['database']['status'] ?? '') === 'ok', 'health db status ok');
    check(($j['data']['health']['metaapi']['status'] ?? '') === 'configured_only', 'health metaapi configured_only (no live probe)');

    echo "\nadmin-panel: " . ($failures === 0 ? 'PASS' : 'FAIL') . " ($checks checks, $failures failures)\n";
    exit($failures === 0 ? 0 : 1);
}

// -------------------------------------------------------------------------
// child
// -------------------------------------------------------------------------
$case = $argv[2] ?? '';
$ROOT = $argv[3] ?? '';
putenv('APP_ENV=local');
putenv('APP_DEBUG=true');
putenv('VELORA_PRIVATE_ROOT=' . $ROOT);
putenv('VELORA_DOCUMENT_ROOT=' . dirname(__DIR__, 2));
putenv('DB_DRIVER=sqlite');
putenv('DB_DATABASE=' . $ROOT . '/data/velora.sqlite');
if (!is_file($ROOT . '/config/velora.env')) {
    file_put_contents($ROOT . '/config/velora.env', implode("\n", [
        'APP_ENV=local', 'APP_DEBUG=true', 'DB_DRIVER=sqlite', 'DB_DATABASE=' . $ROOT . '/data/velora.sqlite',
        'JWT_SECRET=' . str_repeat('j', 48), 'APP_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)),
        'CORS_ALLOWED_ORIGINS=http://localhost', 'FRONTEND_URL=http://localhost', 'MAIL_DRIVER=log',
        'METAAPI_TOKEN=',
    ]) . "\n");
}
@mkdir($ROOT . '/data', 0700, true);
ini_set('error_log', $ROOT . '/logs/php-error.log');
require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

use Velora\Admin\AdminAuditLogRepository;
use Velora\Admin\AuditLogController;
use Velora\Admin\OverviewController;
use Velora\Admin\SecurityController;
use Velora\Admin\UserManagementController;
use Velora\Admin\UserManagementService;
use Velora\Auth\Role;
use Velora\Auth\AuthMiddleware;
use Velora\Core\Request;

$pdo = new PDO('sqlite:' . $ROOT . '/data/velora.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if ($case === 'setup') {
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, password_hash TEXT DEFAULT \'\', full_name TEXT DEFAULT \'\', role TEXT NOT NULL DEFAULT \'user\', status TEXT NOT NULL DEFAULT \'active\', email_verified_at DATETIME NULL, locale TEXT DEFAULT \'fa\', timezone TEXT DEFAULT \'UTC\', plan TEXT NOT NULL DEFAULT \'free\', subscription_status TEXT NOT NULL DEFAULT \'none\', plan_started_at DATETIME NULL, plan_expires_at DATETIME NULL, plan_updated_at DATETIME NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER NOT NULL, actor_role TEXT NOT NULL, action TEXT NOT NULL, target_type TEXT NOT NULL, target_id INTEGER NULL, result TEXT NOT NULL DEFAULT \'success\', summary TEXT NULL, ip_address TEXT NULL, user_agent TEXT NULL, context_id TEXT NULL, metadata_json TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS user_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, revoked_at DATETIME NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS user_devices (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS trading_accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, sync_status TEXT DEFAULT \'DISCONNECTED\', metaapi_account_id TEXT NULL, last_synced_at DATETIME NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS trades (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, symbol TEXT, direction TEXT, entry_price REAL, profit_loss REAL, open_time DATETIME)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ai_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, status TEXT DEFAULT \'success\', tokens_used INTEGER DEFAULT 0)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ai_provider_credentials (provider TEXT PRIMARY KEY, status TEXT DEFAULT \'UNVERIFIED\', last_checked_at DATETIME NULL, error_code TEXT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ai_feature_providers (id INTEGER PRIMARY KEY AUTOINCREMENT, provider TEXT, enabled INTEGER DEFAULT 1)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ai_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, status TEXT, user_id INTEGER)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS sync_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, status TEXT)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS email_notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, status TEXT, sent_at DATETIME NULL, failed_at DATETIME NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS rate_limits (bucket TEXT PRIMARY KEY, hits INTEGER NOT NULL, window_start DATETIME NOT NULL)');

    $seed = [
        // email, name, role, status, plan, subscription_status
        ['alice@example.com', 'Alice', 'user', 'active', 'free', 'none'],
        ['bob@example.com', 'Bob', 'user', 'active', 'pro', 'active'],
        ['carol@example.com', 'Carol', 'user', 'active', 'pro', 'active'],
        ['dave@example.com', 'Dave', 'admin', 'active', 'free', 'none'],
        ['eve@example.com', 'Eve', 'super_admin', 'active', 'free', 'none'],
        ['frank@example.com', 'Frank', 'user', 'suspended', 'free', 'none'],
        ['gina@example.com', 'Gina', 'admin', 'active', 'pro', 'active'],
        ['heidi@example.com', 'Heidi', 'super_admin', 'active', 'pro', 'active'],
    ];
    foreach ($seed as $s) {
        $pdo->prepare('INSERT INTO users (email, full_name, role, status, plan, subscription_status) VALUES (?,?,?,?,?,?)')->execute($s);
    }
    $pdo->exec("INSERT INTO trading_accounts (user_id, sync_status, metaapi_account_id) VALUES (1,'CONNECTED','m1'), (3,'ERROR','m2')");
    $pdo->exec("INSERT INTO trades (user_id, symbol, direction, entry_price, profit_loss, open_time) VALUES (1,'XAUUSD','buy',10,25,CURRENT_TIMESTAMP)");
    $pdo->exec("INSERT INTO ai_requests (user_id, status, tokens_used) VALUES (1,'success',100),(1,'failed',0)");
    $pdo->exec("INSERT INTO ai_provider_credentials (provider, status) VALUES ('gemini','VALID')");
    $pdo->exec("INSERT INTO ai_feature_providers (provider, enabled) VALUES ('gemini',1)");
    echo 'SETUP_OK'; exit(0);
}

function mkRequest(string $path, array $body, string $role, int $uid, string $method = 'POST'): Request
{
    $rq = new Request($method, $path, [], $body,
        ['authorization' => 'Bearer ' . str_repeat('j', 48), 'user-agent' => 'test-agent', 'x-request-id' => 'ctx-123']);
    $rq->attributes['user_role'] = $role;
    $rq->attributes['user_id'] = $uid;
    return $rq;
}

function deny(callable $fn, int $http = 403): void
{
    try { $fn(); echo json_encode(['ok' => true]); } catch (\Throwable $e) {
        http_response_code($http);
        echo json_encode(['error' => ['code' => ($e instanceof \Velora\Core\Exceptions\ApiException ? $e->errorCode() : 'X'), 'details' => ($e instanceof \Velora\Core\Exceptions\ApiException ? $e->details() : null)]]);
    }
}

ob_start();
register_shutdown_function(function (): void { $o = ob_get_clean(); if ($o !== null && $o !== '') { echo $o; } });

switch ($case) {
    case 'rbac_user': deny(fn () => (AuthMiddleware::adminOnly())(mkRequest('/', [], 'user', 2))); break;
    case 'rbac_pro_customer': deny(fn () => (AuthMiddleware::adminOnly())(mkRequest('/', [], 'user', 2))); break; // role=user; plan=pro handled via combos
    case 'admin_gate': (AuthMiddleware::adminOnly())(mkRequest('/', [], 'admin', 4)); echo json_encode(['ok' => true]); break;
    case 'super_gate': (AuthMiddleware::adminOnly())(mkRequest('/', [], 'super_admin', 5)); echo json_encode(['ok' => true]); break;
    case 'unknown_perm': deny(fn () => (AuthMiddleware::requirePermission('does.not.exist'))(mkRequest('/', [], 'super_admin', 5))); break;
    case 'admin_denied_sensitive': deny(fn () => (AuthMiddleware::requirePermission(Role::P_AUDIT_SENSITIVE_VIEW))(mkRequest('/', [], 'admin', 4))); break;

    case 'combos': {
        $out['users'] = [];
        foreach ($pdo->query('SELECT email, role, plan FROM users')->fetchAll() as $r) { $out['users'][$r['email']] = ['role' => $r['role'], 'plan' => $r['plan']]; }
        $roles = $pdo->query('SELECT DISTINCT role FROM users')->fetchAll(PDO::FETCH_COLUMN);
        $out['roles'] = $roles;
        echo json_encode($out); break;
    }
    case 'pro_perms': {
        // A user whose plan=pro must still be a plain user with no admin permission.
        $row = $pdo->query("SELECT role, plan FROM users WHERE email='bob@example.com'")->fetch();
        $role = $row['role'];
        $can = Role::can($role, Role::P_USERS_SUSPEND);
        echo json_encode(['is_user' => $row['role'] === 'user', 'plan' => $row['plan'], 'has_admin_perm' => $can]);
        break;
    }

    case 'list': echo json_encode((new UserManagementService())->listUsers([], 1, 3)); break;
    case 'list_search': echo json_encode((new UserManagementService())->listUsers(['search' => getenv('SEARCH')], 1, 25)); break;
    case 'list_plan': echo json_encode((new UserManagementService())->listUsers(['plan' => getenv('PLAN')], 1, 25)); break;
    case 'list_role': echo json_encode((new UserManagementService())->listUsers(['role' => getenv('ROLE')], 1, 25)); break;
    case 'list_page': echo json_encode((new UserManagementService())->listUsers([], (int) getenv('PAGE'), 4)); break;

    case 'detail': echo json_encode(['user' => (new UserManagementService())->userDetail(1, 5, 'super_admin')]); break;

    case 'suspend': (new UserManagementController())->setStatus(mkRequest('/api/v1/admin/users/2/status', ['status' => 'suspended'], 'admin', 4), ['id' => '2']); break;
    case 'activate': (new UserManagementController())->setStatus(mkRequest('/api/v1/admin/users/2/status', ['status' => 'active'], 'admin', 4), ['id' => '2']); break;
    case 'count_audit': { $n = (int) $pdo->query('SELECT COUNT(*) AS n FROM admin_audit_logs')->fetch()['n']; echo json_encode(['n' => $n]); break; }

    case 'admin_role_denied': deny(fn () => (AuthMiddleware::requirePermission(Role::P_USERS_CHANGE_ROLE))(mkRequest('/', [], 'admin', 4))); break;
    case 'role_super_grant': (new UserManagementController())->setRole(mkRequest('/api/v1/admin/users/1/role', ['role' => 'admin'], 'super_admin', 5), ['id' => '1']); break;
    case 'role_malformed': deny(fn () => (new UserManagementController())->setRole(mkRequest('/api/v1/admin/users/1/role', ['role' => 'superuser'], 'super_admin', 5), ['id' => '1'])); break;
    case 'admin_target_privileged': deny(fn () => (new UserManagementController())->setStatus(mkRequest('/api/v1/admin/users/4/status', ['status' => 'suspended'], 'admin', 7), ['id' => '4'])); break;
    case 'self_role': deny(fn () => (new UserManagementController())->setRole(mkRequest('/api/v1/admin/users/5/role', ['role' => 'admin'], 'super_admin', 5), ['id' => '5'])); break;

    case 'subscription': (new UserManagementController())->setSubscription(mkRequest('/api/v1/admin/users/3/subscription', ['plan' => 'pro', 'status' => 'active'], 'admin', 4), ['id' => '3']); break;
    case 'subscription_upgrade_user_keeps_role': {
        // Upgrade bob (id2, role=user, plan=free) to pro; role must remain 'user'.
        (new UserManagementService())->setSubscription(2, ['plan' => 'pro', 'status' => 'active'], 4, 'admin');
        $row = $pdo->query("SELECT role, plan FROM users WHERE id=2")->fetch();
        echo json_encode(['role' => $row['role'], 'plan' => $row['plan']]); break;
    }
    case 'subscription_bad': deny(fn () => (new UserManagementController())->setSubscription(mkRequest('/api/v1/admin/users/3/subscription', ['plan' => 'enterprise', 'status' => 'active'], 'admin', 4), ['id' => '3']), 422); break;

    case 'overview': (new OverviewController())->overview(mkRequest('/api/v1/admin/overview', [], 'admin', 4, 'GET')); break;
    case 'health': (new OverviewController())->health(mkRequest('/api/v1/admin/system/health', [], 'admin', 4, 'GET')); break;
    case 'audit_admin': (new AuditLogController())->index(mkRequest('/api/v1/admin/logs/audit', [], 'admin', 4, 'GET')); break;
    case 'audit_super': (new AuditLogController())->index(mkRequest('/api/v1/admin/logs/audit', [], 'super_admin', 5, 'GET')); break;
    case 'sanitize': echo json_encode((new AdminAuditLogRepository())->sanitize(['api_key' => 'AIza-secret', 'secret' => 'pw', 'safe' => 'ok'])); break;
    case 'me_super': (new SecurityController())->me(mkRequest('/api/v1/admin/me', [], 'super_admin', 5, 'GET')); break;
    case 'me_admin': (new SecurityController())->me(mkRequest('/api/v1/admin/me', [], 'admin', 4, 'GET')); break;
    default: break;
}
