<?php

declare(strict_types=1);

namespace Velora\AI\Analysis;

use Velora\AI\DTOs\AIRequestDTO;
use Velora\AI\DTOs\AIResponseDTO;

/**
 * P1 placeholder — Trade Analysis.
 * Will analyze last N trades for behavioral mistakes, patterns, risk.
 * Uses generic AIProviderInterface via AIManager::generate().
 */
interface TradeAnalyzerInterface
{
    /**
     * Analyze trades for user.
     *
     * @param int $userId
     * @param array<int,array<string,mixed>> $trades Last N trades from TradeService (DTO, not DB)
     * @param array<string,mixed> $options feature flags, locale, etc.
     * @return AIResponseDTO Normalized analysis result (JSON content)
     */
    public function analyze(int $userId, array $trades, array $options = []): AIResponseDTO;

    /**
     * Check if analysis feature is enabled for user.
     */
    public function isEnabled(int $userId): bool;
}
