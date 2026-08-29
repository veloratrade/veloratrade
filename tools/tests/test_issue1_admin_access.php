<?php

declare(strict_types=1);

/**
 * ISSUE 1 regression — Admin access is role-gated end to end:
 *   - server-side page gate in locale-router.php (session + role, fail-closed),
 *   - every /api/v1/admin/* route behind AuthMiddleware::adminOnly(),
 *   - admin nav hidden for non-admins in every user page (existing pattern),
 *   - admin page client guard redirects non-admins.
 *
 * Run: php tools/tests/test_issue1_admin_access.php
 */

$root = dirname(__DIR__, 2);
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

echo "== Server-side page gate (locale-router.php — authoritative) ==\n";
$router = (string) file_get_contents($root . '/locale-router.php');
check(strpos($router, 'u.id AS user_id, u.role') !== false, 'session query selects u.role');
check(
    strpos($router, "relativeFile === 'admin/index.html'") !== false
    && strpos($router, "!== 'admin'") !== false
    && strpos($router, "/dashboard/', true, 302") !== false,
    'valid session + non-admin on admin page -> 302 to dashboard'
);
check((substr_count($router, 'Location: /' . "' . $locale . '/login/'", 0) >= 1) || strpos($router, "/login/', true, 302") !== false, 'anonymous protected page still -> 302 login');

echo "== Admin API RBAC (api/index.php) ==\n";
$index = (string) file_get_contents($root . '/api/index.php');
preg_match_all("/\\\$router->(?:get|post|put|delete|add)\((?:'PATCH',\s*)?'\/api\/v1\/admin[^']*'.*?(?=\\\$router->|\z)/s", $index, $rm);
$adminRouteLines = array_values(array_filter(
    explode("\n", $index),
    fn (string $l): bool => str_contains($l, '/api/v1/admin')
));
check(count($adminRouteLines) >= 8, 'admin API routes found (' . count($adminRouteLines) . ')');
check(
    array_all($adminRouteLines, fn (string $l): bool => str_contains($l, '$admin')),
    'EVERY /api/v1/admin route is registered with the $admin middleware stack'
);
check(
    strpos($index, '$admin = [') !== false && strpos($index, 'AuthMiddleware::adminOnly()') !== false,
    '$admin stack = auth + adminOnly()'
);

echo "== Runtime middleware behavior ==\n";
$tmp = sys_get_temp_dir() . '/velora-issue1-' . bin2hex(random_bytes(4));
mkdir($tmp . '/config', 0700, true);
mkdir($tmp . '/data', 0700, true);
putenv('APP_ENV=local');
putenv('VELORA_PRIVATE_ROOT=' . $tmp);
putenv('VELORA_DOCUMENT_ROOT=' . $root);
putenv('DB_DRIVER=sqlite');
putenv('DB_DATABASE=' . $tmp . '/data/v.sqlite');
putenv('JWT_SECRET=' . str_repeat('j', 48));
putenv('APP_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
putenv('CORS_ALLOWED_ORIGINS=http://localhost');
putenv('FRONTEND_URL=http://localhost');
putenv('MAIL_DRIVER=log');
file_put_contents($tmp . '/config/velora.env', "APP_ENV=local\nDB_DRIVER=sqlite\nDB_DATABASE={$tmp}/data/v.sqlite\nJWT_SECRET=" . str_repeat('j', 48) . "\nAPP_ENCRYPTION_KEY=" . base64_encode(random_bytes(32)) . "\nCORS_ALLOWED_ORIGINS=http://localhost\nFRONTEND_URL=http://localhost\nMAIL_DRIVER=log\n");
require $root . '/api/src/bootstrap.php';

use Velora\Auth\AuthMiddleware;
use Velora\Core\Request;
use Velora\Core\Exceptions\ForbiddenException;

$forbidden = null;
try {
    $req = new Request('GET', '/api/v1/admin/users', [], [], []);
    $req->attributes['user_role'] = 'user';
    (AuthMiddleware::adminOnly())($req);
} catch (ForbiddenException $e) {
    $forbidden = $e;
}
check($forbidden !== null && $forbidden->errorCode() === 'ADMIN_REQUIRED', 'normal user (role=user) -> 403 ADMIN_REQUIRED');

$passes = true;
try {
    $req = new Request('GET', '/api/v1/admin/users', [], [], []);
    $req->attributes['user_role'] = 'admin';
    (AuthMiddleware::adminOnly())($req);
} catch (\Throwable $e) {
    $passes = false;
}
check($passes, 'admin role passes the same middleware');

$unauth = null;
try {
    $req = new Request('GET', '/api/v1/admin/users', [], [], []); // no user_role attribute
    (AuthMiddleware::adminOnly())($req);
} catch (ForbiddenException $e) {
    $unauth = $e;
}
check($unauth !== null && $unauth->errorCode() === 'ADMIN_REQUIRED', 'unauthenticated request is rejected by adminOnly (401/403 at API layer, never data)');

echo "== Frontend: admin nav hidden for non-admins ==\n";
$pages = ['dashboard', 'trades', 'trades/new', 'wallet', 'news', 'markets', 'performance', 'support', 'intelligence', 'profile'];
foreach ($pages as $page) {
    $html = (string) file_get_contents($root . '/' . $page . '/index.html');
    $linkOk = strpos($html, 'id="adminLinkSide"') !== false && strpos($html, 'data-velora-adminhide-target="1"') !== false;
    $scriptOk = strpos($html, 'data-velora-adminhide') !== false && strpos($html, "u.role === 'admin'") !== false
        && strpos($html, "nodes[i].style.display = isAdmin ? '' : 'none'") !== false;
    check($linkOk && $scriptOk, "{$page}/: admin link tagged + hide-script fails closed for non-admins");
}
$admin = (string) file_get_contents($root . '/admin/index.html');
check(
    strpos($admin, "user.role !== 'admin'") !== false && strpos($admin, "location.replace('/dashboard')") !== false,
    'admin page client guard: non-admin redirected to /dashboard'
);
$localized = (string) file_get_contents($root . '/localized/fa/admin/index.html');
check(strpos($localized, "user.role !== 'admin'") !== false, 'localized admin output carries the same client guard');

$rm = static function (string $p) use (&$rm): void {
    if (!is_dir($p)) { @unlink($p); return; }
    foreach (scandir($p) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $rm($p . '/' . $f);
    }
    @rmdir($p);
};
$rm($tmp);

echo "\nissue1-admin-access: " . ($failures === 0 ? 'PASS' : 'FAIL') . " ($checks checks, $failures failures)\n";
exit($failures === 0 ? 0 : 1);
