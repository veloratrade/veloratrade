<?php

declare(strict_types=1);

/**
 * Admin entry-point handoff regression.
 *
 * The canonical Admin entry (/admin/index.html) hands authorized admins to the
 * Admin v2.2 shell (/admin/v2/index.html) AFTER the existing fail-closed
 * session + role gates in locale-router.php. Everything else is unchanged:
 *   - anonymous user         -> 302 /{locale}/login/     (existing login flow)
 *   - signed-in non-admin    -> 302 /{locale}/dashboard/ (existing authorization)
 *   - authorized admin       -> 302 /admin/v2/index.html (new, no-store)
 *   - /fa|en/admin/index.html hand off too, refreshing the velora_locale cookie
 *   - direct /admin/v2/index.html keeps serving the byte-exact v2 artifact
 *     (router.php mirrors the staging ^admin/v2/index\.html$ pass-through)
 *
 * Run: php tools/tests/test_admin_entrypoint_handoff.php
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

echo "== Source contracts (locale-router.php) ==\n";
$router = (string) file_get_contents($root . '/locale-router.php');
check(strpos($router, "header('Location: /admin/v2/index.html', true, 302);") !== false, 'handoff redirects to /admin/v2/index.html with 302');
$handoffBlock = "if (\$relativeFile === 'admin/index.html') {\n        header('Cache-Control: no-store');\n        header('Location: /admin/v2/index.html', true, 302);";
check(strpos($router, $handoffBlock) !== false, 'handoff is scoped to admin/index.html and sends Cache-Control: no-store');
$roleGatePos = strpos($router, "!== 'admin'");
$handoffPos = strpos($router, '/admin/v2/index.html');
check($roleGatePos !== false && $handoffPos !== false && $handoffPos > $roleGatePos, 'handoff executes only after the server-side role gate');
check(strpos($router, "\$relativeFile === 'admin/index.html' && ((string) (\$session['role'] ?? '')) !== 'admin'") !== false, 'role gate unchanged (signed-in non-admin -> dashboard)');
check(strpos($router, "/' . \$locale . '/login/', true, 302") !== false, 'anonymous gate unchanged (-> /{locale}/login/)');
check(substr_count($router, '/admin/v2/index.html') === 1, 'exactly one v2 reference in the router (no broad rewrites)');

echo "== Source contracts (router.php parity + product links + v2 artifact) ==\n";
$dev = (string) file_get_contents($root . '/router.php');
check(strpos($dev, "if (\$path === '/admin/v2/index.html' && is_file(__DIR__ . \$path))") !== false, 'dev router serves the physical v2 shell (mirror of the staging .htaccess rule)');
check(strpos($dev, "require __DIR__ . '/locale-router.php'") !== false, 'all other page requests still resolve via locale-router');
foreach (['en', 'fa'] as $loc) {
    $dash = (string) file_get_contents($root . '/localized/' . $loc . '/dashboard/index.html');
    check(strpos($dash, 'href="/admin/index.html"') !== false, "dashboard({$loc}) keeps the canonical Admin link /admin/index.html");
}
$shellSha = hash('sha256', (string) file_get_contents($root . '/admin/v2/index.html'));
$shell = (string) file_get_contents($root . '/admin/v2/index.html');
check(strpos($shell, 'PAGE_IMPL') !== false && strpos($shell, 'palette-root') !== false, 'v2 artifact intact (route registry + command palette present)');

echo "== Runtime behavior (temp SQLite; clean-process router execution) ==\n";
$tmp = sys_get_temp_dir() . '/velora-entry-' . bin2hex(random_bytes(4));
mkdir($tmp . '/config', 0700, true);
mkdir($tmp . '/data', 0700, true);
mkdir($tmp . '/storage', 0700, true);
$envLines = [
    'APP_ENV=local',
    'DB_DRIVER=sqlite',
    'DB_DATABASE=' . $tmp . '/data/velora.sqlite',
    'JWT_SECRET=' . str_repeat('j', 48),
    'APP_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)),
    'CORS_ALLOWED_ORIGINS=http://localhost',
    'FRONTEND_URL=http://localhost',
    'MAIL_DRIVER=log',
];
file_put_contents($tmp . '/config/velora.env', implode("\n", $envLines) . "\n");
putenv('APP_ENV=local');
putenv('VELORA_PRIVATE_ROOT=' . $tmp);
putenv('DB_DRIVER=sqlite');
putenv('DB_DATABASE=' . $tmp . '/data/velora.sqlite');
putenv('JWT_SECRET=' . str_repeat('j', 48));
putenv('APP_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
putenv('CORS_ALLOWED_ORIGINS=http://localhost');
putenv('FRONTEND_URL=http://localhost');
putenv('MAIL_DRIVER=log');

// Fresh packaged-schema database (same pattern as the CI e2e harness), then a
// minimal seed: one admin, one regular user, one live session for each.
exec(PHP_BINARY . ' ' . escapeshellarg($root . '/api/init-sqlite.php') . ' 2>&1', $initOut, $initExit);
check($initExit === 0, 'init-sqlite.php prepared a fresh packaged-schema database');
copy($root . '/api/storage/velora.sqlite', $tmp . '/data/velora.sqlite');
$pdo = new PDO('sqlite:' . $tmp . '/data/velora.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$hash = password_hash('EntrypointTest1!', PASSWORD_BCRYPT, ['cost' => 10]);
$insU = $pdo->prepare("INSERT OR REPLACE INTO users (id, email, password_hash, role, locale, status, email_verified_at) VALUES (?, ?, ?, ?, ?, 'active', datetime('now'))");
$insU->execute([601, 'entry-admin@test.local', $hash, 'admin', 'en']);
$insU->execute([602, 'entry-user@test.local', $hash, 'user', 'en']);
$adminToken = bin2hex(random_bytes(32));
$userToken = bin2hex(random_bytes(32));
$insS = $pdo->prepare("INSERT INTO user_sessions (user_id, refresh_token_hash, access_token_hash, expires_at) VALUES (?, ?, ?, datetime('now', '+1 day'))");
$insS->execute([601, hash('sha256', $adminToken), hash('sha256', 'unused-' . bin2hex(random_bytes(8)))]);
$insS->execute([602, hash('sha256', $userToken), hash('sha256', 'unused-' . bin2hex(random_bytes(8)))]);

// Real-HTTP harness: the built-in server via router.php — the same pattern as
// the CI e2e job — so statuses/Location/Set-Cookie headers are observed exactly
// as a browser would. VELORA_PROD=1 keeps served HTML byte-exact (no dev
// injection), mirroring Apache/LiteSpeed behavior.
putenv('VELORA_PROD=1');
$port = 8137 + random_int(0, 400);
$serverCmd = sprintf(
    '%s -d display_errors=1 -S 127.0.0.1:%d -t %s %s',
    escapeshellarg(PHP_BINARY),
    $port,
    escapeshellarg($root),
    escapeshellarg($root . '/router.php')
);
$serverPipes = [];
$server = proc_open($serverCmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $serverPipes, $root);
$ready = false;
for ($i = 0; $i < 50; $i++) {
    usleep(200_000);
    $ch = curl_init('http://127.0.0.1:' . $port . '/admin/v2/index.html');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code === 200) { $ready = true; break; }
}
check(is_resource($server) && $ready, 'built-in server (router.php, VELORA_PROD=1) is up and serves /admin/v2/index.html');

$httpCase = function (string $path, string $token = '') use ($port): array {
    $headers = [];
    $ch = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$headers): int {
            $headers[] = trim($line);
            return strlen($line);
        },
    ]);
    if ($token !== '') {
        curl_setopt($ch, CURLOPT_COOKIE, '__Host-velora_refresh=' . $token);
    }
    $body = (string) curl_exec($ch);
    $result = [
        'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        'headers' => array_values(array_filter($headers, static fn (string $h): bool => $h !== '')),
        'body_sha256' => hash('sha256', $body),
    ];
    curl_close($ch);
    return $result;
};
$headerValue = function (array $result, string $name): ?string {
    $found = null;
    foreach ($result['headers'] as $h) {
        if (stripos($h, $name . ':') === 0) {
            $found = trim(substr($h, strlen($name) + 1));
        }
    }
    return $found;
};

// (C-auth) anonymous -> existing login flow, unchanged
$r = $httpCase('/admin/index.html');
check($r['status'] === 302 && (bool) preg_match('#^/[a-z-]+/login/$#', (string) $headerValue($r, 'Location')), 'anonymous /admin/index.html -> 302 /{locale}/login/ (existing flow)');

// (A) authorized admin -> Admin v2.2
$r = $httpCase('/admin/index.html', $adminToken);
check($r['status'] === 302 && $headerValue($r, 'Location') === '/admin/v2/index.html', 'authorized admin /admin/index.html -> 302 /admin/v2/index.html');
check(stripos((string) $headerValue($r, 'Cache-Control'), 'no-store') !== false, 'handoff response is no-store');

// (D-authz) signed-in non-admin -> existing dashboard redirect, unchanged
$r = $httpCase('/admin/index.html', $userToken);
check($r['status'] === 302 && (bool) preg_match('#^/[a-z-]+/dashboard/$#', (string) $headerValue($r, 'Location')), 'signed-in non-admin -> 302 /{locale}/dashboard/ (unchanged, no admin access)');

// (F-locale) FA and EN prefixed entries hand off and refresh the locale cookie
$r = $httpCase('/fa/admin/index.html', $adminToken);
$faCookies = array_values(array_filter($r['headers'], static fn (string $h): bool => stripos($h, 'Set-Cookie:') === 0 && stripos($h, 'velora_locale=fa') !== false));
check($r['status'] === 302 && $headerValue($r, 'Location') === '/admin/v2/index.html', 'FA entry /fa/admin/index.html -> 302 /admin/v2/index.html');
check($faCookies !== [], 'FA entry refreshes velora_locale=fa (v2 shell boots FA/RTL)');
$r = $httpCase('/en/admin/index.html', $adminToken);
$enCookies = array_values(array_filter($r['headers'], static fn (string $h): bool => stripos($h, 'Set-Cookie:') === 0 && stripos($h, 'velora_locale=en') !== false));
check($r['status'] === 302 && $headerValue($r, 'Location') === '/admin/v2/index.html', 'EN entry /en/admin/index.html -> 302 /admin/v2/index.html');
check($enCookies !== [], 'EN entry refreshes velora_locale=en (v2 shell boots EN/LTR)');

// (B) direct v2 URL: byte-exact artifact over real HTTP
$r = $httpCase('/admin/v2/index.html');
check($r['status'] === 200 && $r['body_sha256'] === $shellSha, 'direct /admin/v2/index.html -> 200, byte-identical v2 artifact');

if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}
putenv('VELORA_PROD');

echo "\nadmin-entrypoint-handoff: " . ($failures === 0 ? 'PASS' : 'FAIL') . " ({$checks} checks, {$failures} failures)\n";
exit($failures === 0 ? 0 : 1);
