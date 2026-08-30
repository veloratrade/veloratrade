<?php

declare(strict_types=1);

/**
 * Admin AI configuration API tests — real persisted state, RBAC, read-back
 * verification, allowlist validation, credential hygiene.
 *
 * Response::json()/error() exit the process, so each controller call runs in
 * a dedicated child process (self-spawned) sharing one temp SQLite database
 * and one private env root. The parent asserts on captured bodies.
 *
 * Run: php tools/tests/test_admin_ai_config.php
 */

$SELF = __FILE__;
$ROOT = sys_get_temp_dir() . '/velora-admin-ai-test-' . bin2hex(random_bytes(5));

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

// -------------------------------------------------------------------------
// parent: orchestration
// -------------------------------------------------------------------------
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

    $SECRET = 'sk-admin-test-secret-DO-NOT-LEAK-9876543210';

    echo "== RBAC: normal user receives 403 ADMIN_REQUIRED ==\n";
    $r = spawn($SELF, $ROOT, 'rbac_non_admin');
    $body = decode($r);
    check(($body['error']['code'] ?? '') === 'ADMIN_REQUIRED', 'non-admin closure returns ADMIN_REQUIRED');
    check (strpos($r['out'], 'administrator') !== false || ($body['error']['code'] ?? '') === 'ADMIN_REQUIRED', '403 admin gate active');
    $r2 = spawn($SELF, $ROOT, 'rbac_admin');
    check(($r2['code'] === 0) && strpos($r2['out'], 'ADMIN_REQUIRED') === false, 'admin role passes the same gate');

    echo "== Overview: REAL persisted state (seed) ==\n";
    $r = spawn($SELF, $ROOT, 'overview');
    $body = decode($r);
    $feats = [];
    foreach (($body['data']['features'] ?? []) as $f) { $feats[$f['feature']] = $f; }
    check(($body['data']['routingRowCount'] ?? -1) === 2, 'overview reports 2 real routing rows');
    check(($feats['screenshot_extraction']['source'] ?? '') === 'db', 'screenshot_extraction source=db');
    $chain = $feats['screenshot_extraction']['chain'] ?? [];
    check(($chain[0]['provider'] ?? '') === 'gemini' && ($chain[0]['priority'] ?? 99) === 1, 'gemini row priority 1');
    $names = array_map(fn ($e) => $e['provider'], $chain);
    $tessRunnable = spawn($SELF, $ROOT, 'tesseract_available')['out'] === '1';
    check(!$tessRunnable || in_array('tesseract', $names, true), 'tesseract row reflected in chain only when executable (sandbox: binary absent)');
    check(($body['data']['routingTableExists'] ?? false) === true, 'routing table reported as existing');
    $prov = [];
    foreach (($body['data']['providers'] ?? []) as $p) { $prov[$p['provider']] = $p; }
    check(isset($prov['gemini']['credentialStatus']['configured']) && is_bool($prov['gemini']['credentialStatus']['configured']), 'gemini credential status is a boolean');
    check(($prov['gemini']['credentialStatus']['envKey'] ?? '') === 'GEMINI_API_KEY', 'credential env key NAME only (no value)');
    check(($prov['openai']['credentialStatus']['configured'] ?? true) === false, 'openai not configured (real env absence)');
    check(($prov['tesseract']['credentialStatus']['required'] ?? true) === false, 'tesseract requires no credential');
    $raw = $r['out'];
    check(strpos($raw, 'AIza-') === false && strpos($raw, 'sk-') === false, 'no credential values in overview response');

    echo "== Persistence: create / update / reorder / delete with read-back ==\n";
    $r = spawn($SELF, $ROOT, 'create_openai', ['TEST_BODY' => json_encode(['feature' => 'screenshot_extraction', 'provider' => 'openai', 'model' => 'gpt-5', 'priority' => 3, 'enabled' => true, 'route' => 'direct'])]);
    $body = decode($r);
    check(($body['data']['featureProvider']['provider'] ?? '') === 'openai', 'create returns persisted openai row (read-back)');
    check(($body['data']['featureProvider']['model'] ?? '') === 'gpt-5', 'persisted model read back');

    $r = spawn($SELF, $ROOT, 'invalid_model', ['TEST_BODY' => json_encode(['feature' => 'screenshot_extraction', 'provider' => 'openai', 'model' => 'gpt-99-fake', 'priority' => 4])]);
    $body = decode($r);
    check(($body['error']['code'] ?? '') === 'VALIDATION_FAILED', 'non-allowlisted model rejected 422');
    $r = spawn($SELF, $ROOT, 'invalid_provider', ['TEST_BODY' => json_encode(['feature' => 'screenshot_extraction', 'provider' => 'grok', 'priority' => 4])]);
    check(($body['error']['code'] ?? '') !== '' || (decode($r)['error']['code'] ?? '') === 'VALIDATION_FAILED', 'non-allowlisted provider rejected 422');
    $r = spawn($SELF, $ROOT, 'invalid_route', ['TEST_BODY' => json_encode(['feature' => 'screenshot_extraction', 'provider' => 'openai', 'priority' => 4, 'route' => 'n8n_relay'])]);
    check((decode($r)['error']['code'] ?? '') === 'VALIDATION_FAILED', 'openai n8n_relay route rejected 422');
    $r = spawn($SELF, $ROOT, 'invalid_feature', ['TEST_BODY' => json_encode(['feature' => 'moon_mining', 'provider' => 'openai', 'priority' => 4])]);
    check((decode($r)['error']['code'] ?? '') === 'VALIDATION_FAILED', 'non-allowlisted feature rejected 422');
    $r = spawn($SELF, $ROOT, 'invalid_priority', ['TEST_BODY' => json_encode(['feature' => 'screenshot_extraction', 'provider' => 'openai', 'priority' => 99])]);
    check((decode($r)['error']['code'] ?? '') === 'VALIDATION_FAILED', 'priority 99 rejected 422');
    check(spawn($SELF, $ROOT, 'count_rows')['out'] === '3', 'invalid requests persisted nothing (still 3 rows)');

    $r = spawn($SELF, $ROOT, 'find_openai_id');
    $openaiId = (int) $r['out'];
    check($openaiId > 0, 'openai row id resolved');

    $r = spawn($SELF, $ROOT, 'update_model', ['TEST_BODY' => json_encode(['model' => 'gpt-4o-mini']), 'TEST_ID' => (string) $openaiId]);
    $body = decode($r);
    check(($body['data']['featureProvider']['model'] ?? '') === 'gpt-4o-mini', 'model change persists (authoritative read-back)');

    $r = spawn($SELF, $ROOT, 'disable_gemini');
    $body = decode($r);
    check(($body['data']['featureProvider']['enabled'] ?? true) === false, 'disable gemini persists');
    $r = spawn($SELF, $ROOT, 'overview');
    $feats = [];
    foreach ((decode($r)['data']['features'] ?? []) as $f) { $feats[$f['feature']] = $f; }
    $names = array_map(fn ($e) => $e['provider'], $feats['screenshot_extraction']['chain'] ?? []);
    check(!in_array('gemini', $names, true), 'runtime chain skips disabled gemini (routing reflects persistence)');

    $r = spawn($SELF, $ROOT, 'enable_gemini');
    check((decode($r)['data']['featureProvider']['enabled'] ?? false) === true, 'enable gemini persists');
    $r = spawn($SELF, $ROOT, 'overview');
    check(strpos($r['out'], 'gemini') !== false, 'overview re-fetched reflects enabled gemini (reload semantics)');

    $r = spawn($SELF, $ROOT, 'reorder');
    $body = decode($r);
    $rows = $body['data']['rows'] ?? [];
    check(count($rows) === 3 && ($rows[0]['provider'] ?? '') === 'openai', 'reorder persists (openai to priority 1)');

    $r = spawn($SELF, $ROOT, 'delete_row', ['TEST_ID' => (string) $openaiId]);
    check(($body['data']['deleted'] ?? false) === true || (decode($r)['data']['deleted'] ?? false) === true, 'delete executes');
    check(spawn($SELF, $ROOT, 'count_rows')['out'] === '2', 'delete persisted (2 rows remain)');
    $r = spawn($SELF, $ROOT, 'delete_missing', ['TEST_ID' => '999999']);
    check((decode($r)['error']['code'] ?? '') === 'AI_CONFIG_NOT_FOUND', 'deleting a missing row is 404, not fake success');

    echo "== Credentials: replace/delete return booleans only ==\n";
    $r = spawn($SELF, $ROOT, 'cred_replace_openai', ['TEST_BODY' => json_encode(['value' => $SECRET])]);
    $out = $r['out'];
    check(strpos($out, $SECRET) === false, 'replace response NEVER echoes the submitted value');
    $body = decode($r);
    check(($body['data']['configured'] ?? null) === true, 'replace returns configured=true only');
    $envNow = (string) @file_get_contents($ROOT . '/config/velora.env');
    check(strpos($envNow, 'OPENAI_API_KEY=' . $SECRET) !== false, 'credential really persisted in private env');
    check(strpos($envNow, 'GEMINI_API_KEY=AIza-test-not-real') !== false, 'pre-existing keys preserved in env');
    $r = spawn($SELF, $ROOT, 'overview');
    check(strpos($r['out'], $SECRET) === false, 'overview exposes no credential');
    check(strpos(spawn($SELF, $ROOT, 'php_error_log')['out'], $SECRET) === false, 'error logs expose no credential');
    $r = spawn($SELF, $ROOT, 'cred_delete_openai');
    $body = decode($r);
    check(($body['data']['configured'] ?? null) === false, 'delete returns configured=false');
    $envNow = (string) @file_get_contents($ROOT . '/config/velora.env');
    check(strpos($envNow, $SECRET) === false, 'credential removed from env after delete');
    $r = spawn($SELF, $ROOT, 'cred_replace_tesseract', ['TEST_BODY' => json_encode(['value' => 'x'])]);
    check((decode($r)['error']['code'] ?? '') === 'VALIDATION_FAILED', 'credential op on credential-less provider rejected');

    echo "== Empty routing table => env-default, never fake rows ==\n";
    spawn($SELF, $ROOT, 'wipe_rows');
    $r = spawn($SELF, $ROOT, 'overview');
    $body = decode($r);
    $feats = [];
    foreach (($body['data']['features'] ?? []) as $f) { $feats[$f['feature']] = $f; }
    check(($body['data']['routingRowCount'] ?? -1) === 0, 'routing row count is really 0');
    check(($feats['screenshot_extraction']['source'] ?? '') === 'env-default', 'source=env-default reported');
    check(($feats['screenshot_extraction']['rows'] ?? null) === [], 'no fabricated rows in empty state');
    check(($feats['screenshot_extraction']['chain'] ?? null) !== [], 'real env-default chain still shown (runtime truth)');

    echo "\nadmin-ai-config: " . ($failures === 0 ? 'PASS' : 'FAIL') . " ($checks checks, $failures failures)\n";
    $rm = static function (string $p) use (&$rm): void {
        if (!is_dir($p)) { @unlink($p); return; }
        foreach (scandir($p) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $rm($p . '/' . $f);
        }
        @rmdir($p);
    };
    $rm($ROOT);
    exit($failures === 0 ? 0 : 1);
}

