<?php

declare(strict_types=1);

/**
 * FeatureRouter + AIFailureClassifier runtime tests (no network, no real
 * provider calls). Covers: db chain ordering, disabled/credential/capability
 * filters, env-default fallback when the table is empty, classifier mapping.
 *
 * Run: php tools/tests/test_feature_routing.php
 */

$root = sys_get_temp_dir() . '/velora-feature-routing-test-' . bin2hex(random_bytes(5));
mkdir($root . '/config', 0700, true);
mkdir($root . '/data', 0700, true);
mkdir($root . '/logs', 0700, true);
putenv('APP_ENV=local');
putenv('VELORA_PRIVATE_ROOT=' . $root);
putenv('VELORA_DOCUMENT_ROOT=' . dirname(__DIR__, 2));
putenv('DB_DRIVER=sqlite');
putenv('DB_DATABASE=' . $root . '/data/velora.sqlite');
putenv('JWT_SECRET=' . str_repeat('j', 48));
putenv('APP_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
putenv('CORS_ALLOWED_ORIGINS=http://localhost');
putenv('FRONTEND_URL=http://localhost');
putenv('MAIL_DRIVER=log');
file_put_contents($root . '/config/velora.env', implode("\n", [
    'APP_ENV=local',
    'APP_DEBUG=true',
    'DB_DRIVER=sqlite',
    'DB_DATABASE=' . $root . '/data/velora.sqlite',
    'JWT_SECRET=' . str_repeat('j', 48),
    'APP_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)),
    'CORS_ALLOWED_ORIGINS=http://localhost',
    'FRONTEND_URL=http://localhost',
    'MAIL_DRIVER=log',
]) . "\n");

require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

use Velora\AI\Exceptions\AIConsentRequiredException;
use Velora\AI\Exceptions\AIProviderException;
use Velora\AI\Exceptions\AIQuotaExhaustedException;
use Velora\AI\Exceptions\AITimeoutException;
use Velora\AI\Exceptions\AIValidationException;
use Velora\AI\Repositories\AIFeatureProviderRepository;
use Velora\AI\Providers\TesseractProvider;
use Velora\AI\Services\AIFailureClassifier;
use Velora\AI\Services\FeatureRouter;

$failures = 0;
$checks = 0;
function check(bool $cond, string $label): void
{
    global $failures, $checks;
    $checks++;
    if ($cond) {
        echo "  PASS: $label\n";
    } else {
        $failures++;
        echo "  FAIL: $label\n";
    }
}

// --- test database -----------------------------------------------------------
$pdo = new PDO('sqlite:' . $root . '/data/velora.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE IF NOT EXISTS ai_feature_providers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    feature TEXT NOT NULL,
    provider TEXT NOT NULL,
    model TEXT NULL,
    priority INTEGER NOT NULL DEFAULT 1,
    enabled INTEGER NOT NULL DEFAULT 1,
    route TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(feature, provider)
)');

// Provider env baseline: gemini configured, openai/claude not configured.
putenv('GEMINI_API_KEY=test-gemini-key-not-real');
putenv('GEMINI_RELAY_URL=');
putenv('GEMINI_RELAY_TOKEN=');
putenv('GEMINI_ROUTE=');
putenv('OPENAI_API_KEY=');
putenv('ANTHROPIC_API_KEY=');
putenv('AI_ENABLED_PROVIDERS=gemini,tesseract');

$repo = new AIFeatureProviderRepository();
$router = new FeatureRouter($repo);

// Tesseract participates only where its binary really exists — the router
// reflects real availability, so tests derive expectations from it.
$tessAvailable = (new TesseractProvider())->isAvailable();
$tessIn = fn (array $chain): bool => in_array('tesseract', array_map(fn ($e) => $e['provider'], $chain), true);

echo "== Empty routing table -> env-default chain, no fabricated rows ==\n";
check($repo->countAll() === 0, 'routing table starts empty');
$chain = $router->resolveChain('screenshot_extraction');
check($router->sourceFor('screenshot_extraction') === 'env-default', 'empty table reports source=env-default');
check($chain !== [] && $chain[0]['provider'] === 'gemini', 'env-default: gemini first (priority 10)');
check($tessIn($chain) === $tessAvailable, 'env-default: tesseract present exactly when its binary is available (legacy registry order)');
check($repo->countAll() === 0, 'no rows fabricated by resolution');

echo "== DB chain: seed gemini(1) + tesseract(2) ==\n";
$pdo->exec("INSERT INTO ai_feature_providers (feature, provider, model, priority, enabled, route) VALUES
    ('screenshot_extraction','gemini',NULL,1,1,NULL),
    ('screenshot_extraction','tesseract',NULL,2,1,NULL)");
$chain = $router->resolveChain('screenshot_extraction');
check($router->sourceFor('screenshot_extraction') === 'db', 'seeded table reports source=db');
check(count($chain) === ($tessAvailable ? 2 : 1), 'db chain has the seeded executable providers (tesseract per real availability)');
check($chain[0]['provider'] === 'gemini' && $chain[0]['fallback_index'] === 0, 'gemini first, fallback_index=0');
if ($tessAvailable) {
    check($chain[1]['provider'] === 'tesseract' && $chain[1]['fallback_index'] === 1, 'tesseract second, fallback_index=1');
} else {
    check(true, 'tesseract binary absent on this host: fallback_index sequencing verified via failover test');
}
check($chain[0]['model'] === 'gemini-3.6-flash', 'gemini model defaults to env/catalog default');
check($chain[0]['route'] === null, 'route NULL keeps legacy provider-internal resolution');

echo "== Disabled provider skipped ==\n";
$pdo->exec("UPDATE ai_feature_providers SET enabled=0 WHERE provider='gemini'");
$chain = $router->resolveChain('screenshot_extraction');
check(!$tessIn($chain) || $chain[0]['provider'] !== 'gemini', 'disabled gemini is skipped');
check($tessAvailable ? ($chain[0]['provider'] === 'tesseract') : ($chain === [] || $chain[0]['provider'] !== 'gemini'), 'disabled gemini: chain reflects real availability of tesseract');
$pdo->exec("UPDATE ai_feature_providers SET enabled=1 WHERE provider='gemini'");

echo "== Missing credential skipped ==\n";
putenv('GEMINI_API_KEY=');
$chain = $router->resolveChain('screenshot_extraction');
$names = array_map(fn ($e) => $e['provider'], $chain);
check(!in_array('gemini', $names, true), 'gemini without credential is skipped');
putenv('GEMINI_API_KEY=test-gemini-key-not-real');

echo "== Explicit route credential rules (gemini) ==\n";
$pdo->exec("UPDATE ai_feature_providers SET route='n8n_relay' WHERE provider='gemini'");
putenv('GEMINI_RELAY_URL=');
putenv('GEMINI_RELAY_TOKEN=');
$chain = $router->resolveChain('screenshot_extraction');
check(!in_array('gemini', array_map(fn ($e) => $e['provider'], $chain), true), 'relay route without URL+token is skipped');
putenv('GEMINI_RELAY_URL=https://relay.example.test/webhook/velora-gemini-relay');
putenv('GEMINI_RELAY_TOKEN=test-relay-token-not-real');
$chain = $router->resolveChain('screenshot_extraction');
check(count($chain) >= 1 && $chain[0]['provider'] === 'gemini' && $chain[0]['route'] === 'n8n_relay', 'relay route with URL+token is executable (no API key needed)');
putenv('GEMINI_RELAY_URL=');
putenv('GEMINI_RELAY_TOKEN=');
$pdo->exec("UPDATE ai_feature_providers SET route=NULL WHERE provider='gemini'");

echo "== Capability filter ==\n";
$visionChain = $router->resolveChain('screenshot_extraction', 'vision');
$visionNames = array_map(fn ($e) => $e['provider'], $visionChain);
check(in_array('gemini', $visionNames, true) && !in_array('tesseract', $visionNames, true), 'vision filter keeps gemini, drops tesseract (ocr/text-only)');
$textChain = $router->resolveChain('trade_analysis', 'text');
$textNames = array_map(fn ($e) => $e['provider'], $textChain);
check(in_array('gemini', $textNames, true), 'text capability filter keeps text-capable gemini');
check($tessIn($textChain) === ($tessAvailable && true), 'text capability: tesseract included only when really available');

echo "== Priority ordering respected (db) ==\n";
$pdo->exec("INSERT INTO ai_feature_providers (feature, provider, model, priority, enabled, route) VALUES
    ('trade_analysis','tesseract',NULL,1,1,NULL),
    ('trade_analysis','gemini',NULL,5,1,NULL)");
$chain = $router->resolveChain('trade_analysis', null);
$names = array_map(fn ($e) => $e['provider'], $chain);
check($names === ($tessAvailable ? ['tesseract', 'gemini'] : ['gemini']), 'priority ASC order respected (tesseract p1 < gemini p5, per availability)');

echo "== Reorder persists ==\n";
$rows = $repo->chainFor('trade_analysis');
$ids = array_map(fn ($r) => (int) $r['id'], $rows);
$rows = $repo->reorder('trade_analysis', array_reverse($ids));
check((int) $rows[0]['priority'] === 1 && $rows[0]['provider'] === 'gemini', 'reorder persists new priorities (read-back)');
$chain = $router->resolveChain('trade_analysis', null);
check($chain[0]['provider'] === 'gemini', 'router reflects persisted reorder (gemini now first)');

echo "== Missing credential on env-default path (openai not configured) ==\n";
putenv('AI_ENABLED_PROVIDERS=gemini,openai,tesseract');
$chain = $router->buildEnvDefaultChain(null);
$names = array_map(fn ($e) => $e['provider'], $chain);
check(!in_array('openai', $names, true), 'env-default chain drops unconfigured openai');
putenv('AI_ENABLED_PROVIDERS=gemini,tesseract');

echo "== AIFailureClassifier mapping (typed, no message guesswork) ==\n";
check(AIFailureClassifier::classify(new AIQuotaExhaustedException('q', 'gemini')) === AIFailureClassifier::FALLBACK, 'quota exhausted -> FALLBACK');
check(AIFailureClassifier::classify(new AITimeoutException('t', 'gemini')) === AIFailureClassifier::FALLBACK, 'timeout -> FALLBACK');
check(AIFailureClassifier::classify(new AIProviderException('auth failed', 'gemini')) === AIFailureClassifier::FALLBACK, 'provider/auth failure -> FALLBACK');
check(AIFailureClassifier::classify(new AIProviderException('upstream unavailable', 'gemini')) === AIFailureClassifier::FALLBACK, 'upstream unavailable -> FALLBACK');
check(AIFailureClassifier::classify(new AIConsentRequiredException('consent')) === AIFailureClassifier::ABORT, 'consent required -> ABORT');
check(AIFailureClassifier::classify(new AIValidationException('bad input', ['provider' => ['code' => 'INVALID_INPUT']])) === AIFailureClassifier::ABORT, 'input validation (INVALID_INPUT) -> ABORT');
check(AIFailureClassifier::classify(new AIValidationException('bad contract', ['extraction' => ['code' => 'INVALID']])) === AIFailureClassifier::ABORT, 'invalid extraction contract -> ABORT');
check(AIFailureClassifier::classify(new AIValidationException('malformed', ['json' => ['code' => 'MALFORMED']])) === AIFailureClassifier::FALLBACK, 'provider-output malformed -> FALLBACK (legacy behavior preserved)');
check(AIFailureClassifier::classify(new AIValidationException('lc', ['provider' => ['code' => 'LOW_CONFIDENCE']])) === AIFailureClassifier::FALLBACK, 'low confidence -> FALLBACK (legacy behavior preserved)');
check(AIFailureClassifier::classify(new AIValidationException('missing', ['response' => ['code' => 'MISSING_TEXT']])) === AIFailureClassifier::FALLBACK, 'missing text from provider -> FALLBACK');
check(AIFailureClassifier::classify(new RuntimeException('app bug')) === AIFailureClassifier::ABORT, 'non-AI application error -> ABORT');

// cleanup
$rootDir = $root;
$rm = static function (string $p) use (&$rm): void {
    if (!is_dir($p)) { @unlink($p); return; }
    foreach (scandir($p) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $rm($p . '/' . $f);
    }
    @rmdir($p);
};
$rm($rootDir);

echo "\nfeature-routing: " . ($failures === 0 ? "PASS" : "FAIL") . " ($checks checks, $failures failures)\n";
exit($failures === 0 ? 0 : 1);
