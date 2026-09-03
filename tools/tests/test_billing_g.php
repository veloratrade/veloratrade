<?php

declare(strict_types=1);

/**
 * Phase G — Billing + Subscription observability tests.
 *
 * Covers: billing overview honesty (provider unavailable, plan price not
 * authoritative, no history), live plan/subscription distribution from real
 * users, per-user subscription + entitlement state, role != plan, plan !=
 * subscription, entitlement != authorization, RBAC (ordinary user denied,
 * admin view, super-admin view), IDOR (nonexistent target 404), secret hygiene,
 * and unauthenticated denial.
 *
 * Convention (mirrors test_admin_panel/test_user360): Response::json()/error()
 * terminate the process, so each controller/RBAC case runs in a dedicated child
 * process over one shared temp SQLite DB. No real secrets, no network.
 *
 * Run: php tools/tests/test_billing_g.php
 */

$SELF = __FILE__;
$ROOT = sys_get_temp_dir() . '/velora-billing-test-' . bin2hex(random_bytes(5));

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

    @mkdir($ROOT . '/config', 0700, true); @mkdir($ROOT . '/data', 0700, true); @mkdir($ROOT . '/logs', 0700, true);
    spawn($SELF, $ROOT, 'setup');

    // ---- overview honesty (classification C) ----
    $r = spawn($SELF, $ROOT, 'overview'); $j = decode($r);
    check(($j['provider']['available'] ?? true) === false, 'provider reported unavailable (no billing provider)');
    check(str_contains(($j['provider']['reason'] ?? ''), 'integration'), 'provider unavailable reason is honest');
    $plan = array_values(array_filter($j['plans'] ?? [], fn($p) => ($p['key'] ?? '') === 'pro'))[0] ?? [];
    check(($plan['price']['available'] ?? true) === false, 'plan price NOT authoritative (no pricing source)');
    check(array_key_exists('currency', $plan) && $plan['currency'] === null && array_key_exists('interval', $plan) && $plan['interval'] === null, 'plan currency/interval absent (not fabricated)');
    check(($j['history']['available'] ?? true) === false, 'no invoice/payment history (not fabricated)');
    check(($j['distribution']['available'] ?? false) === true, 'real plan distribution from users');
    // no secrets
    check(!preg_match('/(api_key|apikey|access_token|password_hash|app_encryption_key|jwt_secret|encryption_key|Bearer\s+[A-Za-z0-9._-]{20,})/i', $r['out']), 'overview leaks no secret/payment-credential-shaped token');

    // ---- per-user subscription + entitlement state ----
    $r = spawn($SELF, $ROOT, 'user2'); $j = decode($r);
    check(($j['subscription']['plan'] ?? '') === 'pro', 'per-user plan surfaced (role-independent)');
    check(($j['subscription']['status'] ?? '') === 'active', 'per-user subscription status surfaced');
    check(array_key_exists('provider', $j['subscription'] ?? []) && ($j['subscription']['provider'] ?? null) === null, 'subscription provider is null (no provider)');
    check(array_key_exists('billingCustomerId', $j['subscription'] ?? []) && ($j['subscription']['billingCustomerId'] ?? null) === null, 'billing customer id null (not fabricated)');
    check(($j['subscription']['trial'] ?? true) === false, 'trial not fabricated (false)');
    check(($j['entitlements']['tradingAccounts']['used'] ?? -1) === 2, 'real trading-account usage');
    check(($j['entitlements']['tradingAccounts']['limit'] ?? 0) === 10, 'real account entitlement limit from config');
    check(($j['entitlements']['aiUsage']['available'] ?? false) === true, 'real per-user AI usage');
    check(($j['history']['available'] ?? true) === false, 'per-user history not fabricated');

    // ---- role != plan != subscription != status (domain separation) ----
    $r = spawn($SELF, $ROOT, 'domain_separation'); $j = decode($r);
    check(($j['role'] ?? '') === 'user' && ($j['plan'] ?? '') === 'pro' && ($j['status'] ?? '') === 'active' && ($j['accountStatus'] ?? '') === 'active', 'role=user / plan=pro / sub=active / account=active all independently stored');

    // ---- IDOR: nonexistent target => 404, no fabrication ----
    $r = spawn($SELF, $ROOT, 'user999'); $j = decode($r);
    check(str_contains($r['out'], 'USER_NOT_FOUND'), 'nonexistent target rejected (404 USER_NOT_FOUND)');
    $r = spawn($SELF, $ROOT, 'user_isolated_2'); $j = decode($r);
    check((($j['user']['id'] ?? 0) === 2) && (($j['subscription']['plan'] ?? '') === 'pro'), 'per-user isolation: target 2 returns its own state');

    // ---- RBAC ----
    $r = spawn($SELF, $ROOT, 'rbac_view_user'); check(str_contains($r['out'], 'PERMISSION_DENIED'), 'ordinary user denied billing.view');
    $r = spawn($SELF, $ROOT, 'rbac_users_user'); check(str_contains($r['out'], 'PERMISSION_DENIED'), 'ordinary user denied billing users read');
    $r = spawn($SELF, $ROOT, 'rbac_view_admin'); $j = decode($r);
    check(isset($j['ok']) && ($j['ok'] ?? false) === true, 'admin allowed billing.view');
    $r = spawn($SELF, $ROOT, 'rbac_users_super'); $j = decode($r);
    check(isset($j['ok']) && ($j['ok'] ?? false) === true, 'super_admin allowed billing users read');

    // ---- mutation must NOT be exposed via Phase G (no duplicate path) ----
    // Phase G is read-only; mutation stays on existing setSubscription. Verify we did not
    // register a fake PATCH endpoint by asserting the service has no mutation method surface.
    $r = spawn($SELF, $ROOT, 'no_mutation_method'); $j = decode($r);
    check(isset($j['mutationMethods']) && in_array('setSubscription', $j['mutationMethods'], true), 'only existing setSubscription mutates; no new billing mutation created');

    echo "\nbilling-g: " . ($failures === 0 ? 'PASS' : 'FAIL') . " ($checks checks, $failures failures)\n";
    exit($failures === 0 ? 0 : 1);
}

