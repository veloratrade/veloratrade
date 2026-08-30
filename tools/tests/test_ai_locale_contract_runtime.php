<?php

declare(strict_types=1);

/**
 * VELORA — G8 AI Locale Contract RUNTIME test (real code path + real DB).
 *
 * Executes the REAL AIController::resolveAiLocale() (G8) via reflection against
 * a throwaway SQLite database seeded with users whose persisted canonical
 * locale differs, and proves the resolution chain:
 *
 *   R1  explicit validated client locale wins        ('en' body + fa user -> en)
 *   R2  missing body locale -> persisted user locale ('fa' user -> fa)
 *   R2b missing body locale -> persisted user locale ('en' user -> en)
 *   R3  invalid body locale -> persisted user locale ('xx' body + fa user -> fa)
 *   R4  invalid persisted locale -> 'en' fallback
 *   R5  unknown user -> 'en' fallback (never an exception)
 *   R6  malformed body locale (array) -> treated as not provided
 *
 * Fail-loud: any assertion failure exits 1. Isolated under a temp
 * VELORA_PRIVATE_ROOT; never touches real data. Same harness pattern as
 * test_user_locale_preference.php (PR-03).
 */

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? "PASS " : "FAIL ") . $name . ($detail !== '' ? " :: $detail" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
}

$repoRoot = dirname(__DIR__, 2);
$root = sys_get_temp_dir() . '/velora-ai-g8-' . bin2hex(random_bytes(5));
mkdir($root . '/config', 0700, true);
mkdir($root . '/data', 0700, true);
putenv('APP_ENV=local');
putenv('VELORA_PRIVATE_ROOT=' . $root);
putenv('VELORA_DOCUMENT_ROOT=' . $repoRoot);
file_put_contents($root . '/config/velora.env', implode("\n", [
    'APP_ENV=local',
    'APP_DEBUG=true',
    'DB_DRIVER=sqlite',
    'JWT_SECRET=' . str_repeat('j', 48),
    'APP_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)),
    'CORS_ALLOWED_ORIGINS=http://localhost',
    'FRONTEND_URL=http://localhost',
    'MAIL_DRIVER=log',
]) . "\n");

require $repoRoot . '/api/src/bootstrap.php';

use Velora\AI\Controllers\AIController;
use Velora\Core\Database;

$pdo = Database::connection();
$pdo->exec("CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    full_name TEXT NOT NULL DEFAULT '',
    role TEXT NOT NULL DEFAULT 'user',
    timezone TEXT NOT NULL DEFAULT 'UTC',
    locale TEXT NOT NULL DEFAULT 'fa',
    locale_source TEXT NOT NULL DEFAULT 'default',
    locale_updated_at TEXT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    email_verified_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");

$insert = $pdo->prepare(
    'INSERT INTO users (id,email,password_hash,full_name,role,timezone,locale,locale_source,status)
     VALUES (?,?,?,?,?,?,?,?,?)'
);
$insert->execute([901, 'fa@velora.test', password_hash('x', PASSWORD_BCRYPT), 'FA User', 'user', 'UTC', 'fa', 'user_persisted', 'active']);
$insert->execute([902, 'en@velora.test', password_hash('x', PASSWORD_BCRYPT), 'EN User', 'user', 'UTC', 'en', 'user_persisted', 'active']);
$insert->execute([903, 'zz@velora.test', password_hash('x', PASSWORD_BCRYPT), 'ZZ User', 'user', 'UTC', 'zz', 'user_persisted', 'active']);

// ---- invoke the REAL method ---------------------------------------------
$controller = new AIController();
$m = new ReflectionMethod(AIController::class, 'resolveAiLocale');
$invoke = fn (int $userId, $bodyLocale): string => (string) $m->invoke($controller, $userId, $bodyLocale);

check('R1 explicit body locale wins over persisted', $invoke(901, 'en') === 'en', $invoke(901, 'en'));
check('R2 missing body locale -> persisted fa', $invoke(901, null) === 'fa', $invoke(901, null));
check('R2b missing body locale -> persisted en', $invoke(902, null) === 'en', $invoke(902, null));
check('R3 invalid body locale -> persisted user locale', $invoke(901, 'xx') === 'fa', $invoke(901, 'xx'));
check('R4 invalid persisted locale -> en fallback', $invoke(903, null) === 'en', $invoke(903, null));
check('R5 unknown user -> en fallback', $invoke(999, null) === 'en', $invoke(999, null));
check('R6 malformed body locale (array) -> persisted', $invoke(901, ['fa']) === 'fa', 'array body');

// Cleanup.
@array_map('unlink', glob($root . '/data/*') ?: []);
@rmdir($root . '/data');
@rmdir($root . '/config');
@rmdir($root);

if ($failures > 0) {
    fwrite(STDERR, "\nG8-RUNTIME FAIL — {$failures} assertion(s) failed.\n");
    exit(1);
}
echo "\nG8-RUNTIME PASS — AIController::resolveAiLocale verified against a real SQLite DB.\n";
