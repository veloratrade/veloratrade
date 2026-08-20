<?php

declare(strict_types=1);

namespace Velora\Core\Exceptions;

/**
 * 503 — the service is temporarily unable to handle the request, typically
 * because a backing dependency (the database) could not be reached after
 * bounded retries. Distinct from a generic 500 so the HTTP layer can return a
 * retryable status without ever exposing connection details (DSN, host,
 * credentials) to the client.
 */
class ServiceUnavailableException extends ApiException
{
    public function __construct(
        string $message = 'Service temporarily unavailable.',
        string $code = 'SERVICE_UNAVAILABLE',
        string $messageKey = 'errors.http.503',
        array $params = [],
    ) {
        parent::__construct($message, 503, $code, null, $messageKey, $params);
    }
}