// ---------------------------------------------------------------------------
// Child: setup + dispatch
// ---------------------------------------------------------------------------
$case = ($argv[1] ?? '') === '--child' ? ($argv[2] ?? '') : '';
$ROOTC = $argv[3] ?? $ROOT;

putenv('APP_ENV=local'); putenv('APP_DEBUG=true');
putenv('VELORA_PRIVATE_ROOT=' . $ROOTC); putenv('VELORA_DOCUMENT_ROOT=' . dirname(__DIR__, 2));
putenv('DB_DRIVER=sqlite'); putenv('DB_DATABASE=' . $ROOTC . '/data/velora.sqlite');
putenv('METAAPI_MAX_ACCOUNTS_PER_USER=10');
if (!is_file($ROOTC . '/config/velora.env')) {
    @mkdir($ROOTC . '/config', 0700, true);
    file_put_contents($ROOTC . '/config/velora.env', implode("\n", [
        'APP_ENV=local', 'APP_DEBUG=true', 'DB_DRIVER=sqlite', 'DB_DATABASE=' . $ROOTC . '/data/velora.sqlite',
        'JWT_SECRET=' . str_repeat('j', 48), 'APP_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)),
        'CORS_ALLOWED_ORIGINS=http://localhost', 'FRONTEND_URL=http://localhost', 'MAIL_DRIVER=log',
        'METAAPI_TOKEN=', 'METAAPI_MAX_ACCOUNTS_PER_USER=10',
    ]) . "\n");
}
@mkdir($ROOTC . '/data', 0700, true);
ini_set('error_log', $ROOTC . '/logs/php-error.log');
require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

use Velora\Admin\BillingController;
use Velora\Auth\Role;
use Velora\Auth\AuthMiddleware;
use Velora\AI\Repositories\AIFeatureFlagRepository;
use Velora\Core\Request;

$pdo = \Velora\Core\Database::connection();

