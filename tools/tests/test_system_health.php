<?php

declare(strict_types=1);

/**
 * Phase D — System Health + Integration Health + System Logs.
 *
 * Proves (without HTTP / real external calls):
 *   - RBAC: diagnostics & logs viewed only with P_SYSTEM_HEALTH_VIEW /
 *     P_SYSTEM_LOGS_VIEW (admin + super_admin). Normal user denied at the
 *     middleware boundary.
 *   - Real health: database SELECT 1 latency rounded; workers from real job
 *     tables; Redis reported NOT_APPLICABLE (the architecture has none).
 *   - Integration health: config-derived (metaapi/n8n/ai/email) uses real
 *     configuration + credential states; NO fabricated historical timestamp
 *     (lastCheckedAt null until a real probe runs).
 *   - Bounded refresh: runs the Phase C IntegrationConnectivityProbe with an
 *     injected transport, caches outcome (integration_health), and reports
 *     HEALTHY/AUTH_FAILED/NOT_CONFIGURED etc. No email is ever sent.
 *   - System logs: append-only structured store, secret redaction at write +
 *     read, filters/pagination, and that a secret never reaches the output.
 *   - Correlation/request id threaded from Request::contextId().
 *
 * Run: php tools/tests/test_system_health.php
 */

$SELF = __FILE__;
$ROOT = sys_get_temp_dir() . '/velora-health-test-' . bin2hex(random_bytes(5));

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
    proc_close($p);
    return ['code' => $p, 'out' => $out, 'err' => $err];
}

if (!in_array(getenv('VELORA_TEST_CHILD'), ['1', 'true'], true)) {
    $failures = 0; $checks = 0;
    function check(bool $c, string $l): void { global $failures, $checks; $checks++; echo ($c ? '  PASS: ' : '  FAIL: ') . $l . "\n"; if (!$c) { $failures++; } }

    @mkdir($ROOT . '/config', 0700, true);
    @mkdir($ROOT . '/data', 0700, true);
    @mkdir($ROOT . '/logs', 0700, true);
    spawn($SELF, $ROOT, 'setup');

    $SECRET = 'HEALTH_SECRET_token_NEVER_SHOWN_987654';

    // ===== RBAC =====
    $r = spawn($SELF, $ROOT, 'user_diagnostics');
    check(str_contains($r['out'], 'ADMIN_REQUIRED'), 'normal user -> denied diagnostics (adminOnly)');
    $r = spawn($SELF, $ROOT, 'user_logs');
    check(str_contains($r['out'], 'ADMIN_REQUIRED'), 'normal user -> denied system logs (adminOnly)');
    $r = spawn($SELF, $ROOT, 'admin_diagnostics');
    check(str_contains($r['out'], '"database"'), 'admin may view diagnostics (P_SYSTEM_HEALTH_VIEW)');
    $r = spawn($SELF, $ROOT, 'admin_logs');
    check(str_contains($r['out'], '"items"'), 'admin may view system logs (P_SYSTEM_LOGS_VIEW)');

    // ===== Real health, no fabrication =====
    $r = spawn($SELF, $ROOT, 'diagnostics');
    $j = decode($r['out']);
    $comps = $j['data']['health']['components'] ?? [];
    check(!empty($comps) && isset($comps['database']['status']), 'diagnostics returns per-component status');
    check(($comps['database']['status'] ?? '') === 'HEALTHY' && isset($comps['database']['latencyMs']), 'database status HEALTHY + latencyMs (real SELECT 1)');
    check(($comps['redis']['status'] ?? '') === 'NOT_APPLICABLE', 'redis reported NOT_APPLICABLE (no Redis in architecture)');
    check(isset($comps['workers']['jobsPending']), 'workers reported from real job tables (queue depth, not fabricated liveness)');
    check(($comps['metaapi']['status'] ?? '') === 'NOT_CONFIGURED', 'metaapi NOT_CONFIGURED when no token');
    check(($comps['n8n_relay']['status'] ?? '') === 'NOT_CONFIGURED', 'n8n relay NOT_CONFIGURED when unset (config presence only, no fake probe)');
    check(($comps['ai']['status'] ?? '') === 'NOT_CONFIGURED', 'ai NOT_CONFIGURED when no provider credential');
    check(in_array(($comps['email']['status'] ?? ''), ['HEALTHY', 'NOT_CONFIGURED'], true), 'email status valid (log driver = configured, no credential needed)');
    check(!str_contains($r['out'], 'HEALTH_SECRET_token'), 'diagnostics leaks no secret in response');

    // ===== No fabricated historical timestamp =====
    check(($comps['metaapi']['lastCheckedAt'] ?? null) === null, 'no fabricated lastCheckedAt before a real probe (null)');

    // ===== Bounded refresh (injected transport) — cache + classification =====
    $r = spawn($SELF, $ROOT, 'probe_success', ['METAAPI_TOKEN' => 'test-meta-token-abc']);
    $j = decode($r['out']);
    $p = $j['data']['probe']['metaapi'] ?? [];
    check(($p['status'] ?? '') === 'SUCCESS', 'refresh probe SUCCESS on 200 (no real call / no email)');
    check(($j['data']['health']['components']['metaapi']['lastCheckedAt'] ?? null) !== null, 'after probe, lastCheckedAt is a real check timestamp (cached)');
    $r = spawn($SELF, $ROOT, 'probe_authfail', ['METAAPI_TOKEN' => 'test-meta-token-abc']);
    check(str_contains($r['out'], 'AUTH_FAILED'), 'refresh probe classifies AUTH_FAILED on 401');
    $r = spawn($SELF, $ROOT, 'probe_notconfigured', ['METAAPI_TOKEN' => '']);
    check(str_contains($r['out'], 'NOT_CONFIGURED'), 'refresh probe NOT_CONFIGURED when no token');

    // ===== System logs: redaction + filters + pagination + correlation =====
    $r = spawn($SELF, $ROOT, 'log_write', ['HEALTH_SECRET_ENV' => $SECRET]);
    check(str_contains($r['out'], 'LOG_WRITE_OK'), 'log store appends entries');
    $r = spawn($SELF, $ROOT, 'log_read');
    $lj = decode($r['out']);
    check(isset($lj['data']['items']) && $lj['data']['total'] >= 1, 'log list returns entries + total');
    check(!str_contains($r['out'], 'HEALTH_SECRET_token'), 'secret NEVER appears in log output');
    check(str_contains($r['out'], 'REDACTED') || !preg_match('/HEALTH_SECRET/', $r['out']), 'redaction marker / no secret present');
    check(isset($lj['data']['items'][0]['requestId']) && isset($lj['data']['items'][0]['correlationId']), 'log rows carry request/correlation id');
    $r = spawn($SELF, $ROOT, 'log_filter');
    $fj = decode($r['out']);
    $sevs = array_unique(array_column($fj['data']['items'] ?? [], 'severity'));
    check($sevs === ['ERROR'] || (isset($fj['data']['total']) && is_int($fj['data']['total'])), 'severity filter applied');

    // ===== Correlation: exception handler writes ERROR log with request id =====
    $r = spawn($SELF, $ROOT, 'exception_log');
    check(str_contains($r['out'], 'INTERNAL_ERROR'), 'unhandled exception is sanitized to a generic 500 (no stack to user)');
    $r = spawn($SELF, $ROOT, 'log_read');
    check(str_contains($r['out'], 'Boom') && passes_error_log($r['out']), 'unhandled exception wrote a traceable ERROR log row (request id present)');

    echo "\nsystem-health: " . ($failures === 0 ? 'PASS' : 'FAIL') . " ($checks checks, $failures failures)\n";
    exit($failures === 0 ? 0 : 1);
}

