<?php

declare(strict_types=1);

/**
 * TEST-24 — Rate Limit Enforcement.
 *
 * Two-layer contract:
 *   Static:  the sensitive auth endpoints must carry the documented buckets
 *            (login 8/300s, register 5/3600s, forgot 4/3600s, resend 4/3600s).
 *   Dynamic: RateLimiter must allow exactly maxAttempts hits per window and
 *            then reject with HTTP 429 TOO_MANY_REQUESTS; buckets and client
 *            IPs must be isolated from each other.
 *
 * GREEN pin: enforcement exists today — this test blocks its removal or
 * silent widening.
 *
 * Deterministic: temp SQLite (DB-backed limiter) + static source contract.
 * No provider, no network, no secrets.
 */

$root = sys_get_temp_dir() . '/velora-rate-limit-' . bin2hex(random_bytes(5));
mkdir($root . '/config', 0700, true);
mkdir($root . '/data', 0700, true);
mkdir($root . '/logs', 0700, true);
$dbPath = $root . '/data/velora.sqlite';
putenv('APP_ENV=local');
putenv('VELORA_PRIVATE_ROOT=' . $root);
putenv('VELORA_DOCUMENT_ROOT=' . dirname(__DIR__, 2));
file_put_contents($root . '/config/velora.env', implode("\n", [
    'APP_ENV=local',
    'APP_DEBUG=true',
    'DB_DRIVER=sqlite',
    'DB_DATABASE=' . $dbPath,
    'JWT_SECRET=' . str_repeat('j', 48),
    'APP_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)),
    'CORS_ALLOWED_ORIGINS=http://localhost',
    'FRONTEND_URL=http://localhost',
    'MAIL_DRIVER=log',
]) . "\n");

require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE rate_limits (bucket TEXT PRIMARY KEY, hits INTEGER NOT NULL, window_start TEXT NOT NULL)');

$assertions = 0;
$expect = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

try {
    // ---- Dynamic layer -------------------------------------------------------
    $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
    unset($_SERVER['HTTP_X_FORWARDED_FOR']);

    for ($i = 1; $i <= 3; $i++) {
        Velora\Core\RateLimiter::hit('test-login', 3, 60);
    }
    $expect(true, 'first 3 hits within the window must pass');

    $blocked = null;
    try {
        Velora\Core\RateLimiter::hit('test-login', 3, 60);
    } catch (Velora\Core\Exceptions\ApiException $e) {
        $blocked = $e;
    }
    $expect($blocked !== null, 'the 4th hit beyond maxAttempts must be rejected');
    $expect($blocked !== null && $blocked->httpStatus() === 429, 'rejection must be HTTP 429');
    $expect($blocked !== null && $blocked->errorCode() === 'TOO_MANY_REQUESTS', 'rejection code must be TOO_MANY_REQUESTS');
    $expect(
        $blocked !== null && $blocked->messageKey() === 'errors.rateLimited',
        'rejection must carry the errors.rateLimited message key',
    );

    // Bucket isolation: a different action is not affected.
    Velora\Core\RateLimiter::hit('test-register', 3, 60);
    $expect(true, 'a different bucket must not inherit the exhausted counter');

    // IP isolation: the same bucket from another IP is not affected.
    $_SERVER['REMOTE_ADDR'] = '198.51.100.8';
    Velora\Core\RateLimiter::hit('test-login', 3, 60);
    $expect(true, 'a different client IP must not inherit the exhausted counter');

    // Spoof guard: untrusted X-Forwarded-For must NOT bypass the limit.
    $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.99';
    $spoofBlocked = null;
    try {
        Velora\Core\RateLimiter::hit('test-login', 3, 60);
    } catch (Velora\Core\Exceptions\ApiException $e) {
        $spoofBlocked = $e;
    }
    $expect(
        $spoofBlocked !== null && $spoofBlocked->httpStatus() === 429,
        'an attacker-controlled X-Forwarded-For header must not reset the limiter (trusted-proxy only)',
    );

    // ---- Static layer: documented buckets on the sensitive endpoints --------
    $index = (string) file_get_contents(dirname(__DIR__, 2) . '/api/index.php');
    $expect(str_contains($index, "RateLimiter::hit('login', 8, 300)"), 'login bucket must stay at 8 attempts / 300s');
    $expect(str_contains($index, "RateLimiter::hit('register', 5, 3600)"), 'register bucket must stay at 5 attempts / 3600s');
    $expect(str_contains($index, "RateLimiter::hit('forgot', 4, 3600)"), 'forgot-password bucket must stay at 4 attempts / 3600s');
    $expect(str_contains($index, "RateLimiter::hit('resend-verification', 4, 3600)"), 'resend-verification bucket must stay at 4 attempts / 3600s');
    $expect(str_contains($index, "RateLimiter::hit('reset', 6, 3600)"), 'reset bucket must stay at 6 attempts / 3600s');
} finally {
    $delete = static function (string $path) use (&$delete): void {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $item) {
                if ($item !== '.' && $item !== '..') {
                    $delete($path . '/' . $item);
                }
            }
            @rmdir($path);
        } else {
            @unlink($path);
        }
    };
    $delete($root);
}

echo "TEST-24 PASS ({$assertions} assertions)\n";
exit(0);
