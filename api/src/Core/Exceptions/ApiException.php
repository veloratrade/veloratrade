<?php

declare(strict_types=1);

namespace Velora\Core\Exceptions;

/**
 * Base HTTP exception — every error thrown by the app should extend this so
 * the front controller can render the standardized error JSON contract.
 */
class ApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        protected int $httpStatus = 400,
        ?string $code = null,
        protected mixed $details = null,
        protected ?string $messageKey = null,
        protected array $params = [],
    ) {
        parent::__construct($message, $httpStatus);
        $this->code = (int) $httpStatus;
        $this->errorCode = $code ?? self::defaultCode($httpStatus);
        $this->messageKey ??= self::defaultMessageKey($httpStatus);
    }

    protected ?string $errorCode;

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function details(): mixed
    {
        return $this->details;
    }

    public function messageKey(): string
    {
        return $this->messageKey ?? 'errors.unknown';
    }

    public function params(): array
    {
        return $this->params;
    }

    private static function defaultMessageKey(int $status): string
    {
        return match ($status) {
            401 => 'errors.unauthorized',
            403 => 'errors.forbidden',
            404 => 'errors.notFound',
            409 => 'errors.conflict',
            422 => 'errors.validation',
            429 => 'errors.rateLimited',
            400, 405, 500, 502, 503, 504 => 'errors.http.' . $status,
            default => 'errors.unknown',
        };
    }

    private static function defaultCode(int $status): string
    {
        return match ($status) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            409 => 'CONFLICT',
            422 => 'VALIDATION_FAILED',
            429 => 'TOO_MANY_REQUESTS',
            default => 'INTERNAL_ERROR',
        };
    }
}
