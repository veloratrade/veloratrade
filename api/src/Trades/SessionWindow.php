<?php

declare(strict_types=1);

namespace Velora\Trades;

/**
 * Phase 3 — immutable definition of one market session window.
 *
 * All times are wall-clock MINUTES-FROM-MIDNIGHT in the window's reference
 * IANA timezone (e.g. London open 08:00 local = startMinute 480). An explicit
 * reference zone is mandatory; minutes are never interpreted as UTC. DST is
 * applied by the engine using the reference zone for the instant's date.
 *
 * Cross-midnight windows are expressed with endMinute <= startMinute (e.g. a
 * 22:00->02:00 session: start 1320, end 120).
 *
 * Validation rejects non-IANA zones and out-of-range minutes so a bad product
 * configuration fails loudly rather than misclassifying sessions.
 */
final class SessionWindow
{
    /**
     * @param int[]|null $daysOfWeek ISO weekdays (1=Mon..7=Sun) the window
     *                               applies on; null = every day.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $timezone,
        public readonly int $startMinute,
        public readonly int $endMinute,
        public readonly ?array $daysOfWeek = null,
        // Optional explicit tie-break priority for overlapping sessions
        // (lower = higher precedence). NULL => no priority; the engine returns
        // ALL matching sessions rather than collapsing to one label.
        public readonly ?int $priority = null,
    ) {
        $id = trim($id);
        if ($id === '' || strlen($id) > 40 || preg_match('/\A[a-z0-9._-]+\z/i', $id) !== 1) {
            throw new \InvalidArgumentException('Invalid session window id.');
        }
        if (trim($label) === '' || strlen($label) > 60) {
            throw new \InvalidArgumentException('Invalid session window label.');
        }
        if (!TimezoneResolver::isValidIana($timezone)) {
            throw new \InvalidArgumentException('Session window timezone must be a valid IANA identifier.');
        }
        if ($startMinute < 0 || $startMinute > 1439 || $endMinute < 0 || $endMinute > 1439) {
            throw new \InvalidArgumentException('Session window minutes must be within 0..1439.');
        }
        if ($startMinute === $endMinute) {
            throw new \InvalidArgumentException('Session window start and end must differ.');
        }
        if ($daysOfWeek !== null) {
            foreach ($daysOfWeek as $d) {
                if (!is_int($d) || $d < 1 || $d > 7) {
                    throw new \InvalidArgumentException('Session window daysOfWeek must be ISO weekdays 1..7.');
                }
            }
        }
        if ($priority !== null && $priority < 0) {
            throw new \InvalidArgumentException('Session window priority must be >= 0.');
        }
    }

    /**
     * @param array<string,mixed> $a
     */
    public static function fromArray(array $a): self
    {
        $id = (string) ($a['id'] ?? '');
        $label = isset($a['label']) && is_string($a['label']) ? $a['label'] : $id;
        $tz = (string) ($a['timezone'] ?? $a['zone'] ?? '');
        $start = self::toMinutes($a['start'] ?? $a['startMinute'] ?? null);
        $end = self::toMinutes($a['end'] ?? $a['endMinute'] ?? null);
        $days = null;
        if (isset($a['daysOfWeek']) && is_array($a['daysOfWeek'])) {
            $days = array_map(static fn($x) => (int) $x, $a['daysOfWeek']);
        }
        $priority = isset($a['priority']) && is_int($a['priority']) ? $a['priority'] : null;
        return new self($id, $label, $tz, $start, $end, $days, $priority);
    }

    private static function toMinutes(mixed $v): int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && preg_match('/\A([01]?\d|2[0-3]):([0-5]\d)\z/', trim($v), $m)) {
            return ((int) $m[1]) * 60 + (int) $m[2];
        }
        throw new \InvalidArgumentException('Session window time must be "HH:MM" or minute-of-day.');
    }
}