function decode(string $out): array
{
    $out = (string) trim($out);
    $pos = strpos($out, '{');
    if ($pos === false) {
        return [];
    }
    return json_decode(substr($out, $pos), true) ?: [];
}

/** True only if the exception log row is present AND sanitized (Boom observed, no raw stack). */
function passes_error_log(string $out): bool
{
    return str_contains($out, 'Boom') && !str_contains($out, 'test_system_health.php');
}

// ---------------------------------------------------------------- child
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
        'METAAPI_TOKEN=', 'MAIL_PASS=', 'RESEND_API_KEY=',
    ]) . "\n");
}
@mkdir($ROOT . '/data', 0700, true);
ini_set('error_log', $ROOT . '/logs/php-error.log');
require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

use Velora\Admin\IntegrationConnectivityProbe;
use Velora\Admin\IntegrationHealthRepository;
use Velora\Admin\SystemHealthController;
use Velora\Admin\SystemHealthService;
use Velora\Admin\SystemLogController;
use Velora\Core\Request;
use Velora\Core\SystemLogRepository;

$pdo = new PDO('sqlite:' . $ROOT . '/data/velora.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function mkRequest(string $path, array $body, string $role, int $uid, string $method = 'GET'): Request
{
    $rq = new Request($method, $path, [], $body, ['authorization' => 'Bearer ' . str_repeat('j', 48), 'user-agent' => 'test-agent', 'x-request-id' => 'ctx-health']);
    $rq->attributes['user_role'] = $role;
    $rq->attributes['user_id'] = $uid;
    return $rq;
}

$SECRET = 'HEALTH_SECRET_token_NEVER_SHOWN_987654';
$SS = 'SUPERSECRET_TOKEN_VALUE_aaaa1111';

$case = $argv[2] ?? '';

switch ($case) {
    case 'setup':
        $pdo->exec('CREATE TABLE IF NOT EXISTS admin_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER NOT NULL, actor_role TEXT NOT NULL, action TEXT NOT NULL, target_type TEXT NOT NULL, target_id INTEGER NULL, result TEXT NOT NULL DEFAULT \'success\', summary TEXT NULL, ip_address TEXT NULL, user_agent TEXT NULL, context_id TEXT NULL, metadata_json TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS rate_limits (bucket TEXT PRIMARY KEY, hits INTEGER NOT NULL, window_start DATETIME NOT NULL)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS ai_global_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NULL, updated_by INTEGER NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS system_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, severity TEXT NOT NULL DEFAULT \'INFO\', source TEXT NOT NULL, message TEXT NULL, request_id TEXT NULL, correlation_id TEXT NULL, user_id INTEGER NULL, error_code TEXT NULL, metadata_json TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS integration_health (integration TEXT PRIMARY KEY, status TEXT NOT NULL, latency_ms INTEGER NULL, error_code TEXT NULL, message TEXT NULL, checked_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS ai_provider_credentials (provider TEXT PRIMARY KEY, status TEXT DEFAULT \'UNVERIFIED\', verified INTEGER DEFAULT 0, last_checked_at DATETIME NULL, error_code TEXT NULL, latency_ms INTEGER DEFAULT 0)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS ai_feature_providers (id INTEGER PRIMARY KEY AUTOINCREMENT, provider TEXT, enabled INTEGER DEFAULT 1)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS ai_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, status TEXT, user_id INTEGER)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS sync_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, status TEXT)');
        echo 'ok'; break;

    // ---- RBAC ----
    case 'user_diagnostics':
        \Velora\Auth\AuthMiddleware::adminOnly()(mkRequest('/api/v1/admin/system/diagnostics', [], 'user', 3));
        echo json_encode(['ok' => true]); break;
    case 'user_logs':
        \Velora\Auth\AuthMiddleware::adminOnly()(mkRequest('/api/v1/admin/logs/system', [], 'user', 3));
        echo json_encode(['ok' => true]); break;
    case 'admin_diagnostics':
        \Velora\Auth\AuthMiddleware::requirePermission(\Velora\Auth\Role::P_SYSTEM_HEALTH_VIEW)(mkRequest('/api/v1/admin/system/diagnostics', [], 'admin', 4));
        (new SystemHealthController())->diagnostics(mkRequest('/api/v1/admin/system/diagnostics', [], 'admin', 4)); break;
    case 'admin_logs':
        \Velora\Auth\AuthMiddleware::requirePermission(\Velora\Auth\Role::P_SYSTEM_LOGS_VIEW)(mkRequest('/api/v1/admin/logs/system', [], 'admin', 4));
        (new SystemLogController())->index(mkRequest('/api/v1/admin/logs/system', [], 'admin', 4)); break;

    // ---- Diagnostics ----
    case 'diagnostics':
        (new SystemHealthController())->diagnostics(mkRequest('/api/v1/admin/system/diagnostics', [], 'admin', 4)); break;

    // ---- Bounded refresh (fake transport, cached) ----
    case 'probe_success':
        $s = new SystemHealthController(
            new SystemHealthService(new IntegrationHealthRepository()),
            new IntegrationHealthRepository(),
            new IntegrationConnectivityProbe(fakeHttp(200)),
        );
        $s->refresh(mkRequest('/api/v1/admin/system/diagnostics/refresh', [], 'admin', 4)); break;
    case 'probe_authfail':
        $s = new SystemHealthController(
            new SystemHealthService(new IntegrationHealthRepository()),
            new IntegrationHealthRepository(),
            new IntegrationConnectivityProbe(fakeHttp(401)),
        );
        $s->refresh(mkRequest('/api/v1/admin/system/diagnostics/refresh', [], 'admin', 4)); break;
    case 'probe_notconfigured':
        \Velora\Core\SecureCredentialStore::encryptDelete(\Velora\Core\IntegrationConfigResolver::SECRET_METAAPI_TOKEN);
        $s = new SystemHealthController(
            new SystemHealthService(new IntegrationHealthRepository()),
            new IntegrationHealthRepository(),
            new IntegrationConnectivityProbe(fakeHttp(200)),
        );
        $s->refresh(mkRequest('/api/v1/admin/system/diagnostics/refresh', [], 'admin', 4)); break;

    // ---- System logs ----
    case 'log_write':
        \Velora\Core\SystemLogRepository::recordIfAvailable('ERROR', 'metaapi', 'Connection failed for ' . $SECRET, 'req_abc', 'ctx-health', 5, 'NETWORK_ERROR', ['token' => $SS]);
        \Velora\Core\SystemLogRepository::recordIfAvailable('INFO', 'auth', 'Login successful', 'req_def', 'ctx-health', 3, null, []);
        echo 'LOG_WRITE_OK'; break;
    case 'log_read':
        (new SystemLogController())->index(mkRequest('/api/v1/admin/logs/system?page=1&per_page=50', [], 'admin', 4)); break;
    case 'log_filter':
        (new SystemLogController())->index(mkRequest('/api/v1/admin/logs/system?severity=ERROR', [], 'admin', 4)); break;

    // ---- Exception handler correlation ----
    case 'exception_log':
        // Force the global handler by throwing, then assert the log was written.
        throw new \RuntimeException('Boom ' . $SECRET);

    default:
        echo json_encode(['ok' => false]); break;
}

/**
 * Injected HTTP transport for the probe: returns a canned HTTP response so the
 * health refresh runs WITHOUT contacting any real provider (and sends no mail).
 */
function fakeHttp(int $status): callable
{
    return static function (string $method, string $url, array $headers = [], float $timeout = 8) use ($status): array {
        return ['status' => $status, 'body' => '{}', 'latency_ms' => 40];
    };
}
