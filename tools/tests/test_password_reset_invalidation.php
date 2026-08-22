<?php

declare(strict_types=1);

/**
 * TEST-03 — After a password reset, authentication STATE must move:
 *   login(old password) MUST FAIL, login(new password) MUST SUCCEED.
 *
 * This goes beyond HTTP status codes: it drives AuthService::login against the
 * real repositories (temp SQLite) so the assertion is on the authentication
 * state machine itself — password hash, user gate, and session issuance.
 *
 * Deterministic: temp SQLite + MAIL_DRIVER=log. No provider, no network, no secrets.
 */

$root = sys_get_temp_dir() . '/velora-reset-invalidation-' . bin2hex(random_bytes(5));
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
    $email = 'reset-state@example.test';
    $oldPassword = 'OldPassword123!';
    $newPassword = 'NewPassword456!';
    $pdo->prepare('INSERT INTO users (email,password_hash,full_name,email_verified_at) VALUES (?,?,?,?)')
        ->execute([$email, password_hash($oldPassword, PASSWORD_BCRYPT), 'Reset State', gmdate('Y-m-d H:i:s')]);
    $userId = (int) $pdo->lastInsertId();

    $auth = new Velora\Auth\AuthService();

    // Baseline: old password authenticates before the reset.
    $baseline = $auth->login(['email' => $email, 'password' => $oldPassword], '10.0.0.1', 'TestAgent/1.0');
    $expect(is_string($baseline['accessToken']) && $baseline['accessToken'] !== '', 'baseline login must issue an access token');

    // Reset flow: consume a one-shot token and set the new password.
    $resetToken = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO password_resets (user_id,token_hash,expires_at) VALUES (?,?,?)')
        ->execute([$userId, hash('sha256', $resetToken), gmdate('Y-m-d H:i:s', time() + 3600)]);
    (new Velora\Auth\PasswordService())->resetPassword(['token' => $resetToken, 'newPassword' => $newPassword]);

    // STATE level: old password must now fail at the authentication layer.
    $oldLoginRejected = false;
    try {
        $auth->login(['email' => $email, 'password' => $oldPassword], '10.0.0.1', 'TestAgent/1.0');
    } catch (Velora\Core\Exceptions\UnauthorizedException $e) {
        $oldLoginRejected = $e->errorCode() === 'INVALID_CREDENTIALS';
    }
    $expect($oldLoginRejected, 'login with the OLD password must fail with INVALID_CREDENTIALS after reset');

    // STATE level: new password must authenticate and issue a fresh session.
    $newLogin = $auth->login(['email' => $email, 'password' => $newPassword], '10.0.0.2', 'TestAgent/1.1');
    $expect(is_string($newLogin['accessToken']) && $newLogin['accessToken'] !== '', 'login with the NEW password must issue an access token');
    $expect(is_string($newLogin['refreshToken']) && $newLogin['refreshToken'] !== '', 'login with the NEW password must issue a refresh token');
    $expect((int) ($newLogin['user']['id'] ?? 0) === $userId, 'new login must resolve to the same user');

    $hash = (string) $pdo->query('SELECT password_hash FROM users WHERE id=' . $userId)->fetchColumn();
    $expect(password_verify($newPassword, $hash), 'stored hash must accept the new password');
    $expect(!password_verify($oldPassword, $hash), 'stored hash must reject the old password');
    $expect(
        (int) $pdo->query("SELECT COUNT(*) FROM user_sessions WHERE user_id={$userId} AND revoked_at IS NULL")->fetchColumn() === 1,
        'only the fresh post-reset session may remain active',
    );

    echo "TEST-03 password reset invalidates old password: PASS ({$assertions} assertions)\n";
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
