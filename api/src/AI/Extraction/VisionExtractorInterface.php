<?php

declare(strict_types=1);

namespace Velora\AI\Extraction;

use Velora\AI\DTOs\AIRequestDTO;
use Velora\AI\DTOs\AIResponseDTO;

/**
 * Vision extractor interface — adapter for image extraction.
 * Bridges generic AIProviderInterface (generate) to domain-specific extraction.
 * Keeps backward compatibility with old extract(image) pattern.
 */
interface VisionExtractorInterface
{
    /**
     * Extract trade data from image using generic AI provider.
     *
     * @param string $imageRaw Raw image bytes
     * @param float $deadline
     * @param string $prompt Optional custom prompt, uses PromptManager if empty
     * @return ExtractedTradeData
     */
    public function extract(string $imageRaw, float $deadline, string $prompt = ''): ExtractedTradeData;

    public function extractWithUser(string $imageRaw, float $deadline, int $userId, string $prompt = ''): ExtractedTradeData;

    /**
     * Generic generate that returns AIResponseDTO — for future use.
     */
    public function generate(AIRequestDTO $request, float $deadline): AIResponseDTO;
}
