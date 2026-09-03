<?php
declare(strict_types=1);
/**
 * PHASE K — Schema completeness release gate.
 *
 * Guards against the Phase J P1 blocker recurring: the production installer
 * (/api/install.php) builds a FRESH database from database/schema.sql, and the
 * SQLite dev/test runtime is bootstrapped by api/init-sqlite.php. Every table
 * and (for users) every column the delivered runtime code references MUST exist
 * in BOTH canonical schema sources. If the dependencies exist only in uncommitted
 * migrations or in dev-only tools/dev/serve_db.php, this gate FAILS.
 *
 * Read-only: it does not modify any database.
 */

$root = dirname(__DIR__, 2);
$schema = $root . '/api/database/schema.sql';
$init = $root . '/api/init-sqlite.php';

function tablesIn(string $path): array {
    $s = file_get_contents($path);
    preg_match_all('/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+([a-z_]+)/i', (string)$s, $m);
    return array_map('strtolower', $m[1]);
}
function hasTable(string $path, string $t): bool {
    return (bool)preg_match('/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+'.preg_quote($t,'/').'\b/i', (string)file_get_contents($path));
}
function usersCols(string $path): array {
    preg_match_all('/^\s*`?([a-z_]+)`?\s+/mi', usersBody($path), $c);
    return array_map('strtolower', $c[1]);
}
// Extract the column names of an arbitrary CREATE TABLE statement by name,
// balancing parens (safe against nested ENUM(...) / COMMENT strings).
function tableCols(string $path, string $table): array {
    $s = (string)file_get_contents($path);
    if (!preg_match('/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+'.preg_quote($table,'/').'\s*\(/i', $s, $m, PREG_OFFSET_CAPTURE)) return [];
    $i = $m[0][1] + strlen($m[0][0]); $depth = 1; $len = strlen($s); $end = -1;
    for (; $i < $len; $i++) { $ch = $s[$i]; if ($ch === '(') $depth++; elseif ($ch === ')') { $depth--; if ($depth <= 0) { $end = $i; break; } } }
    if ($end < 0) return [];
    $body = substr($s, $m[0][1] + strlen($m[0][0]) + 1, $end - (strlen($m[0][0]) + $m[0][1]) - 1);
    // strip FK/comments lines then read leading identifiers
    $body = preg_replace('/\/\*.*?\*\//s', '', $body);
    preg_match_all('/^\s*`?([a-z_0-9]+)`?\s+(?=(?:VARCHAR|CHAR|TEXT|BIGINT|INT|INTEGER|TINYINT|SMALLINT|MEDIUMINT|NUMERIC|DATETIME|TIMESTAMP|DATE|TIME|DECIMAL|REAL|DOUBLE|FLOAT|ENUM|BOOLEAN|BLOB|VARBINARY|JSON)\b)/mi', $body, $c);
    return array_map('strtolower', $c[1]);
}

$checks = [];
function chk(array &$checks, string $name, bool $ok, string $detail = ''): void {
    $checks[] = [$name, $ok, $detail];
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $name . ($ok ? '' : ' — ' . $detail) . "\n";
}

// Every runtime-required table (union of api/src repository TABLE consts and
// SQL references). Keep in sync with runtime code; adding a table to the runtime
// here is required so the gate stays honest.
$runtimeTables = [
    'users','user_sessions','user_devices','user_achievements',
    'trading_accounts','trades','trade_exits',
    'rate_limits','password_resets','email_notifications','email_preferences','email_verifications',
    'ai_requests','ai_feature_flags','ai_feature_providers','ai_provider_credentials',
    'ai_provider_logs','ai_provider_quotas','ai_extractions','ai_jobs','ai_reports','ai_analysis',
    'ai_audit_logs','ai_feedback','ai_global_settings',
    'admin_audit_logs','system_logs','integration_health','metaapi_fills','metaapi_operations',
    'sync_jobs','webhook_events','content_translation_cache','content_translation_jobs',
];
foreach ($runtimeTables as $t) {
    $inSchema = hasTable($schema, $t);
    $inInit = hasTable($init, $t);
    chk($checks, "table '$t' present in schema.sql", $inSchema, 'missing from database/schema.sql');
    chk($checks, "table '$t' present in init-sqlite.php", $inInit, 'missing from api/init-sqlite.php');
}

// users columns the runtime relies on (RBAC, subscription/entitlements, locale).
$requiredUsers = ['plan','subscription_status','plan_started_at','plan_expires_at','plan_updated_at','locale_updated_at','ai_consent_at','role','status'];
foreach ([$schema, $init] as $source) {
    $label = $source === $schema ? 'schema.sql' : 'init-sqlite.php';
    $cols = usersCols($source);
    foreach ($requiredUsers as $c) {
        chk($checks, "users.$c present in $label", in_array($c, $cols, true), "users.$c missing in $label");
    }
}
// role must permit super_admin (RBAC) — enum in MySQL, TEXT in SQLite.
chk($checks, "schema.sql users.role permits super_admin", hasRole($schema), "role ENUM lacks super_admin");

