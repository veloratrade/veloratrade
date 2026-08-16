<?php

declare(strict_types=1);

namespace Velora\Core\Exceptions;

class ForbiddenException extends ApiException
{
    public function __construct(
        string $message = 'Access denied.',
        string $code = 'FORBIDDEN',
        string $messageKey = 'errors.forbidden',
        array $params = [],
    ) {
        parent::__construct($message, 403, $code, null, $messageKey, $params);
    }
}
