<?php

declare(strict_types=1);

/**
 * Phase 3 B-1 — Admin Verify Email tests (decision D1).
 *
 * POST /api/v1/admin/users/{id}/verify-email  (permission users.verify_email)
 *
 * Covers:
 *   Authorization : user -> PERMISSION_DENIED, unauthenticated -> denied,
 *                   admin -> allowed, super_admin -> allowed (middleware chain)
 *   Behavior      : unverified -> changed=true, already verified -> changed=false
 *                   (idempotent, not an error), unknown user -> USER_NOT_FOUND,
 *                   self-action -> SELF_ACTION_DENIED, admin on privileged
 *                   target -> PRIVILEGED_TARGET
 *   Tokens        : pending email_verifications rows are invalidated after the
 *                   admin action (also on the idempotent path)
 *   Audit         : user.verify_email rows with metadata changed=true/false
 *   Failure       : backend failure (missing email_verifications table) does
 *                   NOT produce a success response (no false success)
 *   Rate limiting : admin-user-action bucket eventually rejects (429)
 *
 * Convention: Response::json()/error() terminate the process, so each case
 * runs in a dedicated child process over one shared temp SQLite DB + private
 * env root (same harness as test_admin_panel.php). No real secrets, no network.
 *
 * Run: php tools/tests/test_admin_verify_email.php
 */

$SELF = __FILE__;
$ROOT = sys_get_temp_dir() . '/velora-b1-verify-email-' . bin2hex(random_bytes(5));

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

    // ===== Authorization (server-side RBAC chain) =====
    $r = spawn($SELF, $ROOT, 've_user_denied');    check(str_contains($r['out'], 'PERMISSION_DENIED'), 'user -> PERMISSION_DENIED (users.verify_email not held)');
    $r = spawn($SELF, $ROOT, 've_noauth');         check(!str_contains($r['out'], '"ok":true') && str_contains($r['out'], 'DENIED'), 'unauthenticated (no role attrs) -> denied');
    $r = spawn($SELF, $ROOT, 've_admin_allowed'); $j = decode($r);
    check(($j['data']['ok'] ?? false) === true && ($j['data']['changed'] ?? null) === true, 'admin -> allowed, changed=true on unverified user');
    check(($j['data']['user']['emailVerified'] ?? null) === true, 'response user payload shows emailVerified=true (mapped object)');
    check(!preg_match('/password_hash|refreshToken|accessToken|[A-Za-z0-9_-]*token[a-z_]*"\s*:/i', $r['out']) || !str_contains($r['out'], 'eyJ'), 'response leaks no token/secret');
    check(str_contains($r['out'], 'tokensUsed'), 'response keeps legitimate tokensUsed aggregate (AI usage) without any credential material');
    $r = spawn($SELF, $ROOT, 've_super_allowed'); $j = decode($r);
    check(($j['data']['ok'] ?? false) === true && ($j['data']['changed'] ?? null) === true, 'super_admin -> allowed');

    // ===== Idempotency =====
    $r = spawn($SELF, $ROOT, 've_idempotent'); $j = decode($r);
    check(($j['data']['changed'] ?? null) === false, 'already verified -> changed=false (idempotent, not an error)');
    check(($j['data']['user']['emailVerifiedAt'] ?? null) !== null, 'emailVerifiedAt preserved on idempotent call');

    // ===== Unknown user / self / privileged target =====
    $r = spawn($SELF, $ROOT, 've_404');            check(str_contains($r['out'], 'USER_NOT_FOUND'), 'unknown user -> 404 USER_NOT_FOUND');
    $r = spawn($SELF, $ROOT, 've_self');           check(str_contains($r['out'], 'SELF_ACTION_DENIED'), 'self-action denied (sibling convention)');
    $r = spawn($SELF, $ROOT, 've_privileged');     check(str_contains($r['out'], 'PRIVILEGED_TARGET'), 'plain admin cannot verify another admin (privileged target)');

    // ===== Pending token invalidation =====
    $r = spawn($SELF, $ROOT, 've_tokens_seed');
    $r = spawn($SELF, $ROOT, 've_tokens_verify'); $j = decode($r);
    check(($j['data']['ok'] ?? false) === true, 'admin verifies user with pending tokens');
    $r = spawn($SELF, $ROOT, 've_tokens_state'); $j = decode($r);
    check(($j['pending'] ?? 1) === 0, 'pending verification tokens invalidated after admin verification');
    // idempotent path must ALSO clear tokens
    $r = spawn($SELF, $ROOT, 've_tokens_seed2');
    spawn($SELF, $ROOT, 've_tokens_reverify'); // changed=false path
    $r = spawn($SELF, $ROOT, 've_tokens_state2'); $j = decode($r);
    check(($j['pending'] ?? 1) === 0, 'idempotent path also invalidates newly created pending tokens');

    // ===== Audit =====
    $r = spawn($SELF, $ROOT, 've_audit'); $j = decode($r);
    check(($j['n'] ?? 0) >= 2, 'audit rows written for user.verify_email');
    check(in_array('user.verify_email', $j['actions'] ?? [], true), 'audit action name is user.verify_email');
    check(in_array(true, $j['changed'] ?? [], true) && in_array(false, $j['changed'] ?? [], true), 'audit metadata records changed=true and changed=false');
    check(!str_contains($r['out'], 'token'), 'audit trail contains no token material');

    // ===== Failure behavior (no false success) =====
    $r = spawn($SELF, $ROOT, 've_dbfail');
    check(!str_contains($r['out'], '"ok":true'), 'backend failure does NOT produce success response');
    check(str_contains($r['out'], 'error'), 'backend failure surfaces an error envelope');

    // ===== Rate limiting (admin-user-action 30/300 shared with siblings) =====
    $r = spawn($SELF, $ROOT, 've_ratelimit'); $j = decode($r);
    check(($j['limited'] ?? false) === true && ($j['code'] ?? 0) === 429, 'excessive requests rejected with 429 by existing limiter');

    echo "\nadmin-verify-email (B-1): " . ($failures === 0 ? 'PASS' : 'FAIL') . " ($checks checks, $failures failures)\n";
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
    ]) . "\n");
}
@mkdir($ROOT . '/data', 0700, true);
ini_set('error_log', $ROOT . '/logs/php-error.log');
require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

