<?php

declare(strict_types=1);

/**
 * TEST-10 — Change-password state regression.
 *
 * login -> changePassword -> old-password login MUST FAIL, new-password login
 * MUST SUCCEED; every pre-existing session MUST be revoked; the stored hash
 * MUST match only the new password; the password-changed notification email
 * MUST be triggered and logged.
 *
 * Deterministic: temp SQLite + MAIL_DRIVER=log. No provider, no network, no secrets.
 */

$root = sys_get_temp_dir() . '/velora-change-password-' . bin2hex(random_bytes(5));
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
    $email = 'change-password@example.test';
    $oldPassword = 'OldPassword123!';
    $newPassword = 'NewPassword456!';
    $pdo->prepare('INSERT INTO users (email,password_hash,full_name,email_verified_at) VALUES (?,?,?,?)')
        ->execute([$email, password_hash($oldPassword, PASSWORD_BCRYPT), 'Change Password', gmdate('Y-m-d H:i:s')]);
    $userId = (int) $pdo->lastInsertId();

    $auth = new Velora\Auth\AuthService();

    // Two live sessions: one from this login, one seeded as a "second device".
    $auth->login(['email' => $email, 'password' => $oldPassword], '10.0.0.1', 'TestAgent/1.0');
    $pdo->prepare('INSERT INTO user_sessions (user_id,refresh_token_hash,access_token_hash,expires_at) VALUES (?,?,?,?)')
        ->execute([$userId, hash('sha256', bin2hex(random_bytes(32))), hash('sha256', bin2hex(random_bytes(32))), gmdate('Y-m-d H:i:s', time() + 3600)]);
    $expect(
        (int) $pdo->query("SELECT COUNT(*) FROM user_sessions WHERE user_id={$userId} AND revoked_at IS NULL")->fetchColumn() === 2,
        'precondition: two active sessions',
    );

    (new Velora\Auth\PasswordService())->changePassword($userId, [
        'currentPassword' => $oldPassword,
        'newPassword' => $newPassword,
    ]);

    // Wrong current password must be refused (separate negative guard).
    $wrongCurrentRejected = false;
    try {
        (new Velora\Auth\PasswordService())->changePassword($userId, [
            'currentPassword' => 'NotTheCurrent1!',
            'newPassword' => 'TrickyPassword22!',
        ]);
    } catch (Velora\Core\Exceptions\ValidationException) {
        $wrongCurrentRejected = true;
    }
    $expect($wrongCurrentRejected, 'change-password must reject a wrong current password');

    // STATE: old fails, new succeeds at the authentication layer.
    $oldRejected = false;
    try {
        $auth->login(['email' => $email, 'password' => $oldPassword], '10.0.0.1', 'TestAgent/1.0');
    } catch (Velora\Core\Exceptions\UnauthorizedException $e) {
        $oldRejected = $e->errorCode() === 'INVALID_CREDENTIALS';
    }
    $expect($oldRejected, 'old password must fail after change');
    $newLogin = $auth->login(['email' => $email, 'password' => $newPassword], '10.0.0.2', 'TestAgent/1.1');
    $expect(is_string($newLogin['accessToken']) && $newLogin['accessToken'] !== '', 'new password must authenticate');

    $hash = (string) $pdo->query('SELECT password_hash FROM users WHERE id=' . $userId)->fetchColumn();
    $expect(password_verify($newPassword, $hash), 'hash must accept the new password');
    $expect(!password_verify($oldPassword, $hash), 'hash must reject the old password');

    // Session invalidation: both pre-change sessions revoked; only the fresh login is active.
    $expect(
        (int) $pdo->query("SELECT COUNT(*) FROM user_sessions WHERE user_id={$userId} AND revoked_at IS NOT NULL")->fetchColumn() === 2,
        'all pre-change sessions must be revoked',
    );
    $expect(
        (int) $pdo->query("SELECT COUNT(*) FROM user_sessions WHERE user_id={$userId} AND revoked_at IS NULL")->fetchColumn() === 1,
        'only the post-change login session may be active',
    );

    // Security notification email triggered + logged.
    $expect(
        (int) $pdo->query("SELECT COUNT(*) FROM email_notifications WHERE event_type='PASSWORD_CHANGED' AND recipient_email='{$email}' AND status='sent'")->fetchColumn() >= 1,
        'password-changed notification email must be logged as sent',
    );
    $mailLog = (string) @file_get_contents($root . '/logs/mail.log');
    $expect(str_contains($mailLog, 'TO: ' . $email), 'password-changed email must actually be emitted (log driver evidence)');

    echo "TEST-10 change-password state regression: PASS ({$assertions} assertions)\n";
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
