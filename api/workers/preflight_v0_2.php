<?php

declare(strict_types=1);

/**
 * VELORA v0.2 production preflight.
 * CLI-only safety checker; prints no secrets and makes no database changes.
 *
 * Runtime check:
 *   php /path/to/api/workers/preflight_v0_2.php
 *
 * Mandatory read-only P0-6 migration gate:
 *   php /path/to/api/workers/preflight_v0_2.php --migration-gate \
 *     --migration-sha256=<approved-64-hex-sha256> \
 *     --backup=/protected/path/backup.sql.gz \
 *     --backup-sha256=<read-verified-64-hex-sha256> --workers-stopped
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

$options = (array) getopt('', [
    'migration-gate',
    'migration-sha256:',
    'backup:',
    'backup-sha256:',
    'workers-stopped',
]);
$migrationGate = array_key_exists('migration-gate', $options);

$requiredBcmathFunctions = ['bcadd', 'bcsub', 'bcmul', 'bcdiv', 'bccomp'];
$missingBcmathFunctions = array_values(array_filter(
    $requiredBcmathFunctions,
    static fn (string $function): bool => !function_exists($function),
));
if (!extension_loaded('bcmath') || $missingBcmathFunctions !== []) {
    echo "VELORA v0.2 PREFLIGHT\n";
    echo str_repeat('=', 24) . "\n";
    echo '[FAIL] BCMath extension — ext-bcmath with bcadd, bcsub, bcmul, bcdiv, and bccomp is required; exact decimal fallback is prohibited.' . "\n";
    echo str_repeat('=', 24) . "\n";
    echo "RESULT: 1 issue(s) need attention\n";
    exit(1);
}
unset($requiredBcmathFunctions, $missingBcmathFunctions);

require dirname(__DIR__) . '/src/bootstrap.php';

use Velora\Core\Config;
use Velora\Core\Database;

$checks = [];

function add_check(array &$checks, string $name, bool $ok, string $message): void
{
    $checks[] = ['name' => $name, 'ok' => $ok, 'message' => $message];
}

function columns(string $table): array
{
    $pdo = Database::connection();
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $cols = [];

    if ($driver === 'sqlite') {
        $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
        foreach ($stmt->fetchAll() as $row) {
            $cols[(string) $row['name']] = true;
        }
        return $cols;
    }

    $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
    foreach ($stmt->fetchAll() as $row) {
        $cols[(string) $row['Field']] = true;
    }
    return $cols;
}

function has_cols(string $table, array $required, array &$missing): bool
{
    try {
        $cols = columns($table);
    } catch (Throwable $e) {
        $missing = ['TABLE_NOT_READABLE: ' . $e->getMessage()];
        return false;
    }
    $missing = [];
    foreach ($required as $col) {
        if (!isset($cols[$col])) $missing[] = $col;
    }
    return $missing === [];
}

function table_exists(string $table): bool
{
    $pdo = Database::connection();
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:table");
        $stmt->execute(['table' => $table]);
        return (int) $stmt->fetchColumn() === 1;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table');
    $stmt->execute(['table' => $table]);
    return (int) $stmt->fetchColumn() === 1;
}

function add_count_gate(array &$checks, string $name, string $sql, string $passMessage): void
{
    try {
        $count = (int) Database::connection()->query($sql)->fetchColumn();
        add_check($checks, $name, $count === 0, $count === 0 ? $passMessage : 'BLOCKER count=' . $count);
    } catch (Throwable $e) {
        add_check($checks, $name, false, 'query failed: ' . $e->getMessage());
    }
}

function add_duplicate_gate(
    array &$checks,
    string $name,
    string $table,
    array $requiredColumns,
    string $sql,
    bool $migrationAddsMissingColumns = true,
): void {
    try {
        if (!table_exists($table)) {
            add_check($checks, $name, true, 'table absent; no pre-existing values');
            return;
        }
    } catch (Throwable $e) {
        add_check($checks, $name, false, 'cannot inspect table: ' . $e->getMessage());
        return;
    }

    $missing = [];
    if (!has_cols($table, $requiredColumns, $missing)) {
        $onlyAbsentColumns = $migrationAddsMissingColumns
            && $missing !== []
            && array_reduce($missing, static fn (bool $carry, string $item): bool => $carry && !str_starts_with($item, 'TABLE_NOT_READABLE:'), true);
        add_check(
            $checks,
            $name,
            $onlyAbsentColumns,
            $onlyAbsentColumns
                ? 'no pre-existing target values; migration adds missing prerequisite(s): ' . implode(', ', $missing)
                : 'cannot inspect: ' . implode(', ', $missing),
        );
        return;
    }
    add_count_gate($checks, $name, $sql, 'no duplicate groups');
}

function running_metaapi_worker_pids(): array
{
    $workers = [];
    foreach ((array) glob('/proc/[0-9]*/cmdline') as $path) {
        $pid = (int) basename(dirname($path));
        if ($pid === getmypid()) continue;
        $cmdline = @file_get_contents($path);
        if ($cmdline !== false && str_contains(str_replace("\0", ' ', $cmdline), 'metaapi_sync_worker.php')) {
            $workers[] = $pid;
        }
    }
    return $workers;
}

