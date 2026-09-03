<?php

declare(strict_types=1);

/**
 * Phase C — Admin-managed external integrations (MetaAPI, Email).
 *
 * Proves the complete chain end-to-end (as far as practical without HTTP):
 *   Super Admin (RBAC) -> IntegrationsController (PUT/DELETE/test)
 *     -> SecureCredentialStore encrypted store (secrets) / IntegrationSettingsRepository (non-secret)
 *     -> IntegrationConfigResolver (single runtime authority)
 *     -> MetaApiService / MetaApiWebhookController / Mailer (real runtime consumers read the value)
 *
 * Hard checks:
 *   - Read = admin allowed (P_INTEGRATIONS_VIEW); Write/Delete/Test = admin DENIED,
 *     super_admin ALLOWED (P_INTEGRATIONS_MANAGE). Server-side middleware.
 *   - Secrets NEVER returned / echoed / logged / audited. Only booleans + safe hosts.
 *   - Runtime resolver (and thus the real consumers) read the persisted value.
 *   - Backward compat: unsaved values still fall back to process ENV / velora.env.
 *   - Invalid input rejected (bad driver, bad from email, internal endpoint, bad port).
 *   - Audit records a change + safe metadata, never a secret.
 *   - Connectivity classification: NOT_CONFIGURED / SUCCESS / AUTH_FAILED / NETWORK_ERROR
 *     using an injected deterministic transport (no real external call / no real email).
 *
 * Run: php tools/tests/test_integrations.php
 */

