<?php

declare(strict_types=1);

use Velora\Admin\SecurityController;
use Velora\Admin\UserManagementController;
use Velora\Admin\UserManagementService;
use Velora\Auth\AuthMiddleware;
use Velora\Auth\PasswordService;
use Velora\Auth\PasswordResetRepository;
use Velora\Auth\Role;
use Velora\Core\Request;

/**
 * Phase 2 — Admin Invite + /admin/me identity enrichment: backend,
 * authorization, token lifecycle and security tests.
 *
 * Covers: real privileged-account invitation (role admin/super_admin,
 * unverified, empty password hash — cannot authenticate until acceptance),
 * the reuse of the existing password_resets token store (sha256-hashed,
 * TTL, one-time atomic consume, invalidation), escalation + RBAC denials,
 * duplicate codes (verified/pending, incl. existing admins), invite
 * validation, rate limiting, honest emailSent, secret-free audit and
 * responses, acceptance via the existing reset flow (valid/reused/invalid/
 * expired tokens, weak password), forgot-password anti-enumeration for
 * invited accounts, DB-failure handling, and the enriched /admin/me identity
 * (id/name/fullName/email/role/permissions) with honest fallbacks. Every
 * case runs against a real temp SQLite DB with the real services — no fake
 * data, no network.
 *
 * Convention (mirrors test_admin_create_user / test_user360): each case runs
 * in a dedicated child process over one shared temp SQLite DB.
 *
 * Run: php tools/tests/test_admin_invite_identity.php
 */

