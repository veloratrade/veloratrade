<?php

declare(strict_types=1);

/**
 * TEST-17 (Audit BUG-A12) — Email Notification User Integrity.
 *
 * EmailNotificationRepository::log() currently coerces any null/invalid user id
 * to 0 and swallows every Throwable. Consequences:
 *   - SQLite/loose setups: a phantom row referencing a non-existent user
 *     silently persists (orphan reference).
 *   - MySQL production (FK on user_id): the insert violates the FK and is
 *     swallowed silently — the notification audit trail is lost with no signal.
 *
 * Contract: a notification must reference a real user. Attempts to log with a
 * non-existent user reference must surface loudly (exception), never be
 * coerced to 0 or swallowed.
 *
 * Pins are RED until BUG-A12 is fixed. Deterministic: temp SQLite, log mailer.
 */

$root = sys_get_temp_dir() . '/velora-notif-integrity-' . bin2hex(random_bytes(5));
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
// NOTE: SQLite defaults to foreign_keys=OFF, mirroring the loosest environment
// in which the application can run. The contract must hold even here.
foreach ([
    'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE, password_hash TEXT, full_name TEXT, role TEXT DEFAULT "user", timezone TEXT DEFAULT "UTC", status TEXT DEFAULT "active", email_verified_at TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE email_notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, event_type TEXT, recipient_email TEXT, subject TEXT, payload_json TEXT, status TEXT, sent_at TEXT, failed_at TEXT, error_message TEXT, created_at TEXT)',
] as $sql) {
    $pdo->exec($sql);
}

$assertions = 0;
$failures = [];
$check = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
    $assertions++;
    if (!$condition) {
        $failures[] = $message;
    }
};

try {
    $pdo->prepare('INSERT INTO users (email,password_hash,full_name,email_verified_at) VALUES (?,?,?,?)')
        ->execute(['real@example.test', password_hash('Secret123', PASSWORD_BCRYPT), 'Real User', gmdate('Y-m-d H:i:s')]);
    $realId = (int) $pdo->lastInsertId();

    $repo = new Velora\Core\EmailNotificationRepository();

    // Control: a valid user reference must be persisted.
    $repo->log($realId, 'real@example.test', 'VERIFICATION_EMAIL', 'control');
    $valid = (int) $pdo->query('SELECT COUNT(*) FROM email_notifications WHERE user_id=' . $realId)->fetchColumn();
    $check($valid === 1, 'control: notification with a valid user reference must be persisted');

    // Pin 1: logging against a non-existent user must surface loudly.
    $loudInvalid = false;
    try {
        $repo->log(99999, 'nobody@example.test', 'VERIFICATION_EMAIL', 'invalid-user');
    } catch (Throwable) {
        $loudInvalid = true;
    }
    $check($loudInvalid, 'logging a notification for a non-existent user must raise an error, not be swallowed (BUG-A12)');

    // Pin 2: a null/guest reference must not be silently coerced to user_id=0.
    $loudNull = false;
    try {
        $repo->log(null, 'guest@example.test', 'VERIFICATION_EMAIL', 'guest-user');
    } catch (Throwable) {
        $loudNull = true;
    }
    $check($loudNull, 'a null user reference must raise an error instead of being coerced to user_id=0 (BUG-A12)');

    // Pin 3: no phantom references in the audit trail.
    $phantoms = (int) $pdo->query(
        'SELECT COUNT(*) FROM email_notifications WHERE user_id NOT IN (SELECT id FROM users)'
    )->fetchColumn();
    $check(
        $phantoms === 0,
        "audit trail must never reference a non-existent user; found {$phantoms} phantom row(s) (BUG-A12)",
    );
} finally {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
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

if ($failures !== []) {
    fwrite(STDERR, 'TEST-17 FAILED: ' . count($failures) . "/{$assertions} assertions failed\n");
    exit(1);
}
echo "TEST-17 PASS ({$assertions} assertions)\n";
exit(0);
