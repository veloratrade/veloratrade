<?php

declare(strict_types=1);

namespace Velora\AI\Providers;

use Velora\Core\Config;

/**
 * Gemini provider verification.
 *
 * Direct route: verifies the GEMINI_API_KEY against the real Google Generative
 * Language API using the LOW-COST, auth-only `models` list endpoint
 * (GET /v1beta/models?pageSize=1 with the key in the `x-goog-api-key` header).
 * It never generates content, so it does not consume generation quota and it
 * never touches user data.
 *
 * Relay route (GEMINI_ROUTE=n8n_relay): Velora does not hold the upstream
 * Gemini key (n8n Cloud does). Therefore:
 *   - verifyCredential() returns UNKNOWN (credential validity is NOT provable
 *     from Velora; it is managed upstream). Never collapsed into VALID.
 *   - testConnection() performs a reachability-only TTL/TCP probe via a HEAD
 *     request (no body, no generation) and reports RELAY_REACHABLE as a
 *     *connection* result — explicitly NOT Gemini credential validity.
 *
 * The HTTP layer is injectable for deterministic unit tests; the default is a
 * curl-based client. No credential value is ever logged or returned.
 */
final class GeminiCredentialVerifier implements ProviderVerifierInterface
{
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/';
    private const DEFAULT_TIMEOUT = 8;

    private string $apiKey;
    private string $relayUrl;
    private string $relayToken;
    private int $timeout;
    private ?string $routeOverride;
    /** @var callable|null */
    private $http;

    /**
     * @param callable|null $http (string $method, string $url, array $headers): array
     *                            returns ['http'=>int,'body'=>string,'error'=>?int,'latency_ms'=>int]
     */
    public function __construct(
        ?string $apiKey = null,
        ?string $routeOverride = null,
        ?callable $http = null,
        ?int $timeout = null,
    ) {
        $this->apiKey = trim($apiKey ?? Config::env('GEMINI_API_KEY', ''));
        // Relay config resolves with phase-A precedence (admin-managed encrypted
        // secret > process ENV > velora.env) so panel-saved values are effective.
        $this->relayUrl = trim(\Velora\Admin\RelayConfigResolver::url());
        $this->relayToken = trim(\Velora\Admin\RelayConfigResolver::token());
        $this->timeout = max(2, min(20, $timeout ?? (int) Config::env('GEMINI_VERIFY_TIMEOUT', (string) self::DEFAULT_TIMEOUT)));
        $this->routeOverride = $routeOverride;
        $this->http = $http;
    }

    public function provider(): string
    {
        return 'gemini';
    }

    /** @return array<string,bool> */
    public function capabilities(): array
    {
        return [
            'validate_credentials' => true,
            'connection_test' => true,
            'health_check' => false,
            'list_models' => false,
            'get_usage' => false,
            'get_quota' => false,
            'get_rate_limits' => false,
            'get_billing' => false,
            'get_account_information' => false,
        ];
    }

    public function verifyCredential(): VerificationResult
    {
        if ($this->effectiveRoute() === 'n8n_relay') {
            // The upstream Gemini credential is held by n8n, not Velora. We must
            // not claim VALID for something we cannot prove.
            return VerificationResult::unknown(
                $this->provider(),
                'relay',
                'RELAY_UPSTREAM_CREDENTIAL_UNVERIFIABLE',
                'Gemini credential is managed upstream via the n8n relay; its validity cannot be verified from Velora.',
            );
        }

        if ($this->apiKey === '') {
            return new VerificationResult(
                $this->provider(),
                CredentialStatus::INVALID_CREDENTIAL,
                false,
                null,
                gmdate('Y-m-d\TH:i:s\Z'),
                0,
                'MISSING_CREDENTIAL',
                'No Gemini API key is configured.',
                false,
                'direct',
            );
        }

        [$status, $verified, $reachable, $latencyMs, $code, $message] = $this->callModelsEndpoint($this->apiKey);

        return new VerificationResult(
            $this->provider(),
            $status,
            $verified,
            $reachable,
            gmdate('Y-m-d\TH:i:s\Z'),
            $latencyMs,
            $code,
            $message,
            $this->isRetryable($status),
            'direct',
        );
    }

