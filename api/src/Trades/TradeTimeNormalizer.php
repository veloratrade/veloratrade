<?php

declare(strict_types=1);

namespace Velora\Trades;

/**
 * Deterministic trade-time normalizer (Phase 2C).
 *
 * Converts trustworthy SOURCE date/time evidence into a canonical UTC instant.
 * It is a pure domain service: no DB access, no persistence, no I/O, and —
 * critically — no dependency on the PHP default timezone, the server clock,
 * the browser, the UI locale, or the user's display timezone.
 *
 * Core invariant:
 *   raw datetime + KNOWN trustworthy timezone  -> deterministic -> UTC
 *   raw datetime + UNKNOWN timezone            -> UNRESOLVED (never UTC)
 *
 * Layers are kept separate: this class consumes already-resolved evidence
 * (raw text, source calendar, source timezone / explicit offset, format hint).
 * Timezone *resolution* lives in {@see TimezoneResolver}; this class only turns
 * a resolved source datetime into a UTC instant (or an explicit non-result).
 *
 * Results:
 *   resolved   — a canonical UTC instant was established.
 *   unresolved — input exists but the instant cannot be safely established
 *                (unknown timezone/calendar, ambiguous format, DST fold).
 *   invalid    — the supplied value is malformed/impossible (bad date, DST gap).
 *
 * Legacy open_time/close_time are never touched by this class and never read as
 * authoritative UTC. Jalali is converted server-side with a deterministic
 * algorithm (no AI, no browser Intl).
 */
final class TradeTimeNormalizer
{
    public const RESOLVED = 'resolved';
    public const UNRESOLVED = 'unresolved';
    public const INVALID = 'invalid';

    /**
     * Normalize a single source datetime.
     *
     * @param string|null $rawText         Verbatim source datetime text (digits may be Persian/Arabic).
     * @param string|null $sourceCalendar  gregorian|jalali|unknown
     * @param string|null $sourceTimezone  IANA id (e.g. Europe/London) or null.
     * @param string|null $dateFormatHint  DMY|MDY|YMD|null for ambiguous numeric orders.
     */
    public function normalize(
        ?string $rawText,
        ?string $sourceCalendar = 'unknown',
        ?string $sourceTimezone = null,
        ?string $dateFormatHint = null,
        ?int $explicitOffsetMinutes = null,
    ): NormalizedTime {
        $raw = $rawText === null ? '' : trim($rawText);
        if ($raw === '') {
            return NormalizedTime::unresolved('empty_input', 'Empty source datetime.');
        }

        // 1) Normalize Unicode digits to ASCII WITHOUT mutating the stored raw text.
        $text = self::toAsciiDigits($raw);

        // 2) Detect an explicit numeric offset embedded in the value itself
        //    (e.g. 2026-08-31T14:30+03:30 / 2026-08-31 14:30 UTC / ...Z).
        //    An explicit offset is the strongest evidence and pins the instant
        //    (it also disambiguates a DST fold). It implies Gregorian/ISO.
        $explicit = $this->extractExplicitOffset($text);
        if ($explicit !== null) {
            [$yy, $mm, $dd, $hh, $iii, $ss] = $explicit['parts'];
            if (!checkdate($mm, $dd, $yy) || $hh > 23 || $iii > 59 || $ss > 59) {
                return NormalizedTime::invalid('invalid_datetime', 'Malformed explicit-offset datetime.');
            }
            try {
                // $explicit['iso'] carries the offset; PHP parses it deterministically.
                $dt = new \DateTimeImmutable($explicit['iso']);
            } catch (\Throwable) {
                return NormalizedTime::invalid('bad_offset', 'Unparseable explicit offset.');
            }
            return $this->resolved($dt->setTimezone(new \DateTimeZone('UTC')), true);
        }

        // 3) Split date and time; a trade instant needs BOTH.
        $parts = $this->splitDateAndTime($text);
        if ($parts === null) {
            return NormalizedTime::unresolved('missing_date_or_time', 'Both date and time are required.');
        }
        [$dateText, $timeText] = $parts;

        $time = $this->parseTime($timeText);
        if ($time === null) {
            return NormalizedTime::invalid('invalid_time', 'Malformed time component.');
        }
        [$h, $mi, $s] = $time;

        // 4) Calendar. Unknown + an unambiguous 4-digit year-first Gregorian form
        //    is safe (Jalali years are not 20xx); anything ambiguous stays unknown.
        $calendar = strtolower(trim((string) $sourceCalendar));
        if ($calendar === '') {
            $calendar = 'unknown';
        }

        $order = $this->detectOrder($dateText, $dateFormatHint);
        if ($order === 'ambiguous') {
            return NormalizedTime::unresolved('ambiguous_format', 'Day/month order cannot be determined.');
        }
        if ($order === null) {
            return NormalizedTime::unresolved('unparseable_date', 'Date structure not recognized.');
        }

        $components = $this->parseDate($dateText, $order);
        if ($components === null) {
            return NormalizedTime::invalid('invalid_datetime', 'Malformed date component.');
        }
        [$y, $m, $d] = $components;

        // 4b) A SEPARATE explicit numeric offset (Gemini v2 timezoneOffsetHintMinutes)
        //     is supplied out-of-band rather than embedded in the text. Like an
        //     embedded offset it implies Gregorian (no DST/transitions), and pins
        //     the instant. It does NOT establish an IANA zone.
        if ($explicitOffsetMinutes !== null) {
            if ($explicitOffsetMinutes < -720 || $explicitOffsetMinutes > 840) {
                return NormalizedTime::invalid('bad_offset', 'Numeric offset out of range.');
            }
            if ($calendar === 'jalali' && $this->validJalali($y, $m, $d)) {
                [$y, $m, $d] = $this->jalaliToGregorian($y, $m, $d);
            }
            if (!checkdate($m, $d, $y) || $h > 23 || $mi > 59 || $s > 59) {
                return NormalizedTime::invalid('invalid_datetime', 'Malformed explicit-offset datetime.');
            }
            try {
                $wall = new \DateTimeImmutable(sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $m, $d, $h, $mi, $s), new \DateTimeZone('UTC'));
                // Local = UTC + offset  =>  UTC = local - offset.
                $utc = $wall->modify((- $explicitOffsetMinutes) . ' minutes')->setTimezone(new \DateTimeZone('UTC'));
            } catch (\Throwable) {
                return NormalizedTime::invalid('bad_offset', 'Could not apply numeric offset.');
            }
            return $this->resolved($utc, true);
        }

