<?php

declare(strict_types=1);

/**
 * AIManager provider-chain failover tests with fake providers (no network).
 * Covers mission scenarios 7-13: quota/timeout/rate-limit/unavailable ->
 * next provider; validation/consent/capability -> no fallback; all-fail;
 * fallback_index + observability fields in ai_provider_logs.
 *
 * Run: php tools/tests/test_ai_failover.php
 */

$root = sys_get_temp_dir() . '/velora-ai-failover-test-' . bin2hex(random_bytes(5));
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
    'APP_ENV=local', 'APP_DEBUG=true', 'DB_DRIVER=sqlite',
    'DB_DATABASE=' . $root . '/data/velora.sqlite',
    'JWT_SECRET=' . str_repeat('j', 48),
    'APP_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)),
    'CORS_ALLOWED_ORIGINS=http://localhost', 'FRONTEND_URL=http://localhost',
    'MAIL_DRIVER=log',
]) . "\n");

require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

use Velora\AI\DTOs\AIResponseDTO;
use Velora\AI\Exceptions\AIConsentRequiredException;
use Velora\AI\Exceptions\AIProviderException;
use Velora\AI\Exceptions\AIQuotaExhaustedException;
use Velora\AI\Exceptions\AITimeoutException;
use Velora\AI\Exceptions\AIValidationException;
use Velora\AI\Extraction\ExtractedTradeData;
use Velora\AI\Providers\AIProviderInterface;
use Velora\AI\Repositories\AIFeatureProviderRepository;
use Velora\AI\Services\AIManager;
use Velora\AI\Services\FeatureRouter;

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

// --- database -----------------------------------------------------------------
$pdo = new PDO('sqlite:' . $root . '/data/velora.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach ([
    'ai_feature_providers' => 'CREATE TABLE IF NOT EXISTS ai_feature_providers (
        id INTEGER PRIMARY KEY AUTOINCREMENT, feature TEXT NOT NULL, provider TEXT NOT NULL,
        model TEXT NULL, priority INTEGER NOT NULL DEFAULT 1, enabled INTEGER NOT NULL DEFAULT 1,
        route TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(feature, provider))',
    'ai_provider_quotas' => 'CREATE TABLE IF NOT EXISTS ai_provider_quotas (
        provider TEXT PRIMARY KEY, daily_used INTEGER NOT NULL DEFAULT 0,
        quota_limit INTEGER NOT NULL DEFAULT 1500, reset_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)',
    'ai_provider_logs' => 'CREATE TABLE IF NOT EXISTS ai_provider_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT, provider TEXT NOT NULL,
        status TEXT NOT NULL, latency_ms INTEGER NOT NULL DEFAULT 0, error_code TEXT NULL,
        feature TEXT NULL, model TEXT NULL, route TEXT NULL, fallback_index INTEGER NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)',
    'ai_requests' => 'CREATE TABLE IF NOT EXISTS ai_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, feature TEXT NOT NULL,
        provider TEXT NOT NULL, model TEXT NOT NULL, prompt_hash TEXT NOT NULL,
        tokens_used INTEGER NOT NULL DEFAULT 0, latency_ms INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL, cost REAL NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)',
    'users' => 'CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, ai_consent_at DATETIME NULL,
        role TEXT NOT NULL DEFAULT \'user\')',
] as $table => $ddl) {
    $pdo->exec($ddl);
}
$pdo->exec('CREATE TABLE IF NOT EXISTS rate_limits (bucket TEXT PRIMARY KEY, hits INTEGER NOT NULL, window_start DATETIME NOT NULL)');

