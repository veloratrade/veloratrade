<?php

declare(strict_types=1);

/**
 * Provider credential verification tests (Phase 2).
 *
 * Pure unit tests — NO network, NO database, NO production credentials. The
 * Gemini HTTP layer is injected with a fake client returning canned responses;
 * the verifier's classification logic is asserted against those fixtures.
 *
 * Run: php tools/tests/test_provider_verification.php
 */

// Minimal runtime env: bootstrap() requires a validated private root outside the
// document root, and Config::env() needs it before reading key NAMES (values are
// injected directly into the verifier in this unit test — no real secrets).
$ROOT = sys_get_temp_dir() . '/velora-proof-test-' . bin2hex(random_bytes(4));
@mkdir($ROOT . '/config', 0700, true);
putenv('APP_ENV=local');
putenv('APP_DEBUG=true');
putenv('VELORA_PRIVATE_ROOT=' . $ROOT);
putenv('VELORA_DOCUMENT_ROOT=' . dirname(__DIR__, 2));
// Relay route needs the relay URL/token for connection-test fixtures (fake HTTP,
// never a real call). Values are test-only placeholders.
putenv('GEMINI_RELAY_URL=https://relay.example.invalid/webhook');
putenv('GEMINI_RELAY_TOKEN=relay-test-token-placeholder');
if (!is_file($ROOT . '/config/velora.env')) {
    file_put_contents($ROOT . '/config/velora.env', "APP_ENV=local\nAPP_DEBUG=true\nAPP_ENCRYPTION_KEY=" . base64_encode(random_bytes(32)) . "\n");
}

require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

use Velora\AI\Providers\CredentialStatus;
use Velora\AI\Providers\GeminiCredentialVerifier;
use Velora\AI\Providers\VerificationResult;
use Velora\AI\Security\CredentialFingerprint;

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

const SECRET = 'AIzaSy-TEST-SECRET-NEVER-LEAK-123456789';

/**
 * Fake HTTP client. $map is [method|url => ['http'=>int,'body'=>string,'error'=>int]].
 * Any unmapped request falls through to a generic 200.
 */
function fakeHttp(array $map): callable
{
    return static function (string $method, string $url, array $headers) use ($map): array {
        $started = microtime(true);
        $key = $method . ' ' . $url;
        foreach ($map as $pattern => $res) {
            if (str_contains($key, $pattern)) {
                $latencyMs = isset($res['latency_ms']) ? (int) $res['latency_ms'] : (int) ((microtime(true) - $started) * 1000);
                return [
                    'http' => (int) ($res['http'] ?? 200),
                    'body' => (string) ($res['body'] ?? ''),
                    'error' => (int) ($res['error'] ?? 0),
                    'latency_ms' => $latencyMs,
                ];
            }
        }
        return ['http' => 200, 'body' => '{}', 'error' => 0, 'latency_ms' => (int) ((microtime(true) - $started) * 1000)];
    };
}

function verifier(callable $http, ?string $route = 'direct', ?string $key = SECRET): GeminiCredentialVerifier
{
    return new GeminiCredentialVerifier($key, $route, $http, 5);
}

echo "== CredentialStatus semantics ==\n";
check(CredentialStatus::isEligibleForActivation('VALID'), 'VALID is eligible for activation');
check(!CredentialStatus::isEligibleForActivation('UNVERIFIED'), 'UNVERIFIED is NOT eligible for activation');
check(!CredentialStatus::isEligibleForActivation('INVALID_CREDENTIAL'), 'INVALID_CREDENTIAL is NOT eligible');
check(!CredentialStatus::isEligibleForActivation('UNKNOWN'), 'UNKNOWN is NOT eligible');
check(CredentialStatus::isValid('RATE_LIMITED'), 'RATE_LIMITED is a valid status value');
check(!CredentialStatus::isValid('BOGUS'), 'BOGUS is NOT a valid status value');

