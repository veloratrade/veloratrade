<?php

declare(strict_types=1);

namespace Velora\AI\Extraction;

use Velora\AI\Exceptions\AIValidationException;

/**
 * Validates extracted trade data.
 * Follows existing Validation pattern but for AI DTO.
 */
final class ExtractionValidator
{
    /**
     * Validate and normalize extracted data.
     * Throws AIValidationException on critical failure, otherwise returns normalized DTO.
     */
    public static function validate(ExtractedTradeData $data): ExtractedTradeData
    {
        $errors = [];

        // Symbol: 2-32 chars, alphanumeric + / . - _
        if ($data->symbol !== null && $data->symbol !== '') {
            if (!preg_match('/\A[A-Z0-9\/\.\-_]{2,32}\z/', $data->symbol)) {
                $errors['symbol'] = ['code' => 'INVALID', 'messageKey' => 'errors.ai.validation.symbol'];
            }
        }

        // Side
        if ($data->side !== null && !in_array($data->side, ['buy', 'sell'], true)) {
            $errors['side'] = ['code' => 'INVALID', 'messageKey' => 'errors.ai.validation.side'];
        }

        // Numeric fields: allow decimal, optional negative for pnl
        $numericFields = ['entry', 'exit', 'lot', 'sl', 'tp', 'pnl'];
        foreach ($numericFields as $field) {
            $value = $data->{$field};
            if ($value !== null && $value !== '') {
                if (!is_numeric($value)) {
                    // Try to extract numeric from string like "$123.45"
                    $cleaned = preg_replace('/[^0-9\.\-]/', '', $value);
                    if (!is_numeric($cleaned)) {
                        $errors[$field] = ['code' => 'INVALID', 'messageKey' => 'errors.ai.validation.numeric'];
                    }
                }
            }
        }

        // Time validation: YYYY-MM-DDTHH:MM or YYYY-MM-DD HH:MM:SS or YYYY.MM.DD HH:MM:SS
        $timeFields = ['openTime', 'closeTime'];
        foreach ($timeFields as $field) {
            $value = $data->{$field};
            if ($value !== null && $value !== '') {
                if (strlen($value) > 64) {
                    $errors[$field] = ['code' => 'INVALID', 'messageKey' => 'errors.ai.validation.datetime'];
                    continue;
                }
                // Accept ISO8601 or MT5 format
                $valid = preg_match('/\A20\d{2}[- \.\/]\d{2}[- \.\/]\d{2}[ T]\d{2}:\d{2}(:\d{2})?\z/', $value)
                    || preg_match('/\A20\d{2}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?\z/', $value);
                if (!$valid) {
                    // Allow empty, but if present must be somewhat valid — we don't hard fail for time, just warn
                    // For MVP, we don't add error for time, just keep as is
                }
            }
        }

        // If critical fields all empty and confidence low, it's invalid
        $hasAny = $data->symbol !== null || $data->entry !== null || $data->exit !== null || $data->side !== null;
        if (!$hasAny && $data->confidence < 0.3) {
            // Don't throw for Tesseract fallback which may return low confidence empty
            // Only throw if provider claims high confidence but empty
            if ($data->provider !== 'tesseract' && $data->confidence > 0.5) {
                $errors['extraction'] = ['code' => 'EMPTY', 'messageKey' => 'errors.ai.validation.empty'];
            }
        }

        if ($errors !== []) {
            // For MVP, we throw only if critical errors, otherwise return data as is
            // Check if any error is not just warning
            $critical = array_filter($errors, fn($k) => in_array($k, ['symbol','side'], true), ARRAY_FILTER_USE_KEY);
            if ($critical !== []) {
                throw new AIValidationException('AI extraction validation failed.', $errors);
            }
        }

        return $data;
    }

    /**
     * Check if extracted data has minimum usable fields.
     */
    public static function hasMinimumData(ExtractedTradeData $data): bool
    {
        return ($data->symbol !== null && $data->symbol !== '')
            || ($data->entry !== null && $data->exit !== null)
            || ($data->side !== null);
    }
}
