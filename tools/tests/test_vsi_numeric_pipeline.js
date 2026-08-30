#!/usr/bin/env node
/**
 * VELORA — runtime regression for the screenshot-import numeric pipeline.
 *
 * Executes the REAL functions of public/assets/velora-smart-import.js inside a
 * vm sandbox with a minimal DOM stub. Only instrumentation: one injected line
 * exposes the internal functions (globalThis.__VSI); the functions under test
 * are byte-identical to the shipped asset.
 *
 * Pins (2026-08-30 defect class):
 *   T1  faDigits: Persian / Arabic-Indic / mixed digits -> Latin
 *   T2  normalizeNumber: decimal & thousands separators preserved/corrected
 *   T3  NUMERIC_KEYS availability (module scope on the fixed asset; in-function
 *       on legacy main — parseMt must execute without ReferenceError anyway)
 *   T4  Gemini payload with Persian digits -> canonical Latin field values
 *   T5  inferContractSize: XAUUSD buy=100 / sell=100 / EURUSD=100000,
 *       null on sign contradiction or garbage (no invented multipliers)
 *       + canonical P/L arithmetic (delta * volume * contractSize)
 *   T6  Browser-OCR gate: valid AI extraction => zero browser-OCR loads;
 *       genuine failure => fallback OCR runs
 *
 * Runs with plain Node (no npm deps). Skipped tiers auto-activate when the
 * asset carries the corresponding fix (extractionToParsed / inferContractSize).
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ASSET = path.resolve(__dirname, '..', '..', 'public', 'assets', 'velora-smart-import.js');
const fails = [];

function makeEl(tag) {
  const el = {
    tagName: tag, style: {}, dataset: {}, className: '', textContent: '', value: '',
    checked: false, innerHTML: '', firstElementChild: null, children: [],
    classList: { add() {}, remove() {}, toggle() {}, contains() { return false; } },
    addEventListener() {}, removeEventListener() {}, setAttribute() {}, getAttribute() { return null; },
    removeAttribute() {}, appendChild(c) { return c; }, insertBefore() {}, removeChild() {}, replaceChild() {},
    cloneNode() { return makeEl(tag); }, click() {}, focus() {}, blur() {}, dispatchEvent() {},
    getContext() { return { drawImage() {}, fillRect() {}, getImageData() { return { data: [] }; }, putImageData() {} }; },
    toDataURL() { return 'data:image/png;base64,AAAA'; },
    querySelector() { return makeEl('div'); }, querySelectorAll() { return []; },
    getBoundingClientRect() { return { top: 0, left: 0, width: 0, height: 0 }; }, offsetTop: 0,
    naturalWidth: 100, naturalHeight: 100, _onload: null, _onerror: null,
  };
  Object.defineProperty(el, 'src', { set() {}, get() { return ''; } });
  Object.defineProperty(el, 'onload', { set(f) { el._onload = f; }, get() { return el._onload; } });
  Object.defineProperty(el, 'onerror', { set(f) { el._onerror = f; }, get() { return el._onerror; } });
  return el;
}

function loadAsset() {
  let src = fs.readFileSync(ASSET, 'utf8');
  const scriptCount = { n: 0 };
  const sandbox = {
    console, location: { pathname: '/trades/new/' },
    setTimeout, clearTimeout, queueMicrotask, Promise, JSON, Math, Date, RegExp, Object, Array,
    String, Number, Boolean, Error, isFinite, parseFloat, parseInt, encodeURIComponent, decodeURIComponent,
    Image: class { constructor() { this.naturalWidth = 10; this.naturalHeight = 10; } set src(v) { setTimeout(() => { if (this.onload) this.onload(); }, 0); } get src() { return ''; } },
    Event: class { constructor(t) { this.type = t; } },
    FileReader: class { readAsDataURL() {} },
    document: {
      documentElement: { lang: 'fa', setAttribute() {} }, readyState: 'loading', addEventListener() {},
      getElementById() { return makeEl('div'); },
      createElement(tag) { const e = makeEl(tag); if (String(tag).toLowerCase() === 'script') { scriptCount.n++; setTimeout(() => { if (e._onload) e._onload(); }, 0); } return e; },
      querySelector() { return makeEl('div'); }, querySelectorAll() { return []; },
      head: makeEl('head'), body: makeEl('body'),
      createTextNode(t) { return { textContent: t, nodeType: 3 }; },
    },
    VeloraData: { request() { return Promise.resolve({ texts: [], extraction: null }); } },
    VeloraSymbols: { icon() { return ''; }, displayCode(v) { return v; }, countryNameOf() { return null; } },
    scrollTo() {},
  };
  sandbox.window = sandbox;
  sandbox.globalThis = sandbox;
  const hook = 'globalThis.__VSI = { faDigits, normalizeNumber, looksLikeTrade, parseMt, runExtract, ocrServer, extractionToParsed: (typeof extractionToParsed === "function" ? extractionToParsed : null), inferContractSize: (typeof inferContractSize === "function" ? inferContractSize : null), NUMERIC_KEYS: (typeof NUMERIC_KEYS !== "undefined" ? NUMERIC_KEYS : null), t };\n';
  const idx = src.lastIndexOf('})();');
  src = src.slice(0, idx) + hook +
    'globalThis.__VSI_SET_SHOTS = function (list) { shots = list.map(function (s) { return { dataUrl: s }; }); };\n})();';
  vm.createContext(sandbox);
  let loadErr = null;
  try { vm.runInContext(src, sandbox, { filename: ASSET }); } catch (e) { loadErr = String((e && e.stack) || e); }
  return { sandbox, api: sandbox.__VSI || null, scriptCount, loadErr };
}

function eq(name, actual, expected) {
  if (JSON.stringify(actual) !== JSON.stringify(expected)) fails.push(`${name}: expected ${JSON.stringify(expected)} got ${JSON.stringify(actual)}`);
}
function okv(name, cond) { if (!cond) fails.push(`${name}: condition failed`); }

(async () => {
  const m = loadAsset();
  if (m.loadErr || !m.api) { console.error('MODULE LOAD FAILED:', m.loadErr); process.exit(2); }
  const V = m.api;

  eq('T1a', V.faDigits('۱۳۱.۴۰'), '131.40');
  eq('T1b', V.faDigits('-۹.۰۸'), '-9.08');
  eq('T1c', V.faDigits('۶۰.۰۰'), '60.00');
  eq('T1d', V.faDigits('۳۳۴۲.۱۵'), '3342.15');
  eq('T1e', V.faDigits('۳۳۴۸.۷۲'), '3348.72');
  eq('T1f', V.faDigits('۰.۲۰'), '0.20');
  eq('T1g', V.faDigits('١٢٣.٤٥'), '123.45');
  eq('T1h', V.faDigits('12۳.4'), '123.4');
  eq('T1i', V.faDigits('3,334.50'), '3,334.50');

  eq('T2a', V.normalizeNumber('131.40'), '131.40');
  eq('T2b', V.normalizeNumber('-9.08'), '-9.08');
  eq('T2c', V.normalizeNumber('1,234.56'), '1234.56');
  eq('T2d', V.normalizeNumber('1.234,56'), '1234.56');
  eq('T2e', V.normalizeNumber('0.20'), '0.20');
  eq('T2f', V.normalizeNumber('3342.15'), '3342.15');

  okv('T3', V.NUMERIC_KEYS === null || (V.NUMERIC_KEYS.volume === 1 && V.NUMERIC_KEYS.profitLoss === 1));
  let threw = null, parsed = null;
  try { parsed = V.parseMt('Symbol XAUUSD   Buy 0.20   Entry 3342.15   Exit 3348.72   Profit 131.40'); } catch (e) { threw = String(e); }
  okv('T7a parseMt no ReferenceError (NUMERIC_KEYS)', threw === null);
  const vals = parsed ? Object.keys(parsed).map(k => parsed[k].value).join('|') : '';
  okv('T7b no Persian digits survive', !/[\u06F0-\u06F9\u0660-\u0669]/.test(vals));

  if (V.extractionToParsed) {
    m.sandbox.window.__vsiExtraction = {
      provider: 'gemini', confidence: 0.9,
      fields: { symbol: 'XAUUSD', side: 'buy', lot: '۰.۲۰', entry: '۳۳۴۲.۱۵', exit: '۳۳۴۸.۷۲', pnl: '۱۳۱.۴۰', commission: '۰.۰۰', swap: '۰.۰۰' },
    };
    const p = V.extractionToParsed();
    okv('T4a', !!p);
    eq('T4b', p && p.fields.volume.value, '0.20');
    eq('T4c', p && p.fields.entryPrice.value, '3342.15');
    eq('T4d', p && p.fields.exitPrice.value, '3348.72');
    eq('T4e', p && p.fields.profitLoss.value, '131.40');
  }

  if (V.inferContractSize) {
    eq('T5-1', V.inferContractSize({ entryPrice: '3342.15', exitPrice: '3348.72', volume: '0.20', profitLoss: '131.40', direction: 'buy' }), 100);
    eq('T5-2', V.inferContractSize({ entryPrice: '3342.15', exitPrice: '3342.604', volume: '0.20', profitLoss: '-9.08', direction: 'sell' }), 100);
    eq('T5-3', V.inferContractSize({ entryPrice: '1.08500', exitPrice: '1.08560', volume: '1.00', profitLoss: '60.00', direction: 'buy' }), 100000);
    eq('T5-4', V.inferContractSize({ entryPrice: '3342.15', exitPrice: '3348.72', volume: '0.20', profitLoss: '-131.40', direction: 'buy' }), null);
    eq('T5-5', V.inferContractSize({ entryPrice: 'x', exitPrice: '3348.72', volume: '0.20', profitLoss: '131.40', direction: 'buy' }), null);
    okv('T5-6', Math.abs((3348.72 - 3342.15) * 0.20 * 100 - 131.40) < 1e-9);
    okv('T5-7', Math.abs((3342.15 - 3342.604) * 0.20 * 100 - -9.08) < 1e-9);
    okv('T5-8', Math.abs((1.08560 - 1.08500) * 1.00 * 100000 - 60.00) < 1e-9);
  }

  const scenarios = {
    aiOk: { response: { texts: [], extraction: { symbol: 'XAUUSD', side: 'buy', lot: '۰.۲۰', entry: '۳۳۴۲.۱۵', exit: '۳۳۴۸.۷۲', pnl: '۱۳۱.۴۰' }, provider: 'gemini', confidence: 0.9 } },
    aiFail: { response: { texts: [] } },
    netFail: { requestError: 'network-down' },
  };
  for (const [name, sc] of Object.entries(scenarios)) {
    m.scriptCount.n = 0;
    m.sandbox.window.__vsiExtraction = undefined;
    m.sandbox.VeloraData.request = (url, opts) => (sc.requestError ? Promise.reject(new Error(sc.requestError)) : Promise.resolve(sc.response));
    m.sandbox.__VSI_SET_SHOTS(['data:image/png;base64,AAAA']);
    let err = null;
    try { await V.runExtract(); } catch (e) { err = String((e && e.message) || e); }
    if (name === 'aiOk') {
      if (V.extractionToParsed) eq('T6-aiOk browser OCR must not run after valid AI extraction', m.scriptCount.n, 0);
      else console.log('[T6-contract] legacy asset: AI extraction not parsed; browser-OCR gate cannot be exercised');
    }
    if (name === 'aiFail' || name === 'netFail') okv(`T6-${name} fallback OCR must run`, m.scriptCount.n >= 1);
  }

  if (fails.length) {
    console.error(`VSI NUMERIC PIPELINE: FAIL (${fails.length})`);
    fails.forEach(f => console.error('  ✗', f));
    process.exit(1);
  }
  console.log('VSI NUMERIC PIPELINE: PASS (runtime, real asset functions)');
  process.exit(0);
})().catch(e => { console.error('HARNESS ERROR:', e); process.exit(2); });
