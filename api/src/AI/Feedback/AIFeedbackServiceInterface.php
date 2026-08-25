<?php

declare(strict_types=1);

namespace Velora\AI\Feedback;

/**
 * P1 placeholder — Feedback loop for future training.
 * Stores user corrections: original vs corrected.
 */
interface AIFeedbackServiceInterface
{
    /**
     * Store user correction for extraction.
     *
     * @param int $userId
     * @param int $extractionId FK to ai_extractions
     * @param array<string,mixed> $original
     * @param array<string,mixed> $corrected
     * @return int New feedback ID
     */
    public function storeCorrection(int $userId, int $extractionId, array $original, array $corrected): int;

    /**
     * Get accuracy stats for provider.
     *
     * @return array<string,mixed>
     */
    public function getAccuracyStats(string $provider, int $days = 7): array;
}
