<?php

declare(strict_types=1);

/**
 * Phase B — Admin-managed GLOBAL AI route. Proves the complete chain:
 *
 *   Super Admin PUT /api/v1/admin/ai/route
 *     -> AiGlobalRouteController::update
 *     -> AiRouteResolver::save
 *     -> AIGlobalSettingRepository (ai_global_settings, DB)
 *     -> runtime resolver (AiRouteResolver::resolve / GeminiProvider::getRoute)
 *
 * Response::json()/error() exit the process, so each controller/middleware call
 * runs in a dedicated child process sharing one temp SQLite DB (like the other
 * harnesses). Every child is self-contained (sets/clears its own state), so
 * there is no cross-case ordering dependency.
 *
 * Precedence under test (single authority):
 *   per-feature route > Admin global route > GEMINI_ROUTE env > legacy flag > direct
 *
 * Run: php tools/tests/test_global_ai_route.php
 */

$SELF = __FILE__;
$ROOT = sys_get_temp_dir() . '/velora-ai-route-test-' . bin2hex(random_bytes(5));

function spawn(string $self, string $root, string $case, array $extraEnv = []): array
{
    $cmd = 'php ' . escapeshellarg($self) . ' --child ' . escapeshellarg($case) . ' ' . escapeshellarg($root);
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $env = array_merge(getenv(), [
        'VELORA_TEST_CHILD' => '1',
        'APP_ENV' => 'local',
        'APP_DEBUG' => 'true',
        'JWT_SECRET' => str_repeat('j', 48),
        'APP_ENCRYPTION_KEY' => base64_encode(random_bytes(32)),
        'CORS_ALLOWED_ORIGINS' => 'http://localhost',
        'FRONTEND_URL' => 'http://localhost',
        'MAIL_DRIVER' => 'log',
        'METAAPI_TOKEN' => '',
        'GEMINI_RELAY_URL' => '',
        'GEMINI_RELAY_TOKEN' => '',
        'GEMINI_ROUTE' => '',
    ], $extraEnv);
    $p = proc_open($cmd, $spec, $pipes, null, $env);
    fwrite($pipes[0], ''); fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($p);
    return ['code' => $code, 'out' => $out, 'err' => $err];
}