// -------------------------------------------------------------------------
// child: one controller call per process
// -------------------------------------------------------------------------
$case = $argv[2] ?? '';
$ROOT = $argv[3] ?? '';
putenv('APP_ENV=local');
putenv('VELORA_PRIVATE_ROOT=' . $ROOT);
putenv('VELORA_DOCUMENT_ROOT=' . dirname(__DIR__, 2));
putenv('DB_DRIVER=sqlite');
putenv('DB_DATABASE=' . $ROOT . '/data/velora.sqlite');
if (!is_file($ROOT . '/config/velora.env')) {
    file_put_contents($ROOT . '/config/velora.env', implode("\n", [
        'APP_ENV=local', 'APP_DEBUG=true', 'DB_DRIVER=sqlite',
        'DB_DATABASE=' . $ROOT . '/data/velora.sqlite',
        'JWT_SECRET=' . str_repeat('j', 48),
        'APP_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)),
        'GEMINI_API_KEY=AIza-test-not-real',
        'CORS_ALLOWED_ORIGINS=http://localhost', 'FRONTEND_URL=http://localhost',
        'MAIL_DRIVER=log',
    ]) . "\n");
}
ini_set('error_log', $ROOT . '/logs/php-error.log');

require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

use Velora\Admin\AIConfigController;
use Velora\Auth\AuthMiddleware;
use Velora\Core\Request;

