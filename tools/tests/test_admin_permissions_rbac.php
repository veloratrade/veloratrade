<?php

declare(strict_types=1);

use Velora\Admin\SecurityController;
use Velora\Admin\UserManagementController;
use Velora\Auth\AuthMiddleware;
use Velora\Auth\Role;
use Velora\Core\Request;

/**
 * Phase 7 — RBAC permissions endpoint + map integrity + role-change
 * authorization/audit regressions.
 *
 * Covers: GET /api/v1/admin/permissions (settings.view gate via the REAL
 * middleware closures — user denied, guest 401, admin+super allowed), exact
 * response shape == Role::permissionMap() (server-owned truth, 24 permissions,
 * super_admin ⊇ admin, user = []), no secret material, single route
 * registration statically verified, deny-by-default helpers (unknown
 * role/permission), and the existing audited role-change path (plain admin:
 * PRIVILEGED_TARGET / PRIVILEGE_ESCALATION_DENIED; self: SELF_ACTION_DENIED;
 * invalid role rejected; super_admin success writes the user.role.change
 * audit row). Child-process per case over one shared temp SQLite DB with the
 * real services — no fake data, no network.
 *
 * Run: php tools/tests/test_admin_permissions_rbac.php
 */

$SELF = __FILE__;
$ROOT = sys_get_temp_dir() . '/velora-p7-test-' . bin2hex(random_bytes(5));

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

    // ===== /admin/permissions authorization (real middleware closures) =====
    $r = spawn($SELF, $ROOT, 'perms_rbac');
    check(str_contains($r['out'], 'PERMISSION_DENIED'), 'ordinary user denied /admin/permissions (settings.view RBAC)');
    $r = spawn($SELF, $ROOT, 'perms_guest');
    check(str_contains($r['out'], 'ACCESS_TOKEN_MISSING') || str_contains($r['out'], 'UNAUTHORIZED'), 'unauthenticated /admin/permissions -> 401');
    $r = spawn($SELF, $ROOT, 'perms_admin');
    check(str_contains($r['out'], '"permissions"') && str_contains($r['out'], 'super_admin'), 'plain admin (settings.view) can read the permission matrix');

    // ===== shape == server-owned map =====
    $r = spawn($SELF, $ROOT, 'perms_shape');
    $j = json_decode($r['out'], true) ?: [];
    $map = $j['data']['permissions'] ?? [];
    check(array_keys($map) === ['user', 'admin', 'super_admin'], 'response shape: exactly the three stored roles');
    check(count($map['user'] ?? [1]) === 0, 'user role has zero permissions (deny-by-default)');
    check(count($map['admin'] ?? []) === 18, 'admin role carries exactly 18 permissions');
    check(count($map['super_admin'] ?? []) === 24, 'super_admin carries exactly 24 permissions');
    check(empty(array_diff($map['admin'] ?? ['x'], $map['super_admin'] ?? [])), 'super_admin is a superset of admin');
    $expected = json_decode(spawn($SELF, $ROOT, 'map_expected')['out'], true) ?: [];
    check($map === $expected && $expected !== [], 'returned matrix is EXACTLY Role::permissionMap() (single server-side source of truth)');
    check(in_array('settings.view', $map['admin'] ?? [], true) && in_array('audit.view_sensitive', $map['super_admin'] ?? [], true) && !in_array('audit.view_sensitive', $map['admin'] ?? [], true), 'spot checks: settings.view admin-level; audit.view_sensitive super-only');

    // ===== map integrity / deny-by-default helpers =====
    $r = spawn($SELF, $ROOT, 'map_integrity');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['unknownRoleCan'] ?? true) === false, 'Role::can with UNKNOWN role -> deny');
    check(($j['unknownPermCan'] ?? true) === false, 'Role::can with UNKNOWN permission -> deny');
    check(($j['userRoleCan'] ?? true) === false, 'Role::can(user, users.view) -> deny');
    check(count($j['allPerms'] ?? []) === 24 && count(array_unique($j['allPerms'] ?? [])) === 24, 'permission inventory = 24 unique identifiers');

    // ===== route registration (static, test_issue1 style) =====
    $r = spawn($SELF, $ROOT, 'route_line');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['occurrences'] ?? 9) === 1, 'exactly ONE /admin/permissions route registration');
    check((($j['gated'] ?? false) === true), 'route is gated [...$admin, requirePermission(P_SETTINGS_VIEW)]');

    // ===== secret sweep =====
    $r = spawn($SELF, $ROOT, 'perms_admin');
    check(!preg_match('/password|token|credential|secret|api[_-]?key/i', preg_replace('/"permissions"/', '', $r['out'])), 'permissions response carries NO secret material');

    // ===== role-change authorization + audit (existing audited path) =====
    $r = spawn($SELF, $ROOT, 'role_admin_escalation');
    check(str_contains($r['out'], 'PRIVILEGE_ESCALATION_DENIED'), 'plain admin granting a privileged role -> PRIVILEGE_ESCALATION_DENIED');
    $r = spawn($SELF, $ROOT, 'role_admin_privileged_target');
    check(str_contains($r['out'], 'PRIVILEGED_TARGET'), 'plain admin changing a privileged user -> PRIVILEGED_TARGET');
    $r = spawn($SELF, $ROOT, 'role_self');
    check(str_contains($r['out'], 'SELF_ACTION_DENIED'), 'self role change -> SELF_ACTION_DENIED');
    $r = spawn($SELF, $ROOT, 'role_invalid');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'invalid role value rejected (VALIDATION_FAILED envelope; INVALID_ROLE field detail)');
    $r = spawn($SELF, $ROOT, 'role_super_ok');
    check(str_contains($r['out'], '"ok":true'), 'super_admin role change succeeds');
    $r = spawn($SELF, $ROOT, 'audit_row');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['action'] ?? '') === 'user.role.change' && ($j['meta_role'] ?? '') === 'admin', 'role change is AUDITED (user.role.change with metadata)');

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
    $ins = $pdo->prepare('INSERT INTO users (id, email, password_hash, full_name, role, status, email_verified_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $cost = 4;
    $ins->execute([1, 'super@velora.test', password_hash('SuperAdmin!1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Arman Kaveh', 'super_admin', 'active', gmdate('Y-m-d H:i:s')]);
    $ins->execute([2, 'admin@velora.test', password_hash('AdminUser!1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Ada Admin', 'admin', 'active', gmdate('Y-m-d H:i:s')]);
    $ins->execute([3, 'user@velora.test', password_hash('PlainUser!1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Plain User', 'user', 'active', gmdate('Y-m-d H:i:s')]);
    echo json_encode(['ok' => true]);
    exit(0);
}

$ctrl = new SecurityController();

switch ($case) {
    case 'perms_rbac': deny(fn () => (AuthMiddleware::requirePermission(Role::P_SETTINGS_VIEW))(mkRequest('/api/v1/admin/permissions', [], 'user', 3))); break;
    case 'perms_guest': deny(fn () => (AuthMiddleware::authenticate())(mkRequest('/api/v1/admin/permissions', [], 'guest', 0))); break;
    case 'perms_admin': $ctrl->permissions(mkRequest('/api/v1/admin/permissions', [], 'admin', 2)); break;
    case 'perms_shape': $ctrl->permissions(mkRequest('/api/v1/admin/permissions', [], 'super_admin', 1)); break;
    case 'map_expected': echo json_encode(Role::permissionMap()); exit(0);
    case 'map_integrity': {
        $all = [];
        foreach (Role::permissionMap() as $perms) {
            foreach ($perms as $p) {
                $all[$p] = true;
            }
        }
        echo json_encode([
            'unknownRoleCan' => Role::can('bogus_role', 'overview.view'),
            'unknownPermCan' => Role::can('admin', 'nonexistent.permission'),
            'userRoleCan' => Role::can(Role::USER, Role::P_USERS_VIEW),
            'allPerms' => array_keys($all),
        ]);
        break;
    }
    case 'route_line': {
        $idx = (string) file_get_contents(dirname(__DIR__, 2) . '/api/index.php');
        $lines = array_values(array_filter(explode("\n", $idx), fn (string $l): bool => str_contains($l, '/api/v1/admin/permissions')));
        echo json_encode([
            'occurrences' => count($lines),
            'gated' => count($lines) === 1 && str_contains($lines[0], 'SecurityController::class, \'permissions\'') && str_contains($lines[0], 'P_SETTINGS_VIEW') && str_contains($lines[0], '$admin'),
        ]);
        break;
    }
    case 'role_admin_escalation': deny(fn () => (new UserManagementController())->setRole(mkRequest('/api/v1/admin/users/3/role', [], 'admin', 2, ['role' => 'admin'], 'POST'), ['id' => '3'])); break;
    case 'role_admin_privileged_target': deny(fn () => (new UserManagementController())->setRole(mkRequest('/api/v1/admin/users/1/role', [], 'admin', 2, ['role' => 'user'], 'POST'), ['id' => '1'])); break;
    case 'role_self': deny(fn () => (new UserManagementController())->setRole(mkRequest('/api/v1/admin/users/2/role', [], 'admin', 2, ['role' => 'user'], 'POST'), ['id' => '2']) ); break;
    case 'role_invalid': deny(fn () => (new UserManagementController())->setRole(mkRequest('/api/v1/admin/users/3/role', [], 'super_admin', 1, ['role' => 'wizard'], 'POST'), ['id' => '3'])); break;
    case 'role_super_ok': (new UserManagementController())->setRole(mkRequest('/api/v1/admin/users/3/role', [], 'super_admin', 1, ['role' => 'admin'], 'POST'), ['id' => '3']); break;
    case 'audit_row': {
        $row = $pdo->query("SELECT action, metadata_json FROM admin_audit_logs WHERE action='user.role.change' ORDER BY id DESC LIMIT 1")->fetch();
        $meta = $row !== false ? json_decode((string) ($row['metadata_json'] ?? '{}'), true) : [];
        echo json_encode(['action' => $row['action'] ?? '', 'meta_role' => $meta['role'] ?? '']);
        break;
    }
    default:
        fwrite(STDERR, "unknown case {$case}\n");
        exit(2);
}