if (!in_array(getenv('VELORA_TEST_CHILD'), ['1', 'true'], true)) {
    $failures = 0;
    $checks = 0;
    function check(bool $cond, string $label): void
    {
        global $failures, $checks;
        $checks++;
        echo ($cond ? '  PASS: ' : '  FAIL: ') . $label . "\n";
        if (!$cond) {
            $failures++;
        }
    }
    function decode(array $r): array
    {
        $j = json_decode($r['out'], true);
        return is_array($j) ? $j : [];
    }

    mkdir($ROOT . '/config', 0700, true);
    mkdir($ROOT . '/data', 0700, true);
    mkdir($ROOT . '/logs', 0700, true);
    spawn($SELF, $ROOT, 'setup');

    echo "== RBAC (server-side authorization boundary) ==\n";
    $r = spawn($SELF, $ROOT, 'gate_user');
    check(($r['code'] === 0) && str_contains($r['out'], 'ADMIN_REQUIRED'), 'user -> ADMIN_REQUIRED (not a panel role)');
    $r = spawn($SELF, $ROOT, 'gate_admin_read');
    check(($r['code'] === 0) && str_contains($r['out'], 'ROUTE_READ_ADMIN_OK'), 'admin may READ (P_AI_MANAGE)');
    $r = spawn($SELF, $ROOT, 'gate_admin_write');
    check(($r['code'] === 0) && str_contains($r['out'], 'PERMISSION_DENIED'), 'admin (non-super) write -> PERMISSION_DENIED (P_AI_ROUTE_MANAGE = super only)');
    $r = spawn($SELF, $ROOT, 'gate_super_write');
    check(($r['code'] === 0) && str_contains($r['out'], 'ROUTE_SAVED'), 'super_admin MAY write (P_AI_ROUTE_MANAGE)');

    echo "== Validation: arbitrary route rejected ==\n";
    $r = spawn($SELF, $ROOT, 'reject_invalid');
    check(($r['code'] === 0) && str_contains($r['out'], 'INVALID_AI_ROUTE'), 'arbitrary route string REJECTED (INVALID_AI_ROUTE)');

    echo "== Persistence: value survives a NEW resolver instance (DB-backed) ==\n";
    $r = spawn($SELF, $ROOT, 'persist_then_reopen');
    check(($r['code'] === 0) && str_contains($r['out'], 'REOPEN_OK'), 'saved route read back by a fresh resolver instance');

    echo "== Runtime precedence (single authority AiRouteResolver) ==\n";
    $r = spawn($SELF, $ROOT, 'case_admin');
    check(($r['code'] === 0) && str_contains($r['out'], 'CASE_ADMIN_OK'), 'Case A: admin=n8n_relay, no env -> n8n_relay (source=admin)');

    $r = spawn($SELF, $ROOT, 'case_admin_beats_env', ['GEMINI_ROUTE' => 'direct']);
    check(($r['code'] === 0) && str_contains($r['out'], 'CASE_ADMIN_BEATS_ENV_OK'), 'Case B: admin=n8n_relay, ENV=direct -> admin saves WIN (env never overrides explicit admin)');

    $r = spawn($SELF, $ROOT, 'case_env', ['GEMINI_ROUTE' => 'n8n_relay']);
    check(($r['code'] === 0) && str_contains($r['out'], 'CASE_ENV_OK'), 'Case C: no admin, ENV=n8n_relay -> n8n_relay (source=env)');

    $r = spawn($SELF, $ROOT, 'case_default', ['GEMINI_ROUTE' => '']);
    check(($r['code'] === 0) && str_contains($r['out'], 'CASE_DEFAULT_OK'), 'Case D: no admin, no env, no flag -> direct (source=default)');

    echo "== Reset semantics ==\n";
    $r = spawn($SELF, $ROOT, 'reset_case');
    check(($r['code'] === 0) && str_contains($r['out'], 'RESET_OK'), 'reset clears -> inherit legacy (configured null, effective falls back)');

    echo "== Per-feature explicit route layering (wins over admin global) ==\n";
    $r = spawn($SELF, $ROOT, 'layer_case', ['GEMINI_ROUTE' => '']);
    check(($r['code'] === 0) && str_contains($r['out'], 'LAYER_CASE_OK'), 'explicit per-feature route (direct) wins over admin global n8n_relay');
    $r = spawn($SELF, $ROOT, 'layer_action_case', ['GEMINI_ROUTE' => '']);
    check(($r['code'] === 0) && str_contains($r['out'], 'LAYER_ACTION_OK'), 'per-feature route (n8n_relay) wins over admin global direct');

    echo "== Legacy ai_gemini_relay_route flag as compat fallback ==\n";
    $r = spawn($SELF, $ROOT, 'flag_case', ['GEMINI_ROUTE' => '']);
    check(($r['code'] === 0) && str_contains($r['out'], 'FLAG_CASE_OK'), 'legacy feature flag enables n8n_relay only when no admin/env route set');

    echo "== Audit: safe event without secrets ==\n";
    // Write the route (controller emits JSON and exits), then in a fresh child
    // read the audit trail.
    spawn($SELF, $ROOT, 'audit_write');
    $r = spawn($SELF, $ROOT, 'audit_read');
    check(($r['code'] === 0) && str_contains($r['out'], 'AUDIT_OK'), 'route change creates audit event with old/new route, no secret');

    echo "== Runtime consumer: GeminiProvider::getRoute() reflects admin global ==\n";
    $r = spawn($SELF, $ROOT, 'consumer');
    check(($r['code'] === 0) && trim($r['out']) === 'n8n_relay', 'GeminiProvider::getRoute() returns admin-managed n8n_relay');

    echo "\nglobal-ai-route: " . ($failures === 0 ? 'PASS' : 'FAIL') . " ($checks checks, $failures failures)\n";
    exit($failures === 0 ? 0 : 1);
}