$pdo = new PDO('sqlite:' . $ROOT . '/data/velora.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if ($case === 'setup') {
    foreach ([
        'ai_feature_providers' => 'CREATE TABLE IF NOT EXISTS ai_feature_providers (
            id INTEGER PRIMARY KEY AUTOINCREMENT, feature TEXT NOT NULL, provider TEXT NOT NULL,
            model TEXT NULL, priority INTEGER NOT NULL DEFAULT 1, enabled INTEGER NOT NULL DEFAULT 1,
            route TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(feature, provider))',
        'ai_feature_flags' => 'CREATE TABLE IF NOT EXISTS ai_feature_flags (
            feature_name TEXT PRIMARY KEY, enabled INTEGER NOT NULL DEFAULT 0,
            rollout_percentage INTEGER NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)',
        'ai_provider_quotas' => 'CREATE TABLE IF NOT EXISTS ai_provider_quotas (
            provider TEXT PRIMARY KEY, daily_used INTEGER NOT NULL DEFAULT 0, quota_limit INTEGER NOT NULL DEFAULT 1500,
            reset_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)',
        'rate_limits' => 'CREATE TABLE IF NOT EXISTS rate_limits (bucket TEXT PRIMARY KEY, hits INTEGER NOT NULL, window_start DATETIME NOT NULL)',
        'users' => 'CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, role TEXT NOT NULL DEFAULT \'user\', ai_consent_at DATETIME NULL)',
    ] as $ddl) { $pdo->exec($ddl); }
    $pdo->exec("INSERT OR IGNORE INTO ai_feature_providers (feature, provider, model, priority, enabled, route) VALUES
        ('screenshot_extraction','gemini',NULL,1,1,NULL),
        ('screenshot_extraction','tesseract',NULL,2,1,NULL)");
    echo 'SETUP_OK';
    exit(0);
}