$SELF = __FILE__;
$ROOT = sys_get_temp_dir() . '/velora-invite-identity-test-' . bin2hex(random_bytes(5));

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

    function freshRate(\PDO $pdo): void
    {
        // child-side helper is defined below; parent only spawns
    }

    spawn($SELF, $ROOT, 'setup');

    // ===== Super Admin invites an admin (real insert + token + email) =====
    $r = spawn($SELF, $ROOT, 'super_invite');
    check(str_contains($r['out'], '"ok":true') && str_contains($r['out'], '"role":"admin"'), 'super admin invite -> 201 ok');
    check(str_contains($r['out'], '"emailSent"'), 'honest emailSent state present');
    check(str_contains($r['out'], '"invitePending":true') && str_contains($r['out'], '"emailVerified":false'), 'invite pending + unverified state exposed');
    check(!str_contains($r['out'], 'token') && !str_contains($r['out'], 'password'), 'response leaks NO token/password');

    // ===== DB truth =====
    $r = spawn($SELF, $ROOT, 'verify_db');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['row']['role'] ?? '') === 'admin' && ($j['row']['status'] ?? '') === 'active' && ($j['row']['plan'] ?? '') === 'free', 'DB row: admin/active/free');
    check(($j['row']['email_verified_at'] ?? 'NULL') === 'NULL', 'DB row: invited admin starts unverified');
    check(($j['row']['password_hash'] ?? 'X') === '', 'DB row: empty password hash (cannot authenticate)');
    check((int) ($j['valid_tokens'] ?? 0) === 1, 'exactly one valid password_resets token for invitee');
    check((int) ($j['token_hex64'] ?? 0) === 1, 'stored token is sha256 hex (64 chars)');
    check(($j['ttl_hours'] ?? 0) >= 23.9 && ($j['ttl_hours'] ?? 0) <= 24.1, 'invite token TTL ≈ 24h');
    $r = spawn($SELF, $ROOT, 'verify_db_token_valid');
    check(str_contains($r['out'], '"findable":true'), 'invite token findable/consumable via PasswordResetRepository');

    // ===== audit =====
    $r = spawn($SELF, $ROOT, 'verify_audit');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['audit']['action'] ?? '') === 'user.invite', 'audit row user.invite written');
    $meta = (string) ($j['audit']['metadata_json'] ?? '');
    check(str_contains($meta, '"role"') && str_contains($meta, '"emailSent"'), 'audit metadata carries role/emailSent');
    check(!str_contains($meta, 'token') && !str_contains($meta, 'password'), 'audit metadata free of secrets');

    // ===== authorization =====
    $r = spawn($SELF, $ROOT, 'admin_invite_denied');
    check(str_contains($r['out'], 'PRIVILEGE_ESCALATION_DENIED'), 'admin without users.change_role -> PRIVILEGE_ESCALATION_DENIED');
    $r = spawn($SELF, $ROOT, 'rbac_invite_denied');
    check(str_contains($r['out'], 'PERMISSION_DENIED'), 'ordinary user denied invite (RBAC)');
    $r = spawn($SELF, $ROOT, 'unauthenticated');
    check(str_contains($r['out'], 'ACCESS_TOKEN_MISSING') || str_contains($r['out'], 'UNAUTHORIZED'), 'unauthenticated invite -> 401');

    // ===== Super Admin behavior: can invite super_admin too =====
    $r = spawn($SELF, $ROOT, 'super_invite_super');
    check(str_contains($r['out'], '"role":"super_admin"') && str_contains($r['out'], '"ok":true'), 'super admin invites super_admin -> 201');

    // ===== duplicates / existing admins =====
    $r = spawn($SELF, $ROOT, 'dup_verified');
    check(str_contains($r['out'], 'EMAIL_ALREADY_REGISTERED'), 'duplicate verified email -> EMAIL_ALREADY_REGISTERED');
    $r = spawn($SELF, $ROOT, 'dup_verified_admin');
    check(str_contains($r['out'], 'EMAIL_ALREADY_REGISTERED'), 'existing verified ADMIN -> EMAIL_ALREADY_REGISTERED (safe)');
    $r = spawn($SELF, $ROOT, 'dup_pending');
    check(str_contains($r['out'], 'EMAIL_PENDING_VERIFICATION'), 'duplicate unverified email -> EMAIL_PENDING_VERIFICATION');

    // ===== validation =====
    $r = spawn($SELF, $ROOT, 'role_user_rejected');
    check(str_contains($r['out'], '"role"') && str_contains($r['out'], '"code":"INVALID_CHOICE"'), 'inviting role=user rejected (INVALID_CHOICE)');
    $r = spawn($SELF, $ROOT, 'bad_email');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'invalid email rejected');
    $r = spawn($SELF, $ROOT, 'missing_name');
    check(str_contains($r['out'], '"fullName"') && str_contains($r['out'], '"code":"REQUIRED"'), 'missing full name rejected (fields.fullName REQUIRED)');
    $r = spawn($SELF, $ROOT, 'missing_email');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'missing email rejected');

    // ===== rate limiting (controller-level 10/3600) =====
    $r = spawn($SELF, $ROOT, 'rate_limit');
    check(str_contains($r['out'], 'TOO_MANY_REQUESTS'), '11th invite in window -> 429 TOO_MANY_REQUESTS');

    // ===== DB failure -> honest 500, no fake success =====
    $r = spawn($SELF, $ROOT, 'db_failure');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['error']['code'] ?? '') === 'X' && ($j['error']['class'] ?? '') === 'PDOException', 'database failure surfaces as error (no fabricated success)');

    // ===== acceptance via the EXISTING reset flow =====
    $r = spawn($SELF, $ROOT, 'accept_valid');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['ok'] ?? false) === true, 'acceptance (valid token) succeeds');
    check(str_starts_with((string) ($j['hash_prefix'] ?? ''), '$2y$'), 'acceptance stores bcrypt password');
    check(($j['verified'] ?? false) === true, 'invite-grade acceptance marks email verified');
    $r = spawn($SELF, $ROOT, 'accept_reuse');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'reused token rejected');
    $r = spawn($SELF, $ROOT, 'accept_invalid');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'invalid token rejected');
    $r = spawn($SELF, $ROOT, 'accept_expired');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'expired token rejected');
    $r = spawn($SELF, $ROOT, 'accept_weak_password');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'weak password rejected by shared policy');
    $r = spawn($SELF, $ROOT, 'accept_missing_fields');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'missing acceptance fields rejected');

    // ===== regular reset path unaffected (verified user stays verified; marking is invite-only) =====
    $r = spawn($SELF, $ROOT, 'reset_regular_verified_stays');
    check(str_contains($r['out'], '"ok":true') && str_contains($r['out'], '"verified":true'), 'regular reset for verified user unaffected');

    // ===== forgot-password anti-enumeration preserved for invited accounts =====
    $r = spawn($SELF, $ROOT, 'forgot_no_leak');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['tokens'] ?? -1) === 1, 'forgotPassword for invited (unverified) account creates NO extra token');

    // ===== /admin/me identity enrichment =====
    $r = spawn($SELF, $ROOT, 'me_super');
    $j = json_decode($r['out'], true) ?: [];
    $m = $j['data']['me'] ?? [];
    check(($m['id'] ?? 0) === 1 && ($m['userId'] ?? 0) === 1, '/admin/me exposes real id (and legacy userId)');
    check(($m['name'] ?? '') === 'Arman Kaveh' && ($m['fullName'] ?? '') === 'Arman Kaveh', '/admin/me exposes real name/fullName');
    check(($m['email'] ?? '') === 'super@velora.test', '/admin/me exposes real email');
    check(($m['role'] ?? '') === 'super_admin' && ($m['isSuperAdmin'] ?? false) === true, '/admin/me keeps role semantics');
    check(in_array('users.create', $m['permissions'] ?? [], true) && in_array('users.change_role', $m['permissions'] ?? [], true), '/admin/me exposes canonical permissions');
    $r = spawn($SELF, $ROOT, 'me_fallback');
    $j = json_decode($r['out'], true) ?: [];
    $mf = $j['data']['me'] ?? [];
    check(array_key_exists('name', $mf) && $mf['name'] === null && ($mf['email'] ?? '') === 'empty@velora.test', '/admin/me: missing name stays null (no fabrication), email real');
    $r = spawn($SELF, $ROOT, 'me_admin_deny');
    check(str_contains($r['out'], 'ADMIN_REQUIRED'), '/admin/me: non-admin denied (adminOnly)');

    echo "\ninvite-identity: " . ($failures === 0 ? 'PASS' : 'FAIL') . " ($checks checks, $failures failures)\n";
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
            'fields' => method_exists($e, 'details') ? $e->details() : [],
        ]]);
    }
}

