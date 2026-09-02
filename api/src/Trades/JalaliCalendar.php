<?php

declare(strict_types=1);

namespace Velora\Trades;

/**
 * Phase 3 — deterministic Gregorian <-> Jalali (Solar Hijri) conversion.
 *
 * DISPLAY/HELPER ONLY. This class never participates in canonical-time
 * resolution: it converts an ALREADY-established UTC instant to a display
 * calendar, or provides calendar primitives. The source-calendar conversion
 * for canonicalization lives in TradeTimeNormalizer (jalali -> gregorian).
 *
 * Pure: no DB, no globals, no strtotime/gmdate/date_default_timezone. It
 * operates on integer date components, so it is timezone-independent.
 *
 * The 33-year intercalation cycle matches TradeTimeNormalizer so round-trips
 * are stable for the supported date range (1925..2124 / jy 1304..1503).
 */
final class JalaliCalendar
{
    /**
     * Gregorian (proleptic) -> Jalali. Returns [jy, jm, jd].
     *
     * @return array{0:int,1:int,2:int}
     */
    public static function gregorianToJalali(int $gy, int $gm, int $gd): array
    {
        // Proleptic Gregorian -> Jalali (Solar Hijri), day-count basis shared
        // with jalaliToGregorian() in TradeTimeNormalizer so the pair round-
        // trips. Uses 0-based components internally.
        $gDaysInMonth = [31, self::isGregorianLeap($gy) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

        $gy2 = $gy - 1600;
        $gm2 = $gm - 1;
        $gd2 = $gd - 1;

        $gDayNo = 365 * $gy2
            + intdiv($gy2 + 3, 4)
            - intdiv($gy2 + 99, 100)
            + intdiv($gy2 + 399, 400);
        for ($i = 0; $i < $gm2; $i++) {
            $gDayNo += $gDaysInMonth[$i];
        }
        $gDayNo += $gd2;

        // Jalali epoch 0979/01/01 corresponds to gDayNo of 79 (1600/01/1 frame).
        $jDayNo = $gDayNo - 79;

        $jNp = intdiv($jDayNo, 12053);
        $jDayNo %= 12053;

        $jy = 979 + 33 * $jNp + 4 * intdiv($jDayNo, 1461);
        $jDayNo %= 1461;

        if ($jDayNo >= 366) {
            $jy += intdiv($jDayNo - 1, 365);
            $jDayNo = ($jDayNo - 1) % 365;
        }

        // $jDayNo is now the 0-based day of the Jalali year.
        if ($jDayNo < 186) {
            $jm = 1 + intdiv($jDayNo, 31);
            $jd = 1 + ($jDayNo % 31);
        } else {
            $rest = $jDayNo - 186;
            $jm = 7 + intdiv($rest, 30);
            $jd = 1 + ($rest % 30);
        }

        return [$jy, $jm, $jd];
    }

    /**
     * Jalali -> Gregorian. Delegates to the single algorithm already owned by
     * the normalizer (no duplicate math): returns [gy, gm, gd].
     *
     * @return array{0:int,1:int,2:int}
     */
    public static function jalaliToGregorian(int $jy, int $jm, int $jd): array
    {
        return TradeTimeNormalizer::jalaliToGregorian($jy, $jm, $jd);
    }

    public static function isJalaliLeap(int $jy): bool
    {
        return TradeTimeNormalizer::isJalaliLeap($jy);
    }

    public static function isGregorianLeap(int $gy): bool
    {
        return ($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0);
    }

    /**
     * Format a set of Gregorian date components as a Jalali YYYY/MM/DD string.
     * Components come from a canonical UTC instant via DateTimeImmutable.
     */
    public static function formatJalaliDate(int $gy, int $gm, int $gd, string $separator = '/'): string
    {
        [$jy, $jm, $jd] = self::gregorianToJalali($gy, $gm, $gd);
        return sprintf('%04d%s%02d%s%02d', $jy, $separator, $jm, $separator, $jd);
    }
}
