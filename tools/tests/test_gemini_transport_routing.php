<?php

declare(strict_types=1);

/**
 * Runtime test — Gemini transport routing (direct | n8n_relay), relay payload
 * contract, relay error mapping, and secret-hygiene guarantees.
 *
 * No database, no external network (the only socket touched is a refused
 * localhost connect to prove exception messages never contain the token).
 *
 * Run: php tools/tests/test_gemini_transport_routing.php
 */

$root = sys_get_temp_dir() . '/velora-gemini-transport-test-' . bin2hex(random_bytes(5));
mkdir($root . '/config', 0700, true);
mkdir($root . '/data', 0700, true);
mkdir($root . '/logs', 0700, true);
putenv('APP_ENV=local');
putenv('VELORA_PRIVATE_ROOT=' . $root);
putenv('VELORA_DOCUMENT_ROOT=' . dirname(__DIR__, 2));
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

require __DIR__ . '/../../api/src/bootstrap.php';

use Velora\AI\Exceptions\AIProviderException;
use Velora\AI\Exceptions\AIQuotaExhaustedException;
use Velora\AI\Exceptions\AITimeoutException;
use Velora\AI\Exceptions\AIValidationException;
use Velora\AI\Providers\GeminiProvider;
use Velora\AI\Transports\DirectGeminiTransport;
use Velora\AI\Transports\N8nGeminiRelayTransport;

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

$FAKE_TOKEN = 'sekret-token-never-print-0123456789abcdef';

echo "== Route selection (explicit env wins; default direct) ==\n";
putenv('GEMINI_API_KEY=');
putenv('GEMINI_RELAY_URL=');
putenv('GEMINI_RELAY_TOKEN=');
putenv('GEMINI_ROUTE=n8n_relay');
check((new GeminiProvider())->getRoute() === 'n8n_relay', 'GEMINI_ROUTE=n8n_relay -> n8n_relay');
putenv('GEMINI_ROUTE=direct');
check((new GeminiProvider())->getRoute() === 'direct', 'GEMINI_ROUTE=direct -> direct');
putenv('GEMINI_ROUTE=bogus');
check((new GeminiProvider())->getRoute() === 'direct', 'GEMINI_ROUTE=bogus falls back to direct (no DB flag in CI)');
putenv('GEMINI_ROUTE=');
check((new GeminiProvider())->getRoute() === 'direct', 'GEMINI_ROUTE unset -> direct');

echo "== Availability per route ==\n";
putenv('GEMINI_API_KEY=');
putenv('GEMINI_RELAY_URL=https://relay.example.test/webhook/velora-gemini-relay');
putenv('GEMINI_RELAY_TOKEN=' . $FAKE_TOKEN);
putenv('GEMINI_ROUTE=n8n_relay');
$providerRelay = new GeminiProvider();
check($providerRelay->isAvailable() === true, 'relay route available with URL+token even without API key');
putenv('GEMINI_RELAY_TOKEN=');
check((new GeminiProvider())->isAvailable() === false, 'relay route unavailable when token missing');
putenv('GEMINI_RELAY_TOKEN=' . $FAKE_TOKEN);
putenv('GEMINI_ROUTE=direct');
check((new GeminiProvider())->isAvailable() === false, 'direct route unavailable without API key');
putenv('GEMINI_API_KEY=AIza-test-key-not-real');
check((new GeminiProvider())->isAvailable() === true, 'direct route available with API key');
$relayBadScheme = new N8nGeminiRelayTransport('http://insecure.example.test/hook', 't');
check($relayBadScheme->isConfigured() === false, 'relay rejects non-https URL');
check((new DirectGeminiTransport('AIza-test-key-not-real', 'gemini-3.6-flash'))->isConfigured() === true, 'direct transport configured with key');

echo "== Relay payload contract ==\n";
$payload = N8nGeminiRelayTransport::buildPayload('prompt-x', 'rawbytes', 'image/jpeg');
check(isset($payload['request_id'], $payload['prompt'], $payload['image_base64'], $payload['mime_type']), 'payload has request_id/prompt/image_base64/mime_type');
check(str_starts_with($payload['request_id'], 'velora-'), 'request_id has velora- prefix');
check(base64_decode($payload['image_base64'], true) === 'rawbytes', 'image_base64 round-trips');
check($payload['mime_type'] === 'image/jpeg', 'mime_type passthrough');
$textOnly = N8nGeminiRelayTransport::buildPayload('p', null, null);
check(!isset($textOnly['image_base64']) && !isset($textOnly['mime_type']), 'text-only payload omits image fields');
check(N8nGeminiRelayTransport::buildPayload('p', 'img', null)['mime_type'] === 'image/png', 'null mime defaults to image/png');

