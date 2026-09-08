<?php

declare(strict_types=1);

use Velora\Admin\SettingsController;
use Velora\Auth\AuthMiddleware;
use Velora\Auth\Role;
use Velora\Auth\UserRepository;
use Velora\Core\Exceptions\ApiException;
use Velora\Core\PlatformSettings;
use Velora\Core\RateLimiter;
use Velora\Core\Request;

/**
 * Phase 8 (8a) — Settings Write RBAC + behavior harness.
 *
 * Covers: GET /admin/settings (settings.view read), PUT/DELETE /admin/settings/{key}
 * (reserved system.settings.manage — super_admin ONLY: plain admin explicitly 403
 * PERMISSION_DENIED), guest 401, unknown/secret-shaped keys rejected (allowlist),
 * malformed values rejected, reset-to-env semantics, env override semantics of
 * PlatformSettings::defaultLocale() (DB > ENV > default, invalid fails safe),
 * UserRepository signup-locale consumption (identity when unset), audit rows
 * (settings.updated / settings.reset with before/after, NO secrets), payload
 * secret sweep, rate limiting, and route-registration statics.
 *
 * Run: php tools/tests/test_admin_settings_rbac.php
 * (child-process per case — Response::json exits the process)
 */

$SELF = __FILE__;
$ROOT = sys_get_temp_dir() . '/velora-p8-test-' . bin2hex(random_bytes(5));

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

    $r = spawn($SELF, $ROOT, 'setup');
    check($r['code'] === 0 && str_contains($r['out'], '"ok"'), 'test setup child completed cleanly');

    // ===== authorization (real middleware closures + controller) =====
    $r = spawn($SELF, $ROOT, 'get_guest');
    check(str_contains($r['out'], 'ACCESS_TOKEN_MISSING') || str_contains($r['out'], 'UNAUTHORIZED'), 'GET /admin/settings unauthenticated -> 401');
    $r = spawn($SELF, $ROOT, 'get_user');
    check(str_contains($r['out'], 'ADMIN_REQUIRED'), 'plain user GET /admin/settings -> 403 ADMIN_REQUIRED');
    $r = spawn($SELF, $ROOT, 'put_admin_denied');
    check(str_contains($r['out'], 'PERMISSION_DENIED'), 'plain admin (settings.view, NO system.settings.manage) PUT -> 403 PERMISSION_DENIED');
    $r = spawn($SELF, $ROOT, 'delete_admin_denied');
    check(str_contains($r['out'], 'PERMISSION_DENIED'), 'plain admin DELETE -> 403 PERMISSION_DENIED');
    $r = spawn($SELF, $ROOT, 'put_user_denied');
    check(str_contains($r['out'], 'ADMIN_REQUIRED'), 'plain user PUT -> 403 ADMIN_REQUIRED');
    $r = spawn($SELF, $ROOT, 'get_admin_ok');
    check(str_contains($r['out'], '"settings"'), 'plain admin (settings.view) can READ the settings inventory');
    $r = spawn($SELF, $ROOT, 'env_semantics');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['d_default'] ?? '') === 'fa', 'defaultLocale with nothing configured -> fa (historical default)');
    check(($j['d_db'] ?? '') === 'en', 'DB row overrides default -> en');
    check(($j['d_db_beats_env'] ?? '') === 'en', 'DB row wins over ENV (established precedence)');
    check(($j['d_env_note'] ?? '') === 'invalid-env-rejected' && ($j['d_env_valid'] ?? '') === 'en', 'ENV layer honored when no DB row; invalid ENV fails safe');
    $r = spawn($SELF, $ROOT, 'user_repo_identity');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['locale_unset'] ?? '') === 'fa', 'signup WITHOUT setting -> locale fa (identity with pre-Phase-8 behavior)');
    check(($j['locale_set'] ?? '') === 'en', 'signup WITH platform.default_locale=en -> locale en');
    check(($j['locale_explicit'] ?? '') === 'fr', 'explicit user-chosen locale is preserved (never overridden)');
    spawn($SELF, $ROOT, 'stage_fa');
    $r = spawn($SELF, $ROOT, 'put_super_ok');
    $j = json_decode($r['out'], true) ?: [];
    check((($j['data']['setting'] ?? [])['key'] ?? '') === 'platform.default_locale' && (($j['data']['setting'] ?? [])['value'] ?? '') === 'en' && (($j['data']['setting'] ?? [])['source'] ?? '') === 'admin', 'super_admin PUT succeeds (setting stored, source=admin)');

    // ===== allowlist / value validation =====
    $r = spawn($SELF, $ROOT, 'put_secret_shaped');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'secret-shaped key (app_encryption_key) rejected (allowlist; envelope VALIDATION_FAILED, detail UNKNOWN_SETTING)');
    $r = spawn($SELF, $ROOT, 'put_secret_shaped2');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'secret-shaped key (RESEND_API_KEY) rejected identically');
    $r = spawn($SELF, $ROOT, 'put_unknown');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'unknown key rejected (allowlist)');
    $r = spawn($SELF, $ROOT, 'put_bad_value');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'malformed value (de) rejected (envelope VALIDATION_FAILED, detail INVALID_CHOICE)');
    $r = spawn($SELF, $ROOT, 'put_managed_elsewhere');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'module-managed key (mail.driver) NOT writable here (single-owner rule)');

    // ===== reset / audit =====
    $r = spawn($SELF, $ROOT, 'reset_super_ok');
    $j = json_decode($r['out'], true) ?: [];
    $st = $j['data']['setting'] ?? []; check(($st['source'] ?? '') === 'env-default' && array_key_exists('value', $st) && $st['value'] === null, 'super_admin DELETE resets to env/default (source=env-default, value null)');
    $r = spawn($SELF, $ROOT, 'audit_rows');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['upd_action'] ?? '') === 'settings.updated' && ($j['upd_new'] ?? '') === 'en' && ($j['upd_old'] ?? 'x') === 'fa', 'update is AUDITED (settings.updated with before=fa / after=en)');
    check(($j['rst_action'] ?? '') === 'settings.reset' && array_key_exists('old', $j['rst_meta'] ?? []), 'reset is AUDITED (settings.reset with prior value)');
    check(($j['no_secrets'] ?? false) === true, 'audit metadata carries NO secret material');

    // ===== inventory shape + secret sweep =====
    $r = spawn($SELF, $ROOT, 'inventory_shape');
    $j = json_decode($r['out'], true) ?: [];
    $settings = $j['settings'] ?? [];
    $jD = $j;
    $byKey = [];
    foreach ($settings as $s) {
        $byKey[$s['key']] = $s;
    }
    check(isset($byKey['platform.default_locale']) && $byKey['platform.default_locale']['writable'] === true, 'inventory includes the writable catalog key');
    check(($byKey['mail.driver']['writable'] ?? true) === false && ($byKey['mail.driver']['module'] ?? '') === 'integrations-email', 'module-managed keys listed read-only with owning module');
    check(($byKey['ai_route_default']['moduleEndpoint'] ?? '') === '#/ai-route', 'managed keys carry their owner endpoint (no duplicate write path)');
    check(($jD['no_secrets'] ?? false) === true, 'settings payload carries NO secret material (swept)');
    check(count($settings) >= 9, 'inventory covers catalog + supervised module keys');

    // ===== rate limiting =====
    $r = spawn($SELF, $ROOT, 'rate_limit');
    check(str_contains($r['out'], 'TOO_MANY_REQUESTS'), 'rate limit enforced on admin-settings bucket (16th hit -> 429)');

    // ===== route registration statics =====
    $r = spawn($SELF, $ROOT, 'route_static');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['get_count'] ?? 9) === 1 && ($j['get_gated'] ?? false) === true, 'GET /admin/settings registered once, gated settings.view + $admin');
    check(($j['put_count'] ?? 9) === 1 && ($j['put_gated'] ?? false) === true, 'PUT /admin/settings/{key} registered once, gated system.settings.manage + $admin');
    check(($j['delete_count'] ?? 9) === 1 && ($j['delete_gated'] ?? false) === true, 'DELETE /admin/settings/{key} registered once, gated system.settings.manage + $admin');
    check(($j['manage_consumed'] ?? 0) >= 2, 'reserved P_SETTINGS_MANAGE now consumed by routes (reservation honored, not broadened)');

    echo "\n{$checks} checks, {$failures} failures\n";
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
putenv('PLATFORM_DEFAULT_LOCALE');
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
        ]]);
    }
}

