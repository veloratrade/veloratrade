<?php

declare(strict_types=1);

namespace Velora\Trades;

/**
 * Immutable result of {@see TradeTimeNormalizer::normalize()}.
 *
 * Three mutually exclusive states:
 *   status='resolved'   — canonicalUtc/iso8601 populated (true UTC instant).
 *   status='unresolved' — input exists but the instant is not safely known
 *                         (unknown tz/calendar, ambiguous format, DST fold).
 *                         canonicalUtc/iso8601 are null. Never a fabricated UTC.
 *   status='invalid'    — malformed/impossible value (bad date, DST gap).
 *                         canonicalUtc/iso8601 are null.
 *
 * $reason is a machine-readable code; $ambiguous marks DST fold ambiguity.
 */
final class NormalizedTime
{
    /**
     * @param 'resolved'|'unresolved'|'invalid' $status
     */
    private function __construct(
        public readonly string $status,
        public readonly ?string $canonicalUtc,
        public readonly ?string $iso8601,
        public readonly ?string $reason = null,
        public readonly bool $ambiguous = false,
        public readonly bool $usedExplicitOffset = false,
    ) {
    }

    public static function resolved(string $canonicalUtc, string $iso8601, bool $usedExplicitOffset = false): self
    {
        return new self(
            TradeTimeNormalizer::RESOLVED,
            $canonicalUtc,
            $iso8601,
            null,
            false,
            $usedExplicitOffset,
        );
    }

    public static function unresolved(string $reason, string $detail = '', bool $ambiguous = false): self
    {
        return new self(TradeTimeNormalizer::UNRESOLVED, null, null, $reason, $ambiguous, false);
    }

    public static function invalid(string $reason, string $detail = ''): self
    {
        return new self(TradeTimeNormalizer::INVALID, null, null, $reason, false, false);
    }

    public function isResolved(): bool
    {
        return $this->status === TradeTimeNormalizer::RESOLVED;
    }
}
