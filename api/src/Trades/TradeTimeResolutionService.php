<?php

declare(strict_types=1);

namespace Velora\Trades;

/**
 * Phase 2E — trade open/close canonical-time resolution.
 *
 * Deterministically connects DATETIME EVIDENCE (screenshot v2 raw text,
 * account timezone, import metadata) to the canonical UTC columns, using the
 * two already-built, audited components:
 *
 *   - TimezoneResolver   decides WHICH timezone (if any) is trustworthy, and
 *                        its provenance. It never fabricates a zone.
 *   - TradeTimeNormalizer converts a wall-clock + source calendar + (zone or
 *                        explicit offset) into a canonical UTC instant, or a
 *                        definite unresolved/invalid verdict. It owns all
 *                        calendar conversion and DST rules.
 *
 * This service does NO date parsing, calendar conversion, DST math, or
 * timezone inference of its own. It only:
 *   1. gathers trusted timezone evidence (explicit source > verified broker
 *      metadata > account config > import config),
 *   2. feeds raw text + calendar + format hint + (zone|separate offset) to
 *      the normalizer independently for open and close,
 *   3. packages the result into canonical/truth columns.
 *
 * HARD GUARANTEES
 *   - The AI model is NEVER a canonical-time authority: only raw text +
 *     trustworthy tz evidence produce a UTC instant.
 *   - Unresolved stays NULL; we never fall back to PHP default / server /
 *     browser / UTC. open & close are resolved INDEPENDENTLY (a resolved open
 *     never fabricates a close).
 *   - Invalid input is a hard error (INVALID_DATETIME), never downgraded to
 *     unresolved.
 *   - Bare timezone abbreviations ("EST"), broker names, symbols, sessions,
 *     clock face, UI locale and users.timezone are NEVER timezone sources.
 *
 * Pure service: no DB, no globals, no strtotime/gmdate/date_default_timezone.
 */
final class TradeTimeResolutionService
{
    public function __construct(
        private readonly TradeTimeNormalizer $normalizer = new TradeTimeNormalizer(),
    ) {
    }

