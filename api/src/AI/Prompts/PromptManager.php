<?php

declare(strict_types=1);

namespace Velora\AI\Prompts;

/**
 * Prompt Manager — versioned prompts with language support preparation.
 * Loads prompts from api/src/AI/Prompts/templates/ files.
 * No hardcoded prompts inside providers after this.
 * Follows existing Config pattern for path resolution.
 */
final class PromptManager
{
    private const TEMPLATE_DIR = __DIR__ . '/templates';

    /** @var array<string,string> Cache */
    private static array $cache = [];

    /**
     * Get prompt by name and version.
     *
     * @param string $name e.g. screenshot_extraction, trade_analysis
     * @param string $version e.g. v1, v2
     * @param string $locale fa/en preparation, currently returns same but ready for future
     * @return string Prompt text
     *
     * @throws \RuntimeException If prompt not found
     */
    public static function get(string $name, string $version = 'v1', string $locale = 'en'): string
    {
        $key = $name . '_' . $version . '_' . $locale;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        // Try locale-specific first, then fallback to generic
        $candidates = [
            self::TEMPLATE_DIR . '/' . $name . '_' . $version . '_' . $locale . '.txt',
            self::TEMPLATE_DIR . '/' . $name . '_' . $version . '.txt',
            self::TEMPLATE_DIR . '/' . $name . '.txt',
        ];

        foreach ($candidates as $path) {
            if (is_file($path) && is_readable($path)) {
                $content = file_get_contents($path);
                if ($content !== false && trim($content) !== '') {
                    self::$cache[$key] = $content;
                    return $content;
                }
            }
        }

        // Fallback to hardcoded v1 for backward compatibility (will be removed after migration)
        $fallback = self::fallbackPrompt($name);
        if ($fallback !== null) {
            self::$cache[$key] = $fallback;
            return $fallback;
        }

        throw new \RuntimeException("Prompt not found: $name $version $locale");
    }

    /**
     * Get prompt with variables replaced.
     *
     * @param array<string,string> $vars e.g. ['symbol' => 'XAUUSD']
     */
    public static function getWithVars(string $name, array $vars = [], string $version = 'v1', string $locale = 'en'): string
    {
        $prompt = self::get($name, $version, $locale);
        foreach ($vars as $k => $v) {
            $prompt = str_replace('{' . $k . '}', $v, $prompt);
            $prompt = str_replace('{{' . $k . '}}', $v, $prompt);
        }
        return $prompt;
    }

    /**
     * List available prompts.
     *
     * @return string[]
     */
    public static function list(): array
    {
        $files = glob(self::TEMPLATE_DIR . '/*.txt');
        if ($files === false) {
            return [];
        }
        return array_map(fn($f) => basename($f), $files);
    }

    /**
     * Fallback prompts for backward compatibility during migration.
     */
    private static function fallbackPrompt(string $name): ?string
    {
        return match ($name) {
            'screenshot_extraction', 'screenshot_trade' => <<<PROMPT
You are a trading screenshot parser for Velora AI Trading Journal.
Extract trade data from the image and return ONLY valid JSON.

Required JSON fields (use null if not visible):
{
  "symbol": "e.g. XAUUSD, EURUSD, BTCUSD",
  "side": "buy or sell",
  "entry": "entry price as string",
  "exit": "exit price",
  "lot": "lot size / volume",
  "sl": "stop loss",
  "tp": "take profit",
  "pnl": "profit/loss",
  "openTime": "YYYY-MM-DDTHH:MM",
  "closeTime": "same format",
  "confidence": 0.0 to 1.0
}

Rules:
- Return ONLY JSON, no markdown, no explanation.
- Prices must be numeric strings.
- Symbol uppercase.
- Side exactly "buy" or "sell" or null.
- Persian digits convert to Latin.
- If field not visible, use null.
- Confidence: 0.9 if all clear, 0.7 if partial, 0.4 if uncertain.
PROMPT,
            'trade_analysis' => <<<PROMPT
You are Velora AI trade analyst. Analyze the provided trades JSON and return ONLY valid JSON with insights.

Required JSON:
{
  "summary": "brief summary",
  "strengths": ["..."],
  "weaknesses": ["..."],
  "recommendations": ["..."],
  "confidence": 0.85
}
Return ONLY JSON.
PROMPT,
            default => null,
        };
    }

    /**
     * Clear cache (for testing).
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
