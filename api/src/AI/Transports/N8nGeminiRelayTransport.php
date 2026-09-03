<?php

declare(strict_types=1);

namespace Velora\AI\Transports;

use Velora\AI\Exceptions\AIProviderException;
use Velora\AI\Exceptions\AIQuotaExhaustedException;
use Velora\AI\Exceptions\AITimeoutException;
use Velora\AI\Exceptions\AIValidationException;
use Velora\Core\Config;

/**
 * n8n Cloud relay transport for Gemini (temporary, region-block workaround).
 *
 * Velora staging cannot reach generativelanguage.googleapis.com directly
 * (Google frontend returns 403 for the host's network). The active n8n
 * workflow "VELORA — Gemini Vision Extraction Relay" forwards requests to
 * Gemini from n8n Cloud. This transport keeps that hop swappable: set
 * GEMINI_ROUTE=direct (or remove the route) and Velora talks to Gemini
 * directly again — no code change, no deploy.
 *
 * Relay contract (POST {GEMINI_RELAY_URL}):
 *   headers: X-Velora-Relay-Token: <GEMINI_RELAY_TOKEN>, Content-Type: application/json
 *   body:    {request_id, image_base64, mime_type, prompt}
 *   200:     {success:true, request_id, provider, model, extraction:{...}, error:null, meta:{...}}
 *   4xx/5xx: {success:false, ..., error:{code, http_status, message}}
 *
 * Security: the token lives ONLY in env and the request header. It is never
 * placed in URLs, logs, exception messages or API responses.
 */
final class N8nGeminiRelayTransport implements GeminiTransportInterface
{
    public function __construct(
        ?string $url = null,
        ?string $token = null
    ) {
        // Runtime resolution: Admin-managed encrypted secret > process ENV >
        // private velora.env > unavailable (RelayConfigResolver). This is what
        // makes a relay config saved from the Admin Panel effective without a
        // file edit, while preserving env fallback for existing deployments.
        $this->url = trim($url ?? \Velora\Admin\RelayConfigResolver::url());
        $this->token = trim($token ?? \Velora\Admin\RelayConfigResolver::token());
    }

    private string $url;
    private string $token;

    public function getName(): string
    {
        return 'n8n_relay';
    }

    public function isConfigured(): bool
    {
        return $this->url !== '' && $this->token !== '' && str_starts_with($this->url, 'https://');
    }

    /**
     * Relay request payload per the relay contract (public for tests).
     *
     * @return array<string,string>
     */
    public static function buildPayload(string $prompt, ?string $imageRaw, ?string $mime): array
    {
        $payload = [
            'request_id' => 'velora-' . bin2hex(random_bytes(8)),
            'prompt' => $prompt,
        ];
        if ($imageRaw !== null && $imageRaw !== '') {
            $payload['image_base64'] = base64_encode($imageRaw);
            $payload['mime_type'] = $mime ?? 'image/png';
        }
        return $payload;
    }

