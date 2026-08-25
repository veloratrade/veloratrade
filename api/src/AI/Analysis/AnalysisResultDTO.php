<?php

declare(strict_types=1);

namespace Velora\AI\Analysis;

/**
 * DTO for trade analysis result.
 * Output of TradeAnalyzerService.
 */
final class AnalysisResultDTO
{
    /**
     * @param string[] $mistakes
     * @param string[] $strengths
     * @param string[] $patterns
     * @param string[] $recommendations
     */
    public function __construct(
        public readonly array $mistakes = [],
        public readonly array $strengths = [],
        public readonly array $patterns = [],
        public readonly array $recommendations = [],
        public readonly float $riskScore = 0.0,
        public readonly float $confidence = 0.0,
        public readonly string $summary = '',
        public readonly string $provider = 'unknown',
        public readonly string $model = '',
        public readonly array $rawResponse = [],
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'mistakes' => $this->mistakes,
            'strengths' => $this->strengths,
            'patterns' => $this->patterns,
            'recommendations' => $this->recommendations,
            'riskScore' => $this->riskScore,
            'risk_score' => $this->riskScore,
            'confidence' => $this->confidence,
            'summary' => $this->summary,
            'provider' => $this->provider,
            'model' => $this->model,
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data, string $provider = 'unknown', string $model = '', float $confidence = 0.0, array $rawResponse = []): self
    {
        return new self(
            mistakes: $data['mistakes'] ?? [],
            strengths: $data['strengths'] ?? [],
            patterns: $data['patterns'] ?? $data['patterns'] ?? [],
            recommendations: $data['recommendations'] ?? [],
            riskScore: (float) ($data['riskScore'] ?? $data['risk_score'] ?? 0.0),
            confidence: $confidence > 0 ? $confidence : (float) ($data['confidence'] ?? 0.0),
            summary: (string) ($data['summary'] ?? ''),
            provider: $provider,
            model: $model,
            rawResponse: $rawResponse,
        );
    }
}