function mkRequest(string $path, array $query, string $role, int $uid, array $body = [], string $method = 'GET'): Request
{
    $headers = ['user-agent' => 'test-agent', 'x-request-id' => 'ctx-123', 'authorization' => 'Bearer ' . str_repeat('j', 48)];
    if ($role === 'guest') {
        unset($headers['authorization']);
    }
    $rq = new Request($method, $path, $query, $body, $headers);
    $rq->attributes['user_role'] = $role === 'guest' ? '' : $role;
    $rq->attributes['user_id'] = $uid;
    return $rq;
}

$case = $argv[2];

if ($case === 'setup') {
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, password_hash TEXT DEFAULT \'\', full_name TEXT DEFAULT \'\', role TEXT NOT NULL DEFAULT \'user\', status TEXT NOT NULL DEFAULT \'active\', email_verified_at DATETIME NULL, locale TEXT DEFAULT \'fa\', locale_source TEXT DEFAULT \'auto\', timezone TEXT DEFAULT \'UTC\', plan TEXT NOT NULL DEFAULT \'free\', subscription_status TEXT NOT NULL DEFAULT \'none\', plan_started_at DATETIME NULL, plan_expires_at DATETIME NULL, plan_updated_at DATETIME NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER NOT NULL, actor_role TEXT NOT NULL, action TEXT NOT NULL, target_type TEXT NOT NULL, target_id INTEGER NULL, result TEXT NOT NULL DEFAULT \'success\', summary TEXT NULL, ip_address TEXT NULL, user_agent TEXT NULL, context_id TEXT NULL, metadata_json TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS rate_limits (bucket TEXT PRIMARY KEY, hits INTEGER NOT NULL, window_start DATETIME NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ai_global_settings (setting_key VARCHAR(64) PRIMARY KEY, setting_value VARCHAR(64) NULL, updated_by INTEGER NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $ins = $pdo->prepare('INSERT INTO users (id, email, password_hash, full_name, role, status, email_verified_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $cost = 4;
    $ins->execute([1, 'super@velora.test', password_hash('SuperAdmin!1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Arman Kaveh', 'super_admin', 'active', gmdate('Y-m-d H:i:s')]);
    $ins->execute([2, 'admin@velora.test', password_hash('AdminUser!1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Ada Admin', 'admin', 'active', gmdate('Y-m-d H:i:s')]);
    $ins->execute([3, 'user@velora.test', password_hash('PlainUser!1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Plain User', 'user', 'active', gmdate('Y-m-d H:i:s')]);
    echo json_encode(['ok' => true]);
    exit(0);
}

$ctrl = new SettingsController();
$auth = AuthMiddleware::authenticate();
$adminOnly = AuthMiddleware::adminOnly();
$viewPerm = AuthMiddleware::requirePermission(Role::P_SETTINGS_VIEW);
$managePerm = AuthMiddleware::requirePermission(Role::P_SETTINGS_MANAGE);

switch ($case) {
    case 'get_guest': deny(fn () => $auth(mkRequest('/api/v1/admin/settings', [], 'guest', 0))); break;
    case 'get_user': deny(function () use ($adminOnly, $viewPerm) { $r = mkRequest('/api/v1/admin/settings', [], 'user', 3); $adminOnly($r); $viewPerm($r); }); break;
    case 'get_admin_ok': (function () use ($adminOnly, $viewPerm, $ctrl) { $r = mkRequest('/api/v1/admin/settings', [], 'admin', 2); $adminOnly($r); $viewPerm($r); $ctrl->index($r); })(); break;
    case 'put_admin_denied': deny(function () use ($adminOnly, $managePerm) { $r = mkRequest('/api/v1/admin/settings/platform.default_locale', [], 'admin', 2, ['value' => 'en'], 'PUT'); $adminOnly($r); $managePerm($r); }); break;
    case 'delete_admin_denied': deny(function () use ($adminOnly, $managePerm) { $r = mkRequest('/api/v1/admin/settings/platform.default_locale', [], 'admin', 2, [], 'DELETE'); $adminOnly($r); $managePerm($r); }); break;
    case 'put_user_denied': deny(function () use ($adminOnly, $managePerm) { $r = mkRequest('/api/v1/admin/settings/platform.default_locale', [], 'user', 3, ['value' => 'en'], 'PUT'); $adminOnly($r); $managePerm($r); }); break;
    case 'put_super_ok': (function () use ($adminOnly, $managePerm, $ctrl) { $r = mkRequest('/api/v1/admin/settings/platform.default_locale', [], 'super_admin', 1, ['value' => 'en'], 'PUT'); $adminOnly($r); $managePerm($r); $ctrl->update($r, ['key' => 'platform.default_locale']); })(); break;
    case 'put_secret_shaped': deny(fn () => $ctrl->update(mkRequest('/api/v1/admin/settings/app_encryption_key', [], 'super_admin', 1, ['value' => 'x'], 'PUT'), ['key' => 'app_encryption_key'])); break;
    case 'put_secret_shaped2': deny(fn () => $ctrl->update(mkRequest('/api/v1/admin/settings/RESEND_API_KEY', [], 'super_admin', 1, ['value' => 'x'], 'PUT'), ['key' => 'RESEND_API_KEY'])); break;
    case 'put_unknown': deny(fn () => $ctrl->update(mkRequest('/api/v1/admin/settings/platform.unknown_key', [], 'super_admin', 1, ['value' => 'x'], 'PUT'), ['key' => 'platform.unknown_key'])); break;
    case 'put_bad_value': deny(fn () => $ctrl->update(mkRequest('/api/v1/admin/settings/platform.default_locale', [], 'super_admin', 1, ['value' => 'de'], 'PUT'), ['key' => 'platform.default_locale'])); break;
    case 'put_managed_elsewhere': deny(fn () => $ctrl->update(mkRequest('/api/v1/admin/settings/mail.driver', [], 'super_admin', 1, ['value' => 'smtp'], 'PUT'), ['key' => 'mail.driver'])); break;
    case 'env_semantics': {
        $repo = new \Velora\Core\IntegrationSettingsRepository();
        $out = [];
        $out['d_default'] = PlatformSettings::defaultLocale();
        $repo->set('platform.default_locale', 'en', 1);
        $out['d_db'] = PlatformSettings::defaultLocale();
        putenv('PLATFORM_DEFAULT_LOCALE=de'); // invalid env must NOT beat valid DB row; also must fail safe later
        $out['d_db_beats_env'] = PlatformSettings::defaultLocale();
        $repo->delete('platform.default_locale');
        $out['d_env_note'] = PlatformSettings::defaultLocale() === 'fa' ? 'invalid-env-rejected' : PlatformSettings::defaultLocale();
        putenv('PLATFORM_DEFAULT_LOCALE=de');
        $out['d_env_invalid_rejected'] = PlatformSettings::defaultLocale() === 'fa';
        // valid-env layer check in a fresh child would be needed for a real ENV win;
        // validated here by deleting the row and setting a valid env:
        putenv('PLATFORM_DEFAULT_LOCALE=en');
        $out['d_env_valid'] = PlatformSettings::defaultLocale();
        putenv('PLATFORM_DEFAULT_LOCALE');
        echo json_encode($out);
        break;
    }
    case 'user_repo_identity': {
        $repo = new \Velora\Core\IntegrationSettingsRepository();
        $users = new UserRepository();
        $id1 = $users->create(['email' => 'p8a@velora.test', 'password_hash' => 'x', 'full_name' => 'A', 'timezone' => 'UTC']);
        $l1 = (string) $pdo->query("SELECT locale FROM users WHERE id=$id1")->fetchColumn();
        $repo->set('platform.default_locale', 'en', 1);
        $id2 = $users->create(['email' => 'p8b@velora.test', 'password_hash' => 'x', 'full_name' => 'B', 'timezone' => 'UTC']);
        $l2 = (string) $pdo->query("SELECT locale FROM users WHERE id=$id2")->fetchColumn();
        $id3 = $users->create(['email' => 'p8c@velora.test', 'password_hash' => 'x', 'full_name' => 'C', 'timezone' => 'UTC', 'locale' => 'fr']);
        $l3 = (string) $pdo->query("SELECT locale FROM users WHERE id=$id3")->fetchColumn();
        echo json_encode(['locale_unset' => $l1, 'locale_set' => $l2, 'locale_explicit' => $l3]);
        break;
    }
    case 'stage_fa': (new \Velora\Core\IntegrationSettingsRepository())->set('platform.default_locale', 'fa', 0); echo json_encode(['ok' => true]); break;
    case 'reset_super_ok': (function () use ($adminOnly, $managePerm, $ctrl) { $r = mkRequest('/api/v1/admin/settings/platform.default_locale', [], 'super_admin', 1, [], 'DELETE'); $adminOnly($r); $managePerm($r); $ctrl->reset($r, ['key' => 'platform.default_locale']); })(); break;
    case 'audit_rows': {
        $upd = $pdo->query("SELECT metadata_json FROM admin_audit_logs WHERE action='settings.updated' ORDER BY id DESC LIMIT 1")->fetch();
        $rst = $pdo->query("SELECT metadata_json FROM admin_audit_logs WHERE action='settings.reset' ORDER BY id DESC LIMIT 1")->fetch();
        $u = $upd !== false ? json_decode((string) ($upd['metadata_json'] ?? '{}'), true) : [];
        $r = $rst !== false ? json_decode((string) ($rst['metadata_json'] ?? '{}'), true) : [];
        $blob = json_encode([$u, $r]) ?: '';
        echo json_encode([
            'upd_action' => 'settings.updated',
            'upd_old' => $u['old'] ?? null,
            'upd_new' => $u['new'] ?? null,
            'rst_action' => 'settings.reset',
            'rst_meta' => $r,
            'no_secrets' => !preg_match('/password|token|secret|api[_-]?key|credential/i', str_replace('platform.default_locale', '', $blob)),
        ]);
        break;
    }
    case 'inventory_shape': {
        // NOTE: not via the controller — Response::json exits inside ob_ buffers.
        // The envelope path is proven by the get_admin_ok case; here we prove the
        // service inventory content + secret sweep.
        $svc = new \Velora\Admin\AdminSettingsService();
        $settings = $svc->inventory();
        $payload = (string) json_encode($settings);
        $sweep = preg_replace('/platform\.default_locale|ai_route_default|metaapi\.base_url|mail\.[a-z_]+/', '', $payload) ?: '';
        echo json_encode(['settings' => $settings, 'no_secrets' => !preg_match('/password|token|secret|api[_-]?key|credential|BEGIN [A-Z]+ PRIVATE/i', $sweep)]);
        break;
    }
    case 'rate_limit': {
        deny(function () {
            for ($i = 0; $i < 15; $i++) {
                RateLimiter::hit('admin-settings', 15, 300);
            }
            RateLimiter::hit('admin-settings', 15, 300);
        });
        break;
    }
    case 'route_static': {
        $idx = (string) file_get_contents(dirname(__DIR__, 2) . '/api/index.php');
        $get = array_values(array_filter(explode("\n", $idx), fn (string $l): bool => str_contains($l, "'/api/v1/admin/settings'")));
        $put = array_values(array_filter(explode("\n", $idx), fn (string $l): bool => str_contains($l, "'/api/v1/admin/settings/{key}'") && str_contains($l, 'put(')));
        $del = array_values(array_filter(explode("\n", $idx), fn (string $l): bool => str_contains($l, "'/api/v1/admin/settings/{key}'") && str_contains($l, 'delete(')));
        echo json_encode([
            'get_count' => count($get),
            'get_gated' => count($get) === 1 && str_contains($get[0], 'P_SETTINGS_VIEW') && str_contains($get[0], '$admin'),
            'put_count' => count($put),
            'put_gated' => count($put) === 1 && str_contains($put[0], 'P_SETTINGS_MANAGE') && str_contains($put[0], '$admin'),
            'delete_count' => count($del),
            'delete_gated' => count($del) === 1 && str_contains($del[0], 'P_SETTINGS_MANAGE') && str_contains($del[0], '$admin'),
            'manage_consumed' => substr_count($idx, 'P_SETTINGS_MANAGE'),
        ]);
        break;
    }
    default:
        fwrite(STDERR, "unknown case {$case}\n");
        exit(2);
}
