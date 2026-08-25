<?php

declare(strict_types=1);

namespace Velora\AI\Exceptions;

/**
 * Provider communication failure (5xx, invalid response, network error).
 */
final class AIProviderException extends AIException
{
    public function __construct(
        string $message = 'AI provider failed to process request.',
        ?string $provider = null,
        mixed $details = null,
    ) {
        $safeDetails = null;
        if (is_array($details) && isset($details['provider'])) {
            $safeDetails = ['provider' => $details['provider']];
        } elseif ($provider !== null) {
            $safeDetails = ['provider' => $provider];
        }

        parent::__construct(
            $message,
            502,
            'AI_PROVIDER_ERROR',
            $safeDetails,
            'errors.ai.provider',
            $provider ? ['provider' => $provider] : [],
        );
    }
}
