<?php

declare(strict_types=1);

/**
 * TEST-01 (Audit BUG-A1) — Regression pin.
 *
 * Forgot-password for an UNVERIFIED user (email_verified_at IS NULL) must NOT:
 *   - create a password_resets row,
 *   - trigger any reset email (no PASSWORD_RESET_LINK notification, no mail out),
 *   - mutate the user row (status must stay pending-verification),
 *   - create any session.
 *
 * The exact API wording is left to the owner-approved policy; this test asserts
 * only the side effects, so it survives whatever response envelope is chosen.
 *
 * Deterministic: temp SQLite + MAIL_DRIVER=log. No provider, no network, no secrets.
 */

$root = sys_get_temp_dir() . '/velora-forgot-unverified-' . bin2hex(random_bytes(5));
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
    $email = 'unverified-user@example.test';
    $password = 'OldPassword123!';
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $pdo->prepare('INSERT INTO users (email,password_hash,full_name,email_verified_at) VALUES (?,?,?,NULL)')
        ->execute([$email, $hash, 'Unverified User']);
    $userId = (int) $pdo->lastInsertId();

    // The owner-approved policy may respond softly or with an auth error; both are
    // acceptable as long as the side effects below stay zero.
    try {
        (new Velora\Auth\PasswordService())->forgotPassword($email);
    } catch (Velora\Core\Exceptions\ValidationException|Velora\Core\Exceptions\UnauthorizedException) {
        // Policy may reject explicitly — side effects are what this pin checks.
    }

    $expect(
        (int) $pdo->query('SELECT COUNT(*) FROM password_resets WHERE user_id=' . $userId)->fetchColumn() === 0,
        'unverified user must not receive a reset token row',
    );
    $expect(
        (int) $pdo->query("SELECT COUNT(*) FROM email_notifications WHERE event_type='PASSWORD_RESET_LINK' AND recipient_email='{$email}'")->fetchColumn() === 0,
        'no PASSWORD_RESET_LINK notification may be logged for unverified user',
    );
    $mailLog = $root . '/logs/mail.log';
    $mailLogHadReset = is_file($mailLog) && str_contains((string) file_get_contents($mailLog), $email);
    $expect(!$mailLogHadReset, 'mailer must not emit any message to the unverified user');
    $expect(
        $pdo->query('SELECT email_verified_at FROM users WHERE id=' . $userId)->fetchColumn() === null,
        'user must stay pending verification (email_verified_at remains NULL)',
    );
    $expect(
        (string) $pdo->query('SELECT password_hash FROM users WHERE id=' . $userId)->fetchColumn() === $hash,
        'user password hash must be untouched',
    );
    $expect(
        (int) $pdo->query('SELECT COUNT(*) FROM user_sessions WHERE user_id=' . $userId)->fetchColumn() === 0,
        'unverified forgot-password flow must not create any session',
    );

    echo "TEST-01 forgot-password blocked for unverified user: PASS ({$assertions} assertions)\n";
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
