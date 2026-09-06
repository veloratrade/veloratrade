<?php

declare(strict_types=1);

use Velora\Admin\UserManagementController;
use Velora\Admin\UserManagementService;
use Velora\Auth\AuthMiddleware;
use Velora\Auth\Role;
use Velora\Core\Request;

/**
 * Phase 1 — Admin Create User: backend + authorization + security tests.
 *
 * Covers: real DB insert (real schema — role/plan/status/email-verification
 * state), the new canonical users.create permission (admin + super_admin),
 * privilege-escalation prevention (privileged-role creation requires
 * users.change_role), subscription-permission gating for plan=pro, duplicate
 * email handling (verified vs pending codes), password policy + secret
 * hygiene (no password/hash/token in responses or audit), validation
 * failures, rate limiting, and unauthenticated denial. No fake data: every
 * case runs against a real temp SQLite DB with the real services.
 *
 * Convention (mirrors test_user360): Response::json()/error() terminate the
 * process, so each case runs in a dedicated child process over one shared
 * temp SQLite DB. No real secrets, no network.
 *
 * Run: php tools/tests/test_admin_create_user.php
 */

$SELF = __FILE__;
$ROOT = sys_get_temp_dir() . '/velora-create-user-test-' . bin2hex(random_bytes(5));

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

    // ===== Super Admin creates a normal user (real insert) =====
    $r = spawn($SELF, $ROOT, 'super_create');
    check(str_contains($r['out'], '"ok":true'), 'super admin create -> 201 ok');
    check(str_contains($r['out'], '"role":"user"') && str_contains($r['out'], '"emailVerified":false'), 'created unverified user-role account');
    check(str_contains($r['out'], '"emailSent"'), 'honest emailSent state present');
    check(!str_contains($r['out'], 'Sup3rSecret!x') && !str_contains($r['out'], 'password') && !str_contains($r['out'], 'token') && !str_contains($r['out'], 'password_hash'), 'response leaks NO password/token/hash');

    // ===== DB truth: row + verification + audit =====
    $r = spawn($SELF, $ROOT, 'verify_db');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['row']['role'] ?? '') === 'user' && ($j['row']['status'] ?? '') === 'active' && ($j['row']['plan'] ?? '') === 'free', 'DB row: user/active/free defaults honored');
    check(($j['row']['email_verified_at'] ?? 'NULL') === 'NULL', 'DB row: starts unverified (email_verified_at NULL)');
    check((int) ($j['verification_count'] ?? 0) === 1, 'one hashed verification token stored');
    check(!str_contains($r['out'], 'Sup3rSecret!x'), 'DB password_hash is bcrypt, raw password absent');
    check(str_contains((string) ($j['hash_prefix'] ?? ''), '$2y$'), 'password stored bcrypt-hashed');
    check(($j['audit']['action'] ?? '') === 'user.create', 'audit row user.create written');
    check(!str_contains((string) ($j['audit']['metadata_json'] ?? '') . ($j['audit']['summary'] ?? ''), 'Sup3rSecret') && !str_contains((string) ($j['audit']['metadata_json'] ?? ''), 'token'), 'audit metadata free of secrets');

    // ===== Plain admin creates a normal user (users.create granted) =====
    $r = spawn($SELF, $ROOT, 'admin_create');
    check(str_contains($r['out'], '"ok":true') && str_contains($r['out'], '"role":"user"'), 'admin create -> 201 ok');

    // ===== Privilege escalation prevention =====
    $r = spawn($SELF, $ROOT, 'admin_create_privileged');
    check(str_contains($r['out'], 'PRIVILEGE_ESCALATION_DENIED'), 'admin creating admin-role -> PRIVILEGE_ESCALATION_DENIED');
    $r = spawn($SELF, $ROOT, 'super_create_admin_role');
    check(str_contains($r['out'], '"role":"admin"'), 'super admin creates admin-role account');

    // ===== RBAC: users.create enforced server-side =====
    $r = spawn($SELF, $ROOT, 'rbac_create_denied');
    check(str_contains($r['out'], 'PERMISSION_DENIED'), 'ordinary user denied users.create (RBAC)');

    // ===== Plan assignment permission =====
    $r = spawn($SELF, $ROOT, 'pro_requires_subscription_perm');
    check(str_contains($r['out'], 'SUBSCRIPTION_MANAGE_DENIED'), 'plan=pro without users.manage_subscription -> denied');
    $r = spawn($SELF, $ROOT, 'super_create_pro');
    check(str_contains($r['out'], '"plan":"pro"'), 'permitted pro creation succeeds');
    $r = spawn($SELF, $ROOT, 'verify_pro_db');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['subscription_status'] ?? '') === 'active' && ($j['plan_started_at'] ?? null) !== null, 'pro creation stamps subscription active + plan_started_at');

    // ===== Duplicate email handling =====
    $r = spawn($SELF, $ROOT, 'duplicate_verified');
    check(str_contains($r['out'], 'EMAIL_ALREADY_REGISTERED'), 'duplicate verified email -> EMAIL_ALREADY_REGISTERED');
    $r = spawn($SELF, $ROOT, 'duplicate_pending');
    check(str_contains($r['out'], 'EMAIL_PENDING_VERIFICATION'), 'duplicate unverified email -> EMAIL_PENDING_VERIFICATION');

    // ===== Validation / policy =====
    $r = spawn($SELF, $ROOT, 'weak_password');
    check(str_contains($r['out'], 'VALIDATION') || str_contains($r['out'], 'WEAK'), 'weak password rejected by shared policy');
    $r = spawn($SELF, $ROOT, 'invalid_email');
    check(str_contains($r['out'], 'VALIDATION'), 'invalid email rejected');
    $r = spawn($SELF, $ROOT, 'empty_name');
    check(str_contains($r['out'], '"fullName"') && str_contains($r['out'], '"code":"REQUIRED"') && str_contains($r['out'], 'errors.validation.required'), 'empty full name rejected (fields.fullName REQUIRED)');
    $r = spawn($SELF, $ROOT, 'invalid_role');
    check(str_contains($r['out'], '"role"') && str_contains($r['out'], '"code":"INVALID_CHOICE"') && str_contains($r['out'], 'errors.validation.choice'), 'unknown role rejected (fields.role INVALID_CHOICE)');

    // ===== Rate limiting =====
    $r = spawn($SELF, $ROOT, 'rate_limit');
    check(str_contains($r['out'], 'TOO_MANY_REQUESTS'), '11th create in window -> 429 TOO_MANY_REQUESTS');

    // ===== Unauthenticated =====
    $r = spawn($SELF, $ROOT, 'unauthenticated');
    check(str_contains($r['out'], 'ACCESS_TOKEN_MISSING') || str_contains($r['out'], 'UNAUTHORIZED'), 'unauthenticated request -> 401');

    echo "\nadmin-create-user: " . ($failures === 0 ? 'PASS' : 'FAIL') . " ($checks checks, $failures failures)\n";
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
@mkdir($ROOTC . '/logs', 0700, true);
ini_set('error_log', $ROOTC . '/logs/php-error.log');
require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