    /**
     * Map a normalized relay error object onto Velora's AI exception model
     * (public for tests). Only the normalized code is used — never upstream
     * message bodies, never secrets.
     */
    public static function mapRelayError(array $err): AIException
    {
        $code = is_string($err['code'] ?? null) ? (string) $err['code'] : 'UNKNOWN';
        $upstreamHttp = (int) ($err['http_status'] ?? 0);

        switch ($code) {
            case 'INVALID_INPUT':
            case 'UPSTREAM_BAD_REQUEST':
                return new AIValidationException('Gemini (via relay) rejected request.', ['provider' => ['code' => $code === 'INVALID_INPUT' ? 'RELAY_INVALID_INPUT' : 'BAD_REQUEST']]);
            case 'UPSTREAM_AUTH':
                return new AIProviderException('Upstream Gemini authentication failed.', 'gemini');
            case 'UPSTREAM_MODEL_NOT_FOUND':
            case 'MODEL_NOT_FOUND':
                return new AIProviderException('Upstream Gemini model not found.', 'gemini');
            case 'UPSTREAM_QUOTA_EXHAUSTED':
            case 'QUOTA_EXHAUSTED':
                return new AIQuotaExhaustedException('Upstream Gemini quota exhausted.', 'gemini');
            case 'UPSTREAM_UNAVAILABLE':
            case 'UNAVAILABLE':
                return new AIProviderException('Upstream Gemini unavailable (HTTP ' . $upstreamHttp . ').', 'gemini');
            case 'UPSTREAM_NETWORK_TIMEOUT':
            case 'NETWORK_TIMEOUT':
                return new AITimeoutException('Upstream Gemini timed out.', 'gemini');
            case 'UPSTREAM_MALFORMED_RESPONSE':
            case 'MALFORMED':
                return new AIValidationException('Upstream Gemini malformed response.', ['provider' => ['code' => 'MALFORMED']]);
            case 'UPSTREAM_INVALID_JSON':
            case 'INVALID_JSON':
                return new AIValidationException('Upstream Gemini returned invalid JSON.', ['provider' => ['code' => 'INVALID_JSON']]);
            default:
                // Message carries only the normalized code — never upstream bodies or secrets.
                return new AIProviderException('Gemini relay error: ' . $code, 'gemini');
        }
    }

        public function generateContent(
        string $prompt,
        ?string $imageRaw,
        ?string $mime,
        array $options,
        int $timeout
    ): array {
        if (!$this->isConfigured()) {
            throw new AIProviderException('n8n relay route selected but GEMINI_RELAY_URL/GEMINI_RELAY_TOKEN are not configured.', 'gemini');
        }

        $payload = self::buildPayload($prompt, $imageRaw, $mime);

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($jsonPayload === false) {
            throw new AIProviderException('Failed to encode relay request.', 'gemini');
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new AIProviderException('Failed to init cURL.', 'gemini');
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $this->url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max(2, $timeout),
            CURLOPT_CONNECTTIMEOUT => min(10, max(2, $timeout)),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Velora-Relay-Token: ' . $this->token,
            ],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $started = microtime(true);
        $response = curl_exec($ch);
        $curlErrNo = curl_errno($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $latencyMs = (int) ((microtime(true) - $started) * 1000);

        if ($curlErrNo === 28 || $curlErrNo === CURLE_OPERATION_TIMEDOUT) {
            throw new AITimeoutException('Gemini relay request timed out.', 'gemini');
        }
        if ($curlErrNo !== 0) {
            throw new AIProviderException('Gemini relay communication failed.', 'gemini');
        }

        // Relay-level auth failures (n8n platform rejects the header).
        if ($httpCode === 401 || $httpCode === 403) {
            throw new AIProviderException('Gemini relay rejected credentials.', 'gemini');
        }
        if ($httpCode === 404) {
            throw new AIProviderException('Gemini relay endpoint not found.', 'gemini');
        }
        if ($httpCode !== 200) {
            throw new AIProviderException('Gemini relay HTTP ' . $httpCode, 'gemini');
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new AIValidationException('Invalid JSON from Gemini relay.', ['provider' => ['code' => 'INVALID_JSON']]);
        }

        if (($decoded['success'] ?? null) === true) {
            $extraction = $decoded['extraction'] ?? null;
            $text = is_array($extraction) ? (string) json_encode($extraction, JSON_UNESCAPED_SLASHES) : (string) $extraction;
            return [
                'text' => $text !== '' ? $text : null,
                'model' => is_string($decoded['model'] ?? null) ? (string) $decoded['model'] : 'gemini-3.6-flash',
                'http_code' => (int) ($decoded['meta']['upstream_http_status'] ?? 200),
                'latency_ms' => (int) ($decoded['meta']['latency_ms'] ?? $latencyMs),
                'raw' => $decoded,
            ];
        }

        // Normalized relay failure — map onto Velora's error model.
        $err = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        throw self::mapRelayError($err);
    }
}
