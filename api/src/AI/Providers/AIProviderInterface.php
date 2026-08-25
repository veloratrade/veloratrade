<?php

declare(strict_types=1);

namespace Velora\AI\Providers;

use Velora\AI\DTOs\AIRequestDTO;
use Velora\AI\DTOs\AIResponseDTO;
use Velora\AI\Extraction\ExtractedTradeData;

/**
 * Generic provider abstraction for all AI capabilities.
 * Supports vision, text analysis, chat, reports via generate().
 * Extraction-specific extract() kept for backward compatibility via adapter.
 */
interface AIProviderInterface
{
    public function getName(): string;

    /**
     * @return string[] e.g. ['vision','text','analysis','chat']
     */
    public function getCapabilities(): array;

    public function getCostTier(): int; // 0=free,1=cheap,2=paid

    public function isAvailable(): bool;

    /**
     * Generic AI generation — primary method for all future features.
     *
     * @param string $prompt Prompt text (versioned via PromptManager)
     * @param array<string,mixed> $context Additional context: imageRaw, trades, etc.
     * @param array<string,mixed> $options timeout, temperature, model, deadline, etc.
     * @return AIResponseDTO Normalized response
     *
     * @throws \Velora\AI\Exceptions\AIException
     */
    public function generate(string $prompt, array $context = [], array $options = []): AIResponseDTO;

    /**
     * Backward compatible extraction — for screenshot MVP.
     * Default implementation should call generate() internally.
     * Will be deprecated after full migration to VisionExtractorInterface.
     *
     * @param string $imageRaw
     * @param float $deadline
     * @return ExtractedTradeData
     */
    public function extract(string $imageRaw, float $deadline): ExtractedTradeData;
}
