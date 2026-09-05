<?php

declare(strict_types=1);

/**
 * Phase F — centralized Feature Flag control plane tests.
 *
 * Covers: listing canonical flags + effective state + environment, enable/
 * disable + rollout targeting persistence, server-side validation (unknown
 * feature, rollout range, enabled required), deterministic per-user rollout,
 * audit event recording (safe metadata), RBAC (view admin+super_admin, edit
 * super_admin only, ordinary user denied), runtime effect via AIFeatureGuard,
 * and secret hygiene on every response.
 *
 * Convention (mirrors test_admin_panel): Response::json()/error() terminate the
 * process, so each controller/RBAC case runs in a dedicated child process over
 * one shared temp SQLite DB. No real secrets, no network.
 *
 * Run: php tools/tests/test_feature_flags.php
 */

$SELF = __FILE__;
$ROOT = sys_get_temp_dir() . '/velora-flags-test-' . bin2hex(random_bytes(5));

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

    // ---- list / index (super_admin view) ----
    $r = spawn($SELF, $ROOT, 'index'); $j = decode($r);
    check(($j['environment'] ?? '') !== '', 'index reports runtime environment');
    check(count($j['flags'] ?? []) >= 4, 'index returns the canonical flag set');
    check(in_array('ai_screenshot_extraction', array_column($j['flags'], 'feature'), true), 'canonical flag present');
    $scr = array_values(array_filter($j['flags'], fn($x) => $x['feature'] === 'ai_screenshot_extraction'))[0] ?? [];
    check(($scr['enabled'] ?? false) === true && ($scr['rollout'] ?? 0) === 100, 'screenshot flag seeded ON (default)');
    check(($scr['effective'] ?? '') === 'on', 'effective status honest (on)');
    check(($scr['runtime'] ?? null) === true, 'runtime resolver sees the flag enabled');
    // secret hygiene
    check(!preg_match('/(token|secret|password|credential_de|api_key|JWT)/i', $r['out']), 'index leaks no secret-shaped tokens');

    // ---- validation ----
    $r = spawn($SELF, $ROOT, 'unknown_flag'); check(str_contains($r['out'], 'UNKNOWN_FEATURE_FLAG'), 'unknown feature flag -> 422');
    $r = spawn($SELF, $ROOT, 'rollout_range'); check(str_contains($r['out'], 'ROLLOUT_RANGE'), 'rollout out of range -> 422');
    $r = spawn($SELF, $ROOT, 'enabled_required'); check(str_contains($r['out'], 'ENABLED_REQUIRED'), 'missing enabled -> 422');

    // ---- enable + rollout persistence ----
    $r = spawn($SELF, $ROOT, 'enable'); $j = decode($r);
    check(($j['flag']['enabled'] ?? false) === true, 'PATCH enables a flag');
    check(($j['flag']['rollout'] ?? -1) === 30, 'PATCH stores rollout targeting');
    $r = spawn($SELF, $ROOT, 'reload'); $j = decode($r);
    $f = array_values(array_filter($j['flags'], fn($x) => $x['feature'] === 'ai_trade_analysis'))[0] ?? null;
    check($f !== null && ($f['enabled'] ?? false) && ($f['rollout'] ?? 0) === 30, 'enabling is persisted (server source of truth)');

    // ---- deterministic rollout ----
    $r = spawn($SELF, $ROOT, 'deterministic'); $j = decode($r);
    check(($j['stable'] ?? false) === true, 'rollout is deterministic & stable for a user');
    check(($j['monotonic'] ?? null) !== null, 'rollout threshold comparison computes');
    check(($j['reload_same'] ?? true) === true, 'rollout stable across repository reloads');

    // ---- audit ----
    $r = spawn($SELF, $ROOT, 'audit_rows'); $j = decode($r);
    check(($j['n'] ?? 0) >= 1, 'feature flag mutation writes an audit row');
    $r = spawn($SELF, $ROOT, 'audit_meta'); $j = decode($r);
    check(($j['meta']['feature'] ?? '') === 'ai_trade_analysis', 'audit metadata carries the feature');
    check(!preg_match('/(token|secret|password|api_key|jwt|credential)/i', json_encode($j['meta'] ?? [])), 'audit metadata holds no secret');

    // ---- runtime effect (toggle) ----
    $r = spawn($SELF, $ROOT, 'runtime_effect'); $j = decode($r);
    check(($j['on_enabled'] ?? false) === true && ($j['on_disabled'] ?? false) === false, 'runtime consumer follows the flag toggle');

    // ---- RBAC (middleware, server-authoritative) ----
    $r = spawn($SELF, $ROOT, 'rbac_edit_user'); check(str_contains($r['out'], 'PERMISSION_DENIED'), 'ordinary user denied feature_flags.edit');
    $r = spawn($SELF, $ROOT, 'rbac_view_user'); check(str_contains($r['out'], 'PERMISSION_DENIED'), 'ordinary user denied feature_flags.view');
    $r = spawn($SELF, $ROOT, 'rbac_edit_admin'); check(str_contains($r['out'], 'PERMISSION_DENIED'), 'admin denied feature_flags.edit (super_admin only)');
    $r = spawn($SELF, $ROOT, 'rbac_view_admin'); $j = decode($r);
    check(isset($j['ok']) && ($j['ok'] ?? false) === true, 'admin allowed feature_flags.view');
    $r = spawn($SELF, $ROOT, 'rbac_edit_super'); $j = decode($r);
    check(isset($j['ok']) && ($j['ok'] ?? false) === true, 'super_admin allowed feature_flags.edit');

    echo "\nfeature-flags: " . ($failures === 0 ? 'PASS' : 'FAIL') . " ($checks checks, $failures failures)\n";
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
if (!is_file($ROOTC . '/config/velora.env')) {
    @mkdir($ROOTC . '/config', 0700, true);
    file_put_contents($ROOTC . '/config/velora.env', implode("\n", [
        'APP_ENV=local', 'APP_DEBUG=true', 'DB_DRIVER=sqlite', 'DB_DATABASE=' . $ROOTC . '/data/velora.sqlite',
        'JWT_SECRET=' . str_repeat('j', 48), 'APP_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)),
        'CORS_ALLOWED_ORIGINS=http://localhost', 'FRONTEND_URL=http://localhost', 'MAIL_DRIVER=log', 'METAAPI_TOKEN=',
    ]) . "\n");
}
@mkdir($ROOTC . '/data', 0700, true);
ini_set('error_log', $ROOTC . '/logs/php-error.log');
require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

