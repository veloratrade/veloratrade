<?php

declare(strict_types=1);

namespace Velora\AI\Exceptions;

/**
 * Extracted data failed validation or AI returned malformed JSON.
 */
final class AIValidationException extends AIException
{
    public function __construct(
        string $message = 'AI extraction validation failed.',
        array $fields = [],
    ) {
        $details = $fields !== [] ? ['fields' => $fields] : null;
        parent::__construct(
            $message,
            422,
            'AI_VALIDATION_FAILED',
            $details,
            'errors.ai.validation',
        );
    }
}