// FULL COLUMN PARITY: every column the canonical MySQL schema defines must also
// exist in the SQLite bootstrap for the SAME table. A fresh install must be able
// to serve the same queries on either engine (physical types differ legitimately).
// This guards against the regression where init-sqlite.php's trading_accounts was
// a thin subset and GET /api/v1/accounts failed on a fresh SQLite runtime.
$runtimeTablesForParity = array_unique(array_merge(
    $runtimeTables,
    ['users','trading_accounts','trades','sync_jobs']
));
foreach ($runtimeTablesForParity as $t) {
    $sc = tableCols($schema, $t);
    $ic = tableCols($init, $t);
    $missing = array_values(array_diff($sc, $ic));
    chk($checks, "column parity '$t' (all schema.sql cols in init-sqlite)", $missing === [], 'init-sqlite missing: ' . implode(',', $missing));
}

// trading_accounts: the runtime repository selects `timezone` / `timezone_source`
// (AccountRepository::PUBLIC_COLUMNS) and PATCH .../timezone writes them; they are
// added by the v1.0_trade_time_canonical.sql migration for existing DBs. A fresh
// install MUST carry them too, or GET /api/v1/accounts fails (PDOException).
$taRequired = ['timezone', 'timezone_source'];
foreach ([$schema, $init] as $source) {
    $label = $source === $schema ? 'schema.sql' : 'init-sqlite.php';
    $cols = tableCols($source, 'trading_accounts');
    foreach ($taRequired as $c) {
        chk($checks, "trading_accounts.$c present in $label", in_array($c, $cols, true), "trading_accounts.$c missing in $label");
    }
}

function usersBody(string $path): string {
    $s = (string)file_get_contents($path);
    if (!preg_match('/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+users\s*\(/i', $s, $m, PREG_OFFSET_CAPTURE)) return '';
    $i = $m[0][1] + strlen($m[0][0]); $depth = 1; $end = -1; $len = strlen($s);
    for (; $i < $len; $i++) { $ch = $s[$i]; if ($ch === '(') $depth++; elseif ($ch === ')') { $depth--; if ($depth <= 0) { $end = $i; break; } } }
    if ($end < 0) return '';
    $open = strpos($s, '(', $m[0][1]);
    return substr($s, $open + 1, $end - ($open + 1)) ?: '';
}
function hasRole(string $path): bool {
    return (bool)preg_match('/role\s+ENUM\([^)]*super_admin[^)]*\)/i', usersBody($path));
}

// ---------------------------------------------------------------------------
// UPGRADE PATH: for every runtime-required object, an additive migration must
// exist so an EXISTING production database can reach the same schema. This
// closes the loop: fresh install (schema.sql) and upgrade (migrations) converge.
// ---------------------------------------------------------------------------
$migrationDir = $root . '/api/database/migrations';
$mig = [];
foreach (glob($migrationDir . '/*.sql') as $f) { $mig[] = basename($f); }
function migFor(string $needle, array $mig): array {
    return array_values(array_filter($mig, fn($m) => stripos((string)file_get_contents($GLOBALS['migdir'] . '/' . $m), $needle) !== false));
}
$GLOBALS['migdir'] = $migrationDir;

$upgradeObjects = [
    ['table', 'ai_provider_credentials'],
    ['table', 'admin_audit_logs'],
    ['table', 'system_logs'],
    ['table', 'integration_health'],
    ['table', 'metaapi_fills'],
    ['column', 'users.plan'],
    ['column', 'users.subscription_status'],
    ['column', 'users.plan_started_at'],
    ['column', 'users.plan_expires_at'],
    ['column', 'users.plan_updated_at'],
    ['column', 'users.locale_updated_at'],
    ['column', 'trading_accounts.timezone'],
    ['column', 'trading_accounts.timezone_source'],
    ['role', 'super_admin'],
];
foreach ($upgradeObjects as [$kind, $obj]) {
    $needle = $kind === 'column' ? preg_quote(substr($obj, strpos($obj, '.') + 1), '/') : ($kind === 'table' ? preg_quote($obj, '/') : 'super_admin');
    $files = migFor($needle, $mig);
    if ($kind === 'column') {
        // must be in a migration that ALTERs/defines a column with that name
        $hits = array_filter($files, fn($m) => preg_match('/ADD\s+COLUMN\s+' . $needle . '\b|CONSTRAINT|' . $needle . '\s+(VARCHAR|DATETIME|ENUM|INT|BIGINT)/i', (string)file_get_contents($migrationDir . '/' . $m)));
        chk($checks, "upgrade path: $obj covered by a migration", count($hits) > 0, 'no migration adds ' . $obj);
    } elseif ($kind === 'table') {
        $hits = array_filter($files, fn($m) => preg_match('/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+' . $needle . '\b/i', (string)file_get_contents($migrationDir . '/' . $m)));
        chk($checks, "upgrade path: table $obj covered by a migration", count($hits) > 0, 'no migration creates ' . $obj);
    } else {
        $hits = array_filter($files, fn($m) => preg_match("/ENUM\([^)]*'super_admin'[^)]*\)/i", (string)file_get_contents($migrationDir . '/' . $m)));
        chk($checks, "upgrade path: role super_admin covered by a migration", count($hits) > 0, 'no migration widens role to super_admin');
    }
}

$p = array_filter($checks, fn($c) => $c[1]);
$f = array_filter($checks, fn($c) => !$c[1]);
echo "\nschema-completeness: PASS (" . count($p) . " checks, " . count($f) . " failures)\n";
exit(count($f) === 0 ? 0 : 1);