$driver = '';
$version = '';
$mysql8 = false;
try {
    $pdo = Database::connection();
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $version = (string) ($driver === 'sqlite' ? $pdo->query('select sqlite_version()')->fetchColumn() : $pdo->query('SELECT VERSION()')->fetchColumn());
    add_check($checks, 'Database connection', true, $driver . ' ' . $version);
    preg_match('/^(\d+(?:\.\d+){1,2})/', $version, $versionMatch);
    $mysql8 = $driver === 'mysql'
        && stripos($version, 'mariadb') === false
        && isset($versionMatch[1])
        && version_compare($versionMatch[1], '8.0.0', '>=');
    add_check(
        $checks,
        'Database engine for P0-6',
        !$migrationGate || $mysql8,
        $mysql8 ? 'MySQL ' . $versionMatch[1] : ($migrationGate ? 'real MySQL 8.0+ required; detected ' . $driver . ' ' . $version : 'runtime-only mode'),
    );
} catch (Throwable $e) {
    add_check($checks, 'Database connection', false, $e->getMessage());
    if ($migrationGate) add_check($checks, 'Database engine for P0-6', false, 'unavailable');
}

$appEnv = strtolower((string) Config::env('APP_ENV', 'prod'));
$isProd = in_array($appEnv, ['prod', 'production'], true);
add_check($checks, 'APP_ENV', $isProd, 'APP_ENV=' . $appEnv . ($isProd ? '' : ' (production should be prod/production)'));

$jwt = (string) Config::env('JWT_SECRET', '');
$jwtOk = $jwt !== '' && $jwt !== 'change-me-in-production-velora-2026' && strlen($jwt) >= 48;
add_check($checks, 'JWT_SECRET', $jwtOk, $jwtOk ? 'configured' : 'missing/weak/placeholder; rotate before production');

$enc = (string) Config::env('APP_ENCRYPTION_KEY', '');
$raw = base64_decode($enc, true);
$encOk = $raw !== false && strlen($raw) === 32;
add_check($checks, 'APP_ENCRYPTION_KEY', $encOk, $encOk ? 'configured as base64(32 bytes)' : 'missing/invalid; required for MetaApi credential encryption');

$metaapiToken = (string) Config::env('METAAPI_TOKEN', '');
add_check($checks, 'METAAPI_TOKEN', $metaapiToken !== '', $metaapiToken !== '' ? 'configured' : 'missing; MetaApi connect/sync cannot run in production');

$webhookSecret = (string) Config::env('METAAPI_WEBHOOK_SECRET', '');
add_check($checks, 'METAAPI_WEBHOOK_SECRET', $webhookSecret !== '', $webhookSecret !== '' ? 'configured' : 'missing; production webhooks will fail closed');

add_check($checks, 'BCMath extension', true, 'loaded; exact decimal functions available');
add_check($checks, 'cURL extension', extension_loaded('curl'), extension_loaded('curl') ? 'loaded' : 'missing; MetaApi and Resend HTTP calls will fail');
add_check($checks, 'OpenSSL extension', extension_loaded('openssl'), extension_loaded('openssl') ? 'loaded' : 'missing; encryption/JWT/mail TLS may fail');