$pdo = \Velora\Core\Database::connection();

if ($case === 'setup') {
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, password_hash TEXT DEFAULT \'\', full_name TEXT DEFAULT \'\', role TEXT NOT NULL DEFAULT \'user\', status TEXT NOT NULL DEFAULT \'active\', email_verified_at DATETIME NULL, locale TEXT DEFAULT \'fa\', locale_source TEXT DEFAULT \'auto\', timezone TEXT DEFAULT \'UTC\', plan TEXT NOT NULL DEFAULT \'free\', subscription_status TEXT NOT NULL DEFAULT \'none\', plan_started_at DATETIME NULL, plan_expires_at DATETIME NULL, plan_updated_at DATETIME NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER NOT NULL, actor_role TEXT NOT NULL, action TEXT NOT NULL, target_type TEXT NOT NULL, target_id INTEGER NULL, result TEXT NOT NULL DEFAULT \'success\', summary TEXT NULL, ip_address TEXT NULL, user_agent TEXT NULL, context_id TEXT NULL, metadata_json TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS email_verifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token_hash TEXT NOT NULL, expires_at DATETIME NOT NULL, verified_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS rate_limits (bucket TEXT PRIMARY KEY, hits INTEGER NOT NULL, window_start DATETIME NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS user_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, refresh_token_hash TEXT DEFAULT \'\', access_token_hash TEXT DEFAULT \'\', ip_address TEXT NULL, user_agent TEXT NULL, expires_at DATETIME NULL, revoked_at DATETIME NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec("INSERT INTO users (email, full_name, role, status, email_verified_at) VALUES
        ('eve@example.com','Eve','super_admin','active',CURRENT_TIMESTAMP),
        ('alice@example.com','Alice','user','active',NULL),
        ('bob@example.com','Bob','admin','active',CURRENT_TIMESTAMP),
        ('carol@example.com','Carol','user','active',CURRENT_TIMESTAMP)");
    echo 'SETUP_OK';
    exit(0);
}