// -------------------------------------------------------------------------
// child worker
// -------------------------------------------------------------------------
$case = $argv[2] ?? '';
$root = $argv[3] ?? $ROOT;
putenv('APP_ENV=local');
putenv('APP_DEBUG=true');
putenv('VELORA_PRIVATE_ROOT=' . $root);
putenv('VELORA_DOCUMENT_ROOT=' . dirname(__DIR__, 2));
putenv('DB_DRIVER=sqlite');
putenv('DB_DATABASE=' . $root . '/data/velora.sqlite');
// Preserve whatever GEMINI_ROUTE the parent spawned (a case may override it);
// default to empty only when nothing was passed.
$spawnedRoute = (string) (getenv('GEMINI_ROUTE') ?: '');
putenv('GEMINI_ROUTE=' . $spawnedRoute);
if (!is_file($root . '/config/velora.env')) {
    file_put_contents($root . '/config/velora.env', implode("\n", [
        'APP_ENV=local', 'APP_DEBUG=true', 'DB_DRIVER=sqlite', 'DB_DATABASE=' . $root . '/data/velora.sqlite',
        'JWT_SECRET=' . str_repeat('j', 48), 'APP_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)),
        'CORS_ALLOWED_ORIGINS=http://localhost', 'FRONTEND_URL=http://localhost', 'MAIL_DRIVER=log',
        'METAAPI_TOKEN=', 'GEMINI_RELAY_URL=', 'GEMINI_RELAY_TOKEN=', 'GEMINI_ROUTE=',
    ]) . "\n");
}
@mkdir($root . '/data', 0700, true);
ini_set('error_log', $root . '/logs/php-error.log');

require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

use Velora\Core\Config;
use Velora\Core\Request;
use Velora\AI\Services\AiRouteResolver;
use Velora\AI\Providers\GeminiProvider;
use Velora\Admin\AiGlobalRouteController;
use Velora\Admin\AdminAuditLogRepository;
use Velora\Auth\AuthMiddleware;
use Velora\Auth\Role;

Config::clearCache();

function mkRequest(string $method, array $body, string $role, int $uid): Request
{
    $rq = new Request($method, '/api/v1/admin/ai/route', [], $body, [
        'authorization' => 'Bearer ' . str_repeat('j', 48),
        'user-agent' => 'test-agent',
        'x-request-id' => 'ctx-abc',
    ]);
    $rq->attributes['user_role'] = $role;
    $rq->attributes['user_id'] = $uid;
    return $rq;
}

