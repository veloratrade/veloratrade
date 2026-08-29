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
 * Anthropic Claude provider adapter (Messages API, vision via base64 source
 * blocks). Same contract as GeminiProvider. Secrets stay in env
 * (ANTHROPIC_API_KEY). No retry loops; typed exception mapping only.
 */
final class ClaudeProvider implements AIProviderInterface
{
    public const DEFAULT_MODEL = 'claude-sonnet-4-5';
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const DEFAULT_TIMEOUT = 20;

    private string $apiKey;
    private string $model;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey = trim(Config::env('ANTHROPIC_API_KEY', ''));
        $this->model = trim(Config::env('ANTHROPIC_MODEL', self::DEFAULT_MODEL));
        if ($this->model === '') {
            $this->model = self::DEFAULT_MODEL;
        }
        $this->timeout = max(2, min(45, (int) Config::env('ANTHROPIC_TIMEOUT', (string) self::DEFAULT_TIMEOUT)));
    }

    public function getName(): string
    {
        return 'claude';
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
     * Request payload per the Anthropic Messages API contract (public for
     * tests — contains no secrets).
     *
     * @return array<string,mixed>
     */
    public static function buildPayload(string $prompt, ?string $imageRaw, ?string $mime, string $model, array $options = []): array
    {
        $content = [];
        if ($imageRaw !== null && $imageRaw !== '') {
            $content[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $mime ?? 'image/png',
                    'data' => base64_encode($imageRaw),
                ],
            ];
        }
        $content[] = ['type' => 'text', 'text' => $prompt];
        return [
            'model' => $model,
            'max_tokens' => (int) ($options['maxOutputTokens'] ?? 1024),
            'temperature' => (float) ($options['temperature'] ?? 0.1),
            'messages' => [['role' => 'user', 'content' => $content]],
        ];
    }

    /**
     * Map an HTTP status onto the typed exception model (public for tests).
     */
    public static function mapError(int $httpCode, ?string $upstreamType): \Velora\AI\Exceptions\AIException
    {
        return match (true) {
            $httpCode === 401 || $httpCode === 403 => new AIProviderException('Claude authentication failed.', 'claude'),
            $httpCode === 429 => new AIQuotaExhaustedException('Claude rate limit / quota exhausted.', 'claude'),
            $httpCode === 408 || $httpCode === 504 => new AITimeoutException('Claude request timeout.', 'claude'),
            $httpCode >= 500 => new AIProviderException('Claude unavailable (HTTP ' . $httpCode . ').', 'claude'),
            $httpCode === 400 && ($upstreamType === 'invalid_request_error' || $upstreamType === 'not_found_error') =>
                new AIProviderException('Claude rejected request/model configuration.', 'claude'),
            default => new AIValidationException('Claude rejected request.', ['provider' => ['code' => 'INVALID_INPUT']]),
        };
    }

    public function generate(string $prompt, array $context = [], array $options = []): AIResponseDTO
    {
        if (!$this->isAvailable()) {
            throw new AIQuotaExhaustedException('Anthropic API key not configured.', $this->getName());
        }

        $deadline = $options['deadline'] ?? (microtime(true) + $this->timeout);
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0.5) {
            throw new AITimeoutException('Deadline exceeded before Claude call.', $this->getName());
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
        ]);

        $result = $this->post($payload, $timeout);
        $text = $result['text'];

        $tokensUsed = (int) (($result['raw']['usage']['input_tokens'] ?? 0) + ($result['raw']['usage']['output_tokens'] ?? 0));
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
            throw new AIValidationException('Claude malformed extraction JSON.', ['json' => ['code' => 'MALFORMED']]);
        }

        $confidence = isset($extractedJson['confidence']) ? (float) $extractedJson['confidence'] : 0.85;
        $confidence = max(0.0, min(1.0, $confidence));

        return ExtractedTradeData::fromArray($extractedJson, $this->getName(), $confidence, $text, $response->rawResponse);
    }

    /**
     * Single POST; no retries.
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
                'x-api-key: ' . $this->apiKey, // header only — never logged
                'anthropic-version: 2023-06-01',
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
                throw new AITimeoutException('Claude network timeout.', 'claude');
            }
            throw new AIProviderException('Claude network failure.', 'claude');
        }

        $decoded = json_decode((string) $rawBody, true);
        if (!is_array($decoded)) {
            throw new AIValidationException('Claude invalid JSON response.', ['provider' => ['code' => 'INVALID_JSON']]);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $upstreamType = is_string($decoded['error']['type'] ?? null) ? $decoded['error']['type'] : null;
            throw self::mapError($httpCode, $upstreamType);
        }

        $text = $decoded['content'][0]['text'] ?? null;
        if (!is_string($text) || $text === '') {
            throw new AIValidationException('Claude missing text.', ['response' => ['code' => 'MISSING_TEXT']]);
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
