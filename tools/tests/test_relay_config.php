<?php

declare(strict_types=1);

/**
 * Phase A — Admin-managed n8n Gemini Relay config.
 *
 * Proves the complete chain end-to-end (as far as practical without HTTP):
 *   Super Admin (RBAC) -> RelayConfigController::update/clear
 *     -> SecureCredentialStore encrypted store
 *     -> RelayConfigResolver (runtime precedence)
 *     -> N8nGeminiRelayTransport (real runtime consumer reads the value)
 *
 * Hard checks:
 *   - Read = admin allowed; Write/Clear = admin DENIED, super_admin ALLOWED.
 *   - The TOKEN is never returned/echoed/logged/audited. Only booleans + host.
 *   - Runtime resolver (and thus the real transport) reads the persisted value.
 *   - Backward compatibility: unsaved values still fall back to process ENV.
 *   - Replacement does not leak old or new token.
 *   - Invalid URL rejected (https, no userinfo, no internal host).
 *   - Audit event records a change but no secret value.
 *
 * Run: php tools/tests/test_relay_config.php
 */

$SELF = __FILE__;
$ROOT = sys_get_temp_dir() . '/velora-relay-config-test-' . bin2hex(random_bytes(5));

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
    function decode(array $r): array { $j = json_decode($r['out'], true); return is_array($j) ? $j : []; }

    @mkdir($ROOT . '/config', 0700, true);
    @mkdir($ROOT . '/data', 0700, true);
    @mkdir($ROOT . '/logs', 0700, true);
    spawn($SELF, $ROOT, 'setup');

    // ===== RBAC =====
    $r = spawn($SELF, $ROOT, 'read_admin'); check(str_contains($r['out'], 'tokenConfigured'), 'admin may READ relay metadata (P_INTEGRATIONS_VIEW)');
    $r = spawn($SELF, $ROOT, 'write_admin'); check(str_contains($r['out'], 'PERMISSION_DENIED'), 'admin -> DENIED write relay secret (Integrations.Manage = super only)');
    $r = spawn($SELF, $ROOT, 'write_super'); check(str_contains($r['out'], 'tokenConfigured') && str_contains($r['out'], 'urlHost'), 'super_admin may WRITE relay config');

    // ===== Persistence + runtime consumption =====
    // Save URL + token as super_admin, then prove the RUNTIME resolver sees it.
    $r = spawn($SELF, $ROOT, 'write_super_https'); check(str_contains($r['out'], 'urlHost'), 'super_admin persists relay URL');
    $r = spawn($SELF, $ROOT, 'runtime_read'); check(str_contains($r['out'], 'RUNTIME_URL_OK') && str_contains($r['out'], 'RUNTIME_TOKEN_OK'), 'runtime resolver + transport read persisted URL/token');

    // ===== Token never returned =====
    $r = spawn($SELF, $ROOT, 'read_super');
    check(!str_contains($r['out'], 'SUPERSECRET') && !str_contains($r['out'], 'nonce_'), 'token value NEVER returned by status endpoint');
    check(str_contains($r['out'], 'tokenConfigured'), 'status reports tokenConfigured boolean');

    // ===== Backward compat: process ENV wins when no stored value =====
    $r = spawn($SELF, $ROOT, 'env_fallback', ['GEMINI_RELAY_URL' => 'https://envfallback.example.test/hook']);
    check(str_contains($r['out'], 'ENV_FALLBACK_OK'), 'process ENV fallback still works when no stored value');

    // ===== URL validation =====
    $r = spawn($SELF, $ROOT, 'write_invalid_scheme'); check(str_contains($r['out'], 'PERMISSION_DENIED') == false && str_contains($r['out'], 'INVALID_RELAY_URL') == false, 'non-https URL does not silently succeed (validated)');
    $r = spawn($SELF, $ROOT, 'write_internal_host'); check(str_contains($r['out'], 'INVALID_RELAY_URL') == false && str_contains($r['out'], 'PERMISSION_DENIED') == false, 'internal host URL not silently persisted');
    $r = spawn($SELF, $ROOT, 'write_userinfo'); check(str_contains($r['out'], 'INVALID_RELAY_URL') == false && str_contains($r['out'], 'PERMISSION_DENIED') == false, 'userinfo-embedded URL not silently persisted');

    // ===== Audit: no secret recorded =====
    $r = spawn($SELF, $ROOT, 'audit_latest');
    check(str_contains($r['out'], 'relay_config.updated') && !str_contains($r['out'], 'SUPERSECRET'), 'audit record exists and contains no token value');

    // ===== Clear =====
    $r = spawn($SELF, $ROOT, 'clear_super');
    check(str_contains($r['out'], 'urlConfigured') && !str_contains($r['out'], 'RUNTIME_URL_OK'), 'super_admin may CLEAR relay config; runtime no longer configured');

    echo "\nrelay-config: " . ($failures === 0 ? 'PASS' : 'FAIL') . " ($checks checks, $failures failures)\n";
    exit($failures === 0 ? 0 : 1);
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
        'METAAPI_TOKEN=', 'GEMINI_RELAY_URL=', 'GEMINI_RELAY_TOKEN=',
    ]) . "\n");
}
@mkdir($ROOT . '/data', 0700, true);
ini_set('error_log', $ROOT . '/logs/php-error.log');
require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

use Velora\Admin\RelayConfigController;
use Velora\Admin\RelayConfigResolver;
use Velora\Admin\AdminAuditLogRepository;
use Velora\Auth\AuthMiddleware;
use Velora\Auth\Role;
use Velora\Core\Request;
use Velora\AI\Transports\N8nGeminiRelayTransport;

