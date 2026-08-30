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
 * OpenAI provider adapter (Chat Completions, vision via image_url data URLs).
 *
 * Same contract as GeminiProvider: generate() is primary, extract() wraps it.
 * Secrets stay in env (OPENAI_API_KEY) — never hardcoded, never logged.
 * No retry loops; a single request per call. Failures map onto the typed AI
 * exception model so AIFailureClassifier can route fallback decisions.
 */
final class OpenAIProvider implements AIProviderInterface
{
    public const DEFAULT_MODEL = 'gpt-5-mini';
    private const API_URL = 'https://api.openai.com/v1/chat/completions';
    private const DEFAULT_TIMEOUT = 20;

    private string $apiKey;
    private string $model;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey = trim(Config::env('OPENAI_API_KEY', ''));
        $this->model = trim(Config::env('OPENAI_MODEL', self::DEFAULT_MODEL));
        if ($this->model === '') {
            $this->model = self::DEFAULT_MODEL;
        }
        $this->timeout = max(2, min(45, (int) Config::env('OPENAI_TIMEOUT', (string) self::DEFAULT_TIMEOUT)));
    }

    public function getName(): string
    {
        return 'openai';
    }

    /** @return string[] */
    public function getCapabilities(): array
    {
        return ['vision', 'text', 'extraction'];
    }

    public function getCostTier(): int
    {
        return 2;
    }

    public function isAvailable(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Request payload per the OpenAI Chat Completions contract (public for
     * tests — contains no secrets).
     *
     * @return array<string,mixed>
     */
    public static function buildPayload(string $prompt, ?string $imageRaw, ?string $mime, string $model, array $options = []): array
    {
        $content = [['type' => 'text', 'text' => $prompt]];
        if ($imageRaw !== null && $imageRaw !== '') {
            $content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => 'data:' . ($mime ?? 'image/png') . ';base64,' . base64_encode($imageRaw)],
            ];
        }
        $payload = [
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $content]],
            'temperature' => (float) ($options['temperature'] ?? 0.1),
            'max_tokens' => (int) ($options['maxOutputTokens'] ?? 1024),
        ];
        if (($options['responseMimeType'] ?? '') === 'application/json') {
            $payload['response_format'] = ['type' => 'json_object'];
        }
        return $payload;
    }

    /**
     * Map an HTTP status + decoded error shape onto the typed exception model
     * (public for tests; never carries secrets).
     */
    public static function mapError(int $httpCode, ?string $upstreamCode): \Velora\AI\Exceptions\AIException
    {
        return match (true) {
            $httpCode === 401 || $httpCode === 403 => new AIProviderException('OpenAI authentication failed.', 'openai'),
            $httpCode === 429 => new AIQuotaExhaustedException('OpenAI rate limit / quota exhausted.', 'openai'),
            $httpCode === 408 => new AITimeoutException('OpenAI request timeout.', 'openai'),
            $httpCode >= 500 => new AIProviderException('OpenAI unavailable (HTTP ' . $httpCode . ').', 'openai'),
            $httpCode === 400 && ($upstreamCode === 'invalid_api_key' || $upstreamCode === 'model_not_found') =>
                new AIProviderException('OpenAI rejected model/key configuration.', 'openai'),
            default => new AIValidationException('OpenAI rejected request.', ['provider' => ['code' => 'INVALID_INPUT']]),
        };
    }

    public function generate(string $prompt, array $context = [], array $options = []): AIResponseDTO
    {
        if (!$this->isAvailable()) {
            throw new AIQuotaExhaustedException('OpenAI API key not configured.', $this->getName());
        }

        $deadline = $options['deadline'] ?? (microtime(true) + $this->timeout);
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0.5) {
            throw new AITimeoutException('Deadline exceeded before OpenAI call.', $this->getName());
        }

        $model = is_string($options['model'] ?? null) && $options['model'] !== '' ? $options['model'] : $this->model;
        $timeout = (int) min($options['timeout'] ?? $this->timeout, $remaining - 0.2);
        if ($timeout < 1) {
            $timeout = 1;
        }

        $imageRaw = null;
        $mime = null;
        if (isset($context['imageRaw']) && is_string($context['imageRaw']) && $context['imageRaw'] !== '') {
            $imageRaw = $context['imageRaw'];
            $mime = $this->detectMime($imageRaw);
        }

        $payload = self::buildPayload($prompt, $imageRaw, $mime, $model, [
            'temperature' => $options['temperature'] ?? 0.1,
            'maxOutputTokens' => $options['maxOutputTokens'] ?? 1024,
            'responseMimeType' => $options['responseMimeType'] ?? '',
        ]);

        $result = $this->post($payload, $timeout);
        $text = $result['text'];

        $tokensUsed = (int) ($result['raw']['usage']['total_tokens'] ?? 0);
        if ($tokensUsed === 0) {
            $tokensUsed = (int) (strlen($prompt) / 4 + strlen((string) $text) / 4);
        }

        return new AIResponseDTO(
            content: (string) $text,
            provider: $this->getName(),
            model: $model,
            latencyMs: (int) ($result['latency_ms'] ?? 0),
            tokensUsed: $tokensUsed,
            confidence: 0.85,
            status: 'success',
            rawResponse: is_array($result['raw']) ? $result['raw'] : [],
            metadata: ['http_code' => (int) ($result['http_code'] ?? 0), 'route' => 'direct'],
        );
    }

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
            throw new AIValidationException('OpenAI malformed extraction JSON.', ['json' => ['code' => 'MALFORMED']]);
        }

        $confidence = isset($extractedJson['confidence']) ? (float) $extractedJson['confidence'] : 0.85;
        $confidence = max(0.0, min(1.0, $confidence));

        return ExtractedTradeData::fromArray($extractedJson, $this->getName(), $confidence, $text, $response->rawResponse);
    }

    /**
     * Single POST; no retries. Returns normalized ['text','raw','latency_ms','http_code'].
     *
     * @return array<string,mixed>
     */
    private function post(array $payload, int $timeout): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey, // header only — never logged
            ],
        ]);
        $start = microtime(true);
        $rawBody = curl_exec($ch);
        $latency = (int) ((microtime(true) - $start) * 1000);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($rawBody === false) {
            if (stripos($curlError, 'timed out') !== false || stripos($curlError, 'timeout') !== false) {
                throw new AITimeoutException('OpenAI network timeout.', 'openai');
            }
            throw new AIProviderException('OpenAI network failure.', 'openai');
        }

        $decoded = json_decode((string) $rawBody, true);
        if (!is_array($decoded)) {
            throw new AIValidationException('OpenAI invalid JSON response.', ['provider' => ['code' => 'INVALID_JSON']]);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $upstreamCode = is_string($decoded['error']['code'] ?? null) ? $decoded['error']['code'] : null;
            throw self::mapError($httpCode, $upstreamCode);
        }

        $text = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($text) || $text === '') {
            throw new AIValidationException('OpenAI missing text.', ['response' => ['code' => 'MISSING_TEXT']]);
        }

        return ['text' => $text, 'raw' => $decoded, 'latency_ms' => $latency, 'http_code' => $httpCode];
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
