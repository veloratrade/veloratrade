<?php

declare(strict_types=1);

/**
 * OpenAI / Claude adapter contract tests — offline only (no network calls):
 * payload contracts, typed error mapping, availability without credentials,
 * model/route allowlists, claimed capabilities, and the GeminiProvider
 * explicit route override plumbing (validated, legacy-safe).
 *
 * Run: php tools/tests/test_provider_adapters.php
 */

$root = sys_get_temp_dir() . '/velora-provider-adapters-test-' . bin2hex(random_bytes(5));
mkdir($root . '/config', 0700, true);
mkdir($root . '/data', 0700, true);
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

use Velora\AI\Exceptions\AIProviderException;
use Velora\AI\Exceptions\AIQuotaExhaustedException;
use Velora\AI\Exceptions\AITimeoutException;
use Velora\AI\Exceptions\AIValidationException;
use Velora\AI\Providers\ClaudeProvider;
use Velora\AI\Providers\GeminiProvider;
use Velora\AI\Providers\OpenAIProvider;
use Velora\AI\Services\ProviderCatalog;

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

echo "== Availability: missing credentials => unavailable, never a crash ==\n";
putenv('OPENAI_API_KEY=');
putenv('ANTHROPIC_API_KEY=');
check((new OpenAIProvider())->isAvailable() === false, 'openai unavailable without OPENAI_API_KEY');
check((new ClaudeProvider())->isAvailable() === false, 'claude unavailable without ANTHROPIC_API_KEY');
putenv('OPENAI_API_KEY=sk-test-openai-not-real');
putenv('ANTHROPIC_API_KEY=sk-ant-test-not-real');
check((new OpenAIProvider())->isAvailable() === true, 'openai available with key');
check((new ClaudeProvider())->isAvailable() === true, 'claude available with key');

echo "== Claimed capabilities match implemented surface ==\n";
check((new OpenAIProvider())->getCapabilities() === ['vision', 'text', 'extraction'], 'openai capabilities exactly vision/text/extraction');
check((new ClaudeProvider())->getCapabilities() === ['vision', 'text', 'extraction'], 'claude capabilities exactly vision/text/extraction');
check((new OpenAIProvider())->getName() === 'openai' && (new ClaudeProvider())->getName() === 'claude', 'provider names');

echo "== OpenAI payload contract ==\n";
$p = OpenAIProvider::buildPayload('prompt-x', 'rawbytes', 'image/jpeg', 'gpt-5-mini', ['responseMimeType' => 'application/json']);
check($p['model'] === 'gpt-5-mini', 'openai payload model');
check($p['messages'][0]['content'][0]['type'] === 'text', 'openai text part first');
check($p['messages'][0]['content'][1]['image_url']['url'] === 'data:image/jpeg;base64,' . base64_encode('rawbytes'), 'openai vision data-url part');
check(($p['response_format']['type'] ?? '') === 'json_object', 'openai json mode for extraction-shaped calls');
$p2 = OpenAIProvider::buildPayload('p', null, null, 'gpt-5', []);
check(count($p2['messages'][0]['content']) === 1, 'openai text-only payload has no image part');
check(!isset($p2['response_format']), 'openai no json mode unless requested');

echo "== Claude payload contract ==\n";
$c = ClaudeProvider::buildPayload('prompt-c', 'rawbytes', 'image/png', 'claude-sonnet-4-5', []);
check($c['model'] === 'claude-sonnet-4-5', 'claude payload model');
check($c['messages'][0]['content'][0]['type'] === 'image', 'claude image block');
check($c['messages'][0]['content'][0]['source']['media_type'] === 'image/png', 'claude source media_type');
check($c['messages'][0]['content'][0]['source']['data'] === base64_encode('rawbytes'), 'claude base64 data');
check($c['messages'][0]['content'][1]['type'] === 'text' && $c['messages'][0]['content'][1]['text'] === 'prompt-c', 'claude text block');
check(isset($c['max_tokens']) && $c['max_tokens'] > 0, 'claude max_tokens present');

