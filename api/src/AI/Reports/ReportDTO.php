<?php

declare(strict_types=1);

namespace Velora\AI\Reports;

/**
 * DTO for weekly/monthly AI reports.
 */
final class ReportDTO
{
    public function __construct(
        public readonly int $userId,
        public readonly string $periodStart, // YYYY-MM-DD
        public readonly string $periodEnd,   // YYYY-MM-DD
        public readonly string $locale = 'en', // fa/en
        public readonly array $content = [], // JSON content from AI
        public readonly string $provider = 'unknown',
        public readonly string $model = '',
        public readonly float $confidence = 0.0,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'locale' => $this->locale,
            'content' => $this->content,
            'provider' => $this->provider,
            'model' => $this->model,
            'confidence' => $this->confidence,
        ];
    }
}
