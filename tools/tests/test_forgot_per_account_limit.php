<?php

declare(strict_types=1);

/**
 * TEST-16 (Audit BUG-A11) — Forgot Password Per-Account Rate Limit.
 *
 * The forgot-password flow is throttled per IP (4/hour) but NOT per target
 * account: a distributed caller can trigger unlimited reset emails to one
 * victim address (email flooding / mailbox bomb). There must be a per-account
 * ceiling on reset emails within a rolling window.
 *
 * Expected: at most 3 reset emails per account per hour (mirrors the
 * verification-mail ceiling of 3/day already implemented in AuthService).
 *
 * Pin is RED until BUG-A11 is fixed: simulating 5 requests from five distinct
 * client IPs today produces 5 reset emails for one account.
 *
 * Deterministic: temp SQLite + log mailer. No provider, no network, no secrets.
 */

$root = sys_get_temp_dir() . '/velora-forgot-cap-' . bin2hex(random_bytes(5));
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
    'CREATE TABLE password_resets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, token_hash TEXT UNIQUE, expires_at TEXT, used_at TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
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
    $email = 'victim@example.test';
    $pdo->prepare('INSERT INTO users (email,password_hash,full_name,email_verified_at) VALUES (?,?,?,?)')
        ->execute([$email, password_hash('Secret123', PASSWORD_BCRYPT), 'Victim User', gmdate('Y-m-d H:i:s')]);
    $userId = (int) $pdo->lastInsertId();

    // Five requests from five distinct source IPs — the per-IP limiter is
    // blind to this; only a per-account ceiling can stop the flood.
    $attempts = 5;
    $errors = 0;
    for ($i = 1; $i <= $attempts; $i++) {
        $_SERVER['REMOTE_ADDR'] = "203.0.113.{$i}";
        try {
            (new Velora\Auth\PasswordService())->forgotPassword($email);
        } catch (Throwable) {
            $errors++;
        }
    }
    $check($errors === 0, "forgotPassword must not crash under repeated requests ({$errors} exceptions)");

    $emailsSent = (int) $pdo->query(
        "SELECT COUNT(*) FROM email_notifications WHERE event_type='PASSWORD_RESET_LINK' AND recipient_email='{$email}' AND status='sent'"
    )->fetchColumn();
    $check(
        $emailsSent <= 3,
        "one account must receive at most 3 reset emails per hour; got {$emailsSent} for {$attempts} requests (BUG-A11: no per-account cap)",
    );

    $mailLog = (string) @file_get_contents($root . '/logs/mail.log');
    $logCount = substr_count($mailLog, 'TO: ' . $email);
    $check(
        $logCount <= 3,
        "mailer evidence: at most 3 reset emails may leave the system per account per hour; logged {$logCount} (BUG-A11)",
    );

    // Green pin: a fresh token still exists for the legitimate latest request.
    $active = (int) $pdo->query(
        'SELECT COUNT(*) FROM password_resets WHERE user_id=' . $userId . ' AND used_at IS NULL'
    )->fetchColumn();
    $check($active >= 1, 'the latest reset request must leave one usable token row');
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
    fwrite(STDERR, 'TEST-16 FAILED: ' . count($failures) . "/{$assertions} assertions failed\n");
    exit(1);
}
echo "TEST-16 PASS ({$assertions} assertions)\n";
exit(0);
