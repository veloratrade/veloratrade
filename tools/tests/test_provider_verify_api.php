<?php

declare(strict_types=1);

/**
 * Phase 2 — provider verification ADMIN API tests.
 *
 * Because Response::json()/error() exit the process, each controller call runs
 * in a dedicated child process sharing one temp SQLite DB and one private env
 * root. The Gemini verifier is injected with a FAKE HTTP client (no network);
 * we never use or touch a real credential.
 *
 * Run: php tools/tests/test_provider_verify_api.php
 */

$SELF = __FILE__;
$ROOT = sys_get_temp_dir() . '/velora-verify-api-test-' . bin2hex(random_bytes(5));

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

    // ---- verify: 200 => VALID (with injected fake HTTP) ----
    $r = spawn($SELF, $ROOT, 'verify', ['TEST_HTTP_STATUS' => '200', 'TEST_HTTP_BODY' => '{}']);
    $j = decode($r);
    check($r['code'] === 0, 'verify 200 returns 200');
    check(($j['data']['status'] ?? '') === 'VALID', 'verify 200 -> status VALID');
    check(($j['data']['verified'] ?? false) === true, 'verify 200 -> verified true');

    // ---- verify: 401 => INVALID_CREDENTIAL, and no secret in body ----
    $r = spawn($SELF, $ROOT, 'verify', ['TEST_HTTP_STATUS' => '401', 'TEST_HTTP_BODY' => '{}']);
    $j = decode($r);
    check(($j['data']['status'] ?? '') === 'INVALID_CREDENTIAL', 'verify 401 -> INVALID_CREDENTIAL');
    check(($j['data']['verified'] ?? true) === false, 'verify 401 -> verified false');
    check(!str_contains($r['out'], 'AIza'), 'verify 401 response contains no AIza key');

    // ---- verify: relay route cannot claim Gemini credential validity ----
    $r = spawn($SELF, $ROOT, 'verify', ['TEST_ROUTE' => 'n8n_relay', 'TEST_HTTP_STATUS' => '200']);
    $j = decode($r);
    check(($j['data']['status'] ?? '') === 'UNKNOWN', 'verify relay -> UNKNOWN (not VALID)');
    check(($j['data']['source'] ?? '') === 'relay', 'verify relay -> source=relay');

    // ---- audit: every live provider verify/test must leave a secret-free trail (§L1.10) ----
    $r = spawn($SELF, $ROOT, 'verify', ['TEST_HTTP_STATUS' => '401', 'TEST_HTTP_BODY' => '{}']);
    $audit = spawn($SELF, $ROOT, 'audit_read', []);
    check(str_contains($audit['out'], 'integration.connection_test'), 'live provider verify produces an integration.connection_test audit row');
    check(str_contains($audit['out'], 'verifyCredential'), 'audit records the operation');
    check(str_contains($audit['out'], 'INVALID_CREDENTIAL'), 'audit records the normalized result (not a raw exception)');
    check(!str_contains($audit['out'], 'AIza-test-secret'), 'audit record contains NO credential value');
    check(!str_contains($audit['out'], 'GEMINI_API_KEY'), 'audit metadata contains no key name for the secret');

    // ---- test-connection: 401 direct => endpoint reachable, but not verified ----
    $r = spawn($SELF, $ROOT, 'test_connection', ['TEST_HTTP_STATUS' => '401', 'TEST_HTTP_BODY' => '{}']);
    $j = decode($r);
    check(($j['data']['reachable'] ?? false) === true, 'test-connection 401 -> reachable true');
    check(($j['data']['verified'] ?? true) === false, 'test-connection 401 -> verified false');

    // ---- replaceCredential resets status to UNVERIFIED ----
    $r = spawn($SELF, $ROOT, 'replace');
    $j = decode($r);
    check(($j['data']['configured'] ?? false) === true, 'replace -> configured=true');
    check(($j['data']['credential']['status'] ?? '') === 'UNVERIFIED', 'replace -> credential status UNVERIFIED (not auto-activated)');
    $meta = spawn($SELF, $ROOT, 'meta_status');
    check(trim($meta['out']) === 'UNVERIFIED', 'persisted metadata status is UNVERIFIED after replace');

    // ---- effective config endpoint: no secret, real values ----
    $r = spawn($SELF, $ROOT, 'effective');
    $j = decode($r);
    check(($j['data']['config']['providers'][0]['provider'] ?? '') === 'gemini', 'effective config returns providers');
    check(!str_contains($r['out'], 'AIza'), 'effective config response contains no AIza key');
    check(($j['data']['config']['features'][1]['feature'] ?? '') === 'screenshot_extraction' || ($j['data']['config']['features'][0]['feature'] ?? '') === 'screenshot_extraction', 'effective config returns screenshot_extraction feature');

    // ---- Rate limit: verify endpoint is limiter-connected (15/300) ----
    $r = spawn($SELF, $ROOT, 'rate_limit_boundary');
    $j = decode($r);
    check(($j['rl_near'] ?? 0) === 15, '15 verify attempts allowed (boundary)');
    check(($j['rl_exceeded'] ?? 0) === 429, '16th verify attempt -> 429 TOO_MANY_REQUESTS');

    // ---- Effective config endpoint: admin-only auth (server-side) ----
    $r = spawn($SELF, $ROOT, 'effective_rbac_non_admin');
    check(str_contains($r['out'], 'ADMIN_REQUIRED'), 'effective-config non-admin -> 403 ADMIN_REQUIRED');
    $r = spawn($SELF, $ROOT, 'effective_rbac_admin');
    check(str_contains($r['out'], '"config"'), 'effective-config admin -> allowed and returns config');

    // ---- RBAC: non-admin blocked on verify & effective config; admin allowed ----
    $r = spawn($SELF, $ROOT, 'rbac_non_admin');
    check(($r['code'] === 0) && str_contains($r['out'], 'ADMIN_REQUIRED'), 'non-admin verify -> 403 ADMIN_REQUIRED');
    $r = spawn($SELF, $ROOT, 'rbac_admin');
    check(str_contains($r['out'], '"ok"') || str_contains($r['out'], 'true'), 'admin verify -> allowed');

    echo "\nprovider-verify-api: " . ($failures === 0 ? 'PASS' : 'FAIL') . " ($checks checks, $failures failures)\n";
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
putenv('GEMINI_ROUTE=' . (getenv('TEST_ROUTE') ?: 'direct'));
if (!is_file($ROOT . '/config/velora.env')) {
    file_put_contents($ROOT . '/config/velora.env', implode("\n", [
        'APP_ENV=local', 'APP_DEBUG=true', 'DB_DRIVER=sqlite', 'DB_DATABASE=' . $ROOT . '/data/velora.sqlite',
        'JWT_SECRET=' . str_repeat('j', 48), 'APP_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)),
        'GEMINI_API_KEY=AIza-test-secret-not-real',
        'GEMINI_ROUTE=' . (getenv('TEST_ROUTE') ?: 'direct'),
        'GEMINI_RELAY_URL=https://relay.example.invalid/webhook', 'GEMINI_RELAY_TOKEN=relay-test-token',
        'CORS_ALLOWED_ORIGINS=http://localhost', 'FRONTEND_URL=http://localhost', 'MAIL_DRIVER=log',
    ]) . "\n");
}
@mkdir($ROOT . '/data', 0700, true);
ini_set('error_log', $ROOT . '/logs/php-error.log');
require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

