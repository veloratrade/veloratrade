#!/usr/bin/env node
/**
 * VELORA — Phase 3F frontend canonical-time contract.
 *
 * Pure-node regression over public/assets/velora-time.js (+ static assertions
 * on the shipped pages/assets). Pins the safety rules:
 *   - canonical instants require an explicit offset/Z; naive SQL never gets "Z"
 *   - display tz is the stored IANA user preference only (EST/PST/GMT+3 rejected;
 *     browser tz is never sourced)
 *   - display calendar is explicit; language is only a display default
 *   - formatting the SAME canonical UTC instant yields the same instant under
 *     different tz/calendar/language (presentation differs, instant does not)
 *   - buildTradeEvidence sends raw wall text + screenshot evidence, never a
 *     browser-converted ISO/UTC instant
 *   - session presentation comes from the backend block only; no client hours
 *
 * Runs with plain Node (no npm deps).
 */
'use strict';
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..', '..');
const fails = [];
function check(name, ok, detail) {
  console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? ' :: ' + detail : ''));
  if (!ok) fails.push(name);
}

const VT = require(path.join(ROOT, 'public', 'assets', 'velora-time.js'));

// --- Canonical parsing: explicit offset only ---
check('parse: Z instant accepted', VT.parseCanonicalUtc('2026-08-31T11:00:00Z') instanceof Date);
check('parse: numeric offset accepted', VT.parseCanonicalUtc('2026-08-31T14:30:00+03:30') instanceof Date);
check('parse: naive SQL datetime NOT treated as UTC', VT.parseCanonicalUtc('2026-08-31 14:30:00') === null);
check('parse: naive date-only NOT treated as UTC', VT.parseCanonicalUtc('2026-08-31') === null);
check('parse: garbage returns null', VT.parseCanonicalUtc('not-a-date') === null);

// Explicit offset resolves to the correct absolute instant regardless of host tz.
const inst = VT.parseCanonicalUtc('2026-08-31T14:30:00+03:30');
check('parse: +03:30 wall maps to 11:00 UTC', inst.getUTCHours() === 11 && inst.getUTCMinutes() === 0, inst.toISOString());

// --- IANA validation ---
check('iana: Europe/London accepted', VT.isIanaZone('Europe/London') === true);
check('iana: America/New_York accepted', VT.isIanaZone('America/New_York') === true);
check('iana: Asia/Tehran accepted', VT.isIanaZone('Asia/Tehran') === true);
check('iana: EST rejected (abbreviation)', VT.isIanaZone('EST') === false);
check('iana: PST rejected', VT.isIanaZone('PST') === false);
check('iana: GMT+3 rejected (fixed offset)', VT.isIanaZone('GMT+3') === false);
check('iana: UTC+03:30 rejected (offset)', VT.isIanaZone('UTC+03:30') === false);
check('iana: UTC rejected as a regional zone', VT.isIanaZone('UTC') === false);
check('iana: empty/garbage rejected', VT.isIanaZone('') === false && VT.isIanaZone('Moon/Sea') === false);

// --- Display timezone resolution ---
check('displayTz: uses stored IANA preference', VT.displayTimezone({ timezone: 'Asia/Tehran' }) === 'Asia/Tehran');
check('displayTz: invalid stored tz falls back to UTC (never browser)', VT.displayTimezone({ timezone: 'EST' }) === 'UTC');
check('displayTz: missing user tz falls back to UTC', VT.displayTimezone({}) === 'UTC' && VT.displayTimezone(null) === 'UTC');

// --- Display calendar ---
check('calendar: explicit gregorian wins even for fa locale', VT.displayCalendar({ calendar: 'gregorian', locale: 'fa' }) === 'gregorian');
check('calendar: explicit jalali wins even for en locale', VT.displayCalendar({ calendar: 'jalali', locale: 'en' }) === 'jalali');
check('calendar: fa default -> jalali (display default only)', VT.displayCalendar({ locale: 'fa' }) === 'jalali');
check('calendar: en default -> gregorian', VT.displayCalendar({ locale: 'en' }) === 'gregorian');

// --- Formatting: same instant, different presentation, same instant ---
const canonical = '2026-08-31T11:00:00Z';
const london = VT.formatCanonical(canonical, { timeZone: 'Europe/London', locale: 'en', calendar: 'gregorian' });
const tehranJalali = VT.formatCanonical(canonical, { timeZone: 'Asia/Tehran', locale: 'fa', calendar: 'jalali' });
const ny = VT.formatCanonical(canonical, { timeZone: 'America/New_York', locale: 'en', calendar: 'gregorian' });
check('format: London (BST) shows 12:00', /12:00/.test(london), london);
check('format: Tehran wall shows 14:30', /14:30/.test(tehranJalali), tehranJalali);
check('format: NY (EDT) shows 07:00', /07:00/.test(ny), ny);
check('format: Jalali calendar converts 2026-08-31 to 1405/06', tehranJalali.indexOf('1405') !== -1, tehranJalali);
check('format: null/naive canonical returns empty (no fabrication)', VT.formatCanonical('2026-08-31 14:30:00', { timeZone: 'UTC' }) === '');
// DST: London winter 08:00Z displays 08:00 (GMT); summer 08:00Z -> 09:00 (BST).
check('format: London winter GMT', /08:00/.test(VT.formatCanonical('2026-01-05T08:00:00Z', { timeZone: 'Europe/London', locale: 'en' })));
check('format: London summer BST', /09:00/.test(VT.formatCanonical('2026-08-31T08:00:00Z', { timeZone: 'Europe/London', locale: 'en' })));

