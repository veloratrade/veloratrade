<?php

declare(strict_types=1);

namespace Velora\AI\Services;

use Velora\AI\Providers\AIProviderInterface;
use Velora\AI\Providers\GeminiProvider;
use Velora\AI\Providers\TesseractProvider;
use Velora\Core\Config;

/**
 * Provider registry — loads enabled providers respecting priority.
 * Uses config/env initially, ready for DB-driven providers.
 * AIManager must depend on registry, not hardcoded list.
 */
final class AIProviderRegistry
{
    /** @var array<string,class-string<AIProviderInterface>> */
    private const PROVIDER_MAP = [
        'gemini' => GeminiProvider::class,
        'tesseract' => TesseractProvider::class,
        // Future: 'openai' => OpenAIProvider::class, 'qwen' => QwenProvider::class, 'local' => LocalModelProvider::class
    ];

    /** @var array<string,int> Default priority (lower = higher priority) */
    private const DEFAULT_PRIORITY = [
        'gemini' => 10,
        'openai' => 20,
        'qwen' => 30,
        'local' => 40,
        'tesseract' => 100,
    ];

    /**
     * Load enabled providers respecting priority ordering.
     *
     * @return AIProviderInterface[]
     */
    public function loadEnabledProviders(): array
    {
        $enabledNames = $this->getEnabledProviderNames();
        $providers = [];

        foreach ($enabledNames as $name) {
            $class = self::PROVIDER_MAP[$name] ?? null;
            if ($class === null) {
                continue;
            }
            if (!class_exists($class)) {
                continue;
            }
            try {
                $instance = new $class();
                if ($instance instanceof AIProviderInterface) {
                    $providers[] = $instance;
                }
            } catch (\Throwable $e) {
                error_log('[VELORA_AI_REGISTRY] failed to instantiate ' . $name . ': ' . $e->getMessage());
            }
        }

        // Sort by priority
        usort($providers, function (AIProviderInterface $a, AIProviderInterface $b): int {
            $pa = self::DEFAULT_PRIORITY[$a->getName()] ?? 999;
            $pb = self::DEFAULT_PRIORITY[$b->getName()] ?? 999;
            return $pa <=> $pb;
        });

        return $providers;
    }

    /**
     * Get enabled provider names from env/config, ready for DB override.
     *
     * @return string[]
     */
    public function getEnabledProviderNames(): array
    {
        // Future: try DB table ai_providers first, fallback to env
        try {
            // Check if ai_providers table exists and has enabled providers
            // For now, we use env only to respect cPanel limitations (no extra query)
            // This keeps compatibility with current deployment
            $envNames = $this->getEnabledFromEnv();
            if ($envNames !== []) {
                return $envNames;
            }
        } catch (\Throwable $e) {
            // Fallback to env
        }

        return $this->getEnabledFromEnv();
    }

    /**
     * Get enabled providers from env: AI_ENABLED_PROVIDERS=gemini,tesseract
     *
     * @return string[]
     */
    private function getEnabledFromEnv(): array
    {
        $raw = Config::env('AI_ENABLED_PROVIDERS', 'gemini,tesseract');
        $names = array_values(array_filter(array_map('trim', explode(',', $raw))));
        $valid = [];
        foreach ($names as $n) {
            $lower = strtolower($n);
            if (isset(self::PROVIDER_MAP[$lower])) {
                $valid[] = $lower;
            }
        }
        return $valid !== [] ? $valid : ['gemini', 'tesseract'];
    }

    /**
     * Check if provider is enabled.
     */
    public function isProviderEnabled(string $name): bool
    {
        return in_array(strtolower($name), $this->getEnabledProviderNames(), true);
    }

    /**
     * Get provider map for future extensibility.
     *
     * @return array<string,class-string>
     */
    public static function getProviderMap(): array
    {
        return self::PROVIDER_MAP;
    }

    /**
     * Get default priority.
     */
    public static function getPriority(string $name): int
    {
        return self::DEFAULT_PRIORITY[strtolower($name)] ?? 999;
    }
}