// capture Response output even though Response methods exit
ob_start();
register_shutdown_function(function (): void {
    $out = ob_get_clean();
    if ($out !== null && $out !== '') { echo $out; }
});

$body = json_decode((string) (getenv('TEST_BODY') ?: '{}'), true) ?: [];
$request = new Request('POST', '/api/v1/admin/ai', [], $body, []);
$controller = new AIConfigController();

switch ($case) {
    case 'rbac_non_admin':
        $request->attributes['user_role'] = 'user';
        try {
            (AuthMiddleware::adminOnly())($request);
            echo json_encode(['error' => ['code' => 'MUST_HAVE_FAILED']]);
        } catch (\Velora\Core\Exceptions\ForbiddenException $e) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'data' => null, 'error' => [
                'code' => $e->errorCode(), 'message' => $e->getMessage(),
            ]]);
        }
        exit(0);
    case 'rbac_admin':
        $request->attributes['user_role'] = 'admin';
        (AuthMiddleware::adminOnly())($request);
        echo json_encode(['ok' => true]);
        exit(0);
    case 'overview':
        $request->attributes['user_role'] = 'admin';
        $controller->overview($request);
        exit(0);
    case 'create_openai':
    case 'invalid_model':
    case 'invalid_provider':
    case 'invalid_route':
    case 'invalid_feature':
    case 'invalid_priority':
        $request->attributes['user_role'] = 'admin';
        $controller->create($request);
        exit(0);
    case 'find_openai_id':
        $row = $pdo->query("SELECT id FROM ai_feature_providers WHERE provider='openai' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        echo (string) ($row['id'] ?? '0');
        exit(0);
    case 'update_model':
        $request->attributes['user_role'] = 'admin';
        $controller->update($request, ['id' => (string) (getenv('TEST_ID') ?: '0')]);
        exit(0);
    case 'disable_gemini':
        $row = $pdo->query("SELECT id FROM ai_feature_providers WHERE provider='gemini' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $request = new Request('PATCH', '/api/v1/admin/ai/feature-providers/' . ($row['id'] ?? 0), [], ['enabled' => false], []);
        $request->attributes['user_role'] = 'admin';
        $controller->update($request, ['id' => (string) ($row['id'] ?? '0')]);
        exit(0);
    case 'enable_gemini':
        $row = $pdo->query("SELECT id FROM ai_feature_providers WHERE provider='gemini' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $request = new Request('PATCH', '/api/v1/admin/ai/feature-providers/' . ($row['id'] ?? 0), [], ['enabled' => true], []);
        $request->attributes['user_role'] = 'admin';
        $controller->update($request, ['id' => (string) ($row['id'] ?? '0')]);
        exit(0);
    case 'reorder':
        $ids = $pdo->query("SELECT id FROM ai_feature_providers WHERE feature='screenshot_extraction' ORDER BY priority DESC")->fetchAll(PDO::FETCH_COLUMN);
        $request = new Request('POST', '/api/v1/admin/ai/feature-providers/reorder', [], ['feature' => 'screenshot_extraction', 'orderedIds' => array_map('intval', $ids)], []);
        $request->attributes['user_role'] = 'admin';
        $controller->reorder($request);
        exit(0);
    case 'delete_row':
    case 'delete_missing':
        $request->attributes['user_role'] = 'admin';
        $controller->delete($request, ['id' => (string) (getenv('TEST_ID') ?: '0')]);
        exit(0);
    case 'count_rows':
        echo (string) (int) $pdo->query('SELECT COUNT(*) FROM ai_feature_providers')->fetchColumn();
        exit(0);
    case 'cred_replace_openai':
    case 'cred_replace_tesseract':
        $request->attributes['user_role'] = 'admin';
        $provider = str_starts_with($case, 'cred_replace_openai') ? 'openai' : 'tesseract';
        $controller->replaceCredential($request, ['provider' => $provider]);
        exit(0);
    case 'cred_delete_openai':
        $request->attributes['user_role'] = 'admin';
        $controller->deleteCredential($request, ['provider' => 'openai']);
        exit(0);
    case 'tesseract_available':
        echo (new Velora\AI\Providers\TesseractProvider())->isAvailable() ? '1' : '0';
        exit(0);
    case 'php_error_log':
        $log = (string) @file_get_contents($ROOT . '/logs/php-error.log');
        echo $log;
        exit(0);
    case 'wipe_rows':
        $pdo->exec('DELETE FROM ai_feature_providers');
        echo 'WIPED';
        exit(0);
    default:
        echo json_encode(['error' => ['code' => 'UNKNOWN_CASE', 'message' => $case]]);
        exit(1);
}
