<?php

declare(strict_types=1);

/**
 * Phase 1 — Effective Configuration (source of truth) tests.
 *
 * In-process, SQLite-backed. Verifies:
 *  - DB chain overrides env-default when rows exist (source=db);
 *  - env-default is used when the DB chain is absent (source=env-default);
 *  - effective model / route precedence;
 *  - credential metadata (presence + status) is surfaced, NEVER the value;
 *  - quota is labelled internal (never provider-reported);
 *  - the serialized config contains no secret.
 *
 * Run: php tools/tests/test_effective_config.php
 */

$ROOT = sys_get_temp_dir() . '/velora-config-test-' . bin2hex(random_bytes(4));
@mkdir($ROOT . '/config', 0700, true);

$SECRET = 'AIza-REAL-SECRET-MUST-NEVER-APPEAR-99999999';

putenv('APP_ENV=local');
putenv('APP_DEBUG=true');
putenv('VELORA_PRIVATE_ROOT=' . $ROOT);
putenv('VELORA_DOCUMENT_ROOT=' . dirname(__DIR__, 2));
putenv('DB_DRIVER=sqlite');
putenv('DB_DATABASE=' . $ROOT . '/data/velora.sqlite');
putenv('GEMINI_ROUTE=direct');
if (!is_file($ROOT . '/config/velora.env')) {
    file_put_contents($ROOT . '/config/velora.env', implode("\n", [
        'APP_ENV=local',
        'APP_DEBUG=true',
        'DB_DRIVER=sqlite',
        'DB_DATABASE=' . $ROOT . '/data/velora.sqlite',
        'JWT_SECRET=' . str_repeat('j', 48),
        'APP_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)),
        'GEMINI_API_KEY=' . $SECRET,
        'GEMINI_MODEL=gemini-3.6-flash',
        'CORS_ALLOWED_ORIGINS=http://localhost',
        'FRONTEND_URL=http://localhost',
        'MAIL_DRIVER=log',
    ]) . "\n");
}

require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

use Velora\AI\Services\EffectiveConfigService;

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

