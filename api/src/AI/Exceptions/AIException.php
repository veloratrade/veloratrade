<?php

declare(strict_types=1);

namespace Velora\AI\Exceptions;

use Velora\Core\Exceptions\ApiException;

/**
 * Base AI exception — all AI errors extend this.
 * Never expose raw provider errors or API keys to client.
 */
class AIException extends ApiException
{
    public function __construct(
        string $message = 'AI service temporarily unavailable.',
        int $httpStatus = 502,
        string $code = 'AI_ERROR',
        mixed $details = null,
        string $messageKey = 'errors.ai.generic',
        array $params = [],
    ) {
        parent::__construct($message, $httpStatus, $code, $details, $messageKey, $params);
    }
}