        if ($calendar === 'jalali') {
            $jalaliCheck = $this->validJalali($y, $m, $d);
            if (!$jalaliCheck) {
                return NormalizedTime::invalid('invalid_jalali_date', 'Impossible Jalali date.');
            }
            [$y, $m, $d] = $this->jalaliToGregorian($y, $m, $d);
        } elseif ($calendar === 'unknown') {
            // Only accept year-first Gregorian (e.g. 2026-08-31). Day/month-first
            // forms with unknown calendar remain unresolved (do not guess).
            if ($order !== 'YMD') {
                return NormalizedTime::unresolved('unknown_calendar', 'Calendar cannot be determined safely.');
            }
        } elseif ($calendar !== 'gregorian') {
            return NormalizedTime::unresolved('unknown_calendar', 'Unsupported source calendar.');
        }

        // Gregorian validity check (catches 31/02 etc.).
        if (!checkdate($m, $d, $y)) {
            return NormalizedTime::invalid('invalid_datetime', 'Impossible Gregorian date.');
        }

        // 5) Timezone. Without an explicit offset we require a valid IANA zone.
        if ($sourceTimezone === null || trim($sourceTimezone) === '') {
            return NormalizedTime::unresolved('unknown_timezone', 'No trustworthy source timezone.');
        }
        if (!TimezoneResolver::isValidIana($sourceTimezone)) {
            return NormalizedTime::unresolved('unknown_timezone', 'Timezone is not a valid IANA identifier.');
        }

        try {
            $zone = new \DateTimeZone(trim($sourceTimezone));
        } catch (\Throwable) {
            return NormalizedTime::unresolved('unknown_timezone', 'Timezone rejected at construction.');
        }

