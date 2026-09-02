<?php

declare(strict_types=1);

namespace Velora\Trades;

/**
 * Phase 3G — market-session WINDOW SPECIFICATION (product business rules).
 *
 * ===========================================================================
 * STATUS: PROPOSED — REQUIRES PRODUCT APPROVAL. NOT ACTIVATED.
 * ===========================================================================
 * There is NO authoritative product session-window specification anywhere in
 * the repository (verified): the only hours in code were the legacy frontend
 * marketing/helper buckets (Asia 00–08 / London 08–13 / overlap 13–17 /
 * New York 17–22 in FIXED UTC), which are DST-incorrect and explicitly
 * NON-authoritative, plus a legacy `trade_features.session` enum that carries
 * no window definitions. Those values must NOT be used as authority.
 *
 * This file documents a PROPOSED, deterministic, DST-aware specification built
 * from the standard global forex cash-session convention (24h, opens Monday in
 * Wellington/Sydney and closes Friday 17:00 New York). It is shipped INERT:
 *   - Production wiring (TradeService) keeps using `new TradingSessionEngine()`
 *     with NO windows -> status 'unconfigured'. Nothing here is active.
 *   - The proposal is exposed only through `proposedWindows()` for review and
 *     through `proposedEngine()` strictly in tests, and can only become live
 *     by an explicit, versioned product approval that replaces
 *     approvedWindows() with a non-empty list.
 *
 * To activate after approval: build TradingSessionEngine with
 * approvedWindows() and bump SPEC_VERSION; never edit unapproved values in
 * place (each change gets a new version so historical derivations are
 * traceable).
 *
 * RULES THIS SPECIFICATION FOLLOWS
 *   - Reference zones are IANA ids; windows are LOCAL wall-clock times. There
 *     are NO fixed-UTC hours. The engine evaluates boundaries through the
 *     IANA transition rules for the instant's date, so DST is dynamic and the
 *     effective UTC interval shifts seasonally (by design).
 *   - Session is a MARKET concept (when an exchange centre is open), derived
 *     ONLY from the canonical UTC instant occurred_open_at_utc. It is NOT the
 *     broker timezone, the user/display timezone, the screenshot/account
 *     timezone, or a language. Those three timezone concepts are independent:
 *       (a) broker/source tz -> interpret source wall clock (trading_accounts.timezone)
 *       (b) display tz       -> render instant to the user (users.timezone)
 *       (c) market-session reference tz -> define when a market is open (here)
 *   - Overlaps return ALL matching sessions deterministically; a single
 *     primary label is only collapsed if an explicit priority is approved.
 *   - canonical NULL (legacy/unresolved) -> session 'unresolved' (handled by
 *     engine). No session is ever derived from legacy open_time.
 *   - Weekends (when all three listed centres are closed) return 'outside'.
 *
 * @see SessionWindow
 * @see TradingSessionEngine
 */
final class MarketSessionSpec
{
    /**
     * Specification version. Any change to approved windows MUST get a new
     * version (a date-based label, never a runtime timestamp). The engine
     * exposes its own ENGINE_VERSION (algorithm); this is the RULESET version.
     */
    public const SPEC_VERSION = '2026.09.02-proposed.1';

    /** Approval flag. MUST stay false until product explicitly approves. */
    public const APPROVED = false;

    /** ISO weekdays (1=Mon..7=Sun) the cash sessions run on. */
    private const FOREX_WEEKDAYS = [1, 2, 3, 4, 5]; // Mon..Fri

    /**
     * The ACTIVE, approved windows. Empty while unapproved: production must
     * have zero active windows. Only replace/remove this when APPROVED flips
     * to true under a new SPEC_VERSION after explicit product sign-off.
     *
     * @return SessionWindow[]
     */
    public static function approvedWindows(): array
    {
        // Deliberately empty: no approved product windows exist yet.
        return [];
    }

    /**
     * Build the production engine: UNCONFIGURED until approved windows exist.
     */
    public static function productionEngine(): TradingSessionEngine
    {
        return new TradingSessionEngine(self::approvedWindows());
    }

    /**
     * PROPOSED windows for product review — NOT active anywhere in production.
     *
     * Standard global forex cash sessions (local exchange wall-clock, Mon–Fri):
     *
     *   tokyo   Asia/Tokyo      09:00–18:00 local  (TSE cash equity / Tokyo fx)
     *   london  Europe/London   08:00–17:00 local  (London session)
     *   newyork America/New_York 08:00–17:00 local (NY session; runs past
     *                           London close, producing the London–NY overlap)
     *
     * Effective UTC intervals (illustrative; the engine computes them per-date
     * and they move with DST — these are NOT stored constants):
     *   winter: Tokyo 00:00–09:00Z, London 08:00–17:00Z, NY 13:00–22:00Z
     *   summer: Tokyo 00:00–09:00Z (Japan has no DST), London 07:00–16:00Z,
     *           NY 12:00–21:00Z.
     * Overlap (London & NY both open) is naturally detected as two matching
     * sessions, not a hand-coded bucket.
     *
     * `late` is intentionally ABSENT: the legacy enum had a LATE value with no
     * authoritative definition. LATE SESSION REQUIRES PRODUCT DEFINITION.
     *
     * @return SessionWindow[]
     */
    public static function proposedWindows(): array
    {
        return [
            new SessionWindow(
                'tokyo', 'Tokyo', 'Asia/Tokyo',
                9 * 60, 18 * 60, self::FOREX_WEEKDAYS, 30
            ),
            new SessionWindow(
                'london', 'London', 'Europe/London',
                8 * 60, 17 * 60, self::FOREX_WEEKDAYS, 20
            ),
            new SessionWindow(
                'newyork', 'New York', 'America/New_York',
                8 * 60, 17 * 60, self::FOREX_WEEKDAYS, 10
            ),
        ];
    }

    /**
     * Engine built from the PROPOSAL. For tests/review ONLY — never called by
     * production wiring while APPROVED is false.
     */
    public static function proposedEngine(): TradingSessionEngine
    {
        return new TradingSessionEngine(self::proposedWindows());
    }
}