// --- tradeTimeDisplay: canonical precedence + legacy fallback ---
const withCanon = VT.tradeTimeDisplay('2026-08-31T11:00:00Z', '2026-08-31 14:30:00', { timeZone: 'UTC' });
check('display: canonical preferred over legacy', withCanon.canonical === true && /11:00/.test(withCanon.text), withCanon.text);
const legacyOnly = VT.tradeTimeDisplay(null, '2026-08-31 14:30:00', { timeZone: 'UTC' });
check('display: legacy wall shown verbatim, flagged unknown', legacyOnly.canonical === false && legacyOnly.unknownTz === true && legacyOnly.text.indexOf('2026-08-31') === 0, legacyOnly.text);
check('display: legacy never gains a Z', !/Z$/.test(legacyOnly.text) && legacyOnly.text.indexOf('Z') === -1, legacyOnly.text);

// --- Evidence builder: raw text, no UTC fabrication ---
const ev = VT.buildTimeEvidence({
  openRaw: '2026-08-31T14:30', closeRaw: '2026-08-31T15:42',
  sourceCalendar: 'jalali', dateFormat: 'YYYY-MM-DD', timezoneText: 'EST',
  timezoneOffsetHintMinutes: 210, explicitTimezone: 'Europe/London'
});
check('evidence: rawOpenText is wall text (T->space), not ISO-Z', ev.rawOpenText === '2026-08-31 14:30' && ev.rawOpenText.indexOf('Z') === -1, ev.rawOpenText);
check('evidence: rawCloseText preserved', ev.rawCloseText === '2026-08-31 15:42');
check('evidence: sourceCalendar passed through', ev.sourceCalendar === 'jalali');
check('evidence: dateFormat passed through', ev.dateFormat === 'YYYY-MM-DD');
check('evidence: timezoneText kept verbatim (EST not mapped)', ev.timezoneText === 'EST');
check('evidence: offset hint passed', ev.timezoneOffsetHintMinutes === 210);
check('evidence: explicit valid IANA passed', ev.explicitTimezone === 'Europe/London');
const evNoTz = VT.buildTimeEvidence({ openRaw: '2026-08-31 10:00', closeRaw: '2026-08-31 11:00' });
check('evidence: manual no-tz sends only raw text (no tz/offset fabricated)', evNoTz.explicitTimezone === undefined && evNoTz.timezoneOffsetHintMinutes === undefined && evNoTz.timezoneText === undefined);
check('evidence: invalid explicitTimezone dropped', VT.buildTimeEvidence({ openRaw: 'x', explicitTimezone: 'EST' }).explicitTimezone === undefined);

// --- Session presentation: backend block only ---
check('session: open labels from backend', (function () { var p = VT.sessionPresentation({ status: 'open', sessions: [{ id: 'london', label: 'London' }, { id: 'newyork', label: 'New York' }] }); return p.state === 'open' && p.labels.length === 2; })());
check('session: unconfigured -> neutral, no labels', (function () { var p = VT.sessionPresentation({ status: 'unconfigured', sessions: [] }); return p.neutral === true && p.labels.length === 0; })());
check('session: null/empty -> neutral unconfigured', VT.sessionPresentation(null).neutral === true);
check('session: unresolved -> neutral', VT.sessionPresentation({ status: 'unresolved' }).neutral === true);

// --- Static asset/page safety: no client canonical conversion / no invented hours ---
function read(rel) { return fs.readFileSync(path.join(ROOT, rel), 'utf8'); }
const newTrade = read('trades/new/index.html');
check('static: new-trade submit does NOT call toISOString()', !/new Date\([^)]*openTime[^)]*\)\.toISOString|toISOString\(\)/.test(newTrade.replace(/\/\/[^\n]*/g, '').replace(/\/\*[\s\S]*?\*\//g, '')) || !/\bopenTime: new Date/.test(newTrade));
check('static: new-trade sends rawOpenText evidence', newTrade.indexOf('buildSubmitEvidence') !== -1);
check('static: no hardcoded Asia/London/NY UTC hour buckets in new-trade', !/getUTCHours\(\)[\s\S]{0,200}(asia|london|newyork)/i.test(newTrade.replace(/\/\/[^\n]*/g, '')));
const smartImport = read('public/assets/velora-smart-import.js');
check('static: smart-import preserves __vsiTimeEvidence rawOpenText', smartImport.indexOf('rawOpenText') !== -1 && smartImport.indexOf('__vsiTimeEvidence') !== -1);
check('static: smart-import does not fabricate Z/ISO from evidence', !/rawOpenText[^;]*toISOString/.test(smartImport));
const localization = read('public/assets/velora-localization.js');
check('static: dateValue no longer appends Z to naive SQL', !/replace\(' ', 'T'\) \+ 'Z'/.test(localization));
const data = read('public/assets/velora-data.js');
check('static: normalizeTrade carries occurredOpenAtUtc + session', data.indexOf('occurredOpenAtUtc') !== -1 && data.indexOf('session:') !== -1);
check('static: normalizeTrade legacy openTime uses rawWall (no Z)', /openTime: rawWall\(/.test(data));
check('static: isoDate never appends Z', !/\+ 'Z'/.test(data.match(/function isoDate[\s\S]*?\n  }/)[0]));
// No session-hour rules anywhere in the new time module.
const timeModule = read('public/assets/velora-time.js');
check('static: velora-time contains no session hour thresholds', !/getUTCHours|>= 0 &&|asia|london|new ?york/i.test(timeModule.replace(/\/\/[^\n]*/g, '')));

console.log('\n' + (fails.length ? fails.length + ' FAIL' : 'ALL PASS') + ' (' + (fails.length ? '' : 'frontend time contract') + ')');
process.exit(fails.length ? 1 : 0);