    /**
     * Resolve one trade's open and close times.
     *
     * Evidence keys (all optional; all are SOURCE evidence, never AI truth):
     *   rawOpenText, rawCloseText       verbatim wall-clock strings (PRIMARY)
     *   sourceCalendar                  gregorian|jalali|unknown
     *   dateFormat                      DD/MM/YYYY|MM/DD/YYYY|YYYY/MM/DD|YYYY-MM-DD|unknown
     *   timezoneText                    verbatim tz text from the source image
     *   timezoneOffsetHintMinutes       explicit numeric offset (separate), or null
     *   explicitIana                    caller-asserted trusted IANA (import/account)
     *   explicitIanaSource              provenance code for explicitIana
     *   brokerTimezone                  verified broker-metadata IANA (or null)
     *   accountTimezone                 trading_accounts.timezone IANA (or null)
     *   accountTimezoneSource           its source (user_config|broker_metadata|...)
     *   importTimezone                  import-config IANA (or null)
     *
     * @param array<string,mixed> $evidence
     * @return array{
     *   occurred_open_at_utc:?string, occurred_close_at_utc:?string,
     *   time_status:string, source_timezone:?string, source_timezone_source:string,
     *   source_calendar:string, raw_open_text:?string, raw_close_text:?string,
     *   open_reason:?string, close_reason:?string, open_valid:bool, close_valid:bool
     * }
     */
    public function resolve(array $evidence): array
    {
        $rawOpen = $this->text($evidence['rawOpenText'] ?? null);
        $rawClose = $this->text($evidence['rawCloseText'] ?? null);
        $calendar = $this->vocab(
            $evidence['sourceCalendar'] ?? null,
            ['gregorian', 'jalali', 'unknown'],
            'unknown',
        );
        $dateFormat = $this->vocab(
            $evidence['dateFormat'] ?? null,
            ['DD/MM/YYYY', 'MM/DD/YYYY', 'YYYY/MM/DD', 'YYYY-MM-DD', 'unknown'],
            'unknown',
        );
        $timezoneText = $this->text($evidence['timezoneText'] ?? null);
        $offset = $evidence['timezoneOffsetHintMinutes'] ?? null;
        $offset = (is_int($offset) || (is_string($offset) && preg_match('/\A-?\d+\z/D', $offset)))
            ? (int) $offset : null;
        if ($offset !== null && ($offset < -720 || $offset > 840)) {
            $offset = null; // garbage hint -> no offset evidence
        }

        // --- Decide the trustworthy timezone via the EXISTING resolver. ---
        $tz = $this->resolveTimezone($evidence);
        $zone = $tz['timezone'];              // IANA or null
        $zoneSource = $tz['source'];          // provenance code
        $ambiguous = $tz['ambiguous'];        // resolver confidence ambiguity

        $open = $this->resolveSide($rawOpen, $calendar, $dateFormat, $zone, $zoneSource, $offset, $timezoneText, $ambiguous);
        $close = $this->resolveSide($rawClose, $calendar, $dateFormat, $zone, $zoneSource, $offset, $timezoneText, $ambiguous);

        // Status is the "best" of the two sides; open/close stay independent.
        $status = 'unresolved';
        if ($open['status'] === 'resolved' || $close['status'] === 'resolved') {
            $status = ($open['status'] === 'resolved' && $close['status'] === 'resolved')
                ? 'resolved' : 'partial';
        }

        return [
            'occurred_open_at_utc' => $open['utc'],
            'occurred_close_at_utc' => $close['utc'],
            'time_status' => $status,
            // The zone used (null when only an offset or nothing applied).
            'source_timezone' => $zone,
            'source_timezone_source' => $zoneSource,
            'source_calendar' => $calendar,
            'raw_open_text' => $rawOpen,
            'raw_close_text' => $rawClose,
            'open_reason' => $open['reason'],
            'close_reason' => $close['reason'],
            'open_valid' => $open['status'] !== 'invalid',
            'close_valid' => $close['status'] !== 'invalid',
        ];
    }

    /**
     * Resolve a single side (open or close). Returns
     * [status: resolved|unresolved|invalid, utc: ?string, reason: ?string].
     */
    private function resolveSide(
        ?string $raw,
        string $calendar,
        string $dateFormat,
        ?string $zone,
        string $zoneSource,
        ?int $offset,
        ?string $timezoneText,
        bool $ambiguousZone,
    ): array {
        if ($raw === null) {
            return ['status' => 'unresolved', 'utc' => null, 'reason' => 'empty_input'];
        }

        // A separate explicit offset resolves WITHOUT an IANA zone. The
        // normalizer treats offset as authoritative for the instant.
        $result = $this->normalizer->normalize(
            $raw,
            $calendar,
            $zone,
            $dateFormat,
            $offset,
        );

        if ($result === null) {
            return ['status' => 'unresolved', 'utc' => null, 'reason' => 'unparseable'];
        }

        if ($result->isResolved()) {
            return ['status' => 'resolved', 'utc' => $result->canonicalUtc, 'reason' => null];
        }
        if ($result->status === TradeTimeNormalizer::INVALID) {
            return ['status' => 'invalid', 'utc' => null, 'reason' => $result->reason];
        }

        // Unresolved. Record the most meaningful reason.
        $reason = $result->reason;
        if ($result->reason === 'unknown_timezone' && $ambiguousZone) {
            $reason = 'ambiguous_timezone';
        }
        // Surface that a bare tz abbreviation (e.g. EST) was NOT trusted.
        if ($result->reason === 'unknown_timezone' && $zone === null && $timezoneText !== null) {
            $reason = 'untrusted_timezone_text';
        }
        return ['status' => 'unresolved', 'utc' => null, 'reason' => $reason];
    }

