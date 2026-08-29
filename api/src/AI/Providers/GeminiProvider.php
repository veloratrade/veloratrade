<?php

declare(strict_types=1);

namespace Velora\AI\Providers;

use Velora\AI\DTOs\AIResponseDTO;
use Velora\AI\Exceptions\AIProviderException;
use Velora\AI\Exceptions\AIQuotaExhaustedException;
use Velora\AI\Exceptions\AITimeoutException;
use Velora\AI\Exceptions\AIValidationException;
use Velora\AI\Extraction\ExtractedTradeData;
use Velora\AI\Prompts\PromptManager;
use Velora\Core\Config;

/**
 * Gemini Vision provider — generic + extraction.
 * Uses Config::env() for secrets, never hardcodes.
 * Supports both generate() (generic) and extract() (backward compat).
 * Image optimization is handled in Extraction layer (ImageProcessor), not here.
 * Prompt source is ONLY via PromptManager.
 */
final class GeminiProvider implements AIProviderInterface
{
    private const DEFAULT_MODEL = 'gemini-3.6-flash';
    private const DEFAULT_TIMEOUT = 8;
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    private string $apiKey;
    private string $model;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey = trim(Config::env('GEMINI_API_KEY', ''));
        $this->model = trim(Config::env('GEMINI_MODEL', self::DEFAULT_MODEL));
        if ($this->model === '') {
            $this->model = self::DEFAULT_MODEL;
        }
        $this->timeout = max(2, min(30, (int) Config::env('GEMINI_TIMEOUT', (string) self::DEFAULT_TIMEOUT)));
    }

    public function getName(): string
    {
        return 'gemini';
    }

    public function getCapabilities(): array
    {
        return ['vision', 'text', 'extraction', 'analysis', 'chat'];
    }

    public function getCostTier(): int
    {
        return 0;
    }

    public function isAvailable(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Generic generate — primary method for all AI features.
     *
     * @param string $prompt
     * @param array<string,mixed> $context e.g. ['imageRaw' => ..., 'trades' => [...]]
     * @param array<string,mixed> $options e.g. ['deadline' => float, 'model' => string, 'timeout' => int]
     */
    public function generate(string $prompt, array $context = [], array $options = []): AIResponseDTO
    {
        if (!$this->isAvailable()) {
            throw new AIQuotaExhaustedException('Gemini API key not configured.', $this->getName());
        }

        $deadline = $options['deadline'] ?? (microtime(true) + $this->timeout);
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0.5) {
            throw new AITimeoutException('Deadline exceeded before Gemini call.', $this->getName());
        }

        $timeout = (int) min($options['timeout'] ?? $this->timeout, $remaining - 0.2);
        if ($timeout < 1) {
            $timeout = 1;
        }

        $model = $options['model'] ?? $this->model;
        // API key is sent via the x-goog-api-key header, never in the URL —
        // query-string keys leak into proxy/CDN/access logs.
        $url = self::API_BASE . urlencode($model) . ':generateContent';

        if (!str_starts_with($url, 'https://')) {
            throw new AIProviderException('Invalid Gemini API URL.', $this->getName());
        }

        // Build parts — image already optimized in Extraction layer
        $parts = [['text' => $prompt]];

        // Vision: if imageRaw in context, add inline_data (already optimized)
        if (isset($context['imageRaw']) && is_string($context['imageRaw']) && $context['imageRaw'] !== '') {
            $imageRaw = $context['imageRaw'];
            $mime = $this->detectMime($imageRaw);
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $mime,
                    'data' => base64_encode($imageRaw),
                ],
            ];
        }

        // Text analysis: if trades in context, append as JSON inside a data
        // envelope so the model treats it as DATA, never as instructions.
        if (isset($context['trades']) && is_array($context['trades'])) {
            $tradesJson = json_encode($context['trades'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $parts[0]['text'] .= "\n\n<velora_data>\n" . $tradesJson . "\n</velora_data>\n";
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

        // For extraction, request JSON mime type
        if (($options['responseMimeType'] ?? '') === 'application/json' || ($context['feature'] ?? '') === 'extraction') {
            $payload['generationConfig']['responseMimeType'] = 'application/json';
        }

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($jsonPayload === false) {
            throw new AIProviderException('Failed to encode Gemini request.', $this->getName());
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new AIProviderException('Failed to init cURL.', $this->getName());
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
            throw new AITimeoutException('Gemini request timed out.', $this->getName());
        }
        if ($curlErrNo !== 0) {
            throw new AIProviderException('Gemini provider communication failed.', $this->getName());
        }

        // Classify provider errors distinctly: credential, quota/rate, service, validation.
        if ($httpCode === 429) {
            throw new AIQuotaExhaustedException('Gemini rate limit or quota exhausted.', $this->getName());
        }
        if ($httpCode === 401) {
            throw new AIProviderException('Gemini API key invalid or unauthorized.', $this->getName());
        }
        if ($httpCode === 403) {
            $body = is_string($response) ? $response : '';
            if (stripos($body, 'quota') !== false || stripos($body, 'rate') !== false) {
                throw new AIQuotaExhaustedException('Gemini quota exhausted.', $this->getName());
            }
            throw new AIProviderException('Gemini API key lacks required permission.', $this->getName());
        }
        if ($httpCode >= 500) {
            throw new AIProviderException('Gemini service unavailable (HTTP ' . $httpCode . ').', $this->getName());
        }
        if ($httpCode === 400) {
            throw new AIValidationException('Gemini rejected request.', ['provider' => ['code' => 'BAD_REQUEST']]);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new AIProviderException('Gemini HTTP ' . $httpCode, $this->getName());
        }

        if (!is_string($response) || $response === '') {
            throw new AIProviderException('Empty response from Gemini.', $this->getName());
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new AIValidationException('Invalid JSON from Gemini.', ['response' => ['code' => 'INVALID_JSON']]);
        }

        $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!is_string($text) || $text === '') {
            throw new AIValidationException('Gemini missing text.', ['response' => ['code' => 'MISSING_TEXT']]);
        }

        // Estimate tokens (rough: 4 chars = 1 token)
        $tokensUsed = (int) (strlen($prompt) / 4 + strlen($text) / 4);

        return new AIResponseDTO(
            content: $text,
            provider: $this->getName(),
            model: $model,
            latencyMs: $latencyMs,
            tokensUsed: $tokensUsed,
            confidence: 0.85,
            status: 'success',
            rawResponse: $decoded,
            metadata: ['http_code' => $httpCode],
        );
    }

    /**
     * Backward compatible extraction — uses generate() internally.
     * Prompt source ONLY via PromptManager per hardening requirement.
     */
    public function extract(string $imageRaw, float $deadline): ExtractedTradeData
    {
        $prompt = PromptManager::get('screenshot_extraction', 'v1', 'en');

        $response = $this->generate($prompt, [
            'imageRaw' => $imageRaw,
            'feature' => 'extraction',
        ], [
            'deadline' => $deadline,
            'responseMimeType' => 'application/json',
        ]);

        $text = $response->content;
        $extractedJson = json_decode($text, true);
        if (!is_array($extractedJson)) {
            if (preg_match('/\{.*\}/s', $text, $m)) {
                $extractedJson = json_decode($m[0], true);
            }
        }

        if (!is_array($extractedJson)) {
            throw new AIValidationException('Gemini malformed extraction JSON.', ['json' => ['code' => 'MALFORMED']]);
        }

        $confidence = isset($extractedJson['confidence']) ? (float) $extractedJson['confidence'] : 0.85;
        $confidence = max(0.0, min(1.0, $confidence));

        return ExtractedTradeData::fromArray(
            $extractedJson,
            $this->getName(),
            $confidence,
            $text,
            $response->rawResponse,
        );
    }

    private function detectMime(string $raw): string
    {
        $info = @getimagesizefromstring($raw);
        if ($info === false) {
            return 'image/png';
        }
        return match ($info[2] ?? 0) {
            IMAGETYPE_JPEG => 'image/jpeg',
            IMAGETYPE_PNG => 'image/png',
            IMAGETYPE_WEBP => 'image/webp',
            default => 'image/png',
        };
    }
}
