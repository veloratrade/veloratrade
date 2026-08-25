<?php

declare(strict_types=1);

namespace Velora\AI\Extraction;

use Velora\AI\DTOs\AIRequestDTO;
use Velora\AI\Exceptions\AIException;
use Velora\AI\Prompts\PromptManager;
use Velora\AI\Services\AIManager;
use Velora\AI\Services\ImageProcessor;

/**
 * High-level extraction service — hardened foundation.
 * Flow: ImageProcessor -> PromptManager -> AIManager::generate() -> Validation -> DTO
 * Keeps business logic outside controllers, supports future features via generic generate().
 */
final class ScreenshotExtractor implements VisionExtractorInterface
{
    private AIManager $manager;

    public function __construct(?AIManager $manager = null)
    {
        $this->manager = $manager ?? new AIManager();
    }

    /**
     * Generic generate for future compatibility.
     */
    public function generate(\Velora\AI\DTOs\AIRequestDTO $request, float $deadline): \Velora\AI\DTOs\AIResponseDTO
    {
        $prompt = $request->prompt;
        if ($prompt === '') {
            try {
                $prompt = PromptManager::get('screenshot_extraction', 'v1', 'en');
            } catch (\Throwable $e) {
                $prompt = 'Extract trade data as JSON';
            }
        }

        return $this->manager->generate($prompt, [
            'imageRaw' => $request->context['imageRaw'] ?? '',
            'feature' => $request->feature,
            'user_id' => $request->userId,
        ], [
            'deadline' => $deadline,
            'feature' => $request->feature,
            'user_id' => $request->userId,
            'responseMimeType' => 'application/json',
            'capability' => 'vision',
        ]);
    }

    /**
     * Extract from single image raw bytes — uses ImageProcessor + PromptManager + generic generate().
     */
    public function extractSingle(string $imageRaw, float $deadline, int $userId = 0): ExtractedTradeData
    {
        // P2: Image optimization before AI — ONLY here, not in provider
        $processed = ImageProcessor::process($imageRaw);
        $optimizedRaw = $processed['data'];

        // P2: Prompt versioning — source ONLY via PromptManager
        $prompt = '';
        try {
            $prompt = PromptManager::get('screenshot_extraction', 'v1', 'en');
        } catch (\Throwable $e) {
            // Fallback handled inside PromptManager::fallbackPrompt()
            $prompt = '';
        }

        try {
            $data = $this->manager->extract($optimizedRaw, $deadline, $userId);
            return ExtractionValidator::validate($data);
        } catch (AIException $e) {
            if ($processed['resized'] && $optimizedRaw !== $imageRaw) {
                try {
                    $data = $this->manager->extract($imageRaw, $deadline, $userId);
                    return ExtractionValidator::validate($data);
                } catch (\Throwable $e2) {
                    throw $e;
                }
            }
            throw $e;
        }
    }

    public function extract(string $imageRaw, float $deadline, string $prompt = ''): ExtractedTradeData
    {
        return $this->extractSingle($imageRaw, $deadline, 0);
    }

    public function extractWithUser(string $imageRaw, float $deadline, int $userId, string $prompt = ''): ExtractedTradeData
    {
        if ($prompt !== '') {
            $response = $this->manager->generate($prompt, [
                'imageRaw' => $imageRaw,
                'feature' => 'extraction',
                'user_id' => $userId,
            ], [
                'deadline' => $deadline,
                'feature' => 'extraction',
                'user_id' => $userId,
                'responseMimeType' => 'application/json',
                'capability' => 'vision',
            ]);
            $json = json_decode($response->content, true);
            if (is_array($json)) {
                $dto = ExtractedTradeData::fromArray($json, $response->provider, $response->confidence, $response->content, $response->rawResponse);
                return ExtractionValidator::validate($dto);
            }
        }
        return $this->extractSingle($imageRaw, $deadline, $userId);
    }

    public function extractMultiple(array $imagesRaw, float $deadline, int $userId = 0): ExtractedTradeData
    {
        if ($imagesRaw === []) {
            throw new \InvalidArgumentException('At least one image required.');
        }
        return $this->extractSingle($imagesRaw[0], $deadline, $userId);
    }

    public function getManager(): AIManager
    {
        return $this->manager;
    }
}
