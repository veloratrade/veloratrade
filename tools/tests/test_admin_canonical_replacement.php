<?php

declare(strict_types=1);

/**
 * Canonical Admin replacement regression.
 *
 * Architecture under test: /admin/index.html (canonical URL, unchanged) IS
 * Admin v2.2. The locale router serves the single byte-frozen v2.2 artifact
 * in place (HTTP 200, no user-visible redirect) after the existing fail-closed
 * session + role gates. The legacy localized admin page is no longer served
 * on admin routes; /admin/v2/index.html survives only as a documented internal
 * compatibility route. If the v2 artifact is not packaged in an environment
 * (production until its packaging is separately updated), the router falls
 * through to the CSP-managed legacy page — graceful, never broken.
 *
 * Matrix proven here over real HTTP (temp SQLite, built-in server):
 *   - authorized admin, /admin/index.html        -> 200 + exact v2.2 bytes, NO Location
 *   - authorized admin, /fa/admin/index.html     -> 200 + exact v2.2 bytes + velora_locale=fa
 *   - authorized admin, /en/admin/index.html     -> 200 + exact v2.2 bytes + velora_locale=en
 *   - anonymous,         /admin/index.html       -> 302 /{locale}/login/ (existing flow)
 *   - signed-in non-admin, /admin/index.html     -> 302 /{locale}/dashboard/ (unchanged)
 *   - anyone,            /admin/v2/index.html    -> 200 + exact v2.2 bytes (compatibility)
 *
 * Run: php tools/tests/test_admin_canonical_replacement.php
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

echo "== Source contracts (locale-router.php — serving, not redirecting) ==\n";
$router = (string) file_get_contents($root . '/locale-router.php');
check(strpos($router, "header('Location: /admin/v2/index.html'") === false, 'canonical admin entry does NOT redirect to /admin/v2 (no Location header to the v2 URL)');
$serveNeedle = "if (\$relativeFile === 'admin/index.html' && is_file(\$root . '/admin/v2/index.html')) {";
check(strpos($router, $serveNeedle) !== false, 'admin route serves the physical v2 artifact in place, existence-gated');
check(strpos($router, "echo (string) file_get_contents(\$root . '/admin/v2/index.html');") !== false, 'served body is the byte-frozen artifact read from disk (no duplicate copy)');
$roleGatePos = strpos($router, "!== 'admin'");
$servePos = strpos($router, "/admin/v2/index.html");
check($roleGatePos !== false && $servePos !== false && $servePos > $roleGatePos, 'serving executes only after the server-side role gate');
check(strpos($router, "\$relativeFile === 'admin/index.html' && ((string) (\$session['role'] ?? '')) !== 'admin'") !== false, 'role gate unchanged (signed-in non-admin -> dashboard)');
check(strpos($router, "/' . \$locale . '/login/', true, 302") !== false, 'anonymous gate unchanged (-> /{locale}/login/)');
$serveBlockPos = strpos($router, "if (\$relativeFile === 'admin/index.html' && is_file(\$root . '/admin/v2/index.html')) {");
$serveSeg = $serveBlockPos !== false ? substr($router, $serveBlockPos, 400) : '';
check($serveBlockPos !== false
    && strpos($serveSeg, "header('Content-Type: text/html; charset=utf-8');") !== false
    && strpos($serveSeg, "header('Cache-Control: no-store');") !== false, 'served response is text/html with Cache-Control: no-store');

echo "== Source contracts (router.php compatibility route + product links) ==\n";
$dev = (string) file_get_contents($root . '/router.php');
check(strpos($dev, "if (\$path === '/admin/v2/index.html' && is_file(__DIR__ . \$path))") !== false, 'dev router keeps the /admin/v2 compatibility pass-through (mirror of the staging .htaccess rule)');
check(strpos($dev, "require __DIR__ . '/locale-router.php'") !== false, 'all other page requests still resolve via locale-router');
foreach (['en', 'fa'] as $loc) {
    $dash = (string) file_get_contents($root . '/localized/' . $loc . '/dashboard/index.html');
    check(strpos($dash, 'href="/admin/index.html"') !== false, "dashboard({$loc}) keeps the canonical Admin link /admin/index.html");
}
$shellSha = hash('sha256', (string) file_get_contents($root . '/admin/v2/index.html'));
$shell = (string) file_get_contents($root . '/admin/v2/index.html');
check(strpos($shell, 'PAGE_IMPL') !== false && strpos($shell, 'palette-root') !== false, 'v2 artifact intact (route registry + command palette present)');

echo "== Runtime behavior (temp SQLite; real HTTP on the built-in server) ==\n";
$tmp = sys_get_temp_dir() . '/velora-canon-' . bin2hex(random_bytes(4));
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
$hash = password_hash('CanonicalTest1!', PASSWORD_BCRYPT, ['cost' => 10]);
$insU = $pdo->prepare("INSERT OR REPLACE INTO users (id, email, password_hash, role, locale, status, email_verified_at) VALUES (?, ?, ?, ?, ?, 'active', datetime('now'))");
$insU->execute([701, 'canon-admin@test.local', $hash, 'admin', 'en']);
$insU->execute([702, 'canon-user@test.local', $hash, 'user', 'en']);
$adminToken = bin2hex(random_bytes(32));
$userToken = bin2hex(random_bytes(32));
$insS = $pdo->prepare("INSERT INTO user_sessions (user_id, refresh_token_hash, access_token_hash, expires_at) VALUES (?, ?, ?, datetime('now', '+1 day'))");
$insS->execute([701, hash('sha256', $adminToken), hash('sha256', 'unused-' . bin2hex(random_bytes(8)))]);
$insS->execute([702, hash('sha256', $userToken), hash('sha256', 'unused-' . bin2hex(random_bytes(8)))]);

// Real-HTTP harness: the built-in server via router.php — the same pattern as
// the CI e2e job — so statuses/headers are observed exactly as a browser
// would. VELORA_PROD=1 keeps served HTML byte-exact (no dev injection).
putenv('VELORA_PROD=1');
$port = 8600 + random_int(0, 300);
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
check(is_resource($server) && $ready, 'built-in server (router.php, VELORA_PROD=1) is up');

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
$cookieContains = function (array $result, string $needle): bool {
    foreach ($result['headers'] as $h) {
        if (stripos($h, 'Set-Cookie:') === 0 && strpos($h, $needle) !== false) {
            return true;
        }
    }
    return false;
};

// (1) THE core assertion: canonical entry serves v2.2 in place — 200, exact bytes, no redirect
$r = $httpCase('/admin/index.html', $adminToken);
check($r['status'] === 200, 'authorized admin /admin/index.html -> HTTP 200 (served in place, not redirected)');
check($headerValue($r, 'Location') === null, 'canonical admin entry sends NO Location header (no redirect)');
check($r['body_sha256'] === $shellSha, 'canonical admin entry serves the byte-exact Admin v2.2 artifact');
check(stripos((string) $headerValue($r, 'Cache-Control'), 'no-store') !== false, 'canonical admin entry is no-store');

// (2) Legacy localized admin routes no longer render the legacy admin
$r = $httpCase('/fa/admin/index.html', $adminToken);
check($r['status'] === 200 && $headerValue($r, 'Location') === null && $r['body_sha256'] === $shellSha, 'FA /fa/admin/index.html -> 200, exact v2.2 bytes (legacy page no longer served)');
check($cookieContains($r, 'velora_locale=fa'), 'FA entry refreshes velora_locale=fa (v2 shell boots FA/RTL)');
$r = $httpCase('/en/admin/index.html', $adminToken);
check($r['status'] === 200 && $headerValue($r, 'Location') === null && $r['body_sha256'] === $shellSha, 'EN /en/admin/index.html -> 200, exact v2.2 bytes (legacy page no longer served)');
check($cookieContains($r, 'velora_locale=en'), 'EN entry refreshes velora_locale=en (v2 shell boots EN/LTR)');

// (3) Authentication: anonymous -> existing login flow, unchanged
$r = $httpCase('/admin/index.html');
check($r['status'] === 302 && (bool) preg_match('#^/[a-z-]+/login/$#', (string) $headerValue($r, 'Location')), 'anonymous /admin/index.html -> 302 /{locale}/login/ (existing flow)');

// (4) Authorization: signed-in non-admin -> existing dashboard redirect, unchanged
$r = $httpCase('/admin/index.html', $userToken);
check($r['status'] === 302 && (bool) preg_match('#^/[a-z-]+/dashboard/$#', (string) $headerValue($r, 'Location')), 'signed-in non-admin -> 302 /{locale}/dashboard/ (unchanged, no admin access)');

// (5) Compatibility route: direct v2 URL still serves the exact artifact
$r = $httpCase('/admin/v2/index.html');
check($r['status'] === 200 && $r['body_sha256'] === $shellSha, 'compatibility /admin/v2/index.html -> 200, byte-exact v2.2 artifact');

if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}
putenv('VELORA_PROD');

echo "\nadmin-canonical-replacement: " . ($failures === 0 ? 'PASS' : 'FAIL') . " ({$checks} checks, {$failures} failures)\n";
exit($failures === 0 ? 0 : 1);