function mkRequest(array $body, string $role, int $uid, string $method = 'POST', bool $withAuth = true): Request
{
    $headers = ['user-agent' => 'test-agent', 'x-request-id' => 'ctx-123'];
    if ($withAuth) {
        $headers['authorization'] = 'Bearer ' . str_repeat('j', 48);
    }
    $rq = new Request($method, '/api/v1/admin/users', [], $body, $headers);
    $rq->attributes['user_role'] = $role;
    $rq->attributes['user_id'] = $uid;
    return $rq;
}

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
            'msg' => $e instanceof \Velora\Core\Exceptions\ApiException ? null : $e->getMessage(),
            'fields' => method_exists($e, 'details') ? $e->details() : [],
        ]]);
    }
}

$ctrl = new UserManagementController();
$svc = new UserManagementService();

// Rate-limit isolation: the 10/hour admin-user-create bucket lives in the
// shared temp DB, so every store() case starts from an empty bucket. The
// dedicated rate_limit case fills the bucket itself.
function freshRate(\PDO $pdo): void
{
    $pdo->exec('DELETE FROM rate_limits');
}

$create = [
    'email' => 'newuser@example.com', 'password' => 'Sup3rSecret!x', 'fullName' => 'New User',
    'role' => 'user', 'plan' => 'free', 'locale' => 'en',
];

