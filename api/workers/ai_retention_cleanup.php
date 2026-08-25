<?php

declare(strict_types=1);

/**
 * AI retention cleanup worker — deletes old ai_requests and cleans original_result.
 * Supports --dry-run and --execute, no secrets in output.
 *
 * Usage:
 *   php api/workers/ai_retention_cleanup.php --dry-run
 *   php api/workers/ai_retention_cleanup.php --execute
 *
 * Cron: 0 2 * * * php /path/to/api/workers/ai_retention_cleanup.php --execute
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

require dirname(__DIR__) . '/src/bootstrap.php';

use Velora\Core\Config;
use Velora\Core\Database;

$dryRun = in_array('--dry-run', $argv, true);
$execute = in_array('--execute', $argv, true);

if (!$dryRun && !$execute) {
    echo "Usage: php ai_retention_cleanup.php --dry-run | --execute\n";
    exit(1);
}

$retentionDays = (int) Config::get('ai', [])['retention_days'] ?? (int) Config::env('AI_RETENTION_DAYS', '30');
$retentionDays = max(7, min(365, $retentionDays));

try {
    $pdo = Database::connection();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "[VELORA AI RETENTION] Driver: $driver, Retention: $retentionDays days, Mode: " . ($dryRun ? 'DRY-RUN' : 'EXECUTE') . "\n";

    $cutoff = gmdate('Y-m-d H:i:s', time() - $retentionDays * 86400);
    echo "Cutoff: $cutoff\n";

    $tables = [
        'ai_requests' => "created_at < :cutoff",
        'ai_provider_logs' => "created_at < :cutoff",
        'ai_audit_logs' => "created_at < :cutoff",
        'ai_extractions' => "created_at < :cutoff",
    ];

    $totalToDelete = 0;
    foreach ($tables as $table => $where) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE $where");
            $stmt->execute(['cutoff' => $cutoff]);
            $count = (int) $stmt->fetchColumn();
            echo sprintf("  %s: %d rows to delete\n", $table, $count);
            $totalToDelete += $count;
        } catch (Throwable $e) {
            echo sprintf("  %s: table missing or error (%s)\n", $table, $e->getMessage());
        }
    }

    // Special: clean original_result for old extractions but keep final_result and stats
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ai_extractions WHERE created_at < :cutoff AND original_result IS NOT NULL");
        $stmt->execute(['cutoff' => $cutoff]);
        $count = (int) $stmt->fetchColumn();
        echo sprintf("  ai_extractions.original_result to clean: %d rows\n", $count);
    } catch (Throwable $e) {
        echo "  ai_extractions original_result check failed\n";
    }

    if ($dryRun) {
        echo "DRY-RUN: $totalToDelete total rows would be deleted, no changes made.\n";
        exit(0);
    }

    // EXECUTE mode
    echo "EXECUTE: Deleting old rows...\n";
    $deletedTotal = 0;
    foreach ($tables as $table => $where) {
        // For ai_extractions, we do 2-step: first clean original_result, then delete very old
        if ($table === 'ai_extractions') {
            try {
                // Clean original_result but keep row for stats (older than retention)
                $stmt = $pdo->prepare("UPDATE ai_extractions SET original_result = NULL WHERE created_at < :cutoff AND original_result IS NOT NULL");
                $stmt->execute(['cutoff' => $cutoff]);
                $cleaned = $stmt->rowCount();
                echo sprintf("  Cleaned original_result in %s: %d rows\n", $table, $cleaned);
            } catch (Throwable $e) {
                echo "  Failed to clean $table original_result\n";
            }
            // For extractions, delete only after 2x retention (keep stats longer)
            $doubleCutoff = gmdate('Y-m-d H:i:s', time() - $retentionDays * 2 * 86400);
            try {
                $stmt = $pdo->prepare("DELETE FROM ai_extractions WHERE created_at < :cutoff");
                $stmt->execute(['cutoff' => $doubleCutoff]);
                $deleted = $stmt->rowCount();
                echo sprintf("  Deleted from %s (2x retention): %d rows\n", $table, $deleted);
                $deletedTotal += $deleted;
            } catch (Throwable $e) {
                echo "  Failed to delete from $table\n";
            }
            continue;
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM `$table` WHERE $where");
            $stmt->execute(['cutoff' => $cutoff]);
            $deleted = $stmt->rowCount();
            echo sprintf("  Deleted from %s: %d rows\n", $table, $deleted);
            $deletedTotal += $deleted;
        } catch (Throwable $e) {
            echo sprintf("  Failed to delete from %s: %s\n", $table, $e->getMessage());
        }
    }

    echo "RESULT: Deleted $deletedTotal rows, preserved aggregated stats.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "[VELORA AI RETENTION] FAILED: " . $e->getMessage() . "\n");
    error_log('[VELORA_AI_RETENTION] failed: ' . $e->getMessage());
    exit(1);
}
