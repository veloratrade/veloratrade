<?php

declare(strict_types=1);

namespace Velora\AI\Providers;

/**
 * Credential status model for provider credential verification.
 *
 * These are the distinct, non-collapsed states a credential can occupy. The
 * single most important guarantee: `VALID` is the ONLY state eligible for
 * activation/routing-as-verified. Presence of a non-empty string is
 * `UNVERIFIED` — it is NEVER reported as healthy/available/verified.
 */
final class CredentialStatus
{
    public const VALID = 'VALID';
    public const INVALID_CREDENTIAL = 'INVALID_CREDENTIAL';
    public const EXPIRED = 'EXPIRED';
    public const REVOKED = 'REVOKED';
    public const DISABLED = 'DISABLED';
    public const INSUFFICIENT_PERMISSION = 'INSUFFICIENT_PERMISSION';
    public const QUOTA_EXCEEDED = 'QUOTA_EXCEEDED';
    public const RATE_LIMITED = 'RATE_LIMITED';
    public const PROVIDER_UNAVAILABLE = 'PROVIDER_UNAVAILABLE';
    public const REGION_RESTRICTED = 'REGION_RESTRICTED';
    public const NETWORK_ERROR = 'NETWORK_ERROR';
    public const UNKNOWN = 'UNKNOWN';
    public const UNVERIFIED = 'UNVERIFIED';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::VALID,
            self::INVALID_CREDENTIAL,
            self::EXPIRED,
            self::REVOKED,
            self::DISABLED,
            self::INSUFFICIENT_PERMISSION,
            self::QUOTA_EXCEEDED,
            self::RATE_LIMITED,
            self::PROVIDER_UNAVAILABLE,
            self::REGION_RESTRICTED,
            self::NETWORK_ERROR,
            self::UNKNOWN,
            self::UNVERIFIED,
        ];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    /** ONLY VALID is eligible for activation/routing-as-verified. */
    public static function isEligibleForActivation(string $status): bool
    {
        return $status === self::VALID;
    }

    /**
     * Statuses that definitively mean "this credential cannot authenticate /
     * is not usable" — a confirmed-invalid key MUST NOT be routed at runtime.
     *
     * Transient capacity states (RATE_LIMITED, QUOTA_EXCEEDED, PROVIDER_UNAVAILABLE,
     * NETWORK_ERROR, REGION_RESTRICTED) and NOT-YET-CHECKED states (UNKNOWN,
     * UNVERIFIED) are deliberately EXCLUDED: a transient outage or an
     * unverified key must not permanently disable a provider (that would break
     * existing fallback behaviour), whereas a key the provider explicitly
     * rejected must never be routed as a healthy credential.
     *
     * @return string[]
     */
    public static function confirmedInvalid(): array
    {
        return [
            self::INVALID_CREDENTIAL,
            self::EXPIRED,
            self::REVOKED,
            self::DISABLED,
        ];
    }


    /** States that indicate a provider-side outcome (as opposed to "not yet checked"). */
    public static function isProviderResolved(string $status): bool
    {
        return !in_array($status, [self::UNKNOWN, self::UNVERIFIED], true);
    }
}
