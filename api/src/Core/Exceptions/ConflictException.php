<?php

declare(strict_types=1);

namespace Velora\Core\Exceptions;

class ConflictException extends ApiException
{
    public function __construct(
        string $message = 'Request conflict.',
        string $code = 'CONFLICT',
        string $messageKey = 'errors.conflict',
        array $params = [],
    ) {
        parent::__construct($message, 409, $code, null, $messageKey, $params);
    }
}
