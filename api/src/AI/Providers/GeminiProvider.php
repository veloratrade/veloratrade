<?php

declare(strict_types=1);

namespace Velora\AI\Providers;

use Velora\AI\DTOs\AIResponseDTO;
use Velora\AI\Exceptions\AIException;
use Velora\AI\Exceptions\AIProviderException;
use Velora\AI\Exceptions\AIQuotaExhaustedException;
use Velora\AI\Exceptions\AITimeoutException;
use Velora\AI\Exceptions\AIValidationException;
use Velora\AI\Extraction\ExtractedTradeData;
use Velora\AI\Prompts\PromptManager;
use Velora\AI\Repositories\AIFeatureFlagRepository;
use Velora\AI\Transports\DirectGeminiTransport;
use Velora\AI\Transports\GeminiTransportInterface;
use Velora\AI\Transports\N8nGeminiRelayTransport;
use Velora\Core\Config;

/**
 * Gemini Vision provider — generic + extraction.
 *
 * Transport is swappable (temporary n8n relay while the API host sits in a
 * region Google's frontend blocks; direct HTTPS afterwards):
 *   GEMINI_ROUTE=direct|n8n_relay   (explicit env wins)
 *   flag ai_gemini_relay_route      (admin-style DB switch, fallback)
 *   default: direct
 * The relay is used for extraction-shaped calls only; everything above this
 * class is unaware of the route. Business logic stays transport-agnostic.
 *
 * Secrets stay in env (Config::env), never hardcoded, never logged.
 * Prompt source is ONLY via PromptManager.
 * Image optimization is handled in Extraction layer (ImageProcessor), not here;
 * transports receive already-processed raw image bytes.
 */
final class GeminiProvider implements AIProviderInterface
{
    public const DEFAULT_MODEL = 'gemini-3.6-flash';
    private const DEFAULT_TIMEOUT = 8;
    private const DEFAULT_RELAY_TIMEOUT = 45;

    private string $apiKey;
    private string $model;
    private int $timeout;
    private int $relayTimeout;
    /** @var array<string,GeminiTransportInterface> */
    private array $transportCache = [];