switch ($case) {
    case 'super_create': freshRate($pdo); $ctrl->store(mkRequest($create, 'super_admin', 1), []); break;
    case 'verify_db': {
        $row = $pdo->query("SELECT id, role, status, plan, email_verified_at, password_hash FROM users WHERE email='newuser@example.com'")->fetch(\PDO::FETCH_ASSOC) ?: [];
        $vc = (int) $pdo->query("SELECT COUNT(*) c FROM email_verifications WHERE user_id=" . (int) ($row['id'] ?? 0))->fetch()['c'];
        $audit = $pdo->query("SELECT action, summary, metadata_json FROM admin_audit_logs WHERE action='user.create' ORDER BY id DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC) ?: [];
        echo json_encode([
            'row' => ['role' => $row['role'] ?? null, 'status' => $row['status'] ?? null, 'plan' => $row['plan'] ?? null,
                      'email_verified_at' => ($row['email_verified_at'] ?? null) === null ? 'NULL' : $row['email_verified_at']],
            'hash_prefix' => substr((string) ($row['password_hash'] ?? ''), 0, 4),
            'verification_count' => $vc,
            'audit' => $audit,
        ]);
        break;
    }
    case 'admin_create': freshRate($pdo); $ctrl->store(mkRequest(['email' => 'byadmin@example.com', 'password' => 'Sup3rSecret!x', 'fullName' => 'By Admin'], 'admin', 3), []); break;
    case 'admin_create_privileged': freshRate($pdo); deny(fn () => $ctrl->store(mkRequest(['email' => 'esc@example.com', 'password' => 'Sup3rSecret!x', 'fullName' => 'Esc', 'role' => 'admin'], 'admin', 3), [])); break;
    case 'super_create_admin_role': freshRate($pdo); $ctrl->store(mkRequest(['email' => 'madeadmin@example.com', 'password' => 'Sup3rSecret!x', 'fullName' => 'Made Admin', 'role' => 'admin'], 'super_admin', 1), []); break;
    case 'rbac_create_denied': deny(fn () => (AuthMiddleware::requirePermission(Role::P_USERS_CREATE))(mkRequest($create, 'user', 2))); break;
    case 'pro_requires_subscription_perm': deny(fn () => $svc->createUser(['email' => 'pro@example.com', 'password' => 'Sup3rSecret!x', 'fullName' => 'Pro User', 'plan' => 'pro'], 2, Role::USER)); break;
    case 'super_create_pro': $ctrl->store(mkRequest(['email' => 'prouser@example.com', 'password' => 'Sup3rSecret!x', 'fullName' => 'Pro User', 'plan' => 'pro'], 'super_admin', 1), []); break;
    case 'verify_pro_db': {
        $row = $pdo->query("SELECT subscription_status, plan_started_at FROM users WHERE email='prouser@example.com'")->fetch(\PDO::FETCH_ASSOC) ?: [];
        echo json_encode(['subscription_status' => $row['subscription_status'] ?? null, 'plan_started_at' => $row['plan_started_at'] ?? null]);
        break;
    }
    case 'duplicate_verified': freshRate($pdo); deny(fn () => $ctrl->store(mkRequest(['email' => 'carol@example.com', 'password' => 'Sup3rSecret!x', 'fullName' => 'Dup'], 'super_admin', 1), [])); break;
    case 'duplicate_pending': freshRate($pdo); deny(fn () => $ctrl->store(mkRequest(['email' => 'alice@example.com', 'password' => 'Sup3rSecret!x', 'fullName' => 'Dup'], 'super_admin', 1), [])); break;
    case 'weak_password': freshRate($pdo); deny(fn () => $ctrl->store(mkRequest(['email' => 'weak@example.com', 'password' => 'short', 'fullName' => 'Weak'], 'super_admin', 1), [])); break;
    case 'invalid_email': freshRate($pdo); deny(fn () => $ctrl->store(mkRequest(['email' => 'not-an-email', 'password' => 'Sup3rSecret!x', 'fullName' => 'Bad'], 'super_admin', 1), [])); break;
    case 'empty_name': freshRate($pdo); deny(fn () => $ctrl->store(mkRequest(['email' => 'noname@example.com', 'password' => 'Sup3rSecret!x', 'fullName' => '  '], 'super_admin', 1), [])); break;
    case 'invalid_role': freshRate($pdo); deny(fn () => $ctrl->store(mkRequest(['email' => 'badrole@example.com', 'password' => 'Sup3rSecret!x', 'fullName' => 'Bad', 'role' => 'owner'], 'super_admin', 1), [])); break;
    case 'rate_limit': {
        freshRate($pdo);
        try {
            for ($i = 0; $i < 10; $i++) {
                \Velora\Core\RateLimiter::hit('admin-user-create', 10, 3600);
            }
            \Velora\Core\RateLimiter::hit('admin-user-create', 10, 3600);
            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            http_response_code(429);
            echo json_encode(['error' => ['code' => 'TOO_MANY_REQUESTS']]);
        }
        break;
    }
    case 'unauthenticated': {
        // No authorization header at all -> authenticate() middleware must 401.
        $rq = new Request('POST', '/api/v1/admin/users', [], $create, ['user-agent' => 'anon']);
        $mw = AuthMiddleware::authenticate();
        try {
            $mw($rq);
            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            http_response_code(401);
            echo json_encode(['error' => ['code' => $e instanceof \Velora\Core\Exceptions\ApiException ? $e->errorCode() : 'UNAUTHORIZED']]);
        }
        break;
    }
    default:
        http_response_code(500);
        echo json_encode(['error' => ['code' => 'UNKNOWN_CASE']]);
}
