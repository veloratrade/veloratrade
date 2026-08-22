<?php

declare(strict_types=1);

/**
 * PR-03 — user locale preference persistence.
 *
 * Validates the additive `users.locale` contract without touching any live
 * database or external service:
 *
 *   1. Column parity — the three new columns (locale, locale_source,
 *      locale_updated_at) exist in BOTH api/database/schema.sql and the
 *      migration api/database/migrations/add_user_locale_preference.sql with
 *      identical type/default; the migration is idempotent-guarded.
 *   2. Repository read/write — UserRepository::create() persists a default
 *      locale when none is given, and returns locale via findById/findByEmail.
 *   3. Update persistence — updateLocalePreference() writes locale +
 *      locale_source + locale_updated_at and returns true; unknown ids return
 *      false.
 *
 * Runs against a throwaway SQLite database under a temp VELORA_PRIVATE_ROOT.
 */

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? "PASS " : "FAIL ") . $name . ($detail !== '' ? " :: $detail" : "") . "\n";
    if (!$ok) {
        $failures++;
    }
}

$repoRoot = dirname(__DIR__, 2);
$schemaPath = $repoRoot . '/api/database/schema.sql';
$migrationPath = $repoRoot . '/api/database/migrations/add_user_locale_preference.sql';

// ---- 1. Column parity: schema.sql <-> migration ----------------------------

function extractColumns(string $sql): array
{
    // Match "name TYPE ... DEFAULT 'x'" or "name TYPE NULL" for our three columns.
    $cols = [];
    foreach (['locale', 'locale_source', 'locale_updated_at'] as $name) {
        // locale_source must not match inside locale_updated_at etc.; anchor with word boundary.
        $pattern = '/\b' . preg_quote($name, '/') . '\s+(VARCHAR\(\d+\)|DATETIME)\s+(NOT NULL\s+DEFAULT\s+\'[^\']*\'|NULL)/i';
        if (preg_match($pattern, $sql, $m)) {
            $cols[$name] = strtoupper(trim(preg_replace('/\s+/', ' ', $m[1] . ' ' . $m[2])));
        }
    }
    return $cols;
}

$schemaSql = (string) file_get_contents($schemaPath);
$migrationSql = (string) file_get_contents($migrationPath);

$schemaCols = extractColumns($schemaSql);
$migrationCols = extractColumns($migrationSql);

check('schema.sql has all three locale columns', count($schemaCols) === 3, json_encode($schemaCols));
check('migration has all three locale columns', count($migrationCols) === 3, json_encode($migrationCols));

$parity = true;
foreach (['locale', 'locale_source', 'locale_updated_at'] as $name) {
    if (($schemaCols[$name] ?? null) !== ($migrationCols[$name] ?? null)) {
        $parity = false;
        check("column parity: $name", false, 'schema=' . ($schemaCols[$name] ?? 'MISSING') . ' migration=' . ($migrationCols[$name] ?? 'MISSING'));
    } else {
        check("column parity: $name", true, $schemaCols[$name]);
    }
}
check('schema.sql and migration column contract identical', $parity);

check('migration is idempotent (information_schema guard)', str_contains($migrationSql, 'information_schema.COLUMNS'));
check('migration adds locale via guarded ALTER', str_contains($migrationSql, "ADD COLUMN locale VARCHAR(35) NOT NULL DEFAULT ''fa''"));
check('migration includes rollback instructions', str_contains($migrationSql, 'DROP COLUMN locale_updated_at'));

// ---- 2. Repository read/write on throwaway SQLite --------------------------

$root = sys_get_temp_dir() . '/velora-locale-pref-' . bin2hex(random_bytes(5));
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

use Velora\Auth\UserRepository;
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

$repo = new UserRepository();

// create() with explicit locale
$idA = $repo->create([
    'email' => 'a@example.test',
    'password_hash' => 'h',
    'full_name' => 'A',
    'timezone' => 'UTC',
    'locale' => 'en',
]);
$rowA = $repo->findById($idA);
check('create() persists explicit locale', ($rowA['locale'] ?? null) === 'en', (string) ($rowA['locale'] ?? 'null'));
check('create() default locale_source', ($rowA['locale_source'] ?? null) === 'default', (string) ($rowA['locale_source'] ?? 'null'));

// create() without locale → default 'fa'
$idB = $repo->create([
    'email' => 'b@example.test',
    'password_hash' => 'h',
    'full_name' => 'B',
    'timezone' => 'UTC',
]);
$rowB = $repo->findById($idB);
check('create() defaults locale to fa', ($rowB['locale'] ?? null) === 'fa', (string) ($rowB['locale'] ?? 'null'));

// findByEmail also returns locale
$rowByEmail = $repo->findByEmail('a@example.test');
check('findByEmail returns locale', ($rowByEmail['locale'] ?? null) === 'en', (string) ($rowByEmail['locale'] ?? 'null'));

// ---- 3. updateLocalePreference persistence --------------------------------

$okUpdate = $repo->updateLocalePreference($idB, 'en', 'user');
$rowB2 = $repo->findById($idB);
check('updateLocalePreference returns true for existing user', $okUpdate);
check('updateLocalePreference persists locale', ($rowB2['locale'] ?? null) === 'en');
check('updateLocalePreference persists source', ($rowB2['locale_source'] ?? null) === 'user');
// locale_updated_at is bookkeeping metadata and is not part of the repository
// SELECT contract; verify it directly against the database.
$updatedAt = $pdo->query('SELECT locale_updated_at FROM users WHERE id = ' . (int) $idB)->fetchColumn();
check('updateLocalePreference sets locale_updated_at', $updatedAt !== false && $updatedAt !== null && $updatedAt !== '');

$okMissing = $repo->updateLocalePreference(999999, 'en', 'user');
check('updateLocalePreference returns false for unknown user', $okMissing === false);

// Non-regression: findById still surfaces the legacy fields.
check('findById still returns timezone', ($rowA['timezone'] ?? null) === 'UTC');
check('findById still returns status', ($rowA['status'] ?? null) === 'active');

echo "\n";
if ($failures === 0) {
    echo "ALL CHECKS PASSED\n";
    exit(0);
}
echo "$failures CHECK(S) FAILED\n";
exit(1);
