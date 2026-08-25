<?php

declare(strict_types=1);

namespace Velora\AI\DTOs;

/**
 * Generic AI response DTO — normalized across all providers.
 */
final class AIResponseDTO
{
    public function __construct(
        public readonly string $content, // raw text or JSON string from provider
        public readonly string $provider,
        public readonly string $model,
        public readonly int $latencyMs = 0,
        public readonly int $tokensUsed = 0,
        public readonly float $confidence = 0.0,
        public readonly string $status = 'success', // success, failed, quota_exhausted, timeout
        public readonly ?string $errorCode = null,
        public readonly array $rawResponse = [], // full provider response for debugging (never expose to client)
        public readonly array $metadata = [],
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'provider' => $this->provider,
            'model' => $this->model,
            'latency_ms' => $this->latencyMs,
            'tokens_used' => $this->tokensUsed,
            'confidence' => $this->confidence,
            'status' => $this->status,
            'error_code' => $this->errorCode,
            'metadata' => $this->metadata,
        ];
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }

    /**
     * Try to decode content as JSON.
     *
     * @return array<string,mixed>|null
     */
    public function contentAsJson(): ?array
    {
        $decoded = json_decode($this->content, true);
        return is_array($decoded) ? $decoded : null;
    }
}