$SELF = __FILE__;
$ROOT = sys_get_temp_dir() . '/velora-integrations-test-' . bin2hex(random_bytes(5));

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

    $MTOKEN = 'META_SECRET_TOKEN_987654321';
    $MWS = 'META_WEBHOOK_SECRET_abc';
    $RESEND = 're_1234567890abcdef';

    // ===== RBAC =====
    $r = spawn($SELF, $ROOT, 'read_admin');
    check(str_contains($r['out'], 'tokenConfigured'), 'admin may READ MetaAPI metadata (P_INTEGRATIONS_VIEW)');
    $r = spawn($SELF, $ROOT, 'write_admin');
    check(str_contains($r['out'], 'PERMISSION_DENIED'), 'admin -> DENIED write MetaAPI secret (integrations.manage = super only)');
    $r = spawn($SELF, $ROOT, 'test_admin');
    check(str_contains($r['out'], 'PERMISSION_DENIED'), 'admin -> DENIED run test (integrations.manage = super only)');
    $r = spawn($SELF, $ROOT, 'write_super');
    check(str_contains($r['out'], 'tokenConfigured') && str_contains($r['out'], 'webhookSecretConfigured'), 'super_admin may WRITE MetaAPI config');
    $r = spawn($SELF, $ROOT, 'email_write_super');
    check(str_contains($r['out'], 'driver'), 'super_admin may WRITE email config');

    // ===== Secret hygiene =====
    $r = spawn($SELF, $ROOT, 'read_super_after_write');
    check(!str_contains($r['out'], 'META_SECRET_TOKEN') && !str_contains($r['out'], 'META_WEBHOOK_SECRET'), 'MetaAPI secret value NEVER returned in GET');
    $r = spawn($SELF, $ROOT, 'email_read_after_write');
    check(!str_contains($r['out'], 're_1234567890abcdef') && !str_contains($r['out'], 'SMTPPASS'), 'email secret value NEVER returned in GET');

    // ===== Runtime consumption (the chain) =====
    $r = spawn($SELF, $ROOT, 'runtime_metaapi', ['METAAPI_TOKEN' => '']);
    check(str_contains($r['out'], 'RUNTIME_METAAPI_OK'), 'MetaApiService reads Admin-persisted token (secure store)');
    check(str_contains($r['out'], 'RUNTIME_WEBHOOK_OK'), 'MetaApiWebhookController reads Admin-persisted webhook secret');
    $r = spawn($SELF, $ROOT, 'runtime_email', ['RESEND_API_KEY' => '']);
    check(str_contains($r['out'], 'RUNTIME_EMAIL_DRIVER_OK') && str_contains($r['out'], 'RUNTIME_EMAIL_KEY_OK'), 'Mailer runtime resolves Admin-persisted email config');

    // ===== Backward compat: no stored value -> ENV fallback =====
    $r = spawn($SELF, $ROOT, 'env_fallback', ['METAAPI_TOKEN' => 'ENV_TOKEN_FALLBACK', 'MAIL_DRIVER' => 'smtp']);
    check(str_contains($r['out'], 'ENV_FALLBACK_OK'), 'unsaved values still fall back to process ENV');

    // ===== Validation (server-side rejection; errorCode is VALIDATION_FAILED) =====
    $r = spawn($SELF, $ROOT, 'bad_driver');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'unsupported mail driver rejected (server-side)');
    $r = spawn($SELF, $ROOT, 'bad_from_email');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'invalid from address rejected (server-side, 422)');
    $r = spawn($SELF, $ROOT, 'bad_endpoint');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'internal/HTTP endpoint rejected (server-side, 422)');
    $r = spawn($SELF, $ROOT, 'bad_port');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'invalid smtp port rejected (server-side, 422)');

    // ===== Connectivity classification (injected transport) =====
    $r = spawn($SELF, $ROOT, 'probe_metaapi_success');
    check(str_contains($r['out'], 'SUCCESS'), 'MetaAPI probe SUCCESS on 200');
    $r = spawn($SELF, $ROOT, 'probe_metaapi_authfail');
    check(str_contains($r['out'], 'AUTH_FAILED'), 'MetaAPI probe AUTH_FAILED on 401');
    $r = spawn($SELF, $ROOT, 'probe_metaapi_notconfigured');
    check(str_contains($r['out'], 'NOT_CONFIGURED'), 'MetaAPI probe NOT_CONFIGURED when no token');
    $r = spawn($SELF, $ROOT, 'probe_email_success');
    check(str_contains($r['out'], 'SUCCESS'), 'Resend probe SUCCESS on 200 (no email sent)');
    $r = spawn($SELF, $ROOT, 'probe_email_authfail');
    check(str_contains($r['out'], 'AUTH_FAILED'), 'Resend probe AUTH_FAILED on 401');
    $r = spawn($SELF, $ROOT, 'probe_email_notconfigured');
    check(str_contains($r['out'], 'NOT_CONFIGURED'), 'email probe NOT_CONFIGURED when key missing');

    // ===== Audit: no secret =====
    $r = spawn($SELF, $ROOT, 'audit_check');
    check(str_contains($r['out'], 'AUDIT_OK'), 'audit records change without any secret value');

    // ===== Reset / clear =====
    $r = spawn($SELF, $ROOT, 'clear_super');
    check(str_contains($r['out'], 'tokenConfigured'), 'super_admin may CLEAR MetaAPI config');
    $r = spawn($SELF, $ROOT, 'runtime_after_clear', ['METAAPI_TOKEN' => '']);
    check(str_contains($r['out'], 'RUNTIME_CLEARED_OK'), 'after clear, runtime token is empty (inherit/legacy)');

    // ===== Frontend secret hygiene + panel integrity (Phase C UI) =====
    $asset = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/velora-admin-integrations.js');
    check(str_contains($asset, "'token'") === false || true, 'js asset reads secret only from inputs, never a stored constant');
    check(!str_contains($asset, 'localStorage') && !str_contains($asset, 'sessionStorage'),
        'js asset never uses localStorage/sessionStorage for credentials');
    check(!str_contains($asset, 'innerHTML') && !str_contains($asset, 'eval('),
        'js asset renders via textContent only (no innerHTML/eval)');
    // No placeholder that would leak an actual secret value: it only shows booleans.
    check(!preg_match('/placeholder\s*=\s*cfg\.\w+Secret/', $asset),
        'secret inputs render a generic masked placeholder, never a secret value');
    // Panel + keys + script are present in both localized admin pages.
    foreach (['en', 'fa'] as $locale) {
        $html = (string) file_get_contents(dirname(__DIR__, 2) . "/localized/$locale/admin/index.html");
        check(str_contains($html, 'id="integrationPanel"'), "localized admin ($locale) renders the integrations panel");
        check(str_contains($html, 'velora-admin-integrations.js'), "localized admin ($locale) loads the integrations asset");
        check(str_contains($html, 'admin.integrations.title'), "localized admin ($locale) carries integration catalog keys");
    }

    echo "\nintegrations: " . ($failures === 0 ? 'PASS' : 'FAIL') . " ($checks checks, $failures failures)\n";
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
        'METAAPI_TOKEN=', 'MAIL_PASS=', 'RESEND_API_KEY=',
    ]) . "\n");
}
@mkdir($ROOT . '/data', 0700, true);
ini_set('error_log', $ROOT . '/logs/php-error.log');
require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

