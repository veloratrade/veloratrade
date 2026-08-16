<?php

declare(strict_types=1);

namespace Velora\Core\Exceptions;

class UnauthorizedException extends ApiException
{
    public function __construct(
        string $message = 'Authentication required.',
        string $code = 'UNAUTHORIZED',
        string $messageKey = 'errors.unauthorized',
        array $params = [],
    ) {
        parent::__construct($message, 401, $code, null, $messageKey, $params);
    }
}
