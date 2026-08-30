<?php

declare(strict_types=1);

/**
 * v0.9 migration parity — the inline SQL embedded in
 * .github/workflows/ai-migration-staging.yml must mirror
 * api/database/migrations/v0.9_ai_provider_routing.sql (staging has no SQL
 * files on disk; the workflow probe is the staging source of truth).
 *
 * Run: php tools/tests/test_v09_migration_parity.php
 */

$root = dirname(__DIR__, 2);
$failures = 0;
$checks = 0;
function check(bool $cond, string $label): void
{
    global $failures, $checks;
    $checks++;
    echo ($cond ? '  PASS: ' : '  FAIL: ') . $label . "\n";
    if (!$cond) {
        $failures++;
    }
}

$migration = (string) file_get_contents($root . '/api/database/migrations/v0.9_ai_provider_routing.sql');
$workflow = (string) file_get_contents($root . '/.github/workflows/ai-migration-staging.yml');
$rollback = (string) file_get_contents($root . '/api/database/migrations/v0.9_ai_provider_routing_rollback.sql');

echo "== Table shape parity ==\n";
check(strpos($migration, 'CREATE TABLE IF NOT EXISTS ai_feature_providers') !== false, 'migration creates ai_feature_providers');
check(strpos($workflow, 'CREATE TABLE IF NOT EXISTS ai_feature_providers') !== false, 'workflow probe creates ai_feature_providers');
foreach ([
    'uq_afp_feature_provider (feature, provider)',
    'idx_afp_lookup (feature, enabled, priority)',
    '`route` equivalents present in both',
] as $i => $needle) {
    if ($i === 2) {
        check(substr_count($migration, 'route') > 0 && substr_count($workflow, 'route') > 0, $needle);
    } else {
        check(strpos($migration, $needle) !== false && strpos($workflow, $needle) !== false, "both define {$needle}");
    }
}
foreach (['feature', 'model', 'route', 'fallback_index'] as $col) {
    check(
        preg_match('/ADD COLUMN\s+' . $col . '\b/', $migration) === 1
        && strpos($workflow, "ADD COLUMN {$col} ") !== false,
        "log column {$col} added in both"
    );
}
foreach (['priority', 'enabled'] as $col) {
    check(
        preg_match('/' . $col . '\s+(SMALLINT UNSIGNED|TINYINT)/', $migration) === 1
        && strpos($workflow, $col) !== false,
        "routing table column {$col} defined in both"
    );
}

echo "== Seed parity (exact rows) ==\n";
check(
    strpos($migration, "('screenshot_extraction', 'gemini',    NULL, 1, 1, NULL)") !== false
    && strpos($workflow, "('screenshot_extraction','gemini',NULL,1,1,NULL)") !== false,
    'seed: screenshot_extraction gemini@1 (both sources)'
);
check(
    strpos($migration, "('screenshot_extraction', 'tesseract', NULL, 2, 1, NULL)") !== false
    && strpos($workflow, "('screenshot_extraction','tesseract',NULL,2,1,NULL)") !== false,
    'seed: screenshot_extraction tesseract@2 (both sources)'
);
check(strpos($workflow, 'INSERT IGNORE INTO ai_feature_providers') !== false, 'workflow seed is INSERT IGNORE (idempotent, UNIQUE key guard)');
check(
    substr_count($workflow, "ai_feature_providers (feature, provider, model, priority, enabled, route) VALUES ('screenshot_extraction','gemini'") === 1
    && preg_match("/\('screenshot_extraction',\s*'(openai|claude)'/", $workflow) !== 1,
    'seed contains ONLY gemini+tesseract (openai/claude NOT auto-added)'
);

echo "== Quota seed parity ==\n";
check(
    strpos($migration, "('openai', 1500)") !== false && strpos($workflow, "('openai', 1500)") !== false,
    'quota seed openai=1500 in both'
);
check(
    strpos($migration, "('claude', 1500)") !== false && strpos($workflow, "('claude', 1500)") !== false,
    'quota seed claude=1500 in both'
);
check(strpos($workflow, 'ON DUPLICATE KEY UPDATE quota_limit = VALUES(quota_limit)') !== false, 'quota seed idempotent in workflow');

echo "== Rollback file sanity ==\n";
check(strpos($rollback, 'DROP TABLE IF EXISTS ai_feature_providers') !== false, 'rollback drops ai_feature_providers');
check(strpos($rollback, 'DROP COLUMN feature') !== false && strpos($rollback, 'DROP COLUMN fallback_index') !== false, 'rollback drops the routing log columns');
check(strpos($rollback, 'keep them (no action)') !== false, 'rollback intentionally keeps harmless quota rows (documented)');

echo "== Workflow check-mode coverage ==\n";
check(strpos($workflow, "'ai_reports','ai_analysis','ai_feature_providers'") !== false, 'expectedTables includes ai_feature_providers (check mode reports it)');
check(strpos($workflow, "ai_provider_logs_routing'] = columnExists(\$pdo, 'ai_provider_logs', 'feature')") !== false, 'check mode verifies ai_provider_logs routing columns');
check(strpos($workflow, "'ai_provider_logs.routing_columns'") !== false, 'report block fails on missing routing columns');
check(strpos($workflow, 'APPLY-AI-MIGRATION') !== false, 'apply mode still gated by explicit confirmation string');

echo "\nv0.9-migration-parity: " . ($failures === 0 ? 'PASS' : 'FAIL') . " ($checks checks, $failures failures)\n";
exit($failures === 0 ? 0 : 1);