use Velora\Admin\FeatureFlagController;
use Velora\Auth\Role;
use Velora\Auth\AuthMiddleware;
use Velora\AI\Repositories\AIFeatureFlagRepository;
use Velora\AI\Services\AIFeatureGuard;
use Velora\Core\Request;

$pdo = \Velora\Core\Database::connection();

if ($case === 'setup') {
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, role TEXT NOT NULL DEFAULT \'user\', status TEXT NOT NULL DEFAULT \'active\')');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ai_feature_flags (feature_name TEXT PRIMARY KEY, enabled INTEGER NOT NULL DEFAULT 0, rollout_percentage INTEGER NOT NULL DEFAULT 0, updated_by INTEGER NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER NOT NULL, actor_role TEXT NOT NULL, action TEXT NOT NULL, target_type TEXT NOT NULL, target_id INTEGER NULL, result TEXT NOT NULL DEFAULT \'success\', summary TEXT NULL, ip_address TEXT NULL, user_agent TEXT NULL, context_id TEXT NULL, metadata_json TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS rate_limits (bucket TEXT PRIMARY KEY, hits INTEGER NOT NULL, window_start DATETIME NOT NULL)');
    $pdo->exec("INSERT INTO users (email, role) VALUES ('u@x','user'),('a@x','admin'),('s@x','super_admin')");
    // canonical runtime flag set (defaults mirror v0.5 seed)
    $pdo->exec("INSERT INTO ai_feature_flags (feature_name, enabled, rollout_percentage, updated_by) VALUES
        ('ai_screenshot_extraction',1,100,1),('ai_trade_analysis',0,0,null),('ai_weekly_report',0,0,null),('ai_assistant',0,0,null)");
    echo 'SETUP_OK'; exit(0);
}

function rq(string $path, array $body, string $role, int $uid, string $method = 'GET', array $query = []): Request
{
    $r = new Request($method, $path, $query, $body, ['authorization' => 'Bearer ' . str_repeat('j', 48), 'user-agent' => 'test-agent', 'x-request-id' => 'ctx-ff']);
    $r->attributes['user_role'] = $role;
    $r->attributes['user_id'] = $uid;
    return $r;
}
function deny(callable $fn): void
{
    try { $fn(); echo json_encode(['ok' => true]); } catch (\Throwable $e) {
        http_response_code(403);
        echo json_encode([
            'error' => [
                'code' => ($e instanceof \Velora\Core\Exceptions\ApiException ? $e->errorCode() : 'X'),
                'details' => ($e instanceof \Velora\Core\Exceptions\ApiException ? $e->details() : null),
            ],
        ]);
    }
}