echo "== VerificationResult secret safety ==\n";
$r = VerificationResult::unknown('gemini', 'direct');
$json = json_encode($r->toArray());
check(!str_contains((string) $json, SECRET), 'toArray() NEVER contains the credential');
check(is_array($r->toArray()) && array_key_exists('status', $r->toArray()), 'toArray() exposes status metadata');

echo "== Gemini direct verification: HTTP 200 => VALID ==\n";
$res = verifier(fakeHttp(['GET ' => ['http' => 200, 'body' => '{"models":[]}']]))->verifyCredential();
check($res->status() === CredentialStatus::VALID, 'status=VALID');
check($res->verified() === true, 'verified=true');
check($res->reachable() === true, 'reachable=true');
check($res->isEligibleForActivation() === true, 'eligible for activation');

echo "== Gemini direct verification: HTTP 401 => INVALID_CREDENTIAL ==\n";
$res = verifier(fakeHttp(['GET ' => ['http' => 401, 'body' => '{"error":{"code":401}}']]))->verifyCredential();
check($res->status() === CredentialStatus::INVALID_CREDENTIAL, 'status=INVALID_CREDENTIAL');
check($res->verified() === false, 'verified=false');
check(!$res->isEligibleForActivation(), 'NOT eligible (401 must not activate)');

echo "== Gemini real provider behavior: invalid key is HTTP 400 + API_KEY_INVALID (NOT 401) ==\n";
// This replicates the exact real Gemini response observed against the live API.
$res = verifier(fakeHttp(['GET ' => ['http' => 400, 'body' => '{\"error\":{\"code\":400,\"message\":\"API key not valid. Please pass a valid API key.\",\"status\":\"INVALID_ARGUMENT\",\"details\":[{\"reason\":\"API_KEY_INVALID\"}]}}']]))->verifyCredential();
check($res->status() === CredentialStatus::INVALID_CREDENTIAL, 'invalid key (400+API_KEY_INVALID) => INVALID_CREDENTIAL (not INSUFFICIENT_PERMISSION)');
check(!$res->isEligibleForActivation(), 'invalid key is NOT eligible for activation (gated)');
$res = verifier(fakeHttp(['GET ' => ['http' => 400, 'body' => '{\"error\":{\"message\":\"API key not valid\"}}']]))->verifyCredential();
check($res->status() === CredentialStatus::INVALID_CREDENTIAL, '400 + "API key not valid" body => INVALID_CREDENTIAL');

echo "== Gemini direct verification: HTTP 403 (permission) => INSUFFICIENT_PERMISSION ==\n";
$res = verifier(fakeHttp(['GET ' => ['http' => 403, 'body' => '{"error":{"message":"permission"}}']]))->verifyCredential();
check($res->status() === CredentialStatus::INSUFFICIENT_PERMISSION, 'status=INSUFFICIENT_PERMISSION');

echo "== Gemini direct verification: HTTP 403 region => REGION_RESTRICTED ==\n";
$res = verifier(fakeHttp(['GET ' => ['http' => 403, 'body' => '{"error":{"message":"region not supported"}}']]))->verifyCredential();
check($res->status() === CredentialStatus::REGION_RESTRICTED, 'status=REGION_RESTRICTED');

echo "== Gemini direct verification: HTTP 429 => RATE_LIMITED ==\n";
$res = verifier(fakeHttp(['GET ' => ['http' => 429, 'body' => '{"error":{"message":"rate"}}']]))->verifyCredential();
check($res->status() === CredentialStatus::RATE_LIMITED, 'status=RATE_LIMITED');
check($res->retryable() === true, 'retryable=true');

echo "== Gemini direct verification: HTTP 429 quota => QUOTA_EXCEEDED ==\n";
$res = verifier(fakeHttp(['GET ' => ['http' => 429, 'body' => '{"error":{"message":"quota exceeded"}}']]))->verifyCredential();
check($res->status() === CredentialStatus::QUOTA_EXCEEDED, 'status=QUOTA_EXCEEDED');