$mailDriver = strtolower(trim((string) Config::env('MAIL_DRIVER', 'mail')));
$mailDriverAllowed = in_array($mailDriver, ['log', 'mail', 'smtp', 'resend'], true);
$mailDriverSafe = $mailDriverAllowed && !($isProd && $mailDriver === 'log');
add_check(
    $checks,
    'MAIL_DRIVER',
    $mailDriverSafe,
    'MAIL_DRIVER=' . $mailDriver
        . (!$mailDriverAllowed ? ' is unsupported' : '')
        . ($isProd && $mailDriver === 'log' ? ' logs reset/verification links; do not use in production' : ''),
);

$resendKeyConfigured = trim((string) Config::env('RESEND_API_KEY', '')) !== '';
add_check(
    $checks,
    'RESEND_API_KEY',
    $mailDriver !== 'resend' || $resendKeyConfigured,
    $mailDriver === 'resend'
        ? ($resendKeyConfigured ? 'configured (value hidden)' : 'missing; Resend transport will fail closed')
        : 'not required for selected mail driver',
);

if ($migrationGate) {
    $migrationPath = dirname(__DIR__) . '/database/migrations/v0.2_metaapi_bridge.sql';
    $approvedMigrationHash = strtolower(trim((string) ($options['migration-sha256'] ?? '')));
    $actualMigrationHash = is_readable($migrationPath) ? hash_file('sha256', $migrationPath) : false;
    $migrationHashOk = preg_match('/^[a-f0-9]{64}$/', $approvedMigrationHash) === 1
        && is_string($actualMigrationHash)
        && hash_equals($approvedMigrationHash, $actualMigrationHash);
    add_check(
        $checks,
        'Approved migration checksum',
        $migrationHashOk,
        $migrationHashOk ? 'SHA-256 matches the reviewed migration artifact' : 'missing, invalid, unreadable, or mismatched --migration-sha256',
    );

    $backupPath = (string) ($options['backup'] ?? '');
    $approvedBackupHash = strtolower(trim((string) ($options['backup-sha256'] ?? '')));
    $backupReal = $backupPath !== '' ? realpath($backupPath) : false;
    $sourceRoot = realpath(dirname(__DIR__, 2));
    $backupOutsideSource = is_string($backupReal)
        && is_string($sourceRoot)
        && !str_starts_with($backupReal, $sourceRoot . DIRECTORY_SEPARATOR);
    $backupPerms = is_string($backupReal) ? @fileperms($backupReal) : false;
    $backupProtected = is_int($backupPerms) && ($backupPerms & 0022) === 0;
    $backupReadable = is_string($backupReal) && is_file($backupReal) && is_readable($backupReal) && filesize($backupReal) > 0;
    $actualBackupHash = $backupReadable ? hash_file('sha256', $backupReal) : false;
    $backupHashOk = preg_match('/^[a-f0-9]{64}$/', $approvedBackupHash) === 1
        && is_string($actualBackupHash)
        && hash_equals($approvedBackupHash, $actualBackupHash);
    add_check(
        $checks,
        'Protected database backup read verification',
        $backupOutsideSource && $backupProtected && $backupReadable && $backupHashOk,
        $backupOutsideSource && $backupProtected && $backupReadable && $backupHashOk
            ? 'non-empty backup outside source tree was read and its SHA-256 matched'
            : 'require a readable non-empty backup outside source, no group/world write bits, and matching --backup-sha256',
    );

    $workerAssertion = array_key_exists('workers-stopped', $options);
    $procReadable = is_dir('/proc') && is_readable('/proc');
    $workerPids = $procReadable ? running_metaapi_worker_pids() : [];
    $workersStopped = $workerAssertion && $procReadable && $workerPids === [];
    add_check(
        $checks,
        'MetaApi worker shutdown',
        $workersStopped,
        $workersStopped
            ? 'operator assertion supplied and no running local MetaApi worker process detected; keep cron/service disabled through migration'
            : (!$workerAssertion ? 'missing --workers-stopped operator assertion' : (!$procReadable ? 'cannot inspect local process table' : 'running worker process count=' . count($workerPids))),
    );
}

