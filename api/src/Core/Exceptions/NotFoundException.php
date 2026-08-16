<?php

declare(strict_types=1);

namespace Velora\Core\Exceptions;

class NotFoundException extends ApiException
{
    public function __construct(
        string $message = 'Resource not found.',
        string $code = 'NOT_FOUND',
        string $messageKey = 'errors.notFound',
        array $params = [],
    ) {
        parent::__construct($message, 404, $code, null, $messageKey, $params);
    }
}
