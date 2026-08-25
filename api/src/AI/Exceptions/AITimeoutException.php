<?php

declare(strict_types=1);

namespace Velora\AI\Exceptions;

/**
 * Provider timed out — triggers fallback.
 */
final class AITimeoutException extends AIException
{
    public function __construct(
        string $message = 'AI provider timed out.',
        ?string $provider = null,
    ) {
        parent::__construct(
            $message,
            504,
            'AI_TIMEOUT',
            $provider ? ['provider' => $provider] : null,
            'errors.ai.timeout',
            $provider ? ['provider' => $provider] : [],
        );
    }
}
