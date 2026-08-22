<?php

declare(strict_types=1);

/**
 * TEST-23 — Session Rotation and Revoke.
 *
 * Session lifecycle contract:
 *   1. refresh() rotates the pair: new tokens are returned and the rotated-out
 *      tokens are dead immediately (no replay of an old refresh token, old
 *      access token no longer authenticates).
 *   2. logout() revokes the session: both tokens of that session die.
 *   3. changePassword() revokes ALL sessions of the user.
 *
 * GREEN pin: the lifecycle holds today — this test blocks regressions
 * (including the weak refresh-reuse surface noted in the audit: at minimum,
 * reuse must be rejected).
 *
 * Deterministic: temp SQLite + log mailer. No provider, no network, no secrets.
 */

$root = sys_get_temp_dir() . '/velora-session-rotation-' . bin2hex(random_bytes(5));
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
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$throws = static function (callable $fn, string $errorCode = ''): bool {
    try {
        $fn();
        return false;
    } catch (Velora\Core\Exceptions\UnauthorizedException $e) {
        return $errorCode === '' || $e->errorCode() === $errorCode;
    } catch (Throwable) {
        return false;
    }
};

try {
    $email = 'rotation@example.test';
    $password = 'RotateMe123';
    $pdo->prepare('INSERT INTO users (email,password_hash,full_name,email_verified_at) VALUES (?,?,?,?)')
        ->execute([$email, password_hash($password, PASSWORD_BCRYPT), 'Rotation User', gmdate('Y-m-d H:i:s')]);
    $userId = (int) $pdo->lastInsertId();

    $auth = new Velora\Auth\AuthService();

    // --- 1. Rotation ----------------------------------------------------------
    $pair1 = $auth->login(['email' => $email, 'password' => $password], '10.0.0.1', 'rotation-test');
    $expect(isset($pair1['accessToken'], $pair1['refreshToken']), 'login must return a token pair');

    $pair2 = $auth->refresh($pair1['refreshToken'], '10.0.0.1', 'rotation-test');
    $expect(
        $pair2['refreshToken'] !== $pair1['refreshToken'] && $pair2['accessToken'] !== $pair1['accessToken'],
        'refresh must rotate both tokens',
    );
    $expect(
        $throws(static fn () => $auth->refresh($pair1['refreshToken'], '10.0.0.1', 'rotation-test'), 'INVALID_TOKEN'),
        'replaying the rotated-out refresh token must be rejected (INVALID_TOKEN)',
    );
    $expect(
        $throws(static fn () => $auth->authenticate($pair1['accessToken'])),
        'the rotated-out access token must no longer authenticate',
    );
    $who = $auth->authenticate($pair2['accessToken']);
    $expect((int) ($who['id'] ?? 0) === $userId, 'the rotated-in access token must authenticate the same user');

    // --- 2. Logout -------------------------------------------------------------
    $auth->logout($pair2['refreshToken']);
    $expect(
        (int) $pdo->query('SELECT COUNT(*) FROM user_sessions WHERE user_id=' . $userId . ' AND revoked_at IS NOT NULL')->fetchColumn() === 1,
        'logout must mark the session revoked in the database',
    );
    $expect(
        $throws(static fn () => $auth->authenticate($pair2['accessToken'])),
        'after logout the access token of that session must die even before expiry',
    );
    $expect(
        $throws(static fn () => $auth->refresh($pair2['refreshToken'], '10.0.0.1', 'rotation-test')),
        'after logout the refresh token must be rejected',
    );
    $expect(
        $throws(static fn () => $auth->refresh(str_repeat('ab', 32), '10.0.0.1', 'rotation-test'), 'INVALID_TOKEN'),
        'a fabricated refresh token must be rejected',
    );

    // --- 3. Password change revokes everything ----------------------------------
    $pair3 = $auth->login(['email' => $email, 'password' => $password], '10.0.0.2', 'rotation-test');
    $pair4 = $auth->login(['email' => $email, 'password' => $password], '10.0.0.3', 'rotation-test');
    (new Velora\Auth\PasswordService())->changePassword($userId, [
        'currentPassword' => $password,
        'newPassword' => 'Rotated456',
    ]);
    $expect(
        (int) $pdo->query('SELECT COUNT(*) FROM user_sessions WHERE user_id=' . $userId . ' AND revoked_at IS NULL')->fetchColumn() === 0,
        'changePassword must revoke every active session of the user',
    );
    $expect(
        $throws(static fn () => $auth->authenticate($pair3['accessToken'])),
        'access tokens issued before the password change must die',
    );
    $expect(
        $throws(static fn () => $auth->refresh($pair4['refreshToken'], '10.0.0.3', 'rotation-test')),
        'refresh tokens issued before the password change must die',
    );

    // Old password must be gone, new one must work.
    $expect(
        $throws(static fn () => $auth->login(['email' => $email, 'password' => $password], '10.0.0.4', 'rotation-test'), 'INVALID_CREDENTIALS'),
        'the old password must be rejected after the change',
    );
    $pair5 = $auth->login(['email' => $email, 'password' => 'Rotated456'], '10.0.0.4', 'rotation-test');
    $expect(isset($pair5['accessToken']), 'login with the new password must succeed');
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

echo "TEST-23 PASS ({$assertions} assertions)\n";
exit(0);
