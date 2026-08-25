<?php

declare(strict_types=1);

namespace Velora\AI\Providers;

/**
 * Provider capabilities constants — for future extensibility.
 * No Composer, vanilla PHP.
 */
final class ProviderCapability
{
    public const VISION = 'vision';
    public const TEXT = 'text';
    public const ANALYSIS = 'analysis';
    public const CHAT = 'chat';
    public const EXTRACTION = 'extraction';
    public const OCR = 'ocr';
    public const REPORTS = 'reports';
    public const RECOMMENDATIONS = 'recommendations';
    public const MEMORY = 'memory';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::VISION,
            self::TEXT,
            self::ANALYSIS,
            self::CHAT,
            self::EXTRACTION,
            self::OCR,
            self::REPORTS,
            self::RECOMMENDATIONS,
            self::MEMORY,
        ];
    }

    public static function isValid(string $capability): bool
    {
        return in_array($capability, self::all(), true);
    }
}
