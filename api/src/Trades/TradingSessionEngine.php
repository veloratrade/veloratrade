<?php

declare(strict_types=1);

namespace Velora\Trades;

/**
 * Phase 3 — deterministic, DST-aware MARKET SESSION classifier.
 *
 * CONTRACT
 *   Input : a canonical UTC instant (only ever produced for resolved trades),
 *           or NULL/unresolved.
 *   Output: zero or more session labels (overlaps supported), plus derived
 *           hour/day in UTC and an explicit status.
 *
 * HARD RULES
 *   - The engine NEVER invents market windows. The window set is supplied as
 *     explicit configuration (SessionWindow[]). With no configuration it
 *     returns status='unconfigured' and labels=[] — it does not guess.
 *   - No AI/Gemini, no symbol, no broker name, no browser/server tz, no UI
 *     locale is used. Windows reference an explicit IANA zone each.
 *   - DST is handled by DateTimeImmutable/DateTimeZone in the reference zone;
 *     comparison is performed on UTC epochs, so it is timezone-independent.
 *   - Sessions are DERIVED from canonical UTC, never stored as truth and never
 *     fed back into canonical-time resolution. Session ≠ broker timezone.
 *   - Pure service: no strtotime(), gmdate(), date_default_timezone_set().
 *
 * SESSION WINDOW SPECIFICATION REQUIRED — NO WINDOWS INVENTED.
 * The legacy fixed-UTC marketing/helper copy (Asia 00–08, London 08–13,
 * overlap 13–17, NY 17–22) is deliberately NOT wired in: it is DST-incorrect
 * and not an approved product spec. Product must supply real IANA-referenced
 * windows to activate classifications.
 */
final class TradingSessionEngine
{
    /** Engine version — bump whenever the derivation algorithm changes. */
    public const ENGINE_VERSION = 1;

    /** @var SessionWindow[] */
    private readonly array $windows;

    /**
     * @param array<int,array<string,mixed>|SessionWindow> $windows
     *        Each window: { id, label?, timezone, startMinute, endMinute,
     *                       daysOfWeek?: int[] (ISO 1=Mon..7=Sun) }
     */
    public function __construct(array $windows = [])
    {
        $parsed = [];
        foreach ($windows as $w) {
            $parsed[] = $w instanceof SessionWindow ? $w : SessionWindow::fromArray($w);
        }
        $this->windows = $parsed;
    }

    public function hasConfiguredWindows(): bool
    {
        return $this->windows !== [];
    }

    public function windowCount(): int
    {
        return count($this->windows);
    }

    public function engineVersion(): int
    {
        return self::ENGINE_VERSION;
    }

    /**
     * Classify a canonical UTC MySQL datetime ('Y-m-d H:i:s', explicit UTC).
     * Pass null for an unresolved trade -> 'unresolved'.
     *
     * @return array{
     *   status:string, sessions:array<int,array{id:string,label:string,timezone:string}>,
     *   hourUtc:int, dayOfWeekUtc:int, engineVersion:int, windowCount:int
     * }
     */
    public function classify(?string $canonicalUtc): array
    {
        $empty = [
            'status' => 'unconfigured', 'sessions' => [], 'hourUtc' => null,
            'dayOfWeekUtc' => null, 'engineVersion' => self::ENGINE_VERSION,
            'windowCount' => count($this->windows),
        ];
        if ($canonicalUtc === null || trim($canonicalUtc) === '') {
            $empty['status'] = 'unresolved';
            return $empty;
        }

        // Explicit UTC parse. Never uses default tz; offset is pinned to UTC.
        $instant = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', trim($canonicalUtc), new \DateTimeZone('UTC'));
        if ($instant === false) {
            $empty['status'] = 'invalid';
            return $empty;
        }

        $hourUtc = (int) $instant->format('G');
        $dayOfWeekUtc = (int) $instant->format('N'); // ISO 1..7

        if ($this->windows === []) {
            return [
                'status' => 'unconfigured', 'sessions' => [], 'hourUtc' => $hourUtc,
                'dayOfWeekUtc' => $dayOfWeekUtc, 'engineVersion' => self::ENGINE_VERSION,
                'windowCount' => 0,
            ];
        }

        $matched = [];
        foreach ($this->windows as $window) {
            if ($this->matches($window, $instant)) {
                $matched[] = ['id' => $window->id, 'label' => $window->label, 'timezone' => $window->timezone];
            }
        }

        return [
            'status' => $matched === [] ? 'outside' : 'open',
            'sessions' => $matched,
            'hourUtc' => $hourUtc,
            'dayOfWeekUtc' => $dayOfWeekUtc,
            'engineVersion' => self::ENGINE_VERSION,
            'windowCount' => count($this->windows),
        ];
    }

    /**
     * Determine whether the UTC instant falls inside the window. DST/cross-
     * midnight handled by building reference-local wall times and comparing
     * UTC epochs.
     */
    private function matches(SessionWindow $w, \DateTimeImmutable $utcInstant): bool
    {
        $zone = new \DateTimeZone($w->timezone);
        $local = $utcInstant->setTimezone($zone);
        $dow = (int) $local->format('N');
        if ($w->daysOfWeek !== null && !in_array($dow, $w->daysOfWeek, true)) {
            return false;
        }

        $sh = intdiv($w->startMinute, 60);
        $sm = $w->startMinute % 60;
        $eh = intdiv($w->endMinute, 60);
        $em = $w->endMinute % 60;

        // Window starting on the local date of the instant.
        $start = $local->setTime($sh, $sm, 0)->setTimezone(new \DateTimeZone('UTC'));
        if ($w->endMinute > $w->startMinute) {
            // Same-day window [start, end).
            $end = $local->setTime($eh, $em, 0)->setTimezone(new \DateTimeZone('UTC'));
            return $this->between($utcInstant, $start, $end);
        }

        // Cross-midnight window: starts on D at HH:MM, ends next local day.
        $endNext = $local->setTime($eh, $em, 0)->modify('+1 day')->setTimezone(new \DateTimeZone('UTC'));
        if ($this->between($utcInstant, $start, $endNext)) {
            return true;
        }
        // An early-hours instant belongs to the window that started yesterday.
        $startPrev = $start->modify('-1 day');
        $endToday = $local->setTime($eh, $em, 0)->setTimezone(new \DateTimeZone('UTC'));
        return $this->between($utcInstant, $startPrev, $endToday);
    }

    private function between(\DateTimeImmutable $instant, \DateTimeImmutable $start, \DateTimeImmutable $end): bool
    {
        $t = $instant->getTimestamp();
        return $t >= $start->getTimestamp() && $t < $end->getTimestamp();
    }
}