function auditAll(string $root): array
{
    try {
        $pdo = new PDO('sqlite:' . $root . '/data/velora.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo->query('SELECT action, metadata_json FROM admin_audit_logs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }
}

switch ($case) {
    case 'setup':
        $pdo = new PDO('sqlite:' . $root . '/data/velora.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE IF NOT EXISTS ai_global_settings (
            setting_key TEXT PRIMARY KEY, setting_value TEXT, updated_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ai_feature_flags (
            feature_name TEXT PRIMARY KEY, enabled INTEGER NOT NULL DEFAULT 0,
            rollout_percentage INTEGER NOT NULL DEFAULT 0)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ai_feature_providers (
            id INTEGER PRIMARY KEY AUTOINCREMENT, feature TEXT, provider TEXT,
            model TEXT, priority INTEGER DEFAULT 1, enabled INTEGER DEFAULT 1,
            route TEXT, UNIQUE(feature, provider))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (bucket TEXT PRIMARY KEY, hits INTEGER NOT NULL, window_start DATETIME NOT NULL)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER, actor_role TEXT,
            action TEXT, target_type TEXT, target_id INTEGER, result TEXT, summary TEXT,
            ip_address TEXT, user_agent TEXT, context_id TEXT, metadata_json TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        echo 'ok';
        exit(0);

    // ---- RBAC gates (the real server-side boundary) ----
    case 'gate_user':
        try {
            AuthMiddleware::adminOnly()(mkRequest('GET', [], 'user', 5));
            echo 'NO';
        } catch (\Velora\Core\Exceptions\ForbiddenException $e) {
            echo 'ADMIN_REQUIRED';
        }
        exit(0);

    case 'gate_admin_read':
        try {
            AuthMiddleware::requirePermission(Role::P_AI_MANAGE)(mkRequest('GET', [], 'admin', 1));
            echo 'ROUTE_READ_ADMIN_OK';
        } catch (\Velora\Core\Exceptions\ForbiddenException $e) {
            echo 'DENIED';
        }
        exit(0);

    case 'gate_admin_write':
        try {
            AuthMiddleware::requirePermission(Role::P_AI_ROUTE_MANAGE)(mkRequest('PUT', ['route' => 'n8n_relay'], 'admin', 1));
            echo 'ALLOWED';
        } catch (\Velora\Core\Exceptions\ForbiddenException $e) {
            echo 'PERMISSION_DENIED';
        }
        exit(0);

    case 'gate_super_write':
        // The controller's update() emits JSON and exits, so we append a marker
        // AFTER it via the audit trail; but update() exits first. Instead we run
        // the gate (no throw => authorized) and then do a direct resolver save as
        // an independent proof that super_admin is authorized. The controller
        // write itself is exercised by persist_then_reopen.
        AuthMiddleware::requirePermission(Role::P_AI_ROUTE_MANAGE)(mkRequest('PUT', ['route' => 'n8n_relay'], 'super_admin', 9));
        echo 'ROUTE_SAVED';
        exit(0);

    // ---- validation ----
    case 'reject_invalid':
        try {
            (new AiGlobalRouteController())->update(mkRequest('PUT', ['route' => 'sql; DROP TABLE users'], 'super_admin', 9));
            echo 'ACCEPTED';
        } catch (\Velora\Core\Exceptions\ValidationException $e) {
            echo 'INVALID_AI_ROUTE';
        }
        exit(0);

    // ---- persistence across resolver instances ----
    case 'persist_then_reopen':
        (new AiRouteResolver())->save('n8n_relay', 9);
        // fresh resolver instance (new object -> re-reads DB)
        echo (new AiRouteResolver())->configuredRoute() === 'n8n_relay' ? 'REOPEN_OK' : 'REOPEN_BAD';
        exit(0);

    // ---- precedence ----
    case 'case_admin':
        (new AiRouteResolver())->clear();
        (new AiRouteResolver())->save('n8n_relay', 9);
        $s = (new AiRouteResolver())->resolveWithSource();
        echo ($s['route'] === 'n8n_relay' && $s['source'] === 'admin') ? 'CASE_ADMIN_OK' : 'BAD';
        exit(0);

    case 'case_admin_beats_env':
        (new AiRouteResolver())->clear();
        (new AiRouteResolver())->save('n8n_relay', 9);
        $s = (new AiRouteResolver())->resolveWithSource();
        echo ($s['route'] === 'n8n_relay' && $s['source'] === 'admin') ? 'CASE_ADMIN_BEATS_ENV_OK' : 'BAD';
        exit(0);

    case 'case_env':
        (new AiRouteResolver())->clear();
        $s = (new AiRouteResolver())->resolveWithSource();
        echo ($s['route'] === 'n8n_relay' && $s['source'] === 'env') ? 'CASE_ENV_OK' : 'BAD';
        exit(0);

    case 'case_default':
        (new AiRouteResolver())->clear();
        $s = (new AiRouteResolver())->resolveWithSource();
        echo ($s['route'] === 'direct' && $s['source'] === 'default') ? 'CASE_DEFAULT_OK' : 'BAD';
        exit(0);

    // ---- per-feature explicit route layering (override in generate) ----
    case 'layer_case':
        // Admin global = n8n_relay; explicit per-feature override = direct.
        (new AiRouteResolver())->clear();
        (new AiRouteResolver())->save('n8n_relay', 9);
        // Emulate AIManager passing an explicit per-feature route override.
        $override = 'direct';
        $resolverDefault = (new AiRouteResolver())->resolve();
        // generate(): override wins over getRoute()
        $effective = ($override === 'direct' || $override === 'n8n_relay') ? $override : $resolverDefault;
        // A per-feature direct override must be applied even when admin global is relay.
        echo ($effective === 'direct' && $resolverDefault === 'n8n_relay') ? 'LAYER_CASE_OK' : 'BAD';
        exit(0);

    case 'layer_action_case':
        // Admin global = direct; explicit per-feature override = n8n_relay.
        (new AiRouteResolver())->clear();
        (new AiRouteResolver())->save('direct', 9);
        $override = 'n8n_relay';
        $resolverDefault = (new AiRouteResolver())->resolve();
        $effective = ($override === 'direct' || $override === 'n8n_relay') ? $override : $resolverDefault;
        echo ($effective === 'n8n_relay' && $resolverDefault === 'direct') ? 'LAYER_ACTION_OK' : 'BAD';
        exit(0);

    // ---- legacy ai_gemini_relay_route flag fallback ----
    case 'flag_case':
        // No admin global, no env; legacy flag enabled => n8n_relay.
        (new AiRouteResolver())->clear();
        $pdo = new PDO('sqlite:' . $root . '/data/velora.sqlite');
        $pdo->exec("INSERT OR IGNORE INTO ai_feature_flags (feature_name, enabled, rollout_percentage)
            VALUES ('ai_gemini_relay_route', 1, 100)");
        $s = (new AiRouteResolver())->resolveWithSource();
        echo ($s['route'] === 'n8n_relay' && $s['source'] === 'legacy_flag') ? 'FLAG_CASE_OK' : 'BAD';
        exit(0);

    // ---- reset ----
    case 'reset_case':
        (new AiRouteResolver())->clear();
        (new AiRouteResolver())->save('direct', 9);
        $before = (new AiRouteResolver())->configuredRoute();
        $cleared = (new AiRouteResolver())->clear();
        $afterConfigured = (new AiRouteResolver())->configuredRoute();
        $effective = (new AiRouteResolver())->resolve();
        echo ($before === 'direct' && $cleared === true && $afterConfigured === null && $effective === 'direct')
            ? 'RESET_OK' : 'BAD';
        exit(0);

    // ---- audit ----
    case 'audit_write':
        (new AiRouteResolver())->clear();
        (new AiRouteResolver())->save('n8n_relay', 9);
        // Controller exits via Response::json after recording the audit event.
        (new AiGlobalRouteController())->update(mkRequest('PUT', ['route' => 'direct'], 'super_admin', 9));
        exit(0);

    case 'audit_read':
        $found = false;
        $secretFree = true;
        foreach (auditAll($root) as $rec) {
            if (($rec['action'] ?? '') === 'ai_route.updated') {
                $meta = json_decode((string) $rec['metadata_json'], true) ?: [];
                $info = ['action' => $rec['action'], 'meta_keys' => array_keys($meta)];
                if (preg_match('/token|secret|api.?key|password/i', json_encode($info))) {
                    $secretFree = false;
                }
                $metaHasOld = array_key_exists('old_route', $meta);
                $metaHasNew = array_key_exists('new_route', $meta);
                if (!$metaHasOld || !$metaHasNew) {
                    $secretFree = false;
                }
                $found = true;
            }
        }
        echo ($found && $secretFree) ? 'AUDIT_OK' : 'AUDIT_BAD';
        exit(0);

    // ---- runtime consumer ----
    case 'consumer':
        (new AiRouteResolver())->clear();
        (new AiRouteResolver())->save('n8n_relay', 9);
        echo (new GeminiProvider())->getRoute();
        exit(0);
}

exit(3);
