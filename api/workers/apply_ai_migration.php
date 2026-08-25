<?php

declare(strict_types=1);

/**
 * VELORA v0.4-v0.5 AI Foundation migration runner — staging verification.
 * CLI only, no secrets in output, idempotent.
 *
 * Usage:
 *   php api/workers/apply_ai_migration.php --check
 *   php api/workers/apply_ai_migration.php --apply
 *   php api/workers/apply_ai_migration.php --check --v05
 *   php api/workers/apply_ai_migration.php --apply --v05
 *
 * On staging, this is run via GitHub Actions runner which has DB access
 * (OC-1 firewall allows GitHub runner IPs, not sandbox).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

require dirname(__DIR__) . '/src/bootstrap.php';

use Velora\Core\Database;

$mode = $argv[1] ?? '--check';
$isApply = $mode === '--apply' || in_array('--apply', $argv, true);
$isCheck = $mode === '--check' || in_array('--check', $argv, true) || !$isApply;
$isV05 = in_array('--v05', $argv, true) || in_array('--v5', $argv, true);

$migrationFile = $isV05
    ? dirname(__DIR__) . '/database/migrations/v0.5_ai_requests.sql'
    : dirname(__DIR__) . '/database/migrations/v0.4_ai_foundation.sql';

if (!is_file($migrationFile)) {
    fwrite(STDERR, "Migration file not found: $migrationFile\n");
    exit(1);
}

function tableExists(PDO $pdo, string $table): bool
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:table");
        $stmt->execute(['table' => $table]);
        return (int) $stmt->fetchColumn() === 1;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table');
    $stmt->execute(['table' => $table]);
    return (int) $stmt->fetchColumn() === 1;
}

function countRows(PDO $pdo, string $table): int
{
    try {
        return (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    } catch (Throwable $e) {
        return -1;
    }
}

try {
    $pdo = Database::connection();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "[VELORA AI MIGRATION] Driver: $driver\n";
    echo "[VELORA AI MIGRATION] Mode: " . ($isApply ? 'APPLY' : 'CHECK') . "\n";

    $expectedTables = $isV05
        ? ['ai_requests', 'ai_feature_flags', 'ai_audit_logs', 'ai_feedback']
        : ['ai_extractions', 'ai_provider_quotas', 'ai_provider_logs'];
    $existing = [];
    foreach ($expectedTables as $t) {
        $exists = tableExists($pdo, $t);
        $existing[$t] = $exists;
        echo sprintf("  Table %s: %s\n", $t, $exists ? 'EXISTS' : 'MISSING');
    }

    if ($isCheck) {
        $allExist = !in_array(false, $existing, true);
        if ($allExist) {
            echo "CHECK: All AI tables exist — migration already applied.\n";
            foreach ($expectedTables as $t) {
                echo sprintf("  %s rows: %d\n", $t, countRows($pdo, $t));
            }
            exit(0);
        } else {
            echo "CHECK: Some tables missing — apply needed.\n";
            exit(2);
        }
    }

    // APPLY mode
    if ($driver === 'sqlite') {
        echo "APPLY: SQLite detected — using SQLite-compatible schema for local test.\n";
        if (!$isV05) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS ai_extractions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    provider VARCHAR(32) NOT NULL DEFAULT 'gemini',
                    image_hash CHAR(64) NOT NULL,
                    original_result TEXT,
                    final_result TEXT,
                    confidence FLOAT NOT NULL DEFAULT 0.0,
                    latency_ms INTEGER NOT NULL DEFAULT 0,
                    status VARCHAR(20) NOT NULL DEFAULT 'success',
                    error_code VARCHAR(64),
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
            ");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS ai_provider_quotas (
                    provider VARCHAR(32) NOT NULL PRIMARY KEY,
                    daily_used INTEGER NOT NULL DEFAULT 0,
                    quota_limit INTEGER NOT NULL DEFAULT 1500,
                    reset_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
            ");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS ai_provider_logs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    provider VARCHAR(32) NOT NULL,
                    status VARCHAR(32) NOT NULL,
                    latency_ms INTEGER NOT NULL DEFAULT 0,
                    error_code VARCHAR(64),
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
            ");
            $pdo->exec("INSERT OR IGNORE INTO ai_provider_quotas (provider, quota_limit) VALUES ('gemini', 1500), ('tesseract', 100000)");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS ai_requests (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    feature VARCHAR(32) NOT NULL DEFAULT 'extraction',
                    provider VARCHAR(32) NOT NULL DEFAULT 'gemini',
                    model VARCHAR(64) NOT NULL DEFAULT 'gemini-1.5-flash',
                    prompt_hash CHAR(64) NOT NULL,
                    tokens_used INTEGER NOT NULL DEFAULT 0,
                    latency_ms INTEGER NOT NULL DEFAULT 0,
                    status VARCHAR(32) NOT NULL DEFAULT 'success',
                    cost TEXT NOT NULL DEFAULT '0.000000',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
            ");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS ai_feature_flags (
                    feature_name VARCHAR(64) NOT NULL PRIMARY KEY,
                    enabled INTEGER NOT NULL DEFAULT 0,
                    rollout_percentage INTEGER NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
            ");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS ai_audit_logs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    feature VARCHAR(32) NOT NULL DEFAULT 'extraction',
                    provider VARCHAR(32) NOT NULL DEFAULT 'gemini',
                    image_hash CHAR(64) NOT NULL,
                    action VARCHAR(32) NOT NULL DEFAULT 'extraction',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
            ");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS ai_feedback (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    extraction_id INTEGER,
                    original_result TEXT,
                    corrected_result TEXT,
                    changed_fields TEXT,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
            ");
            $pdo->exec("INSERT OR IGNORE INTO ai_feature_flags (feature_name, enabled, rollout_percentage) VALUES ('ai_screenshot_extraction',1,100), ('ai_trade_analysis',0,0), ('ai_weekly_report',0,0), ('ai_assistant',0,0), ('ai_recommendations',0,0), ('ai_risk_analysis',0,0);");
        }
    } else {
        echo "APPLY: Reading migration SQL...\n";
        $sql = file_get_contents($migrationFile);
        if ($sql === false) {
            throw new RuntimeException('Failed to read migration file');
        }
        $pdo->exec($sql);
        echo "APPLY: Migration SQL executed.\n";
    }

    // Verify after apply
    echo "VERIFY after apply:\n";
    foreach ($expectedTables as $t) {
        $exists = tableExists($pdo, $t);
        echo sprintf("  Table %s: %s (rows: %d)\n", $t, $exists ? 'EXISTS' : 'MISSING', countRows($pdo, $t));
        if (!$exists) {
            fwrite(STDERR, "Failed to create table $t\n");
            exit(1);
        }
    }

    echo "RESULT: AI foundation migration applied and verified.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "[VELORA AI MIGRATION] FAILED: " . $e->getMessage() . "\n");
    // Never log credentials, DSN, or full stack in production
    error_log('[VELORA_AI_MIGRATION] failed: ' . $e->getMessage());
    exit(1);
}