    /**
     * Build resolver evidence and map the verdict to a provenance code.
     *
     * @param array<string,mixed> $e
     * @return array{timezone:?string,source:string,ambiguous:bool}
     */
    private function resolveTimezone(array $e): array
    {
        $explicit = $this->text($e['explicitIana'] ?? null);
        $broker = $this->text($e['brokerTimezone'] ?? null);
        $account = $this->text($e['accountTimezone'] ?? null);
        $import = $this->text($e['importTimezone'] ?? null);

        $evidence = [
            'explicitSource' => $explicit,
            'brokerMetadata' => $broker,
            'accountConfig' => $account,
            'importConfig' => $import,
        ];

        $verdict = (new TimezoneResolver())->resolve($evidence);

        $zone = is_string($verdict['timezone'] ?? null) && $verdict['timezone'] !== ''
            ? $verdict['timezone'] : null;

        // Resolver already returns canonical source codes (explicit_source,
        // broker_metadata, account_config, import_config, inferred_pending,
        // unknown). Normalize inferred_pending -> unknown (not trustworthy
        // without confirmation) and pass account provenance through.
        $resolvedSource = (string) ($verdict['source'] ?? 'unknown');
        if ($resolvedSource === TimezoneResolver::SOURCE_INFERRED_PENDING) {
            $resolvedSource = 'unknown';
        }
        if ($resolvedSource === TimezoneResolver::SOURCE_EXPLICIT) {
            $resolvedSource = $this->provenance($e['explicitIanaSource'] ?? null, TimezoneResolver::SOURCE_EXPLICIT);
        }
        if ($resolvedSource === TimezoneResolver::SOURCE_ACCOUNT_CONFIG) {
            // Resolve WHICH trust level the account zone itself came from.
            // The resolver only trusts it as account_config; if the account
            // recorded it as verified broker metadata, reflect that provenance.
            $acctSrc = $this->text($e['accountTimezoneSource'] ?? null);
            if ($acctSrc === 'broker_metadata' || $acctSrc === 'user_config') {
                $resolvedSource = $acctSrc;
            }
        }

        return [
            'timezone' => $zone,
            'source' => $zone === null ? 'unknown' : $resolvedSource,
            'ambiguous' => (bool) ($verdict['ambiguous'] ?? false),
        ];
    }

    /**
     * Extract evidence keys from a mixed request payload, preserving null
     * semantics. AI-provided openTime/closeTime are deliberately NOT read.
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public static function evidenceFromInput(array $raw): array
    {
        return [
            'rawOpenText' => $raw['rawOpenText'] ?? null,
            'rawCloseText' => $raw['rawCloseText'] ?? null,
            'sourceCalendar' => $raw['sourceCalendar'] ?? null,
            'dateFormat' => $raw['dateFormat'] ?? null,
            'timezoneText' => $raw['timezoneText'] ?? null,
            'timezoneOffsetHintMinutes' => $raw['timezoneOffsetHintMinutes'] ?? null,
            'explicitIana' => $raw['explicitTimezone'] ?? $raw['explicitIana'] ?? null,
            'explicitIanaSource' => $raw['explicitTimezoneSource'] ?? null,
            'brokerTimezone' => $raw['brokerTimezone'] ?? null,
            'importTimezone' => $raw['importTimezone'] ?? null,
        ];
    }

    private function provenance(mixed $value, string $fallback): string
    {
        $allowed = ['explicit_source', 'broker_metadata', 'account_config', 'import_config', 'user_config', 'inferred', 'unknown'];
        $v = $this->text($value);
        return ($v !== null && in_array($v, $allowed, true)) ? $v : $fallback;
    }

    private function text(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);
        return $v === '' ? null : $v;
    }

    private function vocab(mixed $v, array $allowed, string $fallback): string
    {
        $v = $this->text($v);
        return ($v !== null && in_array($v, $allowed, true)) ? $v : $fallback;
    }
}
