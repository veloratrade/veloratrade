<?php

declare(strict_types=1);

namespace Velora\AI\Exceptions;

/**
 * User consent required for external AI processing.
 */
final class AIConsentRequiredException extends AIException
{
    public function __construct(string $message = 'AI consent required for external processing.')
    {
        parent::__construct(
            $message,
            403,
            'AI_CONSENT_REQUIRED',
            null,
            'errors.ai.consentRequired',
        );
    }
}