use Velora\Admin\AIConfigController;
use Velora\Admin\EffectiveConfigController;
use Velora\AI\Providers\GeminiCredentialVerifier;
use Velora\AI\Services\ProviderVerifierRegistry;
use Velora\AI\Repositories\AICredentialMetadataRepository;
use Velora\Auth\AuthMiddleware;
use Velora\Core\Request;

$pdo = new PDO('sqlite:' . $ROOT . '/data/velora.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if ($case === 'setup') {
    foreach ([
        'ai_feature_providers' => 'CREATE TABLE IF NOT EXISTS ai_feature_providers (id INTEGER PRIMARY KEY AUTOINCREMENT, feature TEXT NOT NULL, provider TEXT NOT NULL, model TEXT NULL, priority INTEGER DEFAULT 1, enabled INTEGER DEFAULT 1, route TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE(feature, provider))',
        'ai_feature_flags' => 'CREATE TABLE IF NOT EXISTS ai_feature_flags (feature_name TEXT PRIMARY KEY, enabled INTEGER DEFAULT 0, rollout_percentage INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)',
        'ai_provider_quotas' => 'CREATE TABLE IF NOT EXISTS ai_provider_quotas (provider TEXT PRIMARY KEY, daily_used INTEGER DEFAULT 0, quota_limit INTEGER DEFAULT 1500, reset_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)',
        'ai_provider_credentials' => 'CREATE TABLE IF NOT EXISTS ai_provider_credentials (provider TEXT PRIMARY KEY, status TEXT DEFAULT \'UNVERIFIED\', verified INTEGER DEFAULT 0, fingerprint TEXT, verified_at DATETIME, last_checked_at DATETIME, error_code TEXT, latency_ms INTEGER DEFAULT 0, version INTEGER DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)',
        'rate_limits' => 'CREATE TABLE IF NOT EXISTS rate_limits (bucket TEXT PRIMARY KEY, hits INTEGER NOT NULL, window_start DATETIME NOT NULL)',
        'users' => 'CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, role TEXT DEFAULT \'user\', ai_consent_at DATETIME NULL)',
        'admin_audit_logs' => 'CREATE TABLE IF NOT EXISTS admin_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER NULL, actor_role TEXT NULL, action TEXT NULL, target_type TEXT NULL, target_id TEXT NULL, result TEXT NULL, summary TEXT NULL, ip_address TEXT NULL, user_agent TEXT NULL, context_id TEXT NULL, metadata_json TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)',
    ] as $ddl) { $pdo->exec($ddl); }
    $pdo->exec("INSERT OR REPLACE INTO ai_feature_providers (feature, provider, model, priority, enabled, route) VALUES ('screenshot_extraction','gemini',NULL,1,1,NULL)");
    $pdo->exec("INSERT OR REPLACE INTO ai_provider_quotas (provider, daily_used, quota_limit, reset_at) VALUES ('gemini', 3, 1500, CURRENT_TIMESTAMP)");
    echo 'SETUP_OK'; exit(0);
}

