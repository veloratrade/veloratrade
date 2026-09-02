/*!
 * Velora — canonical trade-time FRONTEND layer (Phase 3F).
 *
 * RULES (enforced here, in one place):
 *   - The browser NEVER creates or guesses a canonical instant. Canonical
 *     trade time is `occurredOpenAtUtc` / `occurredCloseAtUtc` produced by the
 *     backend; this module only FORMATS those instants for display.
 *   - A naive "YYYY-MM-DD HH:mm:ss" legacy wall-clock string is NEVER declared
 *     UTC (no trailing "Z"). Legacy values are shown with an explicit
 *     fallback/unknown-timezone state.
 *   - Display timezone (users.timezone) and display calendar are explicit
 *     presentation preferences only; they never change the stored instant.
 *   - UI language is never a source-calendar authority. Calendar defaults may
 *     follow language as a DISPLAY default only.
 *   - No market-session hours exist here; session labels come ONLY from the
 *     backend `trade.session` block. There is no client-side classification.
 *   - Pure helpers are dependency-free and unit-tested in Node.
 */
(function (global) {
  'use strict';

  var IANA_RE = /^[A-Za-z]+(\/[A-Za-z_+\-]+){1,2}$/;
  // Anything that is an abbreviation, a fixed offset, or a display label is
  // rejected when an IANA zone is expected (EST, PST, GMT+3, UTC+3:30, ...).
  var NON_IANA_RE = /(GMT|UTC)?\s*[+-]\d|^(?:EST|PST|CST|MST|UTC|GMT|CET|EET|WET|IST|JST|AEST)$/i;

  function isIanaZone(value) {
    if (typeof value !== 'string') return false;
    var v = value.trim();
    if (!v || v.length > 64) return false;
    if (NON_IANA_RE.test(v)) return false;
    if (!IANA_RE.test(v)) return false;
    // Confirm the host actually knows the zone (guards typos). Intl throws on
    // unknown zones; a no-op format with timeZone validates it.
    try {
      Intl.DateTimeFormat('en-US', { timeZone: v });
      return true;
    } catch (_) {
      return false;
    }
  }

  /**
   * Parse ONLY an explicit-UTC/offset instant (ISO with Z or ±HH:MM).
   * Returns a Date, or null for naive/unknown strings. NEVER appends "Z".
   */
  function parseCanonicalUtc(value) {
    if (value == null) return null;
    var s = String(value).trim();
    if (!s) return null;
    // Must carry an explicit UTC designator (Z) or numeric offset.
    if (!/(Z$|[+-]\d{2}:?\d{2}$)/i.test(s)) return null;
    var d = new Date(s);
    return isNaN(d.getTime()) ? null : d;
  }

  /**
   * Resolve the DISPLAY timezone: the user's stored IANA preference only.
   * Never falls back to the browser zone as a source; if the stored value is
   * missing/invalid, presentation defaults to 'UTC' (a safe, explicit zone).
   */
  function displayTimezone(user) {
    var tz = user && typeof user.timezone === 'string' ? user.timezone.trim() : '';
    return isIanaZone(tz) ? tz : 'UTC';
  }

  /**
   * Resolve the DISPLAY calendar ('gregorian' | 'jalali') as an explicit
   * preference. Language may seed a DISPLAY default (fa -> jalali) but this is
   * never used to interpret source data.
   */
  function displayCalendar(options) {
    options = options || {};
    if (options.calendar === 'gregorian' || options.calendar === 'jalali') return options.calendar;
    var lang = String(options.locale || options.lang || '').toLowerCase();
    return /^fa/.test(lang) ? 'jalali' : 'gregorian';
  }

  /**
   * Format an already-canonical UTC instant for display under an explicit
   * timezone + calendar. Returns '' for null/unavailable so callers can show a
   * fallback. Uses Intl only for PRESENTATION; it does not alter the instant.
   */
  function formatCanonical(value, options) {
    options = options || {};
    var d = parseCanonicalUtc(value);
    if (!d) return '';
    var tz = isIanaZone(options.timeZone) ? options.timeZone : displayTimezone(options.user);
    var cal = displayCalendar(options);
    var intlLocale = /^fa/.test(String(options.locale || '').toLowerCase()) ? 'fa-IR' : 'en-US';
    var fmtOptions = {
      timeZone: tz,
      numberingSystem: 'latn',
      year: 'numeric', month: '2-digit', day: '2-digit',
      hour: '2-digit', minute: '2-digit', hour12: false
    };
    if (cal === 'jalali') fmtOptions.calendar = 'persian';
    try {
      return new Intl.DateTimeFormat(intlLocale + '-u-nu-latn', fmtOptions).format(d);
    } catch (_) {
      // If the host lacks the persian calendar, degrade to Gregorian rather
      // than fabricating anything.
      delete fmtOptions.calendar;
      return new Intl.DateTimeFormat('en-US-u-nu-latn', fmtOptions).format(d);
    }
  }

  /**
   * Choose the best display string for a trade time.
   *   canonical (occurred*) present -> format it (authoritative)
   *   only legacy openTime/closeTime -> render it verbatim, flagged unknown.
   * Returns { text: string, canonical: boolean }.
   */
  function tradeTimeDisplay(canonicalUtc, legacyWall, options) {
    var formatted = formatCanonical(canonicalUtc, options);
    if (formatted) return { text: formatted, canonical: true, unknownTz: false };
    // Legacy fallback: keep the raw wall string; do NOT append Z / treat as UTC.
    var legacy = legacyWall == null ? '' : String(legacyWall).replace('T', ' ').slice(0, 16);
    return { text: legacy, canonical: false, unknownTz: !!legacy };
  }

  /**
   * Normalize a free-text/wall-clock datetime input to the RAW text that is
   * sent as evidence. This is a wall clock in the SOURCE timezone; the browser
   * must not convert it. We only trim and normalize a datetime-local style
   * separator, preserving digits. No timezone math.
   */
  function toRawWallText(value) {
    if (value == null) return null;
    var s = String(value).trim();
    if (!s) return null;
    // datetime-local "YYYY-MM-DDTHH:mm" -> "YYYY-MM-DD HH:mm" (wall clock kept).
    s = s.replace('T', ' ');
    return s.length > 64 ? s.slice(0, 64) : s;
  }

  /**
   * Build the trade-create body's TIME EVIDENCE (Phase 2E contract). The
   * browser supplies raw source text + explicit context ONLY; it never sends a
   * fabricated canonical instant. openTime/closeTime remain the legacy fields.
   *
   * @param {object} p { openRaw, closeRaw, sourceCalendar, dateFormat,
   *   timezoneText, timezoneOffsetHintMinutes, explicitTimezone, screenshot }
   */
  function buildTimeEvidence(p) {
    p = p || {};
    var body = {};
    var rawOpen = toRawWallText(p.openRaw);
    var rawClose = toRawWallText(p.closeRaw);
    if (rawOpen) body.rawOpenText = rawOpen;
    if (rawClose) body.rawCloseText = rawClose;
    if (p.sourceCalendar === 'gregorian' || p.sourceCalendar === 'jalali' || p.sourceCalendar === 'unknown') {
      body.sourceCalendar = p.sourceCalendar;
    }
    if (p.dateFormat) body.dateFormat = String(p.dateFormat);
    if (p.timezoneText) body.timezoneText = String(p.timezoneText).slice(0, 64);
    if (Number.isInteger(p.timezoneOffsetHintMinutes)) body.timezoneOffsetHintMinutes = p.timezoneOffsetHintMinutes;
    if (isIanaZone(p.explicitTimezone)) body.explicitTimezone = p.explicitTimezone;
    return body;
  }

  /**
   * Session presentation from the BACKEND-derived block only.
   * Never classifies from hours. Returns { state, label } where state is one of
   * open|outside|unconfigured|unresolved|invalid and no hours are invented.
   */
  function sessionPresentation(session) {
    if (!session || typeof session !== 'object') {
      return { state: 'unconfigured', labels: [], neutral: true };
    }
    var status = String(session.status || 'unconfigured');
    if (status === 'open' && Array.isArray(session.sessions) && session.sessions.length) {
      return {
        state: 'open',
        neutral: false,
        labels: session.sessions.map(function (s) { return s && s.label ? String(s.label) : String(s.id || ''); }).filter(Boolean)
      };
    }
    // outside / unconfigured / unresolved / invalid all render as neutral.
    return { state: status, labels: [], neutral: true };
  }

  var api = {
    isIanaZone: isIanaZone,
    parseCanonicalUtc: parseCanonicalUtc,
    displayTimezone: displayTimezone,
    displayCalendar: displayCalendar,
    formatCanonical: formatCanonical,
    tradeTimeDisplay: tradeTimeDisplay,
    toRawWallText: toRawWallText,
    buildTimeEvidence: buildTimeEvidence,
    sessionPresentation: sessionPresentation
  };

  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  global.VeloraTime = api;
})(typeof window !== 'undefined' ? window : globalThis);
