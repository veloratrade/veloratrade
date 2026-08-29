<?php

declare(strict_types=1);

namespace Velora\AI\Transports;

use Velora\AI\Exceptions\AIProviderException;
use Velora\AI\Exceptions\AIQuotaExhaustedException;
use Velora\AI\Exceptions\AITimeoutException;
use Velora\AI\Exceptions\AIValidationException;
use Velora\Core\Config;

/**
 * Direct HTTPS transport to Google Generative Language API.
 * This is the code that lived in GeminiProvider::generate() (v0.4-v0.8),
 * moved verbatim so behavior is identical; it stays the default route.
 *
 * Secrets: GEMINI_API_KEY via Config::env() only, sent via the
 * x-goog-api-key header, never in the URL, never in messages.
 */
final class DirectGeminiTransport implements GeminiTransportInterface
{
    private const DEFAULT_MODEL = 'gemini-3.6-flash';
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    private string $apiKey;
    private string $model;

    public function __construct(?string $apiKey = null, ?string $model = null)
    {
        $this->apiKey = $apiKey ?? trim(Config::env('GEMINI_API_KEY', ''));
        $model = $model ?? trim(Config::env('GEMINI_MODEL', self::DEFAULT_MODEL));
        $this->model = $model === '' ? self::DEFAULT_MODEL : $model;
    }

    public function getName(): string
    {
        return 'direct';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function generateContent(
        string $prompt,
        ?string $imageRaw,
        ?string $mime,
        array $options,
        int $timeout
    ): array {
        if (!$this->isConfigured()) {
            throw new AIProviderException('Direct Gemini transport has no API key configured.', 'gemini');
        }

        $model = (string) ($options['model'] ?? $this->model);
        // API key is sent via the x-goog-api-key header, never in the URL —
        // query-string keys leak into proxy/CDN/access logs.
        $url = self::API_BASE . urlencode($model) . ':generateContent';

        // Build parts — image already optimized in Extraction layer
        $parts = [['text' => $prompt]];
        if ($imageRaw !== null && $imageRaw !== '') {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $mime ?? 'image/png',
                    'data' => base64_encode($imageRaw),
                ],
            ];
        }

        $payload = [
            'contents' => [
                ['parts' => $parts],
            ],
            'generationConfig' => [
                'temperature' => $options['temperature'] ?? 0.1,
                'maxOutputTokens' => $options['maxOutputTokens'] ?? 1024,
            ],
        ];
        if (($options['responseMimeType'] ?? '') === 'application/json') {
            $payload['generationConfig']['responseMimeType'] = 'application/json';
        }

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($jsonPayload === false) {
            throw new AIProviderException('Failed to encode Gemini request.', 'gemini');
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new AIProviderException('Failed to init cURL.', 'gemini');
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'x-goog-api-key: ' . $this->apiKey,
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
            throw new AITimeoutException('Gemini request timed out.', 'gemini');
        }
        if ($curlErrNo !== 0) {
            throw new AIProviderException('Gemini provider communication failed.', 'gemini');
        }

        // Classify provider errors distinctly: credential, quota/rate, service, validation.
        if ($httpCode === 429) {
            throw new AIQuotaExhaustedException('Gemini rate limit or quota exhausted.', 'gemini');
        }
        if ($httpCode === 401) {
            throw new AIProviderException('Gemini API key invalid or unauthorized.', 'gemini');
        }
        if ($httpCode === 403) {
            $body = is_string($response) ? $response : '';
            if (stripos($body, 'quota') !== false || stripos($body, 'rate') !== false) {
                throw new AIQuotaExhaustedException('Gemini quota exhausted.', 'gemini');
            }
            throw new AIProviderException('Gemini API key lacks required permission.', 'gemini');
        }
        if ($httpCode >= 500) {
            throw new AIProviderException('Gemini service unavailable (HTTP ' . $httpCode . ').', 'gemini');
        }
        if ($httpCode === 400) {
            throw new AIValidationException('Gemini rejected request.', ['provider' => ['code' => 'BAD_REQUEST']]);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new AIProviderException('Gemini HTTP ' . $httpCode, 'gemini');
        }

        if (!is_string($response) || $response === '') {
            throw new AIProviderException('Empty response from Gemini.', 'gemini');
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new AIValidationException('Invalid JSON from Gemini.', ['response' => ['code' => 'INVALID_JSON']]);
        }

        $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;

        return [
            'text' => is_string($text) && $text !== '' ? $text : null,
            'model' => is_string($decoded['modelVersion'] ?? null) ? (string) $decoded['modelVersion'] : $model,
            'http_code' => $httpCode,
            'latency_ms' => $latencyMs,
            'raw' => $decoded,
        ];
    }
}