        // Build the local wall-clock datetime explicitly in the SOURCE timezone.
        $wall = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $m, $d, $h, $mi, $s);
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $wall, $zone);
        if ($dt === false) {
            return NormalizedTime::invalid('invalid_datetime', 'Could not construct source datetime.');
        }

        // 6) DST GAP — a nonexistent local wall clock (spring-forward).
        //    Round-trip through the zone: PHP shifts nonexistent times forward;
        //    a shifted wall clock proves the input time does not exist.
        $back = $dt->setTimezone($zone);
        if ($back->format('Y-m-d H:i') !== sprintf('%04d-%02d-%02d %02d:%02d', $y, $m, $d, $h, $mi)) {
            return NormalizedTime::invalid('dst_gap', 'Local time does not exist (spring-forward gap).');
        }

        // 7) DST FOLD — an ambiguous local wall clock occurring twice
        //    (fall-back). Two distinct UTC instants map to this wall clock;
        //    without an explicit offset we must not pick one.
        if ($this->isFold($zone, $wall)) {
            return NormalizedTime::unresolved('dst_fold', 'Local time is ambiguous (fall-back fold).', true);
        }

        return $this->resolved($dt->setTimezone(new \DateTimeZone('UTC')), false);
    }

    private function resolved(\DateTimeImmutable $utc, bool $usedOffset): NormalizedTime
    {
        return NormalizedTime::resolved(
            $utc->format('Y-m-d H:i:s'),
            $utc->format('Y-m-d\TH:i:s\Z'),
            $usedOffset,
        );
    }

    /**
     * Detect an explicit offset/zone suffix and return normalized ISO parts.
     * Supports: trailing Z, +HH:MM/-HHMM/+HH, and a trailing UTC/GMT(+offset).
     *
     * @return array{iso:string,date:string,time:string}|null
     */
    private function extractExplicitOffset(string $text): ?array
    {
        // Match a date+time followed by an optional T/space then Z or ±offset.
        $pattern = '/(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})[ T](\d{1,2}):(\d{2})(?::(\d{2}))?\s*(Z|[+-]\d{2}:?\d{2}|UTC|GMT)\s*$/i';
        if (!preg_match($pattern, $text, $m)) {
            return null;
        }
        $y = (int) $m[1]; $mo = (int) $m[2]; $d = (int) $m[3];
        $h = (int) $m[4]; $mi = (int) $m[5]; $s = isset($m[6]) && $m[6] !== '' ? (int) $m[6] : 0;
        $off = strtoupper($m[7]);
        if ($off === 'Z' || $off === 'UTC' || $off === 'GMT') {
            $offset = '+00:00';
        } else {
            $sign = $off[0];
            $digits = preg_replace('/\D/', '', substr($off, 1));
            $oh = (int) substr($digits, 0, 2);
            $om = (int) substr($digits, 2, 2);
            $offset = $sign . str_pad((string) $oh, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) $om, 2, '0', STR_PAD_LEFT);
        }
        $date = sprintf('%04d-%02d-%02d', $y, $mo, $d);
        $time = sprintf('%02d:%02d:%02d', $h, $mi, $s);
        return [
            'iso' => $date . 'T' . $time . $offset,
            'parts' => [$y, $mo, $d, $h, $mi, $s],
        ];
    }

    /**
     * @return array{0:string,1:string}|null  [dateText, timeText]
     */
    private function splitDateAndTime(string $text): ?array
    {
        // Time = HH:MM(:SS) somewhere in the string; date = the remainder.
        if (!preg_match('/(\d{1,2}:\d{2}(?::\d{2})?)/', $text, $tm, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $timeText = $tm[1][0];
        // Remove the matched time chunk (and the ISO 'T' connector) to leave date.
        $dateText = substr_replace($text, ' ', $tm[1][1], strlen($timeText));
        $dateText = trim(strtr($dateText, ['T' => ' ']));
        $dateText = trim(preg_replace('/\s+/', ' ', $dateText) ?? '');
        if ($dateText === '') {
            return null;
        }
        return [$dateText, $timeText];
    }

    /**
     * @return array{0:int,1:int,2:int}|null [h, mi, s]
     */
    private function parseTime(string $timeText): ?array
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim($timeText), $m)) {
            return null;
        }
        $h = (int) $m[1]; $mi = (int) $m[2]; $s = isset($m[3]) ? (int) $m[3] : 0;
        if ($h > 23 || $mi > 59 || $s > 59) {
            return null;
        }
        return [$h, $mi, $s];
    }

    /**
     * Determine day/month/year ordering. Returns YMD|DMY|MDY|ambiguous|null.
     */
    private function detectOrder(string $dateText, ?string $hint): ?string
    {
        $hint = $hint !== null ? strtoupper(trim($hint)) : null;
        // Year-first: YYYY[-/.]MM[-/.]DD
        if (preg_match('/^\d{4}[-\/.]\d{1,2}[-\/.]\d{1,2}$/', $dateText)) {
            return 'YMD';
        }
        // Day/month-first: DD[-/.]MM[-/.]YYYY or MM[-/.]DD[-/.]YYYY
        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.]\d{4}$/', $dateText, $m)) {
            $a = (int) $m[1]; $b = (int) $m[2];
            if ($hint === 'DMY') {
                return 'DMY';
            }
            if ($hint === 'MDY') {
                return 'MDY';
            }
            if ($a > 12 && $b <= 12) {
                return 'DMY'; // first component cannot be a month
            }
            if ($b > 12 && $a <= 12) {
                return 'MDY'; // second component cannot be a month
            }
            if ($a <= 12 && $b <= 12) {
                return 'ambiguous';
            }
        }
        return null;
    }

    /**
     * @return array{0:int,1:int,2:int}|null [y,m,d]
     */
    private function parseDate(string $dateText, string $order): ?array
    {
        $nums = array_map('intval', preg_split('/[-\/.]/', $dateText) ?: []);
        if (count($nums) !== 3) {
            return null;
        }
        return match ($order) {
            'YMD' => [$nums[0], $nums[1], $nums[2]],
            'DMY' => [$nums[2], $nums[1], $nums[0]],
            'MDY' => [$nums[2], $nums[0], $nums[1]],
            default => null,
        };
    }

    /**
     * DST fold test: more than one distinct UTC instant renders this wall clock.
     * Scans a bounded window of offsets (no hand-maintained DST rules).
     */
    private function isFold(\DateTimeZone $zone, string $wall): bool
    {
        // Wall day anchor, scan instants across a wide enough offset range.
        $dayStart = substr($wall, 0, 10) . ' 00:00:00';
        $base = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dayStart, new \DateTimeZone('UTC'));
        if ($base === false) {
            return false;
        }
        $target = substr($wall, 0, 16); // Y-m-d H:i
        $distinct = [];
        for ($minutes = -300; $minutes <= 780; $minutes++) {
            $candidate = $base->modify($minutes . ' minutes')->setTimezone($zone);
            if ($candidate->format('Y-m-d H:i') === $target) {
                $distinct[$candidate->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')] = true;
                if (count($distinct) > 1) {
                    return true;
                }
            }
        }
        return false;
    }

    // ---------------------------------------------------------------------
    // Digits + Jalali conversion (deterministic, dependency-free).
    // ---------------------------------------------------------------------

    public static function toAsciiDigits(string $s): string
    {
        $persian = ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
                    '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'];
        $arabic = ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
                   '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'];
        return strtr($s, $persian + $arabic);
    }

    /**
     * Jalali (Solar Hijri) -> Gregorian. Deterministic 33-year-cycle algorithm.
     * Inputs are Jalali year/month/day; returns [gy, gm, gd].
     *
     * @return array{0:int,1:int,2:int}
     */
    public static function jalaliToGregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;
        $days = -355668
            + (365 * $jy)
            + intdiv($jy, 33) * 8
            + intdiv(($jy % 33) + 3, 4)
            + $jd
            + ($jm < 7 ? ($jm - 1) * 31 : ($jm - 7) * 30 + 186);
        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;
        if ($days > 36524) {
            $gy += 100 * intdiv($days - 1, 36524);
            $days = ($days - 1) % 36524;
        }
        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        $gd = $days + 1;
        $salA = [31, self::isGregorianLeap($gy) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $gm = 0;
        foreach ($salA as $dim) {
            $gm++;
            if ($gd <= $dim) {
                break;
            }
            $gd -= $dim;
        }
        return [$gy, $gm, $gd];
    }

    private static function isGregorianLeap(int $y): bool
    {
        return ($y % 4 === 0 && $y % 100 !== 0) || ($y % 400 === 0);
    }

    private function validJalali(int $y, int $m, int $d): bool
    {
        if ($m < 1 || $m > 12 || $d < 1 || $y < 1) {
            return false;
        }
        if ($m <= 6) {
            return $d <= 31;
        }
        if ($m <= 11) {
            return $d <= 30;
        }
        // Month 12 has 30 days in a leap year, 29 otherwise.
        return $d <= (self::isJalaliLeap($y) ? 30 : 29);
    }

    public static function isJalaliLeap(int $jy): bool
    {
        // Leap years in the 33-year cycle: years where (jy mod 33) is in the
        // set {1,5,9,13,17,22,26,30}.
        $r = ((($jy - 474) % 33) + 33) % 33;
        return $r === 1 || $r === 5 || $r === 9 || $r === 13 || $r === 17 || $r === 22 || $r === 26 || $r === 30;
    }
}
