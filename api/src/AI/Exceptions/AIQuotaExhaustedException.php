<?php

declare(strict_types=1);

namespace Velora\AI\Exceptions;

/**
 * Quota exhausted, rate limited, or API key missing/invalid.
 * Triggers fallback to next provider.
 */
final class AIQuotaExhaustedException extends AIException
{
    public function __construct(
        string $message = 'AI provider quota exhausted.',
        ?string $provider = null,
    ) {
        parent::__construct(
            $message,
            429,
            'AI_QUOTA_EXHAUSTED',
            $provider ? ['provider' => $provider] : null,
            'errors.ai.quota',
            $provider ? ['provider' => $provider] : [],
        );
    }
}