echo "== Relay error mapping onto Velora exception model ==\n";
check(N8nGeminiRelayTransport::mapRelayError(['code' => 'UPSTREAM_QUOTA_EXHAUSTED', 'http_status' => 429]) instanceof AIQuotaExhaustedException, 'UPSTREAM_QUOTA_EXHAUSTED -> AIQuotaExhaustedException');
check(N8nGeminiRelayTransport::mapRelayError(['code' => 'QUOTA_EXHAUSTED']) instanceof AIQuotaExhaustedException, 'QUOTA_EXHAUSTED alias -> AIQuotaExhaustedException');
check(N8nGeminiRelayTransport::mapRelayError(['code' => 'UPSTREAM_NETWORK_TIMEOUT']) instanceof AITimeoutException, 'UPSTREAM_NETWORK_TIMEOUT -> AITimeoutException');
check(N8nGeminiRelayTransport::mapRelayError(['code' => 'NETWORK_TIMEOUT']) instanceof AITimeoutException, 'NETWORK_TIMEOUT alias -> AITimeoutException');
foreach (['INVALID_INPUT', 'UPSTREAM_BAD_REQUEST', 'UPSTREAM_INVALID_JSON', 'UPSTREAM_MALFORMED_RESPONSE'] as $vc) {
    check(N8nGeminiRelayTransport::mapRelayError(['code' => $vc]) instanceof AIValidationException, "$vc -> AIValidationException");
}
foreach (['UPSTREAM_AUTH', 'UPSTREAM_MODEL_NOT_FOUND', 'UPSTREAM_UNAVAILABLE'] as $pc) {
    check(N8nGeminiRelayTransport::mapRelayError(['code' => $pc]) instanceof AIProviderException, "$pc -> AIProviderException");
}
$unknown = N8nGeminiRelayTransport::mapRelayError(['code' => 'WEIRD_NEW_CODE']);
check($unknown instanceof AIProviderException && $unknown->getMessage() === 'Gemini relay error: WEIRD_NEW_CODE', 'unknown code -> AIProviderException with normalized code only');

echo "== Secret hygiene (token never in messages/exceptions) ==\n";
$leakErr = N8nGeminiRelayTransport::mapRelayError(['code' => 'UPSTREAM_AUTH', 'http_status' => 401, 'message' => 'leaking ' . $FAKE_TOKEN]);
check(strpos($leakErr->getMessage(), $FAKE_TOKEN) === false, 'mapped exception message never contains relay token (upstream message dropped)');

$unconfigured = new N8nGeminiRelayTransport('', '');
try {
    $unconfigured->generateContent('p', null, null, [], 3);
    check(false, 'unconfigured relay must throw');
} catch (AIProviderException $e) {
    check(strpos($e->getMessage(), 'GEMINI_RELAY') !== false, 'unconfigured relay names the missing env config');
    check(strpos($e->getMessage(), $FAKE_TOKEN) === false, 'unconfigured relay message has no token');
}

// Refused localhost connect: proves the transport's failure messages stay clean.
$refused = new N8nGeminiRelayTransport('https://127.0.0.1:1/webhook/velora-gemini-relay', $FAKE_TOKEN);
try {
    $refused->generateContent('p', null, null, ['model' => 'gemini-3.6-flash'], 3);
    check(false, 'dead-end relay URL must throw');
} catch (\Velora\AI\Exceptions\AIException $e) {
    check(true, 'dead-end relay URL throws AIException subclass (' . get_class($e) . ')');
    check(strpos($e->getMessage(), $FAKE_TOKEN) === false, 'transport failure message never contains the token');
}

// Cleanup env for subsequent tests in the same CI job.
putenv('GEMINI_API_KEY=');
putenv('GEMINI_RELAY_URL=');
putenv('GEMINI_RELAY_TOKEN=');
putenv('GEMINI_ROUTE=');

echo "\nGemini transport routing: " . ($failures === 0 ? "OK ($checks checks)" : "FAILED ($failures/$checks)") . "\n";
exit($failures === 0 ? 0 : 1);