    public function testConnection(): VerificationResult
    {
        if ($this->effectiveRoute() === 'n8n_relay') {
            return $this->relayReachability();
        }
        if ($this->apiKey === '') {
            return new VerificationResult(
                $this->provider(),
                CredentialStatus::UNKNOWN,
                false,
                false,
                gmdate('Y-m-d\TH:i:s\Z'),
                0,
                'MISSING_CREDENTIAL',
                'No Gemini API key configured; cannot test connectivity.',
                false,
                'direct',
            );
        }
        [$status, $verified, $reachable, $latencyMs, $code, $message] = $this->callModelsEndpoint($this->apiKey);
        // For a pure connection test we report reachability truthfully: a 401/403
        // still means the endpoint was reachable (auth failed), so reachable=true.
        $effectiveReachable = $status === CredentialStatus::INVALID_CREDENTIAL || $status === CredentialStatus::INSUFFICIENT_PERMISSION
            || $status === CredentialStatus::REGION_RESTRICTED
            ? true
            : $reachable;
        return new VerificationResult(
            $this->provider(),
            $status,
            $verified,
            $effectiveReachable,
            gmdate('Y-m-d\TH:i:s\Z'),
            $latencyMs,
            $code,
            $message,
            $this->isRetryable($status),
            'direct',
        );
    }

    private function effectiveRoute(): string
    {
        if ($this->routeOverride !== null) {
            return $this->routeOverride;
        }
        try {
            return (new GeminiProvider())->getRoute();
        } catch (\Throwable $e) {
            return 'direct';
        }
    }

    /**
     * Issue the low-cost auth-only models list request and classify the result.
     *
     * @return array{0:string,1:bool,2:?bool,3:int,4:?string,5:?string}
     */
    private function callModelsEndpoint(string $key): array
    {
        $url = self::API_BASE . 'models?pageSize=1';
        $headers = ['Accept: application/json', 'x-goog-api-key: ' . $key];
        $resp = $this->http_call('GET', $url, $headers);
        $http = (int) ($resp['http'] ?? 0);
        $latency = (int) ($resp['latency_ms'] ?? 0);
        $error = (int) ($resp['error'] ?? 0);
        $body = is_string($resp['body'] ?? null) ? $resp['body'] : '';

        // Transport failures first.
        if ($error === CURLE_OPERATION_TIMEDOUT) {
            return [CredentialStatus::NETWORK_ERROR, false, false, $latency, 'TIMEOUT', 'Provider request timed out.', ];
        }
        if ($error !== 0) {
            return [CredentialStatus::NETWORK_ERROR, false, false, $latency, 'NETWORK_ERROR', 'Could not reach the provider.', ];
        }
        if ($http === 0) {
            return [CredentialStatus::NETWORK_ERROR, false, false, $latency, 'NETWORK_ERROR', 'No HTTP response received.', ];
        }

        switch ($http) {
            case 200:
                return [CredentialStatus::VALID, true, true, $latency, null, 'OK'];
            case 401:
                return [CredentialStatus::INVALID_CREDENTIAL, false, true, $latency, 'HTTP_401', 'Provider rejected the credential (invalid or unauthorized).', ];
            case 403:
                if ($this->bodyIndicates($body, 'region')) {
                    return [CredentialStatus::REGION_RESTRICTED, false, true, $latency, 'HTTP_403_REGION', 'Provider blocked this request by region or network.', ];
                }
                if ($this->bodyIndicates($body, ['quota', 'quotaexceeded'])) {
                    return [CredentialStatus::QUOTA_EXCEEDED, false, true, $latency, 'HTTP_403_QUOTA', 'Provider quota exhausted.', ];
                }
                return [CredentialStatus::INSUFFICIENT_PERMISSION, false, true, $latency, 'HTTP_403', 'Provider rejected the request due to insufficient permission.', ];
            case 429:
                if ($this->bodyIndicates($body, 'quota')) {
                    return [CredentialStatus::QUOTA_EXCEEDED, false, true, $latency, 'HTTP_429_QUOTA', 'Provider quota exhausted (rate limit).', ];
                }
                return [CredentialStatus::RATE_LIMITED, false, true, $latency, 'HTTP_429', 'Provider rate limit hit.', ];
            case 400:
                // REAL provider behavior (verified against the Gemini API): an
                // invalid key is returned as HTTP 400 with reason API_KEY_INVALID
                // and body "API key not valid" — NOT 401. So we inspect the body
                // and classify a key-invalid signal as INVALID_CREDENTIAL (which
                // is a confirmed-invalid state and is gated at runtime). A 400
                // with no key-invalid signal is a genuine malformed-request error.
                if ($this->bodyIndicates($body, 'API_KEY_INVALID')) {
                    return [CredentialStatus::INVALID_CREDENTIAL, false, true, $latency, 'HTTP_400_API_KEY_INVALID', 'Provider rejected the credential (API key not valid).', ];
                }
                if ($this->bodyIndicates($body, 'API key not valid')) {
                    return [CredentialStatus::INVALID_CREDENTIAL, false, true, $latency, 'HTTP_400_API_KEY_INVALID', 'Provider rejected the credential (API key not valid).', ];
                }
                if ($this->bodyIndicates($body, 'API_KEY_QUOTA')) {
                    return [CredentialStatus::QUOTA_EXCEEDED, false, true, $latency, 'HTTP_400_QUOTA', 'Provider quota exceeded.', ];
                }
                if ($this->bodyIndicates($body, 'API_KEY_SERVICE_BLOCKED')) {
                    return [CredentialStatus::REGION_RESTRICTED, false, true, $latency, 'HTTP_400_REGION', 'Provider blocked this key by region/service.', ];
                }
                return [CredentialStatus::INSUFFICIENT_PERMISSION, false, true, $latency, 'HTTP_400', 'Provider rejected the request as malformed.', ];
            default:
                if ($http >= 500) {
                    return [CredentialStatus::PROVIDER_UNAVAILABLE, false, true, $latency, 'HTTP_' . $http, 'Provider temporarily unavailable (HTTP ' . $http . ').', ];
                }
                return [CredentialStatus::UNKNOWN, false, true, $latency, 'HTTP_' . $http, 'Unexpected provider response (HTTP ' . $http . ').', ];
        }
    }

