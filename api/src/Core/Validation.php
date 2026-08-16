<?php

declare(strict_types=1);

namespace Velora\Core;

use Velora\Core\Exceptions\ValidationException;

/**
 * Language-neutral validation helpers.
 *
 * Field errors are semantic descriptors rather than rendered sentences. UI
 * clients translate messageKey through the shared locale catalog; API resource
 * data and validation execution therefore remain independent of UI language.
 */
final class Validation
{
    /**
     * @return array<string,array{code:string,messageKey:string,params:array<string,int|string>}>
     */
    public static function errors(array $data, array $rules): array
    {
        $errors = [];
        foreach ($rules as $field => $rule) {
            $value = $data[$field] ?? null;
            $present = array_key_exists($field, $data);

            foreach (explode('|', $rule) as $constraint) {
                [$name, $arg] = array_pad(explode(':', $constraint, 2), 2, null);

                switch ($name) {
                    case 'required':
                        if (!$present || self::isEmpty($value)) {
                            $errors[$field] = self::error('REQUIRED', 'errors.validation.required');
                        }
                        break;

                    case 'email':
                        if (!self::isEmpty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            $errors[$field] = self::error('INVALID_EMAIL', 'errors.validation.email');
                        }
                        break;

                    case 'string':
                        if (!self::isEmpty($value) && !is_string($value)) {
                            $errors[$field] = self::error('NOT_STRING', 'errors.validation.string');
                        }
                        break;

                    case 'min':
                        if (!self::isEmpty($value) && is_string($value) && mb_strlen($value) < (int) $arg) {
                            $errors[$field] = self::error(
                                'MIN_LENGTH',
                                'errors.validation.minLength',
                                ['min' => (int) $arg],
                            );
                        }
                        break;

                    case 'max':
                        if (!self::isEmpty($value) && is_string($value) && mb_strlen($value) > (int) $arg) {
                            $errors[$field] = self::error(
                                'MAX_LENGTH',
                                'errors.validation.maxLength',
                                ['max' => (int) $arg],
                            );
                        }
                        break;

                    case 'numeric':
                        if (!self::isEmpty($value) && !is_numeric($value)) {
                            $errors[$field] = self::error('NOT_NUMERIC', 'errors.validation.numeric');
                        }
                        break;

                    case 'decimal': // decimal:15,5
                        if (!self::isEmpty($value)) {
                            $parts = array_pad(explode(',', (string) $arg, 2), 2, '0');
                            $maxInt = (int) $parts[0];
                            $maxDec = (int) $parts[1];
                            $pattern = '/^-?\d{1,' . $maxInt . '}(\.\d{1,' . $maxDec . '})?$/';
                            if (!preg_match($pattern, (string) $value)) {
                                $errors[$field] = self::error(
                                    'INVALID_DECIMAL',
                                    'errors.validation.decimal',
                                    ['maxIntegerDigits' => $maxInt, 'maxFractionDigits' => $maxDec],
                                );
                            }
                        }
                        break;

                    case 'in':
                        $allowed = array_map('trim', explode(',', (string) $arg));
                        if (!self::isEmpty($value) && !in_array($value, $allowed, true)) {
                            $errors[$field] = self::error('INVALID_CHOICE', 'errors.validation.choice');
                        }
                        break;

                    case 'datetime':
                        if (!self::isEmpty($value)
                            && (!is_string($value) || strlen($value) > 64 || strtotime($value) === false)) {
                            $errors[$field] = self::error('INVALID_DATETIME', 'errors.validation.datetime');
                        }
                        break;
                }

                // Report one deterministic error per field.
                if (isset($errors[$field])) {
                    break;
                }
            }
        }
        return $errors;
    }

    /** Throws ValidationException when errors exist. */
    public static function assert(array $data, array $rules): void
    {
        $errors = self::errors($data, $rules);
        if ($errors !== []) {
            throw new ValidationException('Validation failed.', ['fields' => $errors]);
        }
    }

    /** @return array{code:string,messageKey:string,params:array<string,int|string>} */
    private static function error(string $code, string $messageKey, array $params = []): array
    {
        return ['code' => $code, 'messageKey' => $messageKey, 'params' => $params];
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || (is_array($value) && $value === []);
    }
}
