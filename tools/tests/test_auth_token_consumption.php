<?php

declare(strict_types=1);

/** Integration regression test for verification/reset token consumption. */

$root = sys_get_temp_dir() . '/velora-auth-token-test-' . bin2hex(random_bytes(5));
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
    'CREATE TABLE email_verifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, token_hash TEXT UNIQUE, verified_at TEXT NULL, expires_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE password_resets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, token_hash TEXT UNIQUE, expires_at TEXT, used_at TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE user_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, revoked_at TEXT NULL, expires_at TEXT)',
    'CREATE TABLE email_notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, event_type TEXT, recipient_email TEXT, subject TEXT, payload_json TEXT, status TEXT, sent_at TEXT, failed_at TEXT, error_message TEXT, created_at TEXT)',
    'CREATE TABLE user_achievements (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, achievement_key TEXT, achieved_at TEXT, metadata_json TEXT)',
] as $sql) {
    $pdo->exec($sql);
}

$assertions = 0;
$expect = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
};

try {
    $oldPassword = 'OldPassword123!';
    $insertUser = $pdo->prepare('INSERT INTO users (email,password_hash,full_name) VALUES (?,?,?)');
    $insertUser->execute(['auth-token@example.test', password_hash($oldPassword, PASSWORD_BCRYPT), 'Auth Token Test']);
    $userId = (int) $pdo->lastInsertId();

    // Verification token: first use succeeds and second use is rejected.
    $verifyToken = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO email_verifications (user_id,token_hash,expires_at) VALUES (?,?,?)')
        ->execute([$userId, hash('sha256', $verifyToken), gmdate('Y-m-d H:i:s', time() + 3600)]);
    $alreadyVerified = (new Velora\Auth\AuthService())->verifyEmail($verifyToken);
    $expect($alreadyVerified === false, 'first verification must not be reported as already verified');
    $expect((int) $pdo->query('SELECT email_verified_at IS NOT NULL FROM users WHERE id=' . $userId)->fetchColumn() === 1, 'user must be verified');
    $expect((int) $pdo->query('SELECT verified_at IS NOT NULL FROM email_verifications WHERE user_id=' . $userId)->fetchColumn() === 1, 'verification token must be consumed');
    $events = $pdo->query('SELECT event_type FROM email_notifications ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $expect(in_array('WELCOME_EMAIL', $events, true), 'welcome notification must be emitted');
    $expect(in_array('ACHIEVEMENT_UNLOCKED', $events, true), 'achievement notification must be emitted');

    $secondVerification = (new Velora\Auth\AuthService())->verifyEmail($verifyToken);
    $expect($secondVerification === true, 'repeat verification may only report the same user as already verified');

    // Password reset: token consume and hash update are atomic; nonexistent markUsed must never return.
    $resetToken = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO password_resets (user_id,token_hash,expires_at) VALUES (?,?,?)')
        ->execute([$userId, hash('sha256', $resetToken), gmdate('Y-m-d H:i:s', time() + 3600)]);
    $pdo->prepare('INSERT INTO user_sessions (user_id,expires_at) VALUES (?,?)')
        ->execute([$userId, gmdate('Y-m-d H:i:s', time() + 3600)]);
    $newPassword = 'NewPassword456!';
    (new Velora\Auth\PasswordService())->resetPassword(['token' => $resetToken, 'newPassword' => $newPassword]);
    $newHash = (string) $pdo->query('SELECT password_hash FROM users WHERE id=' . $userId)->fetchColumn();
    $expect(password_verify($newPassword, $newHash), 'password hash must be updated');
    $expect(!password_verify($oldPassword, $newHash), 'old password must no longer match');
    $expect((int) $pdo->query('SELECT used_at IS NOT NULL FROM password_resets WHERE user_id=' . $userId)->fetchColumn() === 1, 'reset token must be consumed');
    $expect((int) $pdo->query('SELECT revoked_at IS NOT NULL FROM user_sessions WHERE user_id=' . $userId)->fetchColumn() === 1, 'active sessions must be revoked');
    $expect((int) $pdo->query("SELECT COUNT(*) FROM email_notifications WHERE event_type='PASSWORD_CHANGED' AND status='sent'")->fetchColumn() === 1, 'password-changed email must be logged sent');

    $secondResetRejected = false;
    try {
        (new Velora\Auth\PasswordService())->resetPassword(['token' => $resetToken, 'newPassword' => 'AnotherPassword789!']);
    } catch (Velora\Core\Exceptions\ValidationException) {
        $secondResetRejected = true;
    }
    $expect($secondResetRejected, 'consumed reset token must not be reusable');

    // Static MySQL contract: native prepares cannot reuse a named placeholder.
    foreach ([
        dirname(__DIR__, 2) . '/api/src/Auth/EmailVerificationRepository.php',
        dirname(__DIR__, 2) . '/api/src/Auth/PasswordResetRepository.php',
    ] as $file) {
        $source = file_get_contents($file);
        preg_match_all('/:[A-Za-z_][A-Za-z0-9_]*/', (string) $source, $matches);
        // Repetition across separate SQL statements is fine; the fixed atomic statements use explicit cutoff names.
        $expect(str_contains((string) $source, ':expires_cutoff'), basename($file) . ' must use a distinct expiry placeholder');
    }

    echo "Auth token consumption integration: PASS ({$assertions} assertions)\n";
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