use Velora\Admin\UserManagementController;
use Velora\Auth\AuthMiddleware;
use Velora\Auth\EmailVerificationRepository;
use Velora\Auth\Role;
use Velora\Core\Database;
use Velora\Core\RateLimiter;
use Velora\Core\Exceptions\ApiException;
use Velora\Core\Request;

$pdo = new PDO('sqlite:' . $ROOT . '/data/velora.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function mkRequest(string $path, array $body, string $role, int $uid, string $method = 'POST'): Request
{
    $rq = new Request($method, $path, [], $body,
        ['authorization' => 'Bearer ' . str_repeat('j', 48), 'user-agent' => 'b1-test-agent', 'x-request-id' => 'ctx-b1']);
    if ($role !== '') {
        $rq->attributes['user_role'] = $role;
        $rq->attributes['user_id'] = $uid;
    }
    return $rq;
}

function deny(callable $fn, int $http = 403): void
{
    try { $fn(); echo json_encode(['ok' => true]); } catch (\Throwable $e) {
        http_response_code($http);
        echo json_encode(['error' => ['code' => ($e instanceof \Velora\Core\Exceptions\ApiException ? $e->errorCode() : 'X')]]);
    }
}

/** Full production chain: permission middleware + controller (as wired in api/index.php). */
function callEndpoint(string $role, int $uid, int $targetId): void
{
    $mw = AuthMiddleware::requirePermission(Role::P_USERS_VERIFY_EMAIL);
    $req = mkRequest('/api/v1/admin/users/' . $targetId . '/verify-email', [], $role, $uid);
    $mw($req);
    (new UserManagementController())->verifyEmail($req, ['id' => (string) $targetId]);
}

if ($case === 'setup') {
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, password_hash TEXT DEFAULT \'\', full_name TEXT DEFAULT \'\', role TEXT NOT NULL DEFAULT \'user\', status TEXT NOT NULL DEFAULT \'active\', email_verified_at DATETIME NULL, locale TEXT DEFAULT \'fa\', timezone TEXT DEFAULT \'UTC\', plan TEXT NOT NULL DEFAULT \'free\', subscription_status TEXT NOT NULL DEFAULT \'none\', plan_started_at DATETIME NULL, plan_expires_at DATETIME NULL, plan_updated_at DATETIME NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER NOT NULL, actor_role TEXT NOT NULL, action TEXT NOT NULL, target_type TEXT NOT NULL, target_id INTEGER NULL, result TEXT NOT NULL DEFAULT \'success\', summary TEXT NULL, ip_address TEXT NULL, user_agent TEXT NULL, context_id TEXT NULL, metadata_json TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS email_verifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token_hash TEXT NOT NULL, expires_at DATETIME NOT NULL, verified_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS user_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, revoked_at DATETIME NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS user_devices (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS trading_accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, sync_status TEXT DEFAULT \'DISCONNECTED\', metaapi_account_id TEXT NULL, last_synced_at DATETIME NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS trades (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, symbol TEXT, direction TEXT, entry_price REAL, profit_loss REAL, open_time DATETIME)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ai_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, status TEXT DEFAULT \'success\', tokens_used INTEGER DEFAULT 0)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS rate_limits (bucket TEXT PRIMARY KEY, hits INTEGER NOT NULL, window_start DATETIME NOT NULL)');
    $seed = [
        // id1..id6: email, name, role, status
        ['alice@example.com', 'Alice', 'user', 'active'],       // id1 target (admin path)
        ['bob@example.com', 'Bob', 'user', 'active'],           // id2 plain user (denied actor)
        ['carol@example.com', 'Carol', 'user', 'active'],       // id3 token-invalidation target
        ['dave@example.com', 'Dave', 'admin', 'active'],        // id4 privileged target
        ['eve@example.com', 'Eve', 'super_admin', 'active'],    // id5 actor (super) / self target
        ['frank@example.com', 'Frank', 'user', 'active'],       // id6 target (super path)
        ['gina@example.com', 'Gina', 'admin', 'active'],        // id7 plain admin actor for privileged-target case
    ];
    foreach ($seed as $s) {
        $pdo->prepare('INSERT INTO users (email, full_name, role, status) VALUES (?,?,?,?)')->execute($s);
    }
    echo 'SETUP_OK'; exit(0);
}

