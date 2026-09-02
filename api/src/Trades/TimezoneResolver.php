<?php

declare(strict_types=1);

namespace Velora\Trades;

/**
 * Deterministic timezone resolution foundation (Phase 2B).
 *
 * Establishes the SOURCE timezone of a trade/account from evidence with a fixed
 * trust ordering. It NEVER derives a timezone from UI language, browser
 * timezone, server timezone, IP/geolocation, the bare clock value, or a broker
 * name alone. AI output is never authoritative: it may only supply an explicit
 * label that is validated as a real IANA zone.
 *
 * This phase implements evidence collection + priority + conflict handling.
 * Some sources (broker_metadata) are represented but only accepted when already
 * verified/populated by the caller — nothing is guessed here. When no
 * trustworthy source exists the resolver returns the first-class UNKNOWN state;
 * it never invents UTC.
 *
 * Trust priority (highest first):
 *   1. explicit_source   — a validated IANA/offset label visible in the source
 *   2. broker_metadata   — verified broker/account/API timezone
 *   3. account_config    — trading_accounts.timezone (user-configured)
 *   4. import_config     — explicit screenshot/import default
 *   5. inferred_pending  — strong inference, NEVER auto-trusted (surfaced only)
 *   6. unknown           — no trustworthy timezone
 */
final class TimezoneResolver
{
    public const SOURCE_EXPLICIT = 'explicit_source';
    public const SOURCE_BROKER_METADATA = 'broker_metadata';
    public const SOURCE_ACCOUNT_CONFIG = 'account_config';
    public const SOURCE_IMPORT_CONFIG = 'import_config';
    public const SOURCE_INFERRED_PENDING = 'inferred_pending';
    public const SOURCE_UNKNOWN = 'unknown';

    /**
     * Ordered sources that may be trusted to establish a timezone.
     * inferred_pending is intentionally NOT trusted (never returned as resolved).
     */
    private const TRUSTED_ORDER = [
        self::SOURCE_EXPLICIT,
        self::SOURCE_BROKER_METADATA,
        self::SOURCE_ACCOUNT_CONFIG,
        self::SOURCE_IMPORT_CONFIG,
    ];

    /**
     * Validate an IANA timezone identifier using the runtime tz database.
     * Accepts canonical identifiers (and the special case "UTC"). Rejects
     * display labels ("London"), fixed offsets ("GMT+3", "+03:30"), and
     * browser/server values.
     */
    public static function isValidIana(mixed $timezone): bool
    {
        if (!is_string($timezone)) {
            return false;
        }
        $timezone = trim($timezone);
        if ($timezone === '' || strlen($timezone) > 64) {
            return false;
        }
        // Fixed offsets and abbreviations are not IANA zones.
        if (preg_match('/(?:GMT|UTC)?\s*[+-]\d{1,2}(?::?\d{2})?/i', $timezone) === 1) {
            return false;
        }
        if (!preg_match('/\A[A-Za-z]+(?:[A-Za-z_+\-\/]+)*\z/', $timezone)) {
            return false;
        }
        try {
            // DateTimeZone with a strict IANA id throws for unknown zones; a
            // constructed zone whose name round-trips is a valid database id.
            new \DateTimeZone($timezone);
        } catch (\Throwable) {
            return false;
        }
        $identifiers = \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC);
        return in_array($timezone, $identifiers, true);
    }

    /**
     * Resolve a timezone from an evidence bundle.
     *
     * Expected evidence keys (all optional; each value is a timezone string or
     * null). Keys map to sources:
     *   explicitSource  -> explicit_source   (validated IANA label from image)
     *   brokerMetadata  -> broker_metadata   (only when verified upstream)
     *   accountConfig   -> account_config    (trading_accounts.timezone)
     *   importConfig    -> import_config     (explicit import default)
     *   inferred        -> inferred_pending  (NEVER auto-trusted)
     *
     * Conflict rule: among sources at the SAME trust level that disagree, no
     * value is picked arbitrarily — the result degrades to unknown (ambiguous).
     *
     * @param array<string,mixed> $evidence
     * @return array{timezone:?string, source:string, confidence:string, ambiguous:bool}
     */
    public function resolve(array $evidence): array
    {
        // Group valid candidate timezones by trust level.
        /** @var array<string,string[]> $byLevel */
        $byLevel = [];

        $this->collect($byLevel, self::SOURCE_EXPLICIT, $evidence['explicitSource'] ?? null);
        $this->collect($byLevel, self::SOURCE_BROKER_METADATA, $evidence['brokerMetadata'] ?? null);
        $this->collect($byLevel, self::SOURCE_ACCOUNT_CONFIG, $evidence['accountConfig'] ?? null);
        $this->collect($byLevel, self::SOURCE_IMPORT_CONFIG, $evidence['importConfig'] ?? null);
        // inferred_pending is deliberately not collected as a trusted candidate.

        foreach (self::TRUSTED_ORDER as $level) {
            $zones = $byLevel[$level] ?? [];
            if ($zones === []) {
                continue;
            }
            $unique = array_values(array_unique($zones));
            if (count($unique) > 1) {
                // Same-priority sources disagree -> ambiguous, do not guess.
                return $this->unknown(true);
            }
            return [
                'timezone' => $unique[0],
                'source' => $level,
                'confidence' => $this->confidenceFor($level),
                'ambiguous' => false,
            ];
        }

        // No trusted evidence. An inference exists but is never auto-promoted.
        return $this->unknown(false);
    }

    /**
     * @param array<string,string[]> $byLevel
     */
    private function collect(array &$byLevel, string $source, mixed $value): void
    {
        $candidates = is_array($value) ? $value : [$value];
        foreach ($candidates as $candidate) {
            if (self::isValidIana($candidate)) {
                $byLevel[$source][] = trim((string) $candidate);
            }
        }
    }

    private function confidenceFor(string $source): string
    {
        return match ($source) {
            self::SOURCE_EXPLICIT => 'explicit',
            self::SOURCE_BROKER_METADATA => 'verified',
            self::SOURCE_ACCOUNT_CONFIG, self::SOURCE_IMPORT_CONFIG => 'configured',
            default => 'unknown',
        };
    }

    /**
     * @return array{timezone:null, source:string, confidence:string, ambiguous:bool}
     */
    private function unknown(bool $ambiguous): array
    {
        return [
            'timezone' => null,
            'source' => self::SOURCE_UNKNOWN,
            'confidence' => 'unknown',
            'ambiguous' => $ambiguous,
        ];
    }
}