@mkdir($ROOT . '/data', 0700, true);
$pdo = new PDO('sqlite:' . $ROOT . '/data/velora.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach ([
    'ai_feature_providers' => 'CREATE TABLE IF NOT EXISTS ai_feature_providers (id INTEGER PRIMARY KEY AUTOINCREMENT, feature TEXT, provider TEXT, model TEXT, priority INTEGER DEFAULT 1, enabled INTEGER DEFAULT 1, route TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE(feature, provider))',
    'ai_feature_flags' => 'CREATE TABLE IF NOT EXISTS ai_feature_flags (feature_name TEXT PRIMARY KEY, enabled INTEGER DEFAULT 0, rollout_percentage INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)',
    'ai_provider_quotas' => 'CREATE TABLE IF NOT EXISTS ai_provider_quotas (provider TEXT PRIMARY KEY, daily_used INTEGER DEFAULT 0, quota_limit INTEGER DEFAULT 1500, reset_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)',
    'ai_provider_credentials' => 'CREATE TABLE IF NOT EXISTS ai_provider_credentials (provider TEXT PRIMARY KEY, status TEXT DEFAULT \'UNVERIFIED\', verified INTEGER DEFAULT 0, fingerprint TEXT, verified_at DATETIME, last_checked_at DATETIME, error_code TEXT, latency_ms INTEGER DEFAULT 0, version INTEGER DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)',
    'rate_limits' => 'CREATE TABLE IF NOT EXISTS rate_limits (bucket TEXT PRIMARY KEY, hits INTEGER NOT NULL, window_start DATETIME NOT NULL)',
] as $ddl) {
    $pdo->exec($ddl);
}
// Seed provider chain rows (DB source of truth) + flags + quota + a known credential status.
$pdo->exec("INSERT OR REPLACE INTO ai_feature_providers (feature, provider, model, priority, enabled, route) VALUES
    ('screenshot_extraction','gemini',NULL,1,1,NULL),
    ('screenshot_extraction','tesseract',NULL,2,1,NULL)");
$pdo->exec("INSERT OR REPLACE INTO ai_feature_flags (feature_name, enabled, rollout_percentage) VALUES ('ai_screenshot_extraction',1,100)");
$pdo->exec("INSERT OR REPLACE INTO ai_provider_quotas (provider, daily_used, quota_limit, reset_at) VALUES ('gemini', 12, 1500, CURRENT_TIMESTAMP)");
$pdo->exec("INSERT OR REPLACE INTO ai_provider_credentials (provider, status, verified, last_checked_at) VALUES ('gemini','VALID',1,CURRENT_TIMESTAMP)");

$service = new EffectiveConfigService();
$config = $service->getConfig();

echo "== Phase 1 effective config ==\n";
check(is_array($config) && isset($config['features'], $config['providers'], $config['precedence']), 'config has features/providers/precedence');

echo "-- DB chain overrides env-default when rows exist --\n";
$feature = array_values(array_filter($config['features'], fn ($f) => $f['feature'] === 'screenshot_extraction'))[0];
check($feature['source'] === 'db', 'source=db (rows exist)');
// Tesseract is provider-unavailable in this sandbox (no binary), so the chain
// is gemini only — this is the REAL runtime behavior, asserted as such.
check(count($feature['effectiveChain']) >= 1, 'effective chain non-empty (>=1 executable provider)');
check($feature['effectiveChain'][0]['provider'] === 'gemini', 'first effective provider is gemini (priority 1)');
check(isset($feature['configuredChain']) && count($feature['configuredChain']) === 2, 'configured chain reflects persisted rows');

echo "-- effective model / route precedence --\n";
$gem = array_values(array_filter($config['providers'], fn ($p) => $p['provider'] === 'gemini'))[0];
check($gem['effectiveModel'] === 'gemini-3.6-flash', 'effective model = catalog default (gemini-3.6-flash)');
check($gem['effectiveRoute'] === 'direct', 'effective route = direct (env GEMINI_ROUTE=direct)');

echo "-- credential metadata (presence + status), NEVER the value --\n";
check($gem['credential']['configured'] === true, 'credential configured=true (env presence)');
check($gem['credential']['status'] === 'VALID', 'credential status=VALID from metadata table');
check($gem['credential']['verified'] === true, 'credential verified=true');
check(!array_key_exists('value', $gem['credential']), 'credential metadata has no value key');

echo "-- quota is internal, not provider-reported --\n";
check($gem['quota']['source'] === 'internal', 'quota source=internal');
check($gem['quota']['quotaLimit'] === 1500, 'quota limit retains internal budget 1500');

echo "-- precedence documented --\n";
check(is_array($config['precedence']) && isset($config['precedence']['quota']), 'quota precedence explicitly documents internal budget');
check(str_contains($config['precedence']['quota'], 'internal'), 'quota precedence labels value as internal, not provider');

echo "-- SECRET NEVER appears in serialized config --\n";
$json = json_encode($config);
check(!str_contains((string) $json, 'AIza'), 'no AIza-style key prefix in output');
check(!str_contains((string) $json, $SECRET), 'the exact secret value is absent from output');

echo "-- env-default used when DB chain absent --\n";
$pdo->exec("DELETE FROM ai_feature_providers");
$config2 = (new EffectiveConfigService())->getConfig();
$f2 = array_values(array_filter($config2['features'], fn ($f) => $f['feature'] === 'screenshot_extraction'))[0];
check($f2['source'] === 'env-default', 'source=env-default when DB chain empty');
check(count($f2['effectiveChain']) >= 1, 'env-default effective chain still non-empty (runtime truth)');
// re-seed for cleanliness
$pdo->exec("INSERT OR REPLACE INTO ai_feature_providers (feature, provider, model, priority, enabled, route) VALUES ('screenshot_extraction','gemini',NULL,1,1,NULL)");

echo "\neffective-config: " . ($failures === 0 ? 'PASS' : 'FAIL') . " ($checks checks, $failures failures)\n";
exit($failures === 0 ? 0 : 1);