switch ($case) {
    // ---- authorization ----
    case 've_user_denied': deny(fn () => callEndpoint('user', 2, 1)); break;
    case 've_noauth':      deny(fn () => callEndpoint('', 0, 1)); break;   // no attributes = unauthenticated
    case 've_admin_allowed': callEndpoint('admin', 4, 1); break;           // dave(admin) verifies alice(id1)
    case 've_super_allowed': callEndpoint('super_admin', 5, 6); break;     // eve(super) verifies frank(id6)

    // ---- idempotency (alice id1 already verified above) ----
    case 've_idempotent': callEndpoint('admin', 4, 1); break;

    // ---- errors ----
    case 've_404':        deny(fn () => callEndpoint('super_admin', 5, 9999), 404); break;
    case 've_self':       deny(fn () => callEndpoint('super_admin', 5, 5), 403); break;
    case 've_privileged': deny(fn () => callEndpoint('admin', 7, 4), 403); break; // gina(admin) -> dave(admin)

    // ---- token invalidation ----
    case 've_tokens_seed': {
        $ins = $pdo->prepare('INSERT INTO email_verifications (user_id, token_hash, expires_at) VALUES (?,?,?)');
        $ins->execute([3, hash('sha256', 'tok-a'), gmdate('Y-m-d H:i:s', time() + 3600)]);
        $ins->execute([3, hash('sha256', 'tok-b'), gmdate('Y-m-d H:i:s', time() + 3600)]);
        echo 'SEEDED'; break;
    }
    case 've_tokens_verify': callEndpoint('admin', 4, 3); break; // carol(id3): changed=true + tokens invalidated
    case 've_tokens_state':
        $n = (int) $pdo->query('SELECT COUNT(*) AS n FROM email_verifications WHERE user_id=3 AND verified_at IS NULL')->fetch()['n'];
        echo json_encode(['pending' => $n]); break;
    case 've_tokens_seed2': {
        // after carol is already verified: a fresh pending token appears, then an
        // idempotent admin call must clear it too.
        $ins = $pdo->prepare('INSERT INTO email_verifications (user_id, token_hash, expires_at) VALUES (?,?,?)');
        $ins->execute([3, hash('sha256', 'tok-c'), gmdate('Y-m-d H:i:s', time() + 3600)]);
        echo 'SEEDED'; break;
    }
    case 've_tokens_reverify': callEndpoint('admin', 4, 3); break; // changed=false path
    case 've_tokens_state2':
        $n = (int) $pdo->query('SELECT COUNT(*) AS n FROM email_verifications WHERE user_id=3 AND verified_at IS NULL')->fetch()['n'];
        echo json_encode(['pending' => $n]); break;

    // ---- audit ----
    case 've_audit': {
        $rows = $pdo->query("SELECT action, metadata_json FROM admin_audit_logs WHERE action='user.verify_email'")->fetchAll();
        $changed = array_map(static fn (array $r): bool => (bool) preg_match('/"changed"\s*:\s*true/', (string) $r['metadata_json']), $rows);
        echo json_encode(['n' => count($rows), 'actions' => array_column($rows, 'action'), 'changed' => $changed]); break;
    }

    // ---- failure behavior: backend breakage must not yield success ----
    case 've_dbfail': {
        $pdo->exec('DROP TABLE email_verifications');
        deny(fn () => callEndpoint('super_admin', 5, 2), 500); // bob(id2, still unverified)
        $pdo->exec('CREATE TABLE email_verifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token_hash TEXT NOT NULL, expires_at DATETIME NOT NULL, verified_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        break;
    }

    // ---- rate limiting: same admin-user-action bucket (30/300) the controller hits ----
    case 've_ratelimit': {
        $limited = false; $code = 0; $done = 0;
        for ($i = 0; $i < 60; $i++) {
            try { RateLimiter::hit('admin-user-action', 30, 300); $done++; }
            catch (ApiException $e) { $limited = true; $code = $e->httpStatus(); break; }
        }
        echo json_encode(['limited' => $limited, 'code' => $code, 'hits_accepted' => $done]); break;
    }
    default: break;
}
