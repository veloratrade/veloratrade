<?php

declare(strict_types=1);

namespace Velora\AI\Services;

use Velora\AI\Exceptions\AIConsentRequiredException;
use Velora\AI\Exceptions\AIException;
use Velora\AI\Exceptions\AIProviderException;
use Velora\AI\Exceptions\AIQuotaExhaustedException;
use Velora\AI\Exceptions\AITimeoutException;
use Velora\AI\Exceptions\AIValidationException;

/**
 * Centralized failure classification for the provider fallback chain.
 *
 * FALLBACK — eligible to move to the next configured provider:
 *   quota exhausted, rate limit, timeout, provider unavailable, credential
 *   not configured, upstream temporary failure, network/transport failure,
 *   and provider-output quality failures (malformed/empty/low-confidence
 *   output from the provider — preserves the legacy "try tesseract next"
 *   behavior of AIManager).
 *
 * ABORT — stop the chain immediately and surface the error:
 *   validation of the user's input/request, malformed request payload,
 *   invalid image/MIME, unsupported capability, AI consent required,
 *   invalid configuration, application-level programming errors.
 *
 * Classification is based on the typed AI exception model that already
 * exists in the codebase — no string guesswork on messages. Unknown
 * non-AI throwables are application errors (ABORT).
 */
final class AIFailureClassifier
{
    public const FALLBACK = 'fallback';
    public const ABORT = 'abort';

    /**
     * Provider-output quality codes — the provider answered but its output is
     * unusable. This is a provider-quality failure (FALLBACK), distinct from
     * validation of the user's input (ABORT).
     */
    private const PROVIDER_QUALITY_CODES = [
        'MALFORMED',
        'INVALID_JSON',
        'MISSING_TEXT',
        'LOW_CONFIDENCE',
        'EMPTY',
    ];

    public static function classify(\Throwable $e): string
    {
        // Privacy/consent is a hard stop — never fallback around user consent.
        if ($e instanceof AIConsentRequiredException) {
            return self::ABORT;
        }

        // Transient provider-side capacity failures — try the next provider.
        if ($e instanceof AIQuotaExhaustedException || $e instanceof AITimeoutException) {
            return self::FALLBACK;
        }

        if ($e instanceof AIValidationException) {
            return self::isProviderQualityFailure($e) ? self::FALLBACK : self::ABORT;
        }

        // Provider/transport layer failures: auth rejected upstream, model not
        // found upstream, upstream unavailable, network errors, relay errors.
        if ($e instanceof AIProviderException) {
            return self::FALLBACK;
        }

        // Remaining typed AI-layer exceptions (provider domain) — fallback.
        if ($e instanceof AIException) {
            return self::FALLBACK;
        }

        // Anything else is an application-level error — abort, never sweep it
        // under provider fallback.
        return self::ABORT;
    }

    /**
     * Detects provider-output quality codes inside AIValidationException
     * details. Transports/providers wrap codes structurally, e.g.:
     *   fields => [provider|json|response|extraction => [code => 'MALFORMED']]
     * so the scan is recursive (bounded) over ['code' => ...] nodes.
     */
    private static function isProviderQualityFailure(AIValidationException $e): bool
    {
        $details = $e->details();
        if (!is_array($details)) {
            return false;
        }
        return self::scanForQualityCode($details, 0);
    }

    private static function scanForQualityCode(array $node, int $depth): bool
    {
        if ($depth > 3) {
            return false;
        }
        if (isset($node['code']) && is_string($node['code'])
            && in_array(strtoupper($node['code']), self::PROVIDER_QUALITY_CODES, true)) {
            return true;
        }
        foreach ($node as $value) {
            if (is_array($value) && self::scanForQualityCode($value, $depth + 1)) {
                return true;
            }
        }
        return false;
    }
}