echo "== Typed error mapping ==\n";
check(OpenAIProvider::mapError(401, null) instanceof AIProviderException, 'openai 401 auth -> AIProviderException (FALLBACK-class)');
check(OpenAIProvider::mapError(429, 'rate_limit_exceeded') instanceof AIQuotaExhaustedException, 'openai 429 -> quota/rate (FALLBACK-class)');
check(OpenAIProvider::mapError(408, null) instanceof AITimeoutException, 'openai 408 -> timeout');
check(OpenAIProvider::mapError(503, null) instanceof AIProviderException, 'openai 5xx -> provider unavailable');
check(OpenAIProvider::mapError(400, 'invalid_prompt') instanceof AIValidationException, 'openai 400 invalid input -> validation (ABORT-class)');
check(ClaudeProvider::mapError(401, null) instanceof AIProviderException, 'claude 401 -> provider/auth');
check(ClaudeProvider::mapError(429, 'rate_limit_error') instanceof AIQuotaExhaustedException, 'claude 429 -> quota/rate');
check(ClaudeProvider::mapError(504, null) instanceof AITimeoutException, 'claude 504 -> timeout');
check(ClaudeProvider::mapError(500, 'api_error') instanceof AIProviderException, 'claude 5xx -> unavailable');
check(ClaudeProvider::mapError(400, 'invalid_request_error') instanceof AIProviderException, 'claude 400 config -> provider (model/key config)');

echo "== Server-side allowlists (never trust the browser) ==\n";
check(ProviderCatalog::isValidModel('openai', 'gpt-5'), 'openai model allowlist accepts gpt-5');
check(!ProviderCatalog::isValidModel('openai', 'gpt-99-fake'), 'openai rejects non-allowlisted model');
check(!ProviderCatalog::isValidModel('claude', 'gpt-5'), 'claude rejects an openai model');
check(ProviderCatalog::isValidModel('gemini', null), 'NULL model = provider default (allowed)');
check(!ProviderCatalog::isValidRoute('openai', 'n8n_relay'), 'openai rejects n8n_relay route');
check(ProviderCatalog::isValidRoute('gemini', 'n8n_relay') && ProviderCatalog::isValidRoute('gemini', 'direct') && ProviderCatalog::isValidRoute('gemini', null), 'gemini routes: direct|n8n_relay|null');
check(ProviderCatalog::isValidRoute('tesseract', 'direct') === false && ProviderCatalog::isValidRoute('tesseract', null) === true, 'tesseract: route NULL only');
check(!ProviderCatalog::isRegisteredProvider('deepseek'), 'deepseek not registered yet (future provider)');

echo "== GeminiProvider explicit route override (validated, legacy-safe) ==\n";
putenv('GEMINI_API_KEY=test-gemini-key-not-real');
putenv('GEMINI_RELAY_URL=');
putenv('GEMINI_RELAY_TOKEN=');
putenv('GEMINI_ROUTE=');
// Override to n8n_relay while relay is UNCONFIGURED must fail closed BEFORE
// any network call (relay transport refuses), and the message carries no secret.
$err = null;
try {
    $img = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    (new GeminiProvider())->extract((string) base64_decode($img), microtime(true) + 5, 'n8n_relay');
} catch (AIProviderException $e) {
    $err = $e;
} catch (AIValidationException $e) {
    $err = $e;
}
check($err !== null, 'relay override with unconfigured relay fails closed (no network)');
// Invalid override values are ignored -> legacy resolution (direct, api key set => proceeds to network,
// so we only verify resolution semantics via getRoute-equivalent: use bogus override on generate with
// zero deadline to stop before any socket).
$timeout = null;
try {
    (new GeminiProvider())->generate('p', [], ['route' => 'bogus', 'deadline' => microtime(true) - 1]);
} catch (AITimeoutException $e) {
    $timeout = $e;
}
check($timeout instanceof AITimeoutException, 'invalid route override ignored — legacy path taken (deadline guard hit before transport)');

echo "\nprovider-adapters: " . ($failures === 0 ? 'PASS' : 'FAIL') . " ($checks checks, $failures failures)\n";
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