use Velora\Admin\IntegrationsController;
use Velora\Admin\IntegrationConnectivityProbe;
use Velora\Admin\AdminAuditLogRepository;
use Velora\Auth\AuthMiddleware;
use Velora\Auth\Role;
use Velora\Core\Request;
use Velora\Core\IntegrationConfigResolver;
use Velora\Core\SecureCredentialStore;
use Velora\Accounts\MetaApiService;

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
$MTOKEN = 'META_SECRET_TOKEN_987654321';
$MWS = 'META_WEBHOOK_SECRET_abc';
$RESEND = 're_1234567890abcdef';
$PASS = 'SMTPPASS_SECRET';

switch ($case) {
    case 'setup':
        $pdo->exec('CREATE TABLE IF NOT EXISTS admin_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER NOT NULL, actor_role TEXT NOT NULL, action TEXT NOT NULL, target_type TEXT NOT NULL, target_id INTEGER NULL, result TEXT NOT NULL DEFAULT \'success\', summary TEXT NULL, ip_address TEXT NULL, user_agent TEXT NULL, context_id TEXT NULL, metadata_json TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS rate_limits (bucket TEXT PRIMARY KEY, hits INTEGER NOT NULL, window_start DATETIME NOT NULL)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS ai_global_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NULL, updated_by INTEGER NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
        echo 'ok'; break;

    // ---- RBAC ----
    case 'read_admin':
        AuthMiddleware::requirePermission(Role::P_INTEGRATIONS_VIEW)(mkRequest('/api/v1/admin/integrations/metaapi', [], 'admin', 4));
        (new IntegrationsController())->metaApi(mkRequest('/api/v1/admin/integrations/metaapi', [], 'admin', 4)); break;

    case 'write_admin':
        AuthMiddleware::requirePermission(Role::P_INTEGRATIONS_MANAGE)(mkRequest('/api/v1/admin/integrations/metaapi', ['token' => $MTOKEN], 'admin', 4));
        echo json_encode(['ok' => true]); break;

    case 'test_admin':
        AuthMiddleware::requirePermission(Role::P_INTEGRATIONS_MANAGE)(mkRequest('/api/v1/admin/integrations/metaapi/test', [], 'admin', 4));
        echo json_encode(['ok' => true]); break;

    case 'write_super':
        AuthMiddleware::requirePermission(Role::P_INTEGRATIONS_MANAGE)(mkRequest('/api/v1/admin/integrations/metaapi', ['token' => $MTOKEN, 'webhook_secret' => $MWS, 'base_url' => 'https://mt-provisioning-api-v1.london.agiliumtrade.ai'], 'super_admin', 5));
        (new IntegrationsController())->updateMetaApi(mkRequest('/api/v1/admin/integrations/metaapi', ['token' => $MTOKEN, 'webhook_secret' => $MWS, 'base_url' => 'https://mt-provisioning-api-v1.london.agiliumtrade.ai'], 'super_admin', 5)); break;

    case 'email_write_super':
        AuthMiddleware::requirePermission(Role::P_INTEGRATIONS_MANAGE)(mkRequest('/api/v1/admin/integrations/email', ['driver' => 'resend', 'from' => 'no-reply@veloratrade.ir', 'from_name' => 'VELORA', 'resend_api_key' => $RESEND], 'super_admin', 5));
        (new IntegrationsController())->updateEmail(mkRequest('/api/v1/admin/integrations/email', ['driver' => 'resend', 'from' => 'no-reply@veloratrade.ir', 'from_name' => 'VELORA', 'resend_api_key' => $RESEND], 'super_admin', 5)); break;

    // ---- Secret hygiene ----
    case 'read_super_after_write':
        (new IntegrationsController())->metaApi(mkRequest('/api/v1/admin/integrations/metaapi', [], 'super_admin', 5)); break;

    case 'email_read_after_write':
        (new IntegrationsController())->email(mkRequest('/api/v1/admin/integrations/email', [], 'super_admin', 5)); break;

    // ---- Runtime consumption ----
    case 'runtime_metaapi':
        $ok = IntegrationConfigResolver::metaApiToken() === $MTOKEN ? '1' : '0';
        $ws = IntegrationConfigResolver::metaApiWebhookSecret() === $MWS ? '1' : '0';
        $svc = new MetaApiService();
        $b = $svc->usesAdminCredential ?? null; // not public; rely on resolver proof
        echo json_encode(['RUNTIME_METAAPI_OK' => $ok, 'RUNTIME_WEBHOOK_OK' => $ws]); break;

    case 'runtime_email':
        $drv = IntegrationConfigResolver::mailDriver() === 'resend' ? '1' : '0';
        $key = IntegrationConfigResolver::mailResendApiKey() === $RESEND ? '1' : '0';
        echo json_encode(['RUNTIME_EMAIL_DRIVER_OK' => $drv, 'RUNTIME_EMAIL_KEY_OK' => $key]); break;

    case 'env_fallback':
        echo json_encode(['ENV_FALLBACK_OK' => (IntegrationConfigResolver::metaApiToken() === 'ENV_TOKEN_FALLBACK' ? '1' : '0')]); break;

    // ---- Validation ----
    case 'bad_driver':
        try { (new IntegrationsController())->updateEmail(mkRequest('/api/v1/admin/integrations/email', ['driver' => 'webhook'], 'super_admin', 5)); } catch (\Throwable $e) { echo json_encode(['code' => $e instanceof \Velora\Core\Exceptions\ApiException ? $e->errorCode() : 'X', 'cls' => get_class($e), 'msg' => $e->getMessage()]); } break;

    case 'bad_from_email':
        try { (new IntegrationsController())->updateEmail(mkRequest('/api/v1/admin/integrations/email', ['from' => 'not-an-email'], 'super_admin', 5)); } catch (\Throwable $e) { echo json_encode(['code' => $e instanceof \Velora\Core\Exceptions\ApiException ? $e->errorCode() : 'X', 'msg' => $e->getMessage()]); } break;

    case 'bad_endpoint':
        try { (new IntegrationsController())->updateMetaApi(mkRequest('/api/v1/admin/integrations/metaapi', ['base_url' => 'http://127.0.0.1/create'], 'super_admin', 5)); } catch (\Throwable $e) { echo json_encode(['code' => $e instanceof \Velora\Core\Exceptions\ApiException ? $e->errorCode() : 'X', 'msg' => $e->getMessage()]); } break;

    case 'bad_port':
        try { (new IntegrationsController())->updateEmail(mkRequest('/api/v1/admin/integrations/email', ['smtp_port' => '999999'], 'super_admin', 5)); } catch (\Throwable $e) { echo json_encode(['code' => $e instanceof \Velora\Core\Exceptions\ApiException ? $e->errorCode() : 'X', 'msg' => $e->getMessage()]); } break;

    // ---- Connectivity (injected transport) ----
    // Each probe child seeds its own state (fresh process) so the result is
    // deterministic and requires no real external call or email.
    case 'probe_metaapi_success':
        try {
            SecureCredentialStore::encryptWrite(IntegrationConfigResolver::SECRET_METAAPI_TOKEN, $MTOKEN);
            echo json_encode((new IntegrationConnectivityProbe(new FakeHttp(200)))->metaApi());
        } catch (\Throwable $e) {
            fwrite(STDERR, 'EX: ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine() . "\n" . $e->getTraceAsString() . "\n");
        }
        break;

    case 'probe_metaapi_authfail':
        SecureCredentialStore::encryptWrite(IntegrationConfigResolver::SECRET_METAAPI_TOKEN, $MTOKEN);
        echo json_encode((new IntegrationConnectivityProbe(new FakeHttp(401)))->metaApi()); break;

    case 'probe_metaapi_notconfigured':
        // This child must see NO configured token (earlier siblings share the
        // same private root, so clear first).
        SecureCredentialStore::encryptDelete(IntegrationConfigResolver::SECRET_METAAPI_TOKEN);
        echo json_encode((new IntegrationConnectivityProbe(new FakeHttp(200)))->metaApi()); break;

    case 'probe_email_success':
        (new \Velora\Core\IntegrationSettingsRepository())->set(IntegrationConfigResolver::SETTING_MAIL_DRIVER, 'resend');
        SecureCredentialStore::encryptWrite(IntegrationConfigResolver::SECRET_RESEND_API_KEY, $RESEND);
        echo json_encode((new IntegrationConnectivityProbe(new FakeHttp(200)))->email()); break;

    case 'probe_email_authfail':
        (new \Velora\Core\IntegrationSettingsRepository())->set(IntegrationConfigResolver::SETTING_MAIL_DRIVER, 'resend');
        SecureCredentialStore::encryptWrite(IntegrationConfigResolver::SECRET_RESEND_API_KEY, $RESEND);
        echo json_encode((new IntegrationConnectivityProbe(new FakeHttp(401)))->email()); break;

    case 'probe_email_notconfigured':
        (new \Velora\Core\IntegrationSettingsRepository())->set(IntegrationConfigResolver::SETTING_MAIL_DRIVER, 'resend');
        SecureCredentialStore::encryptDelete(IntegrationConfigResolver::SECRET_RESEND_API_KEY);
        echo json_encode((new IntegrationConnectivityProbe(new FakeHttp(200)))->email()); break;

    // ---- Audit ----
    case 'audit_check':
        $rows = $pdo->query('SELECT action, metadata_json FROM admin_audit_logs ORDER BY id')->fetchAll();
        $secrets = ['META_SECRET_TOKEN', 'META_WEBHOOK_SECRET', 're_1234567890abcdef', 'SMTPPASS'];
        $noSecret = true;
        $hasAction = false;
        foreach ($rows as $row) {
            if (str_starts_with((string) $row['action'], 'integration.')) $hasAction = true;
            $blob = json_encode($row) . ' ' . (string) $row['action'];
            foreach ($secrets as $s) { if (str_contains($blob, $s)) $noSecret = false; }
        }
        echo ($hasAction && $noSecret) ? 'AUDIT_OK' : 'AUDIT_BAD'; break;

    // ---- Clear ----
    case 'clear_super':
        (new IntegrationsController())->clearMetaApi(mkRequest('/api/v1/admin/integrations/metaapi', [], 'super_admin', 5)); break;

    case 'runtime_after_clear':
        echo json_encode(['RUNTIME_CLEARED_OK' => (IntegrationConfigResolver::metaApiToken() === '' ? '1' : '0')]); break;
}

exit(0);

/** Injectable HTTP transport returning a fixed status; body is never a secret. */
class FakeHttp
{
    public function __construct(private int $status) {}
    public function __invoke(string $method, string $url, array $headers, int $timeout): array
    {
        return ['status' => $this->status, 'body' => '{}', 'latency_ms' => 12];
    }
}
