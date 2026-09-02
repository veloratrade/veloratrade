<?php

declare(strict_types=1);

namespace Velora\Trades;

/**
 * Phase 4 — MetaApi absolute-instant resolver.
 *
 * Converts a MetaApi timestamp into a canonical UTC instant ONLY when the
 * value is an absolute, offset-explicit instant. This is the one MetaApi time
 * surface the Phase 2E verification proved trustworthy:
 *
 *   MetatraderDeal.time / MetatraderPosition.time / MetatraderOrder.time /
 *   MetatraderOrder.doneTime / ServerTime.time  — all are ISO-8601 UTC strings
 *   carrying a trailing "Z" or an explicit numeric offset (e.g.
 *   "2020-04-20T05:30:04.361Z").
 *
 * HARD GUARANTEES (identical contract to the canonical architecture):
 *   - A value is accepted ONLY if it carries an explicit designator: "Z" or a
 *     numeric +/-HH:MM offset. It is then parsed deterministically to UTC.
 *   - A NAIVE value ("2020-04-20 08:30:04.361" — a brokerTime, or a MetaStats
 *     openTime/closeTime) is NEVER parsed with a default timezone. It returns
 *     unresolved() with reason "naive_no_offset". PHP's default timezone, the
 *     server clock, and any guessed zone are never consulted for it.
 *   - No IANA zone is inferred or stored. MetaApi exposes no durable broker
 *     timezone; an instant needs none. source timezone stays NULL for MetaApi
 *     rows (provenance = metaapi_instant).
 *   - Pure service: no DB, no globals, no strtotime()/gmdate()/
 *     date_default_timezone_set(). Parsing uses DateTimeImmutable, which honors
 *     the explicit offset in the string itself — the result is independent of
 *     the PHP default timezone (proven in tests under UTC/London/NY/Tehran).
 *   - Malformed/impossible values are invalid (hard signal), never downgraded
 *     to a fabricated instant.
 *
 * DST is a non-issue by construction: an offset-explicit instant fully
 * specifies the moment; no local wall clock is interpreted, so no fold/gap can
 * occur. brokerTime (naive) is rejected, never DST-guessed.
 */
final class MetaApiInstantResolver
{
    /**
     * Provenance recorded on canonical columns for instant-sourced rows.
     * Stored in trades.source_timezone_source (VARCHAR(20)); length safe.
     */
    public const PROVENANCE = 'metaapi_instant';

    /**
     * A timestamp carries an explicit UTC designator iff it ends with "Z" or a
     * numeric offset "+HH:MM" / "-HH:MM" (optional seconds/millis handled by
     * the parser). Everything else is treated as naive (no absolute instant).
     */
    private const OFFSET_PATTERN = '/(Z|[+-]\d{2}:\d{2})$/i';

    /**
     * Resolve a MetaApi timestamp to a canonical UTC instant.
     *
     * @return NormalizedTime resolved -> canonicalUtc/iso8601 populated;
     *                         unresolved -> naive/empty input (reason set);
     *                         invalid    -> malformed/impossible (reason set).
     */
    public function resolve(mixed $value): NormalizedTime
    {
        if (!is_string($value)) {
            return NormalizedTime::unresolved('empty_input', 'Timestamp is not a string.');
        }
        $raw = trim($value);
        if ($raw === '' || strlen($raw) > 64 || preg_match('/[\x00-\x1F\x7F]/', $raw) === 1) {
            return NormalizedTime::unresolved('empty_input', 'Timestamp empty or contains control characters.');
        }

        // Strict gate: an absolute instant MUST be offset-explicit.
        if (preg_match(self::OFFSET_PATTERN, $raw) !== 1) {
            // Naive broker wall clock (brokerTime) / MetaStats time: absolute
            // instant unknown. Never apply a default or guessed timezone.
            return NormalizedTime::unresolved('naive_no_offset', 'Timestamp has no Z/offset; absolute instant is not known.');
        }

        $dt = $this->parseInstant($raw);
        if ($dt === null) {
            return NormalizedTime::invalid('invalid_datetime', 'Offset-explicit timestamp could not be parsed.');
        }

        $utc = $dt->setTimezone(new \DateTimeZone('UTC'));
        return NormalizedTime::resolved(
            $utc->format('Y-m-d H:i:s'),
            $utc->format('Y-m-d\TH:i:s\Z'),
            true, // usedExplicitOffset
        );
    }

    /**
     * Parse strictly, refusing PHP's relative/native fallbacks that could mask
     * garbage. Returns null on any malformed or impossible value.
     */
    private function parseInstant(string $raw): ?\DateTimeImmutable
    {
        // Require a full date+time skeleton before accepting an offset.
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{1,2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:\d{2})$/i', $raw) !== 1) {
            return null;
        }
        try {
            $dt = new \DateTimeImmutable($raw);
        } catch (\Throwable) {
            return null;
        }
        // Defensive: the parsed value must carry a real offset (never float).
        $warnings = \DateTimeImmutable::getLastErrors();
        if (is_array($warnings) && ($warnings['warning_count'] ?? 0) > 0) {
            return null;
        }
        return $dt;
    }

    /**
     * Convenience: resolve and return canonical "Y-m-d H:i:s" or null for any
     * non-resolved state (caller decides whether null means skip or partial).
     */
    public function canonicalUtc(mixed $value): ?string
    {
        $r = $this->resolve($value);
        return $r->isResolved() ? $r->canonicalUtc : null;
    }
}
