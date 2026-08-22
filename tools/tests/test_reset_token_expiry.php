<?php

declare(strict_types=1);

/**
 * TEST-05 — An expired reset token must be rejected and must not change anything.
 *
 * Deterministic: temp SQLite + MAIL_DRIVER=log. No provider, no network, no secrets.
 */

$root = sys_get_temp_dir() . '/velora-reset-expiry-' . bin2hex(random_bytes(5));
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
    'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE, password_hash TEXT, full_name TEXT, role TEXT DEFAULT "user", timezone TEXT DEFAULT "UTC", locale TEXT NOT NULL DEFAULT "fa", locale_source TEXT NOT NULL DEFAULT "default", locale_updated_at TEXT NULL, status TEXT DEFAULT "active", email_verified_at TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE password_resets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, token_hash TEXT UNIQUE, expires_at TEXT, used_at TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
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
        // NOTE: exit(1) bypasses the app's global exception handler so a failing
        // assertion can never be swallowed into a 500 JSON with exit code 0.
        // The enclosing finally {} block still runs and cleans up temp state.
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

try {
    $email = 'reset-expired@example.test';
    $oldPassword = 'OldPassword123!';
    $oldHash = password_hash($oldPassword, PASSWORD_BCRYPT);
    $pdo->prepare('INSERT INTO users (email,password_hash,full_name,email_verified_at) VALUES (?,?,?,?)')
        ->execute([$email, $oldHash, 'Expired Token', gmdate('Y-m-d H:i:s')]);
    $userId = (int) $pdo->lastInsertId();

    // Token whose expiry is one hour in the past.
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO password_resets (user_id,token_hash,expires_at) VALUES (?,?,?)')
        ->execute([$userId, hash('sha256', $token), gmdate('Y-m-d H:i:s', time() - 3600)]);

    $rejected = false;
    try {
        (new Velora\Auth\PasswordService())->resetPassword(['token' => $token, 'newPassword' => 'NewPassword456!']);
    } catch (Velora\Core\Exceptions\ValidationException) {
        $rejected = true;
    }
    $expect($rejected, 'expired reset token must be rejected');
    $expect(
        (string) $pdo->query('SELECT password_hash FROM users WHERE id=' . $userId)->fetchColumn() === $oldHash,
        'expired token attempt must not change the password hash',
    );
    $expect(
        $pdo->query('SELECT used_at FROM password_resets WHERE user_id=' . $userId)->fetchColumn() === null,
        'expired token row must remain unconsumed (nothing silently succeeds)',
    );

    echo "TEST-05 expired reset token rejected: PASS ({$assertions} assertions)\n";
} finally {
    $delete = static function (string $path) use (&$delete): void {
        if (is_dir($path)) {
            foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
                $delete($path . '/' . $item);
            }
            @rmdir($path);
        } else {
            @unlink($path);
        }
    };
    $delete($root);
}
