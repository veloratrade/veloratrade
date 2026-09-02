<?php

declare(strict_types=1);

namespace Velora\Trades;

/**
 * Phase 3 — timezone/calendar-aware DISPLAY layer for canonical trade times.
 *
 * This service only FORMATS an already-established canonical UTC instant for
 * display. It never derives, infers, or writes back an instant:
 *   - input MUST be canonical UTC (occurred_*_at_utc); it never touches
 *     legacy open_time/close_time as a source of truth,
 *   - the display timezone is EXPLICIT (users.timezone is a display
 *     preference and is the only timezone allowed here — it is never used to
 *     resolve when a trade occurred),
 *   - the display calendar is EXPLICIT (gregorian|jalali) and is independent
 *     of UI language (a Persian UI does not force Jalali and vice-versa).
 *
 * Pure: no strtotime(), gmdate(), date_default_timezone_set(), no DB, no
 * globals. It uses DateTimeImmutable/DateTimeZone with explicit zones, so it
 * is timezone/DST-independent of the PHP default tz.
 *
 * Output is numeric components (Latin digits) so the frontend owns locale
 * digit shaping (Persian/Arabic-Indic digits) via Intl — the backend never
 * guesses digits from language.
 */
final class TradeDisplayService
{
    public const CAL_GREGORIAN = 'gregorian';
    public const CAL_JALALI = 'jalali';

    /**
     * Format a canonical UTC instant for an explicit display timezone/calendar.
     *
     * @param string|null $canonicalUtc 'Y-m-d H:i:s' in canonical UTC, or null.
     * @param string      $displayTz    IANA zone for display (users.timezone).
     * @param string      $calendar     gregorian|jalali.
     * @return array{
     *   available:bool, status:string, calendar:string, timezone:string,
     *   iso8601:?string, date:?string, time:?string,
     *   year:?int, month:?int, day:?int, hour:?int, minute:?int, weekday:?int
     * }
     */
    public function formatInstant(?string $canonicalUtc, string $displayTz = 'UTC', string $calendar = self::CAL_GREGORIAN): array
    {
        $unavailable = [
            'available' => false, 'status' => 'unresolved', 'calendar' => $calendar === self::CAL_JALALI ? self::CAL_JALALI : self::CAL_GREGORIAN,
            'timezone' => $displayTz, 'iso8601' => null, 'date' => null, 'time' => null,
            'year' => null, 'month' => null, 'day' => null, 'hour' => null, 'minute' => null, 'weekday' => null,
        ];

        if ($canonicalUtc === null || trim($canonicalUtc) === '') {
            return $unavailable;
        }
        if (!TimezoneResolver::isValidIana($displayTz)) {
            // Never fall back to PHP default; an unknown display tz is an error.
            $unavailable['status'] = 'invalid_timezone';
            return $unavailable;
        }
        $cal = $calendar === self::CAL_JALALI ? self::CAL_JALALI : self::CAL_GREGORIAN;

        $utc = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', trim($canonicalUtc), new \DateTimeZone('UTC'));
        if ($utc === false) {
            $unavailable['status'] = 'invalid';
            return $unavailable;
        }

        $local = $utc->setTimezone(new \DateTimeZone($displayTz));
        $gy = (int) $local->format('Y');
        $gm = (int) $local->format('n');
        $gd = (int) $local->format('j');
        $hour = (int) $local->format('G');
        $minute = (int) $local->format('i');
        $weekday = (int) $local->format('N');

        if ($cal === self::CAL_JALALI) {
            [$jy, $jm, $jd] = JalaliCalendar::gregorianToJalali($gy, $gm, $gd);
            $date = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
            return [
                'available' => true, 'status' => 'ok', 'calendar' => self::CAL_JALALI, 'timezone' => $displayTz,
                'iso8601' => $utc->format('Y-m-d\TH:i:s\Z'),
                'date' => $date, 'time' => sprintf('%02d:%02d', $hour, $minute),
                'year' => $jy, 'month' => $jm, 'day' => $jd, 'hour' => $hour, 'minute' => $minute, 'weekday' => $weekday,
            ];
        }

        return [
            'available' => true, 'status' => 'ok', 'calendar' => self::CAL_GREGORIAN, 'timezone' => $displayTz,
            'iso8601' => $utc->format('Y-m-d\TH:i:s\Z'),
            'date' => sprintf('%04d-%02d-%02d', $gy, $gm, $gd),
            'time' => sprintf('%02d:%02d', $hour, $minute),
            'year' => $gy, 'month' => $gm, 'day' => $gd, 'hour' => $hour, 'minute' => $minute, 'weekday' => $weekday,
        ];
    }

    /**
     * Build the full display block for one trade row. Canonical instants drive
     * display; legacy open_time/close_time are exposed only as an explicit
     * fallback flag (never reinterpreted as UTC).
     *
     * @param array<string,mixed> $trade
     * @return array<string,mixed>
     */
    public function forTrade(array $trade, string $displayTz = 'UTC', string $calendar = self::CAL_GREGORIAN): array
    {
        $open = $this->formatInstant($trade['occurred_open_at_utc'] ?? null, $displayTz, $calendar);
        $close = $this->formatInstant($trade['occurred_close_at_utc'] ?? null, $displayTz, $calendar);
        return [
            'open' => $open,
            'close' => $close,
            'timeStatus' => $trade['time_status'] ?? 'unresolved',
            // When canonical is unavailable the UI must fall back to legacy raw
            // fields and flag them as such — it must NOT pretend they are UTC.
            'canonicalAvailable' => ($open['available'] || $close['available']),
            'legacyFallback' => [
                'openTime' => $trade['open_time'] ?? null,
                'closeTime' => $trade['close_time'] ?? null,
                'note' => 'legacy wall-clock, original timezone unknown',
            ],
        ];
    }
}