    public function __construct()
    {
        $this->apiKey = trim(Config::env('GEMINI_API_KEY', ''));
        $this->model = trim(Config::env('GEMINI_MODEL', self::DEFAULT_MODEL));
        if ($this->model === '') {
            $this->model = self::DEFAULT_MODEL;
        }
        $this->timeout = max(2, min(30, (int) Config::env('GEMINI_TIMEOUT', (string) self::DEFAULT_TIMEOUT)));
        // Relay adds an n8n Cloud hop; measured p50 ~2-6s, tail higher.
        $this->relayTimeout = max(5, min(90, (int) Config::env('GEMINI_RELAY_TIMEOUT', (string) self::DEFAULT_RELAY_TIMEOUT)));
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

    /**
     * Effective route: explicit env first, then admin flag, then direct.
     * Also exposed for tests/diagnostics (no secrets involved).
     */
    public function getRoute(?bool $extractionCall = null): string
    {
        $env = strtolower(trim(Config::env('GEMINI_ROUTE', '')));
        if ($env === 'n8n_relay' || $env === 'direct') {
            return $env;
        }
        try {
            if ((new AIFeatureFlagRepository())->isEnabled('ai_gemini_relay_route')) {
                return 'n8n_relay';
            }
        } catch (\Throwable $e) {
            // Flag table unavailable — stay on direct.
        }
        return 'direct';
    }

    private function transport(string $route): GeminiTransportInterface
    {
        if (!isset($this->transportCache[$route])) {
            $this->transportCache[$route] = $route === 'n8n_relay'
                ? new N8nGeminiRelayTransport()
                : new DirectGeminiTransport();
        }
        return $this->transportCache[$route];
    }

    public function isAvailable(): bool
    {
        $route = $this->getRoute();
        if ($route === 'n8n_relay') {
            return $this->transport('n8n_relay')->isConfigured();
        }
        return $this->apiKey !== '';
    }

    /**
     * Generic generate — primary method for all AI features.
     *
     * @param string $prompt
     * @param array<string,mixed> $context e.g. ['imageRaw' => ..., 'trades' => [...], 'feature' => 'extraction']
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

        // Relay only carries extraction-shaped calls (JSON response contract);
        // free-text analysis stays on direct transport.
        $extractionCall = ($options['responseMimeType'] ?? '') === 'application/json'
            || ($context['feature'] ?? '') === 'extraction';
        // Optional explicit per-feature route override (validated allowlist:
        // direct|n8n_relay). Absent/invalid => legacy resolution below, so the
        // default behavior stays byte-compatible: GEMINI_ROUTE env >
        // ai_gemini_relay_route flag > direct.
        $override = strtolower(trim((string) ($options['route'] ?? '')));
        $route = ($override === 'direct' || $override === 'n8n_relay') ? $override : $this->getRoute();
        $useRelay = $route === 'n8n_relay' && $extractionCall;
        $effectiveRoute = $useRelay ? 'n8n_relay' : 'direct';
        $transport = $this->transport($effectiveRoute);

        $baseTimeout = $useRelay ? $this->relayTimeout : $this->timeout;
        $timeout = (int) min($options['timeout'] ?? $baseTimeout, $remaining - 0.2);
        if ($timeout < 1) {
            $timeout = 1;
        }

        $model = $options['model'] ?? $this->model;

        // Build parts — image already optimized in Extraction layer
        $imageRaw = null;
        $mime = null;
        if (isset($context['imageRaw']) && is_string($context['imageRaw']) && $context['imageRaw'] !== '') {
            $imageRaw = $context['imageRaw'];
            $mime = $this->detectMime($imageRaw);
        }

        // Text analysis: if trades in context, append as JSON inside a data
        // envelope so the model treats it as DATA, never as instructions.
        if (isset($context['trades']) && is_array($context['trades'])) {
            $tradesJson = json_encode($context['trades'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $prompt .= "\n\n<velora_data>\n" . $tradesJson . "\n</velora_data>\n";
        }

        $transportOptions = [
            'model' => $model,
            'temperature' => $options['temperature'] ?? 0.1,
            'maxOutputTokens' => $options['maxOutputTokens'] ?? 1024,
        ];
        // For extraction, request JSON mime type
        if (($options['responseMimeType'] ?? '') === 'application/json' || ($context['feature'] ?? '') === 'extraction') {
            $transportOptions['responseMimeType'] = 'application/json';
        }

        $result = $transport->generateContent($prompt, $imageRaw, $mime, $transportOptions, $timeout);

        $text = $result['text'];
        if (!is_string($text) || $text === '') {
            throw new AIValidationException('Gemini missing text.', ['response' => ['code' => 'MISSING_TEXT']]);
        }

        // Estimate tokens (rough: 4 chars = 1 token)
        $tokensUsed = (int) (strlen($prompt) / 4 + strlen($text) / 4);

        return new AIResponseDTO(
            content: $text,
            provider: $this->getName(),
            model: is_string($result['model'] ?? null) && $result['model'] !== '' ? $result['model'] : $model,
            latencyMs: (int) ($result['latency_ms'] ?? 0),
            tokensUsed: $tokensUsed,
            confidence: 0.85,
            status: 'success',
            rawResponse: is_array($result['raw'] ?? null) ? $result['raw'] : [],
            metadata: ['http_code' => (int) ($result['http_code'] ?? 0), 'route' => $effectiveRoute],
        );
    }

    /**
     * Backward compatible extraction — uses generate() internally.
     * Prompt source ONLY via PromptManager per hardening requirement.
     *
     * The optional $routeOverride (explicitly validated allowlist value from a
     * per-feature chain row: 'direct'|'n8n_relay') is forwarded to generate();
     * null keeps the legacy route resolution unchanged.
     */
    public function extract(string $imageRaw, float $deadline, ?string $routeOverride = null): ExtractedTradeData
    {
        $prompt = PromptManager::get('screenshot_extraction', 'v1', 'en');

        $generateOptions = [
            'deadline' => $deadline,
            'responseMimeType' => 'application/json',
        ];
        if ($routeOverride !== null) {
            $generateOptions['route'] = $routeOverride;
        }

        $response = $this->generate($prompt, [
            'imageRaw' => $imageRaw,
            'feature' => 'extraction',
        ], $generateOptions);

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