echo "== Gemini direct verification: HTTP 5xx => PROVIDER_UNAVAILABLE ==\n";
$res = verifier(fakeHttp(['GET ' => ['http' => 503, 'body' => '{}']]))->verifyCredential();
check($res->status() === CredentialStatus::PROVIDER_UNAVAILABLE, 'status=PROVIDER_UNAVAILABLE');
check($res->retryable() === true, 'retryable=true');

echo "== Gemini direct verification: timeout => NETWORK_ERROR ==\n";
$res = verifier(fakeHttp(['GET ' => ['http' => 0, 'error' => CURLE_OPERATION_TIMEDOUT]]))->verifyCredential();
check($res->status() === CredentialStatus::NETWORK_ERROR, 'status=NETWORK_ERROR');
check($res->retryable() === true, 'retryable=true');

echo "== Gemini direct verification: empty key => INVALID_CREDENTIAL ==\n";
$res = verifier(fakeHttp(['GET ' => ['http' => 200]]), 'direct', '')->verifyCredential();
check($res->status() === CredentialStatus::INVALID_CREDENTIAL, 'status=INVALID_CREDENTIAL (missing)');

echo "== Connection test on direct keeps reachable=true for HTTP 401 ==\n";
$res = verifier(fakeHttp(['GET ' => ['http' => 401, 'body' => '{}']]))->testConnection();
check($res->reachable() === true, 'reachable=true (endpoint was reachable, auth failed)');
check($res->verified() === false, 'verified=false');

echo "== Relay: verifyCredential must NOT claim VALID ==\n";
$res = verifier(fakeHttp(['GET ' => ['http' => 200, 'body' => '{}']]), 'n8n_relay')->verifyCredential();
check($res->status() === CredentialStatus::UNKNOWN, 'status=UNKNOWN (relay upstream key not verifiable from Velora)');
check($res->verified() === false, 'verified=false');
check($res->source() === 'relay', 'source=relay');
check(!$res->isEligibleForActivation(), 'NOT eligible (relay does not prove Gemini credential validity)');

echo "== Relay: connection test reachable but NEVER credential-valid ==\n";
$res = verifier(fakeHttp(['HEAD ' => ['http' => 200, 'body' => '']]), 'n8n_relay')->testConnection();
check($res->reachable() === true, 'reachable=true (relay responded)');
check($res->verified() === false, 'verified=false');
check($res->status() === CredentialStatus::UNKNOWN, 'status=UNKNOWN (RELAY_REACHABLE != GEMINI_CREDENTIAL_VALID)');
check($res->code() === 'RELAY_REACHABLE', 'code=RELAY_REACHABLE');

echo "== Relay: unavailable => PROVIDER_UNAVAILABLE ==\n";
$res = verifier(fakeHttp(['HEAD ' => ['http' => 0, 'error' => 7]]), 'n8n_relay')->testConnection();
check($res->status() === CredentialStatus::PROVIDER_UNAVAILABLE, 'status=PROVIDER_UNAVAILABLE (unreachable)');
check($res->reachable() === false, 'reachable=false');

echo "== Capability map is explicit, not fabricated ==\n";
$cap = verifier(fakeHttp([]))->capabilities();
check($cap['validate_credentials'] === true, 'validate_credentials supported');
check($cap['connection_test'] === true, 'connection_test supported');
check($cap['get_billing'] === false, 'get_billing explicitly unavailable (never fabricated)');

echo "== CredentialFingerprint non-reversible & no secret ==\n";
$fp = CredentialFingerprint::of(SECRET, 'unit-test-mac-key');
check(str_starts_with((string) $fp, 'hmac:'), 'fingerprint is HMAC-prefixed');
check(!str_contains((string) $fp, SECRET), 'fingerprint contains no secret');
check(CredentialFingerprint::of(SECRET, 'unit-test-mac-key') === $fp, 'fingerprint is deterministic');
check((string) CredentialFingerprint::of('', 'k') === '', 'empty value -> empty fingerprint');

echo "\nprovider-verification: " . ($failures === 0 ? 'PASS' : 'FAIL') . " ($checks checks, $failures failures)\n";
exit($failures === 0 ? 0 : 1);