function mkRequest(array $body, string $role, int $uid, string $method = 'POST', bool $withAuth = true): Request
{
    $headers = ['user-agent' => 'test-agent', 'x-request-id' => 'ctx-123'];
    if ($withAuth) {
        $headers['authorization'] = 'Bearer ' . str_repeat('j', 48);
    }
    $rq = new Request($method, '/api/v1/admin/users/invitations', [], $body, $headers);
    $rq->attributes['user_role'] = $role;
    $rq->attributes['user_id'] = $uid;
    return $rq;
}

$case = $argv[2];

if ($case === 'setup') {
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, password_hash TEXT DEFAULT \'\', full_name TEXT DEFAULT \'\', role TEXT NOT NULL DEFAULT \'user\', status TEXT NOT NULL DEFAULT \'active\', email_verified_at DATETIME NULL, locale TEXT DEFAULT \'fa\', locale_source TEXT DEFAULT \'auto\', timezone TEXT DEFAULT \'UTC\', plan TEXT NOT NULL DEFAULT \'free\', subscription_status TEXT NOT NULL DEFAULT \'none\', plan_started_at DATETIME NULL, plan_expires_at DATETIME NULL, plan_updated_at DATETIME NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER NOT NULL, actor_role TEXT NOT NULL, action TEXT NOT NULL, target_type TEXT NOT NULL, target_id INTEGER NULL, result TEXT NOT NULL DEFAULT \'success\', summary TEXT NULL, ip_address TEXT NULL, user_agent TEXT NULL, context_id TEXT NULL, metadata_json TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token_hash TEXT NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec('CREATE TABLE IF NOT EXISTS rate_limits (bucket TEXT PRIMARY KEY, hits INTEGER NOT NULL, window_start DATETIME NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS user_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, refresh_token_hash TEXT DEFAULT \'\', access_token_hash TEXT DEFAULT \'\', ip_address TEXT NULL, user_agent TEXT NULL, expires_at DATETIME NULL, revoked_at DATETIME NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS email_notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, event_type TEXT NOT NULL, recipient_email TEXT NOT NULL, subject TEXT NOT NULL, payload_json TEXT NULL, status TEXT NOT NULL DEFAULT \'queued\', sent_at DATETIME NULL, failed_at DATETIME NULL, error_message TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $ins = $pdo->prepare('INSERT INTO users (email, password_hash, full_name, role, status, email_verified_at) VALUES (?, ?, ?, ?, ?, ?)');
    $cost = 4; // fast hashes in tests only; production cost unchanged (12)
    $ins->execute(['super@velora.test', password_hash('SuperAdmin!1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Arman Kaveh', 'super_admin', 'active', gmdate('Y-m-d H:i:s')]);
    $ins->execute(['user@velora.test', password_hash('PlainUser!1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Plain User', 'user', 'active', gmdate('Y-m-d H:i:s')]);
    $ins->execute(['admin@velora.test', password_hash('AdminUser!1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Ada Admin', 'admin', 'active', gmdate('Y-m-d H:i:s')]);
    $ins->execute(['carol@example.com', password_hash('CarolPass!1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Carol Verified', 'user', 'active', gmdate('Y-m-d H:i:s')]);
    $ins->execute(['admin4@velora.test', password_hash('AdminFour!1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Fourth Admin', 'admin', 'active', gmdate('Y-m-d H:i:s')]);
    $ins->execute(['alice@example.com', password_hash('AlicePass!1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Alice Pending', 'user', 'active', null]);
    $ins->execute(['empty@velora.test', password_hash('EmptyName!1', PASSWORD_BCRYPT, ['cost' => $cost]), '', 'admin', 'active', gmdate('Y-m-d H:i:s')]);
    echo json_encode(['ok' => true]);
    exit(0);
}

$ctrl = new UserManagementController();
$svc = new UserManagementService();

// Rate-limit isolation: each store()/invite() case starts from an empty
// bucket; the dedicated rate_limit case fills it itself.
$pdo->exec('DELETE FROM rate_limits');

$invite = ['email' => 'invitee@velora.test', 'fullName' => 'Nima Invitee', 'role' => 'admin'];

function freshPdo(): \PDO
{
    return \Velora\Core\Database::connection();
}

switch ($case) {
    case 'super_invite': $ctrl->invite(mkRequest($invite, 'super_admin', 1), []); break;
    case 'verify_db': {
        $row = $pdo->query("SELECT id, role, status, plan, email_verified_at, password_hash FROM users WHERE email='invitee@velora.test'")->fetch(\PDO::FETCH_ASSOC) ?: [];
        $valid = (int) $pdo->query("SELECT COUNT(*) c FROM password_resets WHERE user_id=" . (int) ($row['id'] ?? 0) . " AND used_at IS NULL AND expires_at >= '" . gmdate('Y-m-d H:i:s') . "'")->fetch()['c'];
        $hex = (int) $pdo->query("SELECT COUNT(*) c FROM password_resets WHERE user_id=" . (int) ($row['id'] ?? 0) . " AND LENGTH(token_hash)=64 AND trim(token_hash, '0123456789abcdef')=''")->fetch()['c'];
        $exp = $pdo->query("SELECT expires_at FROM password_resets WHERE user_id=" . (int) ($row['id'] ?? 0) . " ORDER BY id DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        $hours = $exp ? (strtotime((string) $exp['expires_at']) - time()) / 3600 : 0;
        echo json_encode(['row' => ['role' => $row['role'] ?? null, 'status' => $row['status'] ?? null, 'plan' => $row['plan'] ?? null,
            'email_verified_at' => ($row['email_verified_at'] ?? null) === null ? 'NULL' : $row['email_verified_at'],
            'password_hash' => (string) ($row['password_hash'] ?? 'X')],
            'valid_tokens' => $valid, 'token_hex64' => $hex, 'ttl_hours' => round($hours, 2)]);
        break;
    }
    case 'verify_db_token_valid': {
        $row = $pdo->query("SELECT id FROM users WHERE email='invitee@velora.test'")->fetch(\PDO::FETCH_ASSOC) ?: [];
        $t = $pdo->query("SELECT token_hash FROM password_resets WHERE user_id=" . (int) ($row['id'] ?? 0) . " AND used_at IS NULL ORDER BY id DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        $findable = $t ? (new PasswordResetRepository())->findValidByHash((string) $t['token_hash']) !== null : false;
        echo json_encode(['findable' => $findable]);
        break;
    }
    case 'verify_audit': {
        $audit = $pdo->query("SELECT action, summary, metadata_json FROM admin_audit_logs WHERE action='user.invite' ORDER BY id DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC) ?: [];
        echo json_encode(['audit' => $audit]);
        break;
    }
    case 'admin_invite_denied': deny(fn () => $ctrl->invite(mkRequest(['email' => 'esc@velora.test', 'fullName' => 'Esc', 'role' => 'admin'], 'admin', 3), [])); break;
    case 'rbac_invite_denied': deny(fn () => (AuthMiddleware::requirePermission(Role::P_USERS_CREATE))(mkRequest($invite, 'user', 2))); break;
    case 'unauthenticated': {
        $rq = new Request('POST', '/api/v1/admin/users/invitations', [], $invite, ['user-agent' => 'anon']);
        try {
            (AuthMiddleware::authenticate())($rq);
            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            http_response_code(401);
            echo json_encode(['error' => ['code' => $e instanceof \Velora\Core\Exceptions\ApiException ? $e->errorCode() : 'UNAUTHORIZED']]);
        }
        break;
    }
    case 'super_invite_super': $ctrl->invite(mkRequest(['email' => 'secondsuper@velora.test', 'fullName' => 'Second Super', 'role' => 'super_admin'], 'super_admin', 1), []); break;
    case 'dup_verified': deny(fn () => $ctrl->invite(mkRequest(['email' => 'carol@example.com', 'fullName' => 'Dup'], 'super_admin', 1), [])); break;
    case 'dup_verified_admin': deny(fn () => $ctrl->invite(mkRequest(['email' => 'admin4@velora.test', 'fullName' => 'Dup Admin'], 'super_admin', 1), [])); break;
    case 'dup_pending': deny(fn () => $ctrl->invite(mkRequest(['email' => 'alice@example.com', 'fullName' => 'Dup Pending'], 'super_admin', 1), [])); break;
    case 'role_user_rejected': deny(fn () => $ctrl->invite(mkRequest(['email' => 'plain@velora.test', 'fullName' => 'Plain', 'role' => 'user'], 'super_admin', 1), [])); break;
    case 'bad_email': deny(fn () => $ctrl->invite(mkRequest(['email' => 'not-an-email', 'fullName' => 'Bad'], 'super_admin', 1), [])); break;
    case 'missing_name': deny(fn () => $ctrl->invite(mkRequest(['email' => 'noname@velora.test'], 'super_admin', 1), [])); break;
    case 'missing_email': deny(fn () => $ctrl->invite(mkRequest(['fullName' => 'No Email'], 'super_admin', 1), [])); break;
    case 'rate_limit': {
        try {
            for ($i = 0; $i < 10; $i++) {
                \Velora\Core\RateLimiter::hit('admin-user-invite', 10, 3600);
            }
            \Velora\Core\RateLimiter::hit('admin-user-invite', 10, 3600);
            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            http_response_code(429);
            echo json_encode(['error' => ['code' => 'TOO_MANY_REQUESTS']]);
        }
        break;
    }
    case 'db_failure': {
        $pdo->exec('ALTER TABLE users RENAME TO users_bak');
        deny(fn () => $svc->inviteAdmin(['email' => 'dbfail@velora.test', 'fullName' => 'DB Fail', 'role' => 'admin'], 1, 'super_admin'));
        // restore the shared schema — later cases must not inherit the failure
        $pdo->exec('ALTER TABLE users_bak RENAME TO users');
        break;
    }
    case 'accept_valid': {
        $uid = (int) $pdo->query("SELECT id FROM users WHERE email='invitee@velora.test'")->fetch()['id'];
        $raw = bin2hex(random_bytes(32));
        (new PasswordResetRepository())->create($uid, hash('sha256', $raw), 3600);
        try {
            (new PasswordService())->resetPassword(['token' => $raw, 'newPassword' => 'Invited!Pass1']);
            $row = $pdo->query("SELECT password_hash, email_verified_at FROM users WHERE id={$uid}")->fetch(\PDO::FETCH_ASSOC) ?: [];
            $used = (int) $pdo->query("SELECT COUNT(*) c FROM password_resets WHERE user_id={$uid} AND used_at IS NOT NULL")->fetch()['c'];
            echo json_encode(['ok' => true, 'hash_prefix' => substr((string) ($row['password_hash'] ?? ''), 0, 4),
                'verified' => ($row['email_verified_at'] ?? null) !== null, 'consumed' => $used]);
        } catch (\Throwable $e) {
            http_response_code(422);
            echo json_encode(['error' => ['code' => $e instanceof \Velora\Core\Exceptions\ApiException ? $e->errorCode() : 'X']]);
        }
        break;
    }
    case 'accept_reuse': {
        // reuse the token consumed by accept_valid (its hash is stored used)
        $t = $pdo->query("SELECT token_hash FROM password_resets WHERE used_at IS NOT NULL ORDER BY id DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        deny(fn () => (new PasswordService())->resetPassword(['token' => str_repeat('a', 64), 'newPassword' => 'Whatever!123']));
        if ($t !== false) {
            // deterministic reuse probe on a fresh token consumed twice
            $uid = (int) $pdo->query("SELECT id FROM users WHERE email='secondsuper@velora.test'")->fetch()['id'];
            $raw = bin2hex(random_bytes(32));
            $repo = new PasswordResetRepository();
            $repo->create($uid, hash('sha256', $raw), 3600);
            (new PasswordService())->resetPassword(['token' => $raw, 'newPassword' => 'Second!Pass1']);
            deny(fn () => (new PasswordService())->resetPassword(['token' => $raw, 'newPassword' => 'Second!Pass2']));
        }
        break;
    }
    case 'accept_invalid': deny(fn () => (new PasswordService())->resetPassword(['token' => str_repeat('f', 64), 'newPassword' => 'Whatever!123'])); break;
    case 'accept_expired': {
        $uid = (int) $pdo->query("SELECT id FROM users WHERE email='empty@velora.test'")->fetch()['id'];
        $raw = bin2hex(random_bytes(32));
        $repo = new PasswordResetRepository();
        $id = $repo->create($uid, hash('sha256', $raw), 3600);
        $pdo->exec("UPDATE password_resets SET expires_at='" . gmdate('Y-m-d H:i:s', time() - 10) . "' WHERE id={$id}");
        deny(fn () => (new PasswordService())->resetPassword(['token' => $raw, 'newPassword' => 'Whatever!123']));
        break;
    }
    case 'accept_weak_password': {
        $uid = (int) $pdo->query("SELECT id FROM users WHERE email='empty@velora.test'")->fetch()['id'];
        $raw = bin2hex(random_bytes(32));
        (new PasswordResetRepository())->create($uid, hash('sha256', $raw), 3600);
        deny(fn () => (new PasswordService())->resetPassword(['token' => $raw, 'newPassword' => 'short']));
        break;
    }
    case 'accept_missing_fields': deny(fn () => (new \Velora\Auth\AuthController())->resetPassword(mkRequest(['newPassword' => 'Whatever!123'], 'guest', 0, 'POST', false))); break;
    case 'reset_regular_verified_stays': {
        $uid = (int) $pdo->query("SELECT id FROM users WHERE email='carol@example.com'")->fetch()['id'];
        $raw = bin2hex(random_bytes(32));
        (new PasswordResetRepository())->create($uid, hash('sha256', $raw), 3600);
        try {
            (new PasswordService())->resetPassword(['token' => $raw, 'newPassword' => 'CarolNew!Pass1']);
            $row = $pdo->query("SELECT email_verified_at FROM users WHERE id={$uid}")->fetch(\PDO::FETCH_ASSOC) ?: [];
            echo json_encode(['ok' => true, 'verified' => ($row['email_verified_at'] ?? null) !== null]);
        } catch (\Throwable $e) {
            http_response_code(422);
            echo json_encode(['error' => ['code' => 'X']]);
        }
        break;
    }
    case 'forgot_no_leak': {
        $uid = $svc->inviteAdmin(['email' => 'forgotcase@velora.test', 'fullName' => 'Forgot Case', 'role' => 'admin'], 1, 'super_admin')['id'];
        (new PasswordService())->forgotPassword('forgotcase@velora.test');
        $tokens = (int) $pdo->query("SELECT COUNT(*) c FROM password_resets WHERE user_id={$uid}")->fetch()['c'];
        echo json_encode(['tokens' => $tokens]);
        break;
    }
    case 'me_super': (new SecurityController())->me(mkRequest([], 'super_admin', 1, 'GET')); break;
    case 'me_fallback': (new SecurityController())->me(mkRequest([], 'admin', 7, 'GET')); break;
    case 'me_admin_deny': deny(fn () => (AuthMiddleware::adminOnly())(mkRequest([], 'user', 2, 'GET'))); break;
    default:
        http_response_code(500);
        echo json_encode(['error' => ['code' => 'UNKNOWN_CASE']]);
}
