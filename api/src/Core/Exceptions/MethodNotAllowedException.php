<?php

declare(strict_types=1);

namespace Velora\Core\Exceptions;

class MethodNotAllowedException extends ApiException
{
    public function __construct(string $message = 'Method not allowed.')
    {
        parent::__construct($message, 405);
    }
}