// Chain: gemini(1) -> tesseract(2) — fake instances injected into AIManager.
$pdo->exec("INSERT INTO ai_feature_providers (feature, provider, model, priority, enabled, route) VALUES
    ('screenshot_extraction','gemini','gemini-3.6-flash',1,1,NULL),
    ('screenshot_extraction','tesseract',NULL,2,1,NULL)");
$pdo->exec("INSERT INTO ai_provider_quotas (provider, quota_limit) VALUES ('gemini',1500),('tesseract',100000)");
$pdo->exec("INSERT INTO users (email, ai_consent_at) VALUES ('t@velora.test', datetime('now'))");
$userId = (int) $pdo->lastInsertId();

abstract class FakeProvider implements AIProviderInterface
{
    public static int $calls = 0;
    public function __construct(public \Closure $behavior) {}
    public function getName(): string { return 'fake'; }
    public function getCapabilities(): array { return ['vision', 'text', 'extraction']; }
    public function getCostTier(): int { return 0; }
    public function isAvailable(): bool { return true; }
    public function generate(string $prompt, array $context = [], array $options = []): AIResponseDTO
    {
        static::$calls++;
        return ($this->behavior)();
    }
    public function extract(string $imageRaw, float $deadline): ExtractedTradeData
    {
        static::$calls++;
        return ($this->behavior)();
    }
}
final class FakeGemini extends FakeProvider
{
    public static int $calls = 0;
    public function getName(): string { return 'gemini'; }
}
final class FakeTesseract extends FakeProvider
{
    public static int $calls = 0;
    public function getName(): string { return 'tesseract'; }
}

function okExtract(string $provider): ExtractedTradeData
{
    return ExtractedTradeData::fromArray([
        'symbol' => 'XAUUSD', 'side' => 'buy', 'entry' => '2000.5', 'exit' => '2010.2',
        'lot' => '0.1', 'pnl' => '97.5', 'confidence' => 0.9,
    ], $provider, 0.9);
}

$repo = new AIFeatureProviderRepository();

function manager(FakeGemini $g, FakeTesseract $t, AIFeatureProviderRepository $repo): AIManager
{
    $router = new FeatureRouter($repo, null, ['gemini' => $g, 'tesseract' => $t]);
    return new AIManager(providers: [$g, $t], router: $router);
}

$deadline = microtime(true) + 30;

// Real tiny PNG — the privacy anonymizer (fail-closed) rejects invalid image
// bytes for external providers, so a dummy string would skip gemini entirely.
$im = imagecreatetruecolor(64, 64);
imagefill($im, 0, 0, imagecolorallocate($im, 40, 50, 70));
ob_start();
imagepng($im);
$IMG = (string) ob_get_clean();

echo "== 7/9/10: gemini quota/timeout/unavailable -> next provider ==\n";
foreach ([
    ['quota exhausted', fn () => new AIQuotaExhaustedException('quota', 'gemini'), 'quota_exhausted'],
    ['timeout', fn () => new AITimeoutException('timeout', 'gemini'), 'timeout'],
    ['rate limit (provider-layer)', fn () => new AIProviderException('RATE_LIMIT', 'gemini'), 'failed'],
    ['unavailable upstream', fn () => new AIProviderException('UPSTREAM_UNAVAILABLE', 'gemini'), 'failed'],
] as [$label, $mk, $logStatus]) {
    FakeGemini::$calls = 0;
    FakeTesseract::$calls = 0;
    $g = new FakeGemini(fn () => throw $mk());
    $t = new FakeTesseract(fn () => okExtract('tesseract'));
    $result = manager($g, $t, $repo)->extract($IMG, $deadline, $userId);
    check($result->provider === 'tesseract', "$label -> falls back to tesseract (result)");
    check(FakeGemini::$calls === 1 && FakeTesseract::$calls === 1, "$label -> each provider called exactly once (no retry loops)");
    $log = $pdo->query("SELECT provider, status, feature, fallback_index FROM ai_provider_logs ORDER BY id DESC LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
    check($log[0]['provider'] === 'tesseract' && (int) $log[0]['fallback_index'] === 1 && $log[0]['feature'] === 'screenshot_extraction', "$label -> success logged with fallback_index=1 + feature");
    check($log[1]['provider'] === 'gemini' && $log[1]['status'] === $logStatus && (int) $log[1]['fallback_index'] === 0, "$label -> failure logged with fallback_index=0");
}

echo "== 11: input validation -> NO fallback ==\n";
FakeGemini::$calls = 0;
FakeTesseract::$calls = 0;
$g = new FakeGemini(fn () => throw new AIValidationException('invalid input', ['provider' => ['code' => 'INVALID_INPUT']]));
$t = new FakeTesseract(fn () => okExtract('tesseract'));
$threw = null;
try {
    manager($g, $t, $repo)->extract($IMG, $deadline, $userId);
} catch (AIValidationException $e) {
    $threw = $e;
}
check($threw instanceof AIValidationException, 'validation error rethrown (AI_VALIDATION_FAILED)');
check(FakeGemini::$calls === 1 && FakeTesseract::$calls === 0, 'validation error does NOT fall through the chain');

echo "== 12: consent -> ABORT, distinguishable ==\n";
$pdo->exec("UPDATE users SET ai_consent_at = NULL");
$g = new FakeGemini(fn () => okExtract('gemini'));
$t = new FakeTesseract(fn () => okExtract('tesseract'));
$threw = null;
try {
    manager($g, $t, $repo)->extract($IMG, $deadline, $userId);
} catch (AIConsentRequiredException $e) {
    $threw = $e;
}
check($threw instanceof AIConsentRequiredException, 'missing consent throws AIConsentRequiredException (distinguishable AI_CONSENT_REQUIRED)');
$pdo->exec("UPDATE users SET ai_consent_at = datetime('now')");

echo "== 13: unsupported capability -> no fallback attempt ==\n";
$g = new FakeGemini(fn () => okExtract('gemini'));
$t = new FakeTesseract(fn () => okExtract('tesseract'));
$textOnlyFake = new class(fn () => new AIResponseDTO(content: '{}', provider: 'gemini', model: 'm', latencyMs: 1, tokensUsed: 1, confidence: 0.9, status: 'success')) extends FakeProvider {
    public function getName(): string { return 'gemini'; }
    public function getCapabilities(): array { return ['text']; } // no vision
};
$tessForCap = new FakeTesseract(fn () => new AIResponseDTO(content: '{}', provider: 'tesseract', model: 't', latencyMs: 1, tokensUsed: 1, confidence: 0.9, status: 'success'));
$m = new AIManager(providers: [$textOnlyFake, $tessForCap], router: new FeatureRouter($repo, null, ['gemini' => $textOnlyFake, 'tesseract' => $tessForCap]));
$resp = $m->generate('p', ['feature' => 'screenshot_extraction'], [
    'deadline' => $deadline, 'capability' => 'vision', 'user_id' => $userId, 'feature' => 'screenshot_extraction',
]);
check($resp->provider === 'tesseract', 'capability mismatch (vision required, fake gemini text-only) -> skipped, tesseract serves');

echo "== all providers fail -> machine-readable final error ==\n";
$g = new FakeGemini(fn () => throw new AIQuotaExhaustedException('quota', 'gemini'));
$t = new FakeTesseract(fn () => throw new AITimeoutException('timeout', 'tesseract'));
$threw = null;
try {
    manager($g, $t, $repo)->extract($IMG, $deadline, $userId);
} catch (AIQuotaExhaustedException|AITimeoutException|AIProviderException $e) {
    $threw = $e;
}
check($threw !== null, 'all-fail surfaces typed final error (last FALLBACK exception)');

echo "== generate() path: quota fallback + fallback_index logging ==\n";
$g = new FakeGemini(fn () => throw new AIQuotaExhaustedException('quota', 'gemini'));
$t = new FakeTesseract(function () {
    return new AIResponseDTO(content: '{"ok":true}', provider: 'tesseract', model: 'tess', latencyMs: 5, tokensUsed: 3, confidence: 0.9, status: 'success');
});
$resp = manager($g, $t, $repo)->generate('p', ['feature' => 'screenshot_extraction'], [
    'deadline' => $deadline, 'feature' => 'screenshot_extraction', 'user_id' => $userId,
]);
check($resp->provider === 'tesseract', 'generate(): quota on gemini -> tesseract result');
$log = $pdo->query("SELECT provider, status, feature, model, fallback_index FROM ai_provider_logs ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check($log['provider'] === 'tesseract' && $log['feature'] === 'screenshot_extraction' && (int) $log['fallback_index'] === 1, 'generate(): success log carries feature+fallback_index');

echo "== disabled provider row -> chain skips gemini entirely ==\n";
$pdo->exec("UPDATE ai_feature_providers SET enabled=0 WHERE provider='gemini'");
FakeGemini::$calls = 0;
$g = new FakeGemini(fn () => okExtract('gemini'));
$t = new FakeTesseract(fn () => okExtract('tesseract'));
$result = manager($g, $t, $repo)->extract($IMG, $deadline, $userId);
check($result->provider === 'tesseract' && FakeGemini::$calls === 0, 'disabled gemini row: gemini never invoked');
$pdo->exec("UPDATE ai_feature_providers SET enabled=1 WHERE provider='gemini'");

echo "== empty routing table -> env-default legacy behavior ==\n";
$pdo->exec('DELETE FROM ai_feature_providers');
FakeGemini::$calls = 0;
$g = new FakeGemini(fn () => okExtract('gemini'));
$t = new FakeTesseract(fn () => okExtract('tesseract'));
$result = manager($g, $t, $repo)->extract($IMG, $deadline, $userId);
check($result->provider === 'gemini', 'empty table: env-default chain keeps gemini-first (legacy)');

echo "\nai-failover: " . ($failures === 0 ? 'PASS' : 'FAIL') . " ($checks checks, $failures failures)\n";
$rm = static function (string $p) use (&$rm): void {
    if (!is_dir($p)) { @unlink($p); return; }
    foreach (scandir($p) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $rm($p . '/' . $f);
    }
    @rmdir($p);
};
$rm($root);
exit($failures === 0 ? 0 : 1);
