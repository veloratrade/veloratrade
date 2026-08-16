<?php

declare(strict_types=1);

namespace Velora\Core\Exceptions;

/** Validation error whose field details contain codes/message keys, not UI copy. */
class ValidationException extends ApiException
{
    public function __construct(string $message = 'Validation failed.', array $details = [])
    {
        parent::__construct(
            'Validation failed.',
            422,
            'VALIDATION_FAILED',
            self::normalizeDetails($details),
            'errors.validation',
        );
    }

    /** @return array{fields:array<string,array{code:string,messageKey:string,params:array}>} */
    private static function normalizeDetails(array $details): array
    {
        $fields = isset($details['fields']) && is_array($details['fields'])
            ? $details['fields']
            : $details;
        $normalized = [];
        foreach ($fields as $field => $error) {
            if (is_array($error) && isset($error['code'], $error['messageKey'])) {
                $normalized[(string) $field] = [
                    'code' => (string) $error['code'],
                    'messageKey' => (string) $error['messageKey'],
                    'params' => is_array($error['params'] ?? null) ? $error['params'] : [],
                ];
                continue;
            }
            // Legacy call sites are normalized without exposing embedded copy.
            $normalized[(string) $field] = [
                'code' => 'INVALID',
                'messageKey' => 'errors.validation.invalid',
                'params' => [],
            ];
        }
        return ['fields' => $normalized];
    }
}
