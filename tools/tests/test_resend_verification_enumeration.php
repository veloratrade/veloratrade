<?php

declare(strict_types=1);

/**
 * TEST-13 (Audit BUG-A7) — Resend Verification Enumeration Protection.
 *
 * The resend-verification endpoint must NOT reveal account existence or
 * verification status. Responses for an unknown address, a registered-but-
 * unverified address, and an already-verified address must be INDISTINGUISHABLE
 * (same shape, same messageKey, same sent semantics).
 *
 * Current behavior leaks via a distinct verified branch
 * (sent=false, alreadyVerified=true, messageKey=auth.emailAlreadyVerified) —
 * pinned RED until fixed.
 *
 * Deterministic: temp SQLite + log mailer. No provider, no network, no secrets.
 */

$root = sys_get_temp_dir() . '/velora-resend-enum-' . bin2hex(random_bytes(5));
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
    'CREATE TABLE email_verifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, token_hash TEXT, expires_at TEXT, verified_at TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
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
    $pdo->prepare('INSERT INTO users (email,password_hash,full_name,email_verified_at) VALUES (?,?,?,NULL)')
        ->execute(['unverified@example.test', password_hash('Secret123', PASSWORD_BCRYPT), 'Unverified User']);
    $pdo->prepare('INSERT INTO users (email,password_hash,full_name,email_verified_at) VALUES (?,?,?,?)')
        ->execute(['verified@example.test', password_hash('Secret123', PASSWORD_BCRYPT), 'Verified User', gmdate('Y-m-d H:i:s')]);

    $service = new Velora\Auth\AuthService();
    $responses = [];
    foreach (['ghost@example.test', 'unverified@example.test', 'verified@example.test'] as $email) {
        $responses[$email] = $service->resendVerification(['email' => $email]);
        $check(
            is_array($responses[$email]) && isset($responses[$email]['messageKey'], $responses[$email]['sent']),
            "resend for {$email} must return the standard response envelope",
        );
    }

    $ghost = $responses['ghost@example.test'];
    $unverified = $responses['unverified@example.test'];
    $verified = $responses['verified@example.test'];

    // A7 pins: the three responses must be indistinguishable.
    $check(
        $ghost['messageKey'] === $unverified['messageKey'],
        'unknown vs registered-unverified addresses must share one generic messageKey (account-existence oracle)',
    );
    $check(
        $verified['messageKey'] === $unverified['messageKey'],
        "verified addresses must not get a distinct messageKey (got '{$verified['messageKey']}' vs '{$unverified['messageKey']}') — verification-status oracle (BUG-A7)",
    );
    $check(
        (bool) $verified['sent'] === (bool) $unverified['sent'],
        'verified addresses must not flip the sent flag (verification-status oracle, BUG-A7)',
    );
    $check(
        !array_key_exists('alreadyVerified', $verified) || $verified['alreadyVerified'] === $unverified['alreadyVerified'],
        'verified addresses must not expose an alreadyVerified discriminator (BUG-A7)',
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
    fwrite(STDERR, 'TEST-13 FAILED: ' . count($failures) . "/{$assertions} assertions failed\n");
    exit(1);
}
echo "TEST-13 PASS ({$assertions} assertions)\n";
exit(0);