if (!$migrationGate) {
    foreach ([
    'trading_accounts' => ['id','user_id','metaapi_account_id','sync_status','connection_credentials_encrypted','auto_sync_enabled','last_synced_at'],
    'trades' => ['id','user_id','account_id','external_deal_id','contract_size','source'],
    'metaapi_operations' => ['id','operation_key','provider_marker','request_fingerprint','user_id','account_id','operation_type','status','provider_account_id','attempts','last_error_code','completed_at','created_at','updated_at'],
    'sync_jobs' => ['id','account_id','user_id','type','status','payload','attempts','max_attempts','available_at','locked_at','locked_by','lease_token','dedupe_key','last_error','range_from','range_to','created_at','started_at','completed_at','dead_lettered_at','updated_at'],
    'webhook_events' => ['id','event_key','account_id','metaapi_account_id','event_type','payload','hmac_verified','processed','processing_token','processing_started_at','last_error','created_at','processed_at'],
] as $table => $required) {
    $missing = [];
    $ok = has_cols($table, $required, $missing);
    add_check($checks, 'DB table ' . $table, $ok, $ok ? 'required columns present' : 'missing: ' . implode(', ', $missing));
}

try {
    $tradeCols = columns('trades');
    $syncCols = columns('sync_jobs');
    $webhookCols = columns('webhook_events');
    add_check($checks, 'Trades idempotency', isset($tradeCols['external_deal_id']), isset($tradeCols['external_deal_id']) ? 'external_deal_id present' : 'external_deal_id missing');
    add_check($checks, 'Sync jobs schema compatibility', (isset($syncCols['user_id']) && isset($syncCols['type']) && isset($syncCols['payload'])) || (isset($syncCols['job_type']) && isset($syncCols['sync_type']) && isset($syncCols['finished_at'])), 'adaptive repository supports detected schema');
    add_check($checks, 'Webhook log schema compatibility', isset($webhookCols['event_type']) && isset($webhookCols['payload']), 'adaptive repository supports detected schema');
} catch (Throwable $e) {
    add_check($checks, 'Schema compatibility', false, $e->getMessage());
}

    try {
        $count = Database::connection()->query("SELECT COUNT(*) FROM trading_accounts WHERE connection_credentials_encrypted IS NOT NULL AND connection_credentials_encrypted <> ''")->fetchColumn();
        add_check($checks, 'Encrypted MetaApi accounts', true, 'count=' . (int) $count . ' (do not print account data)');
    } catch (Throwable $e) {
        add_check($checks, 'Encrypted MetaApi accounts', false, $e->getMessage());
    }
} else {
    foreach ([
        'users' => ['id'],
        'trading_accounts' => ['id', 'user_id'],
        'trades' => ['id', 'user_id', 'account_id', 'close_time'],
    ] as $table => $required) {
        $missing = [];
        $ok = has_cols($table, $required, $missing);
        add_check($checks, 'Migration base table ' . $table, $ok, $ok ? 'required pre-migration columns present' : 'missing: ' . implode(', ', $missing));
    }

    foreach ([
        'sync_jobs' => ['id', 'account_id', 'status', 'attempts', 'last_error', 'created_at'],
        'webhook_events' => ['id', 'account_id', 'event_type', 'payload', 'processed', 'created_at'],
        'metaapi_operations' => ['id', 'operation_key', 'provider_marker', 'request_fingerprint', 'user_id', 'account_id', 'operation_type', 'status', 'provider_account_id', 'attempts', 'last_error_code', 'completed_at', 'created_at', 'updated_at'],
    ] as $table => $required) {
        try {
            if (!table_exists($table)) {
                add_check($checks, 'Optional pre-migration table ' . $table, true, 'absent; migration creates it');
                continue;
            }
            $missing = [];
            $ok = has_cols($table, $required, $missing);
            add_check($checks, 'Existing pre-migration table ' . $table, $ok, $ok ? 'required legacy columns present' : 'missing unsupported columns: ' . implode(', ', $missing));
        } catch (Throwable $e) {
            add_check($checks, 'Existing pre-migration table ' . $table, false, 'cannot inspect: ' . $e->getMessage());
        }
    }
}

