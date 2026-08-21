<?php

declare(strict_types=1);

/**
 * TEST-12 (Audit BUG-A5) — Password Policy Consistency.
 *
 * The SAME password candidate must receive the SAME verdict in all three
 * password entry points:
 *   - Registration        (AuthController register rules + AuthService)
 *   - Password Reset      (AuthController reset rules + PasswordService::assertResetPasswordRules)
 *   - Change Password     (AuthController change rules + PasswordService)
 *
 * Current drift (pins BUG-A5, RED until fixed):
 *   - reset enforces min:8 + letter + digit at service level,
 *   - register/change enforce min:8 only (controller level; nothing at service level).
 *
 * Deterministic: temp SQLite + log mailer; service-level probes + static rule
 * extraction. No provider, no network, no secrets.
 */

$root = sys_get_temp_dir() . '/velora-policy-' . bin2hex(random_bytes(5));
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
    'CREATE TABLE password_resets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, token_hash TEXT UNIQUE, expires_at TEXT, used_at TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE user_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, refresh_token_hash TEXT, access_token_hash TEXT, ip_address TEXT, user_agent TEXT, expires_at TEXT, revoked_at TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
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
    $repoRoot = dirname(__DIR__, 2);

    // ---- Static layer: controller rules must be one identical policy ------
    // Only password-CREATION flows are compared (register/change/reset). The
    // login rule intentionally omits min-length: any string must be accepted
    // there to be checked against the stored hash.
    $controller = (string) file_get_contents($repoRoot . '/api/src/Auth/AuthController.php');
    preg_match_all("/'(?:new)?password'\s*=>\s*'([^']+)'/u", $controller, $ruleMatches);
    $creationRules = array_values(array_filter($ruleMatches[1] ?? [], static fn (string $r): bool => str_contains($r, 'min:')));
    $rules = array_values(array_unique($creationRules));
    $check(
        $rules === ['required|string|min:8|max:72'],
        'register/change/reset password rules in AuthController must be one identical rule set (found: ' . implode(' | ', $rules) . ')',
    );

    $service = (string) file_get_contents($repoRoot . '/api/src/Auth/PasswordService.php');
    $check(
        (bool) preg_match('/function changePassword[\s\S]*assertResetPasswordRules/', $service),
        'changePassword must apply the same service-level strength policy as resetPassword (BUG-A5)',
    );
    $authService = (string) file_get_contents($repoRoot . '/api/src/Auth/AuthService.php');
    $check(
        str_contains($authService, 'assertResetPasswordRules') || str_contains($authService, 'assertPasswordRules'),
        'AuthService::register must apply the same service-level strength policy as resetPassword (BUG-A5)',
    );

    // ---- Dynamic layer: identical verdicts for identical candidates -------
    $makeUser = static function (string $email, string $password) use ($pdo): int {
        $pdo->prepare('INSERT INTO users (email,password_hash,full_name,email_verified_at) VALUES (?,?,?,?)')
            ->execute([$email, password_hash($password, PASSWORD_BCRYPT), 'Policy Probe', gmdate('Y-m-d H:i:s')]);
        return (int) $pdo->lastInsertId();
    };
    $verdict = static function (callable $fn): string {
        try {
            $fn();
            return 'accept';
        } catch (Throwable) {
            return 'reject';
        }
    };

    $candidates = ['AlphaBetaGamma', 'Abcdef12', 'tiny'];
    $flows = ['register', 'reset', 'change'];
    $i = 0;
    foreach ($candidates as $candidate) {
        $i++;
        $v = [];
        // register flow (fresh email per attempt)
        $v['register'] = $verdict(static function () use ($candidate, $i): void {
            (new Velora\Auth\AuthService())->register([
                'email' => "policy{$i}@example.test",
                'password' => $candidate,
                'full_name' => 'Policy Probe',
            ], '10.0.0.1', 'policy-test');
        });
        // reset flow (own token row with a known deterministic per-candidate token)
        $v['reset'] = $verdict(static function () use ($candidate, $makeUser, $pdo, $i): void {
            $uid = $makeUser("policy-reset{$i}@example.test", 'Current123');
            $token = hash('sha256', 'policy-probe-token-' . $i);
            $pdo->prepare('INSERT INTO password_resets (user_id,token_hash,expires_at) VALUES (?,?,?)')
                ->execute([$uid, hash('sha256', $token), gmdate('Y-m-d H:i:s', time() + 3600)]);
            (new Velora\Auth\PasswordService())->resetPassword(['token' => $token, 'newPassword' => $candidate]);
        });
        // change flow
        $v['change'] = $verdict(static function () use ($candidate, $makeUser, $i): void {
            $uid = $makeUser("policy-change{$i}@example.test", 'Current123');
            (new Velora\Auth\PasswordService())->changePassword($uid, [
                'currentPassword' => 'Current123',
                'newPassword' => $candidate,
            ]);
        });

        $check(
            $v['register'] === $v['reset'] && $v['reset'] === $v['change'],
            "candidate '{$candidate}' must get ONE verdict in all flows; got register={$v['register']} reset={$v['reset']} change={$v['change']} (BUG-A5)",
        );
    }

    // Explicit policy pin: a 8+ letter password with NO digit must be rejected
    // everywhere (reset already does; register/change must match).
    $i++;
    $weak = 'AlphaBetaGamma';
    $check(
        $verdict(static function () use ($weak, $i): void {
            (new Velora\Auth\AuthService())->register([
                'email' => "policy-weak{$i}@example.test",
                'password' => $weak,
                'full_name' => 'Policy Probe',
            ], '10.0.0.1', 'policy-test');
        }) === 'reject',
        "registration must reject '{$weak}' (8+ letters, no digit) exactly like resetPassword does (BUG-A5)",
    );
    $uidWeak = $makeUser("policy-weak-change{$i}@example.test", 'Current123');
    $check(
        $verdict(static function () use ($uidWeak, $weak): void {
            (new Velora\Auth\PasswordService())->changePassword($uidWeak, [
                'currentPassword' => 'Current123',
                'newPassword' => $weak,
            ]);
        }) === 'reject',
        "changePassword must reject '{$weak}' (8+ letters, no digit) exactly like resetPassword does (BUG-A5)",
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
    fwrite(STDERR, 'TEST-12 FAILED: ' . count($failures) . "/{$assertions} assertions failed\n");
    exit(1);
}
echo "TEST-12 PASS ({$assertions} assertions)\n";
exit(0);
