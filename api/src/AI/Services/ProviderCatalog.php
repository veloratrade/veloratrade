<?php

declare(strict_types=1);

namespace Velora\AI\Services;

use Velora\AI\Providers\ClaudeProvider;
use Velora\AI\Providers\GeminiProvider;
use Velora\AI\Providers\OpenAIProvider;
use Velora\AI\Providers\TesseractProvider;
use Velora\Core\Config;

/**
 * Server-side source of truth for provider definitions and validation allowlists.
 *
 * Everything the Admin AI API accepts from the browser is validated against
 * this catalog — the browser is never trusted. Secrets stay in env only:
 * this class stores env KEY NAMES, never values.
 */
final class ProviderCatalog
{
    /** Feature allowlist (mirrors AIController features + extraction). */
    public const FEATURES = [
        'screenshot_extraction',
        'trade_analysis',
        'weekly_report',
        'assistant',
    ];

    /**
     * Required capability per feature (capability filter for chains).
     * screenshot_extraction => null: the runtime extract() path applies no
     * capability filter (tesseract runs with 'ocr' capability) — the router
     * mirrors the real executable chain instead of a theoretical filter.
     */
    public const FEATURE_CAPABILITY = [
        'screenshot_extraction' => null,
        'trade_analysis' => 'text',
        'weekly_report' => 'text',
        'assistant' => 'text',
    ];

    /**
     * Provider definitions: class, credential env keys (names only),
     * model allowlist, env key for default model, route allowlist.
     *
     * route === null means "let the provider resolve its default route"
     * (GeminiProvider: GEMINI_ROUTE env > ai_gemini_relay_route flag > direct).
     */
    private const PROVIDERS = [
        'gemini' => [
            'class' => GeminiProvider::class,
            'credentialKeys' => ['GEMINI_API_KEY'],
            'relayKeys' => ['GEMINI_RELAY_URL', 'GEMINI_RELAY_TOKEN'],
            'modelEnvKey' => 'GEMINI_MODEL',
            'models' => [
                'gemini-3.6-flash',
                'gemini-3.6-pro',
                'gemini-2.5-flash',
                'gemini-2.5-pro',
                'gemini-2.0-flash',
            ],
            'defaultModel' => 'gemini-3.6-flash',
            'routes' => ['direct', 'n8n_relay', null],
        ],
        'openai' => [
            'class' => OpenAIProvider::class,
            'credentialKeys' => ['OPENAI_API_KEY'],
            'relayKeys' => [],
            'modelEnvKey' => 'OPENAI_MODEL',
            'models' => [
                'gpt-5',
                'gpt-5-mini',
                'gpt-4.1',
                'gpt-4o',
                'gpt-4o-mini',
            ],
            'defaultModel' => 'gpt-5-mini',
            'routes' => ['direct', null],
        ],
        'claude' => [
            'class' => ClaudeProvider::class,
            'credentialKeys' => ['ANTHROPIC_API_KEY'],
            'relayKeys' => [],
            'modelEnvKey' => 'ANTHROPIC_MODEL',
            'models' => [
                'claude-sonnet-4-5',
                'claude-opus-4-5',
                'claude-sonnet-4-20250514',
                'claude-3-7-sonnet-latest',
                'claude-3-5-haiku-latest',
            ],
            'defaultModel' => 'claude-sonnet-4-5',
            'routes' => ['direct', null],
        ],
        'tesseract' => [
            'class' => TesseractProvider::class,
            'credentialKeys' => [],
            'relayKeys' => [],
            'modelEnvKey' => '',
            'models' => [],
            'defaultModel' => '',
            'routes' => [null],
        ],
    ];

    /** @return string[] registered provider names */
    public static function providerNames(): array
    {
        return array_keys(self::PROVIDERS);
    }

    public static function isRegisteredProvider(string $name): bool
    {
        return isset(self::PROVIDERS[strtolower(trim($name))]);
    }

    /** @return class-string<AIProviderInterface>|null */
    public static function providerClass(string $name): ?string
    {
        return self::PROVIDERS[strtolower(trim($name))]['class'] ?? null;
    }

    public static function isCredentialProvider(string $name): bool
    {
        $def = self::PROVIDERS[strtolower(trim($name))] ?? null;
        return $def !== null && $def['credentialKeys'] !== [];
    }

    /** @return string[] env key NAMES (never values) */
    public static function credentialKeys(string $name): array
    {
        return self::PROVIDERS[strtolower(trim($name))]['credentialKeys'] ?? [];
    }

    /** @return string[] env key NAMES for the Gemini relay (never values) */
    public static function relayKeys(string $name): array
    {
        return self::PROVIDERS[strtolower(trim($name))]['relayKeys'] ?? [];
    }

    /** @return string[] */
    public static function modelAllowlist(string $name): array
    {
        return self::PROVIDERS[strtolower(trim($name))]['models'] ?? [];
    }

    /** NULL (provider default) or a string; tesseract has no model concept. */
    public static function isValidModel(string $name, ?string $model): bool
    {
        if ($model === null || $model === '') {
            return true; // NULL = provider/env default
        }
        return in_array(trim($model), self::modelAllowlist($name), true);
    }

    /** Route allowlist per provider; null entry = "no explicit route". */
    public static function isValidRoute(string $name, ?string $route): bool
    {
        $routes = self::PROVIDERS[strtolower(trim($name))]['routes'] ?? [];
        if ($route === null || $route === '') {
            return in_array(null, $routes, true);
        }
        return in_array(strtolower(trim($route)), $routes, true);
    }

    public static function isCredentialEnvKey(string $key): bool
    {
        foreach (self::PROVIDERS as $def) {
            if (in_array($key, $def['credentialKeys'], true) || in_array($key, $def['relayKeys'], true)) {
                return true;
            }
        }
        return false;
    }

    /** Effective default model for a provider: env override > catalog default. */
    public static function defaultModel(string $name): ?string
    {
        $def = self::PROVIDERS[strtolower(trim($name))] ?? null;
        if ($def === null) {
            return null;
        }
        if ($def['modelEnvKey'] !== '') {
            $env = trim(Config::env($def['modelEnvKey'], ''));
            if ($env !== '') {
                return $env;
            }
        }
        return $def['defaultModel'];
    }
}