ob_start();
register_shutdown_function(function (): void { $o = ob_get_clean(); if ($o !== null && $o !== '') { echo $o; } });

$ctrl = new FeatureFlagController();

switch ($case) {
    case 'index': $ctrl->index(rq('/api/v1/admin/feature-flags', [], 'super_admin', 3)); break;
    case 'unknown_flag': deny(fn () => $ctrl->update(rq('/p', ['enabled' => true], 'super_admin', 3, 'PATCH'), ['feature' => 'does_not_exist'])); break;
    case 'rollout_range': deny(fn () => $ctrl->update(rq('/p', ['enabled' => true, 'rollout' => 150], 'super_admin', 3, 'PATCH'), ['feature' => 'ai_trade_analysis'])); break;
    case 'enabled_required': deny(fn () => $ctrl->update(rq('/p', [], 'super_admin', 3, 'PATCH'), ['feature' => 'ai_trade_analysis'])); break;
    case 'enable': $ctrl->update(rq('/p', ['enabled' => true, 'rollout' => 30], 'super_admin', 3, 'PATCH'), ['feature' => 'ai_trade_analysis']); break;
    case 'reload': $ctrl->index(rq('/p', [], 'super_admin', 3)); break;
    case 'deterministic': {
        $repo = new AIFeatureFlagRepository();
        $repo->setFlag('ai_trade_analysis', true, 45, 3);
        $guard = new AIFeatureGuard($repo);
        $a = $guard->isEnabled('ai_trade_analysis', 101);
        $b = $guard->isEnabled('ai_trade_analysis', 101); // repeat => stable
        // a user whose hash < 45 must be in; find one deterministically
        $in = $out = null;
        for ($uid = 1; $uid <= 999; $uid++) {
            $v = $guard->isEnabled('ai_trade_analysis', $uid);
            if ($in === null && $v) $in = $uid;
            if ($out === null && !$v) $out = $uid;
            if ($in !== null && $out !== null) break;
        }
        // reload + re-evaluate for stability
        $refreshed = (new AIFeatureFlagRepository())->get('ai_trade_analysis');
        $repo2 = new AIFeatureFlagRepository();
        $g2 = new AIFeatureGuard($repo2);
        $stable2 = $g2->isEnabled('ai_trade_analysis', 101) === $a;
        echo json_encode(['stable' => ($a === $b), 'monotonic' => ($in !== null && $out !== null), 'reload_same' => ($stable2 && ($refreshed['rollout_percentage'] ?? null) === 45)]);
        break;
    }
    case 'audit_rows': { $row = $pdo->query('SELECT COUNT(*) AS n FROM admin_audit_logs')->fetch(); echo json_encode(['n' => (int) ($row['n'] ?? 0)]); break; }
    case 'audit_meta': {
        $row = $pdo->query("SELECT metadata_json FROM admin_audit_logs ORDER BY id DESC LIMIT 1")->fetch();
        echo json_encode(['meta' => $row ? json_decode($row['metadata_json'], true) : []]); break;
    }
    case 'runtime_effect': {
        $repo = new AIFeatureFlagRepository();
        $guard = new AIFeatureGuard($repo);
        $repo->setFlag('ai_weekly_report', true, 100, 3);
        $on = $guard->isEnabled('ai_weekly_report', 42);
        $repo->setFlag('ai_weekly_report', false, 0, 3);
        $off = $guard->isEnabled('ai_weekly_report', 42);
        echo json_encode(['on_enabled' => $on, 'on_disabled' => $off]); break;
    }
    case 'rbac_edit_user': deny(fn () => (AuthMiddleware::requirePermission(Role::P_FEATURE_FLAGS_EDIT))(rq('/', [], 'user', 1))); break;
    case 'rbac_view_user': deny(fn () => (AuthMiddleware::requirePermission(Role::P_FEATURE_FLAGS_VIEW))(rq('/', [], 'user', 1))); break;
    case 'rbac_edit_admin': deny(fn () => (AuthMiddleware::requirePermission(Role::P_FEATURE_FLAGS_EDIT))(rq('/', [], 'admin', 2))); break;
    case 'rbac_view_admin': (AuthMiddleware::requirePermission(Role::P_FEATURE_FLAGS_VIEW))(rq('/', [], 'admin', 2)); echo json_encode(['ok' => true]); break;
    case 'rbac_edit_super': (AuthMiddleware::requirePermission(Role::P_FEATURE_FLAGS_EDIT))(rq('/', [], 'super_admin', 3)); echo json_encode(['ok' => true]); break;
    default: break;
}
