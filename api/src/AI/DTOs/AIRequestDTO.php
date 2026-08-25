<?php

declare(strict_types=1);

namespace Velora\AI\DTOs;

/**
 * Generic AI request DTO — used by all future AI features.
 * Follows existing Velora DTO patterns.
 */
final class AIRequestDTO
{
    public function __construct(
        public readonly int $userId,
        public readonly string $feature, // extraction, analysis, report, assistant, etc.
        public readonly string $provider = 'gemini',
        public readonly string $model = 'gemini-1.5-flash',
        public readonly string $prompt = '',
        public readonly string $promptHash = '',
        public readonly array $context = [], // additional context: trades, image_hash, etc.
        public readonly array $options = [], // timeout, temperature, etc.
        public readonly array $metadata = [], // image_hash, etc.
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'feature' => $this->feature,
            'provider' => $this->provider,
            'model' => $this->model,
            'prompt' => $this->prompt,
            'prompt_hash' => $this->promptHash !== '' ? $this->promptHash : hash('sha256', $this->prompt),
            'context' => $this->context,
            'options' => $this->options,
            'metadata' => $this->metadata,
        ];
    }

    public static function forExtraction(int $userId, string $prompt, string $imageHash, string $provider = 'gemini', string $model = 'gemini-1.5-flash', array $options = []): self
    {
        return new self(
            userId: $userId,
            feature: 'extraction',
            provider: $provider,
            model: $model,
            prompt: $prompt,
            promptHash: hash('sha256', $prompt),
            context: ['image_hash' => $imageHash],
            options: $options,
            metadata: ['image_hash' => $imageHash],
        );
    }

    public static function forAnalysis(int $userId, string $prompt, array $trades, string $provider = 'gemini', array $options = []): self
    {
        return new self(
            userId: $userId,
            feature: 'analysis',
            provider: $provider,
            model: $provider === 'gemini' ? 'gemini-1.5-flash' : 'gpt-4o-mini',
            prompt: $prompt,
            promptHash: hash('sha256', $prompt),
            context: ['trades' => $trades],
            options: $options,
        );
    }
}