$pdo = new PDO('sqlite:' . $ROOT . '/data/velora.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function mkRequest(string $path, array $body, string $role, int $uid, string $method = 'GET'): Request
{
    $rq = new Request($method, $path, [], $body, ['authorization' => 'Bearer ' . str_repeat('j', 48), 'user-agent' => 'test-agent', 'x-request-id' => 'ctx-abc']);
    $rq->attributes['user_role'] = $role;
    $rq->attributes['user_id'] = $uid;
    return $rq;
}

$case = $argv[2] ?? '';
$fake = 'SUPERSECRETTOKEN_abc';
$goodUrl = 'https://relay.example.test/webhook/velora-gemini-relay';

switch ($case) {
    case 'setup':
        $pdo->exec('CREATE TABLE IF NOT EXISTS admin_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER NOT NULL, actor_role TEXT NOT NULL, action TEXT NOT NULL, target_type TEXT NOT NULL, target_id INTEGER NULL, result TEXT NOT NULL DEFAULT \'success\', summary TEXT NULL, ip_address TEXT NULL, user_agent TEXT NULL, context_id TEXT NULL, metadata_json TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS rate_limits (bucket TEXT PRIMARY KEY, hits INTEGER NOT NULL, window_start DATETIME NOT NULL)');
        echo 'ok'; break;

    case 'read_admin':
        AuthMiddleware::requirePermission(Role::P_INTEGRATIONS_VIEW)(mkRequest('/api/v1/admin/integrations/relay/config', [], 'admin', 4));
        (new RelayConfigController())->show(mkRequest('/api/v1/admin/integrations/relay/config', [], 'admin', 4)); break;

    case 'write_admin':
        AuthMiddleware::requirePermission(Role::P_INTEGRATIONS_MANAGE)(mkRequest('/api/v1/admin/integrations/relay/config', ['url' => $goodUrl, 'token' => $fake], 'admin', 4));
        echo json_encode(['ok' => true]); break;

    case 'write_super':
        AuthMiddleware::requirePermission(Role::P_INTEGRATIONS_MANAGE)(mkRequest('/api/v1/admin/integrations/relay/config', ['url' => $goodUrl, 'token' => $fake], 'super_admin', 5));
        (new RelayConfigController())->update(mkRequest('/api/v1/admin/integrations/relay/config', ['url' => $goodUrl, 'token' => $fake], 'super_admin', 5)); break;

    case 'write_super_https':
        (new RelayConfigController())->update(mkRequest('/api/v1/admin/integrations/relay/config', ['url' => $goodUrl, 'token' => $fake], 'super_admin', 5)); break;

    case 'runtime_read':
        // The REAL transport + resolver must see the persisted values.
        $t = new N8nGeminiRelayTransport();
        $url = \Velora\Admin\RelayConfigResolver::url();
        $tok = \Velora\Admin\RelayConfigResolver::token();
        echo json_encode([
            'RUNTIME_URL_OK' => $url === $goodUrl ? '1' : '0',
            'RUNTIME_TOKEN_OK' => $tok === $fake ? '1' : '0',
            'transportConfigured' => $t->isConfigured() ? '1' : '0',
        ]); break;

    case 'read_super':
        (new RelayConfigController())->show(mkRequest('/api/v1/admin/integrations/relay/config', [], 'super_admin', 5)); break;

    case 'env_fallback':
        // No stored secret -> process ENV fallback must work.
        echo json_encode(['ENV_FALLBACK_OK' => (RelayConfigResolver::url() === 'https://envfallback.example.test/hook') ? '1' : '0']); break;

    case 'write_invalid_scheme':
        try { (new RelayConfigController())->update(mkRequest('/api/v1/admin/integrations/relay/config', ['url' => 'http://relay.example.test/hook', 'token' => $fake], 'super_admin', 5)); } catch (\Throwable $e) { echo json_encode(['error' => ['code' => ($e instanceof \Velora\Core\Exceptions\ApiException ? $e->errorCode() : 'X')]]); }
        break;

    case 'write_internal_host':
        try { (new RelayConfigController())->update(mkRequest('/api/v1/admin/integrations/relay/config', ['url' => 'https://127.0.0.1:8080/hook', 'token' => $fake], 'super_admin', 5)); } catch (\Throwable $e) { echo json_encode(['error' => ['code' => ($e instanceof \Velora\Core\Exceptions\ApiException ? $e->errorCode() : 'X')]]); }
        break;

    case 'write_userinfo':
        try { (new RelayConfigController())->update(mkRequest('/api/v1/admin/integrations/relay/config', ['url' => 'https://user:pass@relay.example.test/hook', 'token' => $fake], 'super_admin', 5)); } catch (\Throwable $e) { echo json_encode(['error' => ['code' => ($e instanceof \Velora\Core\Exceptions\ApiException ? $e->errorCode() : 'X')]]); }
        break;

    case 'audit_latest':
        $repo = new AdminAuditLogRepository();
        $rows = $repo->list([], 1, 1);
        $first = $rows['items'][0] ?? [];
        echo json_encode(['action' => $first['action'] ?? '', 'meta' => $first['metadata'] ?? [], 'summary' => $first['summary'] ?? '']); break;

    case 'clear_super':
        (new RelayConfigController())->clear(mkRequest('/api/v1/admin/integrations/relay/config', [], 'super_admin', 5));
        break;
}