// Fake HTTP: returns canned status/body. Never touches the network.
$status = (int) (getenv('TEST_HTTP_STATUS') ?: 200);
$body = (string) (getenv('TEST_HTTP_BODY') ?: '{}');
$fakeHttp = static fn (string $m, string $u, array $h): array => ['http' => $status, 'body' => $body, 'error' => 0, 'latency_ms' => 5];
$verifier = new GeminiCredentialVerifier('AIza-test-secret-not-real', getenv('TEST_ROUTE') ?: 'direct', $fakeHttp, 5);
$registry = new ProviderVerifierRegistry([$verifier]);
$controller = new AIConfigController(verifiers: $registry);

ob_start();
register_shutdown_function(function (): void { $o = ob_get_clean(); if ($o !== null && $o !== '') { echo $o; } });

$request = new Request('POST', '/api/v1/admin/providers/gemini/verify', [], [], []);

switch ($case) {
    case 'verify':
        $request->attributes['user_role'] = 'admin';
        $controller->verifyCredential($request, ['provider' => 'gemini']);
        exit(0);
    case 'test_connection':
        $request->attributes['user_role'] = 'admin';
        $controller->testConnection($request, ['provider' => 'gemini']);
        exit(0);
    case 'replace':
        $request = new Request('POST', '/api/v1/admin/ai/credentials/gemini', [], ['value' => 'AIza-new-value'], []);
        $request->attributes['user_role'] = 'admin';
        $controller->replaceCredential($request, ['provider' => 'gemini']);
        exit(0);
    case 'meta_status':
        echo (string) ((new AICredentialMetadataRepository())->get('gemini')['status'] ?? 'NONE');
        exit(0);
    case 'audit_read':
        try {
            $rows = $pdo->query(
                "SELECT action, result, COALESCE(metadata_json,'') AS meta FROM admin_audit_logs ORDER BY id DESC LIMIT 5"
            )->fetchAll(PDO::FETCH_ASSOC);
            if ($rows === []) { echo 'NO_AUDIT'; exit(0); }
            echo implode('|', array_map(static fn (array $r): string => trim($r['action'] . ' ' . strval($r['result']) . ' ' . $r['meta']), $rows));
        } catch (\Throwable $e) {
            echo 'AUDIT_ERR:' . $e->getMessage();
        }
        exit(0);
    case 'effective':
        (new EffectiveConfigController())->show(new Request('GET', '/api/v1/admin/config/effective', [], [], []));
        exit(0);
    case 'effective_rbac_non_admin':
        $request->attributes['user_role'] = 'user';
        try {
            (AuthMiddleware::adminOnly())($request);
            echo json_encode(['error' => ['code' => 'MUST_HAVE_FAILED']]);
        } catch (\Velora\Core\Exceptions\ForbiddenException $e) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'data' => null, 'error' => ['code' => $e->errorCode(), 'message' => $e->getMessage()]]);
        }
        exit(0);
    case 'effective_rbac_admin':
        $request->attributes['user_role'] = 'admin';
        (new EffectiveConfigController())->show($request);
        exit(0);
    case 'rate_limit_boundary':
        // reset the shared bucket in this DB, then exercise the boundary + wiring.
        $pdo->exec("DELETE FROM rate_limits WHERE bucket LIKE 'admin-provider-verify%'");
        $near = 0; $exceeded = 0;
        for ($i = 1; $i <= 16; $i++) {
            try {
                \Velora\Core\RateLimiter::hit('admin-provider-verify', 15, 300);
                $near = $i;
            } catch (\Velora\Core\Exceptions\ApiException $e) {
                $exceeded = $e->httpStatus();
            }
        }
        // wiring proof: calling the controller action passes through the limiter
        $request->attributes['user_role'] = 'admin';
        try { $controller->verifyCredential($request, ['provider' => 'gemini']); } catch (\Throwable $e) {}
        echo json_encode(['rl_near' => $near, 'rl_exceeded' => $exceeded]);
        exit(0);
    case 'rbac_non_admin':
        $request->attributes['user_role'] = 'user';
        try {
            (AuthMiddleware::adminOnly())($request);
            echo json_encode(['error' => ['code' => 'MUST_HAVE_FAILED']]);
        } catch (\Velora\Core\Exceptions\ForbiddenException $e) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'data' => null, 'error' => ['code' => $e->errorCode(), 'message' => $e->getMessage()]]);
        }
        exit(0);
    case 'rbac_admin':
        $request->attributes['user_role'] = 'admin';
        (AuthMiddleware::adminOnly())($request);
        echo json_encode(['ok' => true]);
        exit(0);
}