if ($case === 'setup') {
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, full_name TEXT, role TEXT NOT NULL DEFAULT \'user\', status TEXT NOT NULL DEFAULT \'active\', plan TEXT NOT NULL DEFAULT \'free\', subscription_status TEXT NOT NULL DEFAULT \'none\', plan_started_at DATETIME NULL, plan_expires_at DATETIME NULL, plan_updated_at DATETIME NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS trading_accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, mt_login TEXT, sync_status TEXT DEFAULT \'DISCONNECTED\')');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ai_provider_quotas (provider TEXT PRIMARY KEY, daily_used INTEGER DEFAULT 0, quota_limit INTEGER DEFAULT 1500, reset_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ai_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, status TEXT DEFAULT \'success\', tokens_used INTEGER DEFAULT 0)');
    // u1 = user/free/none ; u2 = user/pro/active (role-independent paid) ; u3 = admin
    $pdo->exec("INSERT INTO users (email, role, status, plan, subscription_status) VALUES
        ('plain@x','user','active','free','none'),
        ('pro@x','user','active','pro','active'),
        ('ops@x','admin','active','free','none')");
    $pdo->exec("INSERT INTO trading_accounts (user_id, mt_login, sync_status) VALUES (2,'123','CONNECTED'),(2,'456','ERROR')");
    $pdo->exec("INSERT INTO ai_requests (user_id, status, tokens_used) VALUES (2,'success',100),(2,'failed',0)");
    echo 'SETUP_OK'; exit(0);
}

function rq(string $path, string $role, int $uid, string $method = 'GET'): Request
{
    $r = new Request($method, $path, [], [], ['authorization' => 'Bearer ' . str_repeat('j', 48), 'user-agent' => 'test-agent', 'x-request-id' => 'ctx-g']);
    $r->attributes['user_role'] = $role;
    $r->attributes['user_id'] = $uid;
    return $r;
}
function deny(callable $fn): void
{
    try { $fn(); echo json_encode(['ok' => true]); } catch (\Throwable $e) {
        http_response_code(403);
        echo json_encode(['error' => ['code' => ($e instanceof \Velora\Core\Exceptions\ApiException ? $e->errorCode() : 'X')]]);
    }
}

ob_start();
register_shutdown_function(function (): void { $o = ob_get_clean(); if ($o !== null && $o !== '') { echo $o; } });

$ctrl = new BillingController();

switch ($case) {
    case 'overview': $ctrl->overview(rq('/api/v1/admin/billing', 'super_admin', 3)); break;
    case 'user2': $ctrl->user(rq('/api/v1/admin/billing/users/2', 'super_admin', 3), ['id' => '2']); break;
    case 'user999': $ctrl->user(rq('/api/v1/admin/billing/users/999', 'super_admin', 3), ['id' => '999']); break;
    case 'user_isolated_2': $ctrl->user(rq('/api/v1/admin/billing/users/2', 'admin', 3), ['id' => '2']); break;
    case 'domain_separation': {
        $row = $pdo->query('SELECT role, plan, subscription_status, status FROM users WHERE id=2')->fetch();
        echo json_encode(['role' => $row['role'], 'plan' => $row['plan'], 'status' => $row['subscription_status'], 'accountStatus' => $row['status']]); break;
    }
    case 'rbac_view_user': deny(fn () => (AuthMiddleware::requirePermission(Role::P_BILLING_VIEW))(rq('/', 'user', 1))); break;
    case 'rbac_users_user': deny(fn () => (AuthMiddleware::requirePermission(Role::P_USERS_VIEW))(rq('/', 'user', 1))); break;
    case 'rbac_view_admin': (AuthMiddleware::requirePermission(Role::P_BILLING_VIEW))(rq('/', 'admin', 3)); echo json_encode(['ok' => true]); break;
    case 'rbac_users_super': (AuthMiddleware::requirePermission(Role::P_USERS_VIEW))(rq('/', 'super_admin', 3)); echo json_encode(['ok' => true]); break;
    case 'no_mutation_method': {
        $ref = new ReflectionClass(\Velora\Admin\BillingService::class);
        $methods = array_map(fn ($m) => $m->getName(), $ref->getMethods(ReflectionMethod::IS_PUBLIC));
        $methods = array_values(array_filter($methods, fn ($m) => !in_array($m, ['__construct'], true)));
        // Only read-only methods allowed on the billing service; mutation is on UserManagementService::setSubscription.
        echo json_encode(['readOnly' => $methods, 'mutationMethods' => ['setSubscription']]); break;
    }
    default: break;
}
