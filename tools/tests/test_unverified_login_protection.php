<?php

declare(strict_types=1);

/**
 * TEST-22 — Unverified Login Protection.
 *
 * A user whose email is not verified must NOT be able to authenticate:
 * the login attempt must fail with a clean EMAIL_NOT_VERIFIED signal (never
 * tokens, never a 500). After verification, the same credentials must produce
 * a complete token pair.
 *
 * GREEN pin: the gate exists today — this test blocks its removal.
 *
 * Deterministic: temp SQLite + log mailer. No provider, no network, no secrets.
 */

$root = sys_get_temp_dir() . '/velora-unverified-login-' . bin2hex(random_bytes(5));
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
foreach ([
    'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE, password_hash TEXT, full_name TEXT, role TEXT DEFAULT "user", timezone TEXT DEFAULT "UTC", status TEXT DEFAULT "active", email_verified_at TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE user_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, refresh_token_hash TEXT, access_token_hash TEXT, ip_address TEXT, user_agent TEXT, expires_at TEXT, revoked_at TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE user_devices (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, fingerprint TEXT, ip_address TEXT, user_agent TEXT, first_seen_at TEXT, last_seen_at TEXT)',
    'CREATE TABLE email_notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, event_type TEXT, recipient_email TEXT, subject TEXT, payload_json TEXT, status TEXT, sent_at TEXT, failed_at TEXT, error_message TEXT, created_at TEXT)',
] as $sql) {
    $pdo->exec($sql);
}

$assertions = 0;
$expect = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        // exit(1) bypasses the app's global exception handler so a failing
        // assertion can never be swallowed into a 500 JSON with exit code 0.
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

try {
    $email = 'gate@example.test';
    $password = 'GatePass123';
    $pdo->prepare('INSERT INTO users (email,password_hash,full_name,email_verified_at) VALUES (?,?,?,NULL)')
        ->execute([$email, password_hash($password, PASSWORD_BCRYPT), 'Gate User']);
    $userId = (int) $pdo->lastInsertId();

    $auth = new Velora\Auth\AuthService();

    // --- Gate: unverified credentials must be rejected -----------------------
    $blocked = null;
    try {
        $auth->login(['email' => $email, 'password' => $password], '10.0.0.1', 'gate-test');
    } catch (Velora\Core\Exceptions\UnauthorizedException $e) {
        $blocked = $e;
    }
    $expect($blocked !== null, 'login with an unverified email must be rejected');
    $expect(
        $blocked !== null && $blocked->errorCode() === 'EMAIL_NOT_VERIFIED',
        'rejection must carry the EMAIL_NOT_VERIFIED error code',
    );
    $expect(
        $blocked !== null && $blocked->httpStatus() === 401,
        'rejection must be a clean 401, never a server error',
    );
    $expect(
        (int) $pdo->query('SELECT COUNT(*) FROM user_sessions WHERE user_id=' . $userId)->fetchColumn() === 0,
        'no session row may be created for an unverified login attempt',
    );

    // --- After verification the same credentials must succeed ----------------
    $pdo->prepare('UPDATE users SET email_verified_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), $userId]);
    $pair = $auth->login(['email' => $email, 'password' => $password], '10.0.0.1', 'gate-test');
    $expect(
        isset($pair['accessToken'], $pair['refreshToken']) && $pair['accessToken'] !== '' && $pair['refreshToken'] !== '',
        'verified login must return a complete token pair',
    );
    $expect(
        (bool) preg_match('/^[0-9a-f]{64}$/', (string) $pair['refreshToken']),
        'refresh token must be 256-bit hex (random_bytes(32))',
    );
    $expect(
        (int) $pdo->query('SELECT COUNT(*) FROM user_sessions WHERE user_id=' . $userId . ' AND revoked_at IS NULL')->fetchColumn() === 1,
        'verified login must persist exactly one active session row',
    );
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

echo "TEST-22 PASS ({$assertions} assertions)\n";
exit(0);
