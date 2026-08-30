#!/usr/bin/env node
/**
 * VELORA — synthetic extraction corpus: field-level normalization + contract
 * evaluation against the REAL smart-import asset functions (vm sandbox).
 *
 * Corpus: tools/tests/fixtures/extraction_corpus.json — SYNTHETIC payloads
 * emulating Gemini field output for documented MT4/MT5 layout variants. No real
 * screenshots were available in the audit environment; real-image Gemini/Vision
 * accuracy is NOT VERIFIED (see the audit report, "Remaining NOT VERIFIED").
 *
 * Per case (ground truth = authoritative):
 *   RAW digits (Persian / Arabic-Indic / mixed / thousands / European decimal)
 *     → faDigits → normalizeNumber → canonical Latin value             (G5)
 *   extractionToParsed must map fields exactly, no inventing, dropping
 *     invalid numerics, ignoring unsupported keys                       (G6)
 *   inferContractSize + canonical P/L math:
 *     XAUUSD BUY = 131.40, SELL = -9.08, EURUSD = 60.00;
 *     sign contradiction / garbage => null (no invented multipliers)    (G7)
 *
 * Auto-activation: extraction-level contract checks activate when the asset
 * carries extractionToParsed / inferContractSize (i.e. after the smart-import
 * fix merges); on the legacy main asset they report [skip] explicitly —
 * never a silent pass.
 *
 * Run:
 *   node tools/tests/test_extraction_corpus.js
 *   VSI_ASSET=/path/to/velora-smart-import.js node tools/tests/test_extraction_corpus.js
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ASSET = process.env.VSI_ASSET || path.resolve(__dirname, '..', '..', 'public', 'assets', 'velora-smart-import.js');
const CORPUS = path.resolve(__dirname, 'fixtures', 'extraction_corpus.json');
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
  const hook = 'globalThis.__X = { faDigits, normalizeNumber, extractionToParsed: (typeof extractionToParsed === "function" ? extractionToParsed : null), inferContractSize: (typeof inferContractSize === "function" ? inferContractSize : null), NUMERIC_KEYS: (typeof NUMERIC_KEYS !== "undefined" ? NUMERIC_KEYS : null) };\n';
  const idx = src.lastIndexOf('})();');
  src = src.slice(0, idx) + hook + '})();';
  vm.createContext(sandbox);
  let loadErr = null;
  try { vm.runInContext(src, sandbox, { filename: ASSET }); } catch (e) { loadErr = String((e && e.stack) || e); }
  return { sandbox, api: sandbox.__X || null, loadErr };
}

function eq(caseId, name, actual, expected) {
  // absence semantics: expected null = field must be ABSENT (undefined) or null;
  // expected value = exact match.
  if (expected === null || expected === undefined) {
    if (actual !== null && actual !== undefined) fails.push(`${caseId}.${name}: expected ABSENT got ${JSON.stringify(actual)}`);
    return;
  }
  if (String(actual) !== String(expected)) fails.push(`${caseId}.${name}: expected ${JSON.stringify(expected)} got ${JSON.stringify(actual)}`);
}

function normalize(v) {
  return String(v == null ? '' : v);
}

function gtOf(cs, key) { return cs.ground_truth[key]; }

(async () => {
  const m = loadAsset();
  if (m.loadErr || !m.api) { console.error('MODULE LOAD FAILED:', m.loadErr); process.exit(2); }
  const V = m.api;
  const corpus = JSON.parse(fs.readFileSync(CORPUS, 'utf8')).cases;
  console.log(`corpus: ${corpus.length} synthetic cases | asset: ${ASSET}`);

  for (const c of corpus) {
    // ── G5: RAW → canonical normalization (works on legacy and fixed assets)
    const FIELDMAP = { lot: 'volume', entry: 'entryPrice', exit: 'exitPrice',
                       pnl: 'profitLoss', commission: 'commission', sl: 'stopLoss', tp: 'takeProfit' };
    for (const [rawKey, gtKey] of Object.entries(FIELDMAP)) {
      const raw = c.input[rawKey];
      const exp = gtOf(c, gtKey);
      if (raw == null || exp == null) continue;
      let canon = V.normalizeNumber(V.faDigits(normalize(raw)));
      eq(c.id, `${rawKey}->canonical`, canon, exp);
    }

    // ── G6: extraction field mapping (auto-activates on fixed assets)
    if (V.extractionToParsed) {
      m.sandbox.window.__vsiExtraction = { provider: 'gemini', confidence: 0.9, fields: Object.assign({}, c.input) };
      const p = V.extractionToParsed();
      if (!p) { eq(c.id, 'extractionToParsed-result', 'non-null', 'null'); continue; }
      for (const [gtKey, exp] of Object.entries(c.ground_truth)) {
        if (gtKey === 'state') continue;
        const actual = p.fields[gtKey] && p.fields[gtKey].value;
        eq(c.id, `extract.${gtKey}`, actual, exp);
      }
      // invalid numerics must never survive as fields
      if (c.id === 'C11_garbage_values') {
        for (const bad of ['volume', 'entryPrice', 'profitLoss']) {
          if (p.fields[bad]) fails.push(`C11.${bad}: garbage value survived extraction`);
        }
        if (p.fields.direction) fails.push('C11.direction: invalid side survived extraction');
      }
      // multi-row: rows must NOT be merged or invented
      if (c.id === 'C16_multirow_unsupported') {
        if (p.fields.rows) fails.push('C16.rows: multi-row merged into single model (unsupported contract)');
      }
    } else {
      console.log(`  [skip] extract-level checks: extractionToParsed absent on this asset`);
    }

    // ── G7: contract-size + canonical P/L math
    if (V.inferContractSize) {
      const gt = c.ground_truth;
      if (gt.entryPrice != null && gt.exitPrice != null && gt.volume != null) {
        const inferred = V.inferContractSize({
          entryPrice: gt.entryPrice, exitPrice: gt.exitPrice, volume: gt.volume,
          profitLoss: gt.profitLoss != null ? gt.profitLoss : undefined,
          direction: gt.direction,
        });
        if (c.contractSize != null) eq(c.id, 'contractSize', inferred, c.contractSize);
        if (c.expected_pnl != null && inferred) {
          const delta = gt.direction === 'sell'
            ? (parseFloat(gt.entryPrice) - parseFloat(gt.exitPrice)) * parseFloat(gt.volume) * inferred
            : (parseFloat(gt.exitPrice) - parseFloat(gt.entryPrice)) * parseFloat(gt.volume) * inferred;
          eq(c.id, 'canonical-pnl', delta.toFixed(2), c.expected_pnl);
        }
      }
    } else {
      console.log('  [skip] math pins: inferContractSize absent on this asset');
    }
  }

  if (fails.length) {
    console.error(`EXTRACTION CORPUS: FAIL (${fails.length})`);
    fails.forEach(f => console.error('  ✗', f));
    process.exit(1);
  }
  console.log('EXTRACTION CORPUS: PASS (field-level, synthetic ground truth)');
  process.exit(0);
})().catch(e => { console.error('HARNESS ERROR:', e); process.exit(2); });