if ($migrationGate && !$mysql8) {
    add_check($checks, 'Migration duplicate/ownership queries', false, 'not executed: a real MySQL 8.0+ connection is required');
} elseif ($migrationGate) {
    add_duplicate_gate(
        $checks,
        'Duplicate trading-account MetaApi IDs',
        'trading_accounts',
        ['metaapi_account_id'],
        "SELECT COUNT(*) FROM (SELECT metaapi_account_id FROM trading_accounts WHERE metaapi_account_id IS NOT NULL GROUP BY metaapi_account_id HAVING COUNT(*) > 1) duplicate_groups",
    );

    try {
        $accountColumns = columns('trading_accounts');
        if (!isset($accountColumns['server'], $accountColumns['mt_login'])) {
            add_check($checks, 'Duplicate trading-account connection identities', true, 'server or mt_login is absent and will be added as NULL; no pre-existing enforceable identity');
        } elseif (!isset($accountColumns['user_id'])) {
            add_check($checks, 'Duplicate trading-account connection identities', false, 'cannot inspect: user_id missing');
        } else {
            $platformExpression = isset($accountColumns['platform']) ? 'platform' : "'MANUAL'";
            add_count_gate(
                $checks,
                'Duplicate trading-account connection identities',
                "SELECT COUNT(*) FROM (SELECT user_id, {$platformExpression}, server, mt_login FROM trading_accounts WHERE user_id IS NOT NULL AND server IS NOT NULL AND mt_login IS NOT NULL GROUP BY user_id, {$platformExpression}, server, mt_login HAVING COUNT(*) > 1) duplicate_groups",
                'no duplicate groups in the post-prerequisite identity shape',
            );
        }
    } catch (Throwable $e) {
        add_check($checks, 'Duplicate trading-account connection identities', false, 'cannot inspect: ' . $e->getMessage());
    }

    foreach ([
        ['Duplicate MetaApi operation keys', 'operation_key', 'operation_key'],
        ['Duplicate MetaApi provider markers', 'provider_marker', 'provider_marker'],
        ['Duplicate MetaApi request fingerprints', 'request_fingerprint', 'request_fingerprint'],
    ] as [$name, $column, $group]) {
        add_duplicate_gate(
            $checks,
            $name,
            'metaapi_operations',
            [$column],
            "SELECT COUNT(*) FROM (SELECT {$group} FROM metaapi_operations GROUP BY {$group} HAVING COUNT(*) > 1) duplicate_groups",
            false,
        );
    }

    try {
        if (!table_exists('metaapi_operations')) {
            add_check($checks, 'MetaApi operation ownership', true, 'table absent; no pre-existing ownership rows');
        } else {
            $missing = [];
            if (!has_cols('metaapi_operations', ['user_id', 'account_id'], $missing)) {
                add_check($checks, 'MetaApi operation ownership', false, 'cannot inspect: ' . implode(', ', $missing));
            } else {
                add_count_gate(
                    $checks,
                    'MetaApi operation ownership',
                    'SELECT COUNT(*) FROM metaapi_operations mo LEFT JOIN users u ON u.id=mo.user_id LEFT JOIN trading_accounts ta ON ta.id=mo.account_id WHERE u.id IS NULL OR (mo.account_id IS NOT NULL AND ta.id IS NULL) OR (mo.account_id IS NOT NULL AND ta.user_id<>mo.user_id)',
                    'no orphan or conflicting ownership references',
                );
            }
        }
    } catch (Throwable $e) {
        add_check($checks, 'MetaApi operation ownership', false, 'cannot inspect: ' . $e->getMessage());
    }

    try {
        if (!table_exists('sync_jobs')) {
            add_check($checks, 'Sync-job ownership', true, 'table absent; no pre-existing ownership rows');
        } else {
            $syncColumns = columns('sync_jobs');
            if (!isset($syncColumns['account_id'])) {
                add_check($checks, 'Sync-job ownership', false, 'cannot inspect: account_id missing');
            } elseif (isset($syncColumns['user_id'])) {
                add_count_gate(
                    $checks,
                    'Sync-job ownership',
                    'SELECT COUNT(*) FROM sync_jobs sj LEFT JOIN trading_accounts ta ON ta.id=sj.account_id LEFT JOIN users u ON u.id=sj.user_id WHERE ta.id IS NULL OR sj.user_id IS NULL OR u.id IS NULL OR ta.user_id<>sj.user_id',
                    'no orphan or conflicting ownership references',
                );
            } else {
                add_count_gate(
                    $checks,
                    'Sync-job ownership',
                    'SELECT COUNT(*) FROM sync_jobs sj LEFT JOIN trading_accounts ta ON ta.id=sj.account_id WHERE ta.id IS NULL',
                    'all legacy account references can supply immutable user ownership',
                );
            }
        }
    } catch (Throwable $e) {
        add_check($checks, 'Sync-job ownership', false, 'cannot inspect: ' . $e->getMessage());
    }

    try {
        if (!table_exists('sync_jobs')) {
            add_check($checks, 'Duplicate active sync intents', true, 'table absent; no pre-existing values');
        } else {
            $syncColumns = columns('sync_jobs');
            if (!isset($syncColumns['account_id'], $syncColumns['status'])) {
                add_check($checks, 'Duplicate active sync intents', false, 'cannot inspect: account_id or status missing');
            } else {
                $effectiveActive = "status IN ('PENDING','RUNNING')";
                if (isset($syncColumns['attempts'], $syncColumns['max_attempts'])) {
                    $effectiveActive .= " OR (status='FAILED' AND attempts < max_attempts)";
                } elseif (isset($syncColumns['attempts'])) {
                    $effectiveActive .= " OR (status='FAILED' AND attempts < 5)";
                }
                add_count_gate(
                    $checks,
                    'Duplicate active sync intents',
                    "SELECT COUNT(*) FROM (SELECT account_id FROM sync_jobs WHERE {$effectiveActive} GROUP BY account_id HAVING COUNT(*) > 1) duplicate_groups",
                    'no duplicate groups after applying the migration status mapping',
                );
            }
        }
    } catch (Throwable $e) {
        add_check($checks, 'Duplicate active sync intents', false, 'cannot inspect: ' . $e->getMessage());
    }

    add_duplicate_gate(
        $checks,
        'Duplicate sync lease tokens',
        'sync_jobs',
        ['lease_token'],
        'SELECT COUNT(*) FROM (SELECT lease_token FROM sync_jobs WHERE lease_token IS NOT NULL GROUP BY lease_token HAVING COUNT(*) > 1) duplicate_groups',
    );
    add_duplicate_gate(
        $checks,
        'Duplicate webhook event keys',
        'webhook_events',
        ['event_key'],
        'SELECT COUNT(*) FROM (SELECT event_key FROM webhook_events WHERE event_key IS NOT NULL GROUP BY event_key HAVING COUNT(*) > 1) duplicate_groups',
    );
    add_duplicate_gate(
        $checks,
        'Duplicate webhook processing tokens',
        'webhook_events',
        ['processing_token'],
        'SELECT COUNT(*) FROM (SELECT processing_token FROM webhook_events WHERE processing_token IS NOT NULL GROUP BY processing_token HAVING COUNT(*) > 1) duplicate_groups',
    );

    try {
        if (!table_exists('webhook_events')) {
            add_check($checks, 'Webhook account ownership', true, 'table absent; no pre-existing ownership rows');
        } else {
            $missing = [];
            if (!has_cols('webhook_events', ['account_id'], $missing)) {
                add_check($checks, 'Webhook account ownership', false, 'cannot inspect: ' . implode(', ', $missing));
            } else {
                add_count_gate(
                    $checks,
                    'Webhook account ownership',
                    'SELECT COUNT(*) FROM webhook_events we LEFT JOIN trading_accounts ta ON ta.id=we.account_id WHERE we.account_id IS NOT NULL AND ta.id IS NULL',
                    'no orphan account references',
                );
            }
        }
    } catch (Throwable $e) {
        add_check($checks, 'Webhook account ownership', false, 'cannot inspect: ' . $e->getMessage());
    }

    add_duplicate_gate(
        $checks,
        'Duplicate external trade deal identities',
        'trades',
        ['account_id', 'external_deal_id'],
        'SELECT COUNT(*) FROM (SELECT account_id, external_deal_id FROM trades WHERE account_id IS NOT NULL AND external_deal_id IS NOT NULL GROUP BY account_id, external_deal_id HAVING COUNT(*) > 1) duplicate_groups',
    );
}

$failures = 0;
echo "VELORA v0.2 PREFLIGHT\n";
echo str_repeat('=', 24) . "\n";
foreach ($checks as $check) {
    if (!$check['ok']) $failures++;
    echo ($check['ok'] ? '[OK]   ' : '[FAIL] ') . $check['name'] . ' — ' . $check['message'] . "\n";
}
echo str_repeat('=', 24) . "\n";
echo $failures === 0 ? "RESULT: PASS\n" : "RESULT: {$failures} issue(s) need attention\n";
exit($failures === 0 ? 0 : 1);