    /**
     * Reachability-only relay probe via a HEAD request (no body, no generation).
     * Explicitly reports relay connectivity, NEVER Gemini credential validity.
     */
    private function relayReachability(): VerificationResult
    {
        if ($this->relayUrl === '' || $this->relayToken === '' || !str_starts_with($this->relayUrl, 'https://')) {
            return new VerificationResult(
                $this->provider(),
                CredentialStatus::UNKNOWN,
                false,
                null,
                gmdate('Y-m-d\TH:i:s\Z'),
                0,
                'RELAY_NOT_CONFIGURED',
                'Gemini relay is not fully configured.',
                false,
                'relay',
            );
        }
        $headers = ['Accept: application/json', 'X-Velora-Relay-Token: ' . $this->relayToken];
        $resp = $this->http_call('HEAD', $this->relayUrl, $headers);
        $http = (int) ($resp['http'] ?? 0);
        $latency = (int) ($resp['latency_ms'] ?? 0);
        $error = (int) ($resp['error'] ?? 0);

        if ($error === CURLE_OPERATION_TIMEDOUT) {
            return new VerificationResult($this->provider(), CredentialStatus::NETWORK_ERROR, false, false, gmdate('Y-m-d\TH:i:s\Z'), $latency, 'TIMEOUT', 'Relay request timed out.', true, 'relay');
        }
        if ($error !== 0 || $http === 0) {
            return new VerificationResult($this->provider(), CredentialStatus::PROVIDER_UNAVAILABLE, false, false, gmdate('Y-m-d\TH:i:s\Z'), $latency, 'RELAY_UNREACHABLE', 'Relay endpoint not reachable.', true, 'relay');
        }

        // Any HTTP response proves the relay is reachable & responds. This is a
        // CONNECTION result only; it does NOT validate the upstream Gemini key.
        return new VerificationResult(
            $this->provider(),
            CredentialStatus::UNKNOWN,
            false,
            true,
            gmdate('Y-m-d\TH:i:s\Z'),
            $latency,
            'RELAY_REACHABLE',
            'Relay endpoint reachable; upstream Gemini credential validity is not verified from Velora.',
            false,
            'relay',
        );
    }

    private function isRetryable(string $status): bool
    {
        return in_array($status, [
            CredentialStatus::QUOTA_EXCEEDED,
            CredentialStatus::RATE_LIMITED,
            CredentialStatus::PROVIDER_UNAVAILABLE,
            CredentialStatus::NETWORK_ERROR,
            CredentialStatus::REGION_RESTRICTED,
        ], true);
    }

    private function bodyIndicates(string $body, string|array $needles): bool
    {
        $lower = strtolower($body);
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && str_contains($lower, strtolower($needle))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Default curl-based HTTP client; injectable for tests.
     *
     * @param string[] $headers
     * @return array{http:int,body:string,error:int,latency_ms:int}
     */
    private function http_call(string $method, string $url, array $headers): array
    {
        if ($this->http !== null) {
            return (array) ($this->http)($method, $url, $headers);
        }
        $ch = curl_init();
        if ($ch === false) {
            return ['http' => 0, 'body' => '', 'error' => CURLE_FAILED_INIT, 'latency_ms' => 0];
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(3, $this->timeout),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_CUSTOMREQUEST => $method === 'HEAD' ? 'HEAD' : 'GET',
        ]);
        $started = microtime(true);
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [
            'http' => $http,
            'body' => is_string($response) ? $response : '',
            'error' => $errno,
            'latency_ms' => (int) ((microtime(true) - $started) * 1000),
        ];
    }
}
