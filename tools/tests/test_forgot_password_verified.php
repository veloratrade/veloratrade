<?php

declare(strict_types=1);

/**
 * TEST-02 — Forgot-password happy path for a VERIFIED user.
 *
 * Expected side effects of PasswordService::forgotPassword():
 *   - exactly one fresh password_resets row (used_at NULL, expires_at in future),
 *   - the reset email is actually triggered (mail.log entry via MAIL_DRIVER=log),
 *   - the notification is logged as sent in email_notifications,
 *   - no exception; no user/session mutation.
 *
 * Deterministic: temp SQLite + log mail driver. No provider, no network, no secrets.
 */

$root = sys_get_temp_dir() . '/velora-forgot-verified-' . bin2hex(random_bytes(5));
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
    $email = 'verified-user@example.test';
    $pdo->prepare('INSERT INTO users (email,password_hash,full_name,email_verified_at) VALUES (?,?,?,?)')
        ->execute([$email, password_hash('OldPassword123!', PASSWORD_BCRYPT), 'Verified User', gmdate('Y-m-d H:i:s')]);
    $userId = (int) $pdo->lastInsertId();

    (new Velora\Auth\PasswordService())->forgotPassword($email);
    // Reaching here without exception is the service-level success signal.

    $count = (int) $pdo->query('SELECT COUNT(*) FROM password_resets WHERE user_id=' . $userId)->fetchColumn();
    $expect($count === 1, 'exactly one reset token row must be created');
    $expect(
        $pdo->query('SELECT used_at FROM password_resets WHERE user_id=' . $userId)->fetchColumn() === null,
        'fresh reset token must be unused',
    );
    $expiresAt = (string) $pdo->query('SELECT expires_at FROM password_resets WHERE user_id=' . $userId)->fetchColumn();
    $expect(strtotime($expiresAt) > time(), 'reset token must carry a future expiry');
    $tokenHash = (string) $pdo->query('SELECT token_hash FROM password_resets WHERE user_id=' . $userId)->fetchColumn();
    $expect((bool) preg_match('/^[0-9a-f]{64}$/', $tokenHash), 'only the sha256 hash may be stored');

    $expect(
        (int) $pdo->query("SELECT COUNT(*) FROM email_notifications WHERE event_type='PASSWORD_RESET_LINK' AND recipient_email='{$email}' AND status='sent'")->fetchColumn() === 1,
        'reset email must be logged in email_notifications as sent',
    );
    $mailLog = (string) @file_get_contents($root . '/logs/mail.log');
    $expect(str_contains($mailLog, 'TO: ' . $email), 'mailer must actually emit the reset email (log driver evidence)');
    $expect(!str_contains($mailLog, 'token='), 'logged link must keep the capability token redacted');
    $expect(
        (int) $pdo->query('SELECT COUNT(*) FROM user_sessions WHERE user_id=' . $userId)->fetchColumn() === 0,
        'forgot-password must not create any session',
    );

    echo "TEST-02 forgot-password verified-user flow: PASS ({$assertions} assertions)\n";
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
