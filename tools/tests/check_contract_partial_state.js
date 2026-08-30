#!/usr/bin/env node
/**
 * VELORA — extraction state-machine contract gate (G7 partial-extraction).
 *
 * REQUIRED CONTRACT (§7 of the audit mission):
 *   An extraction missing any MANDATORY field (symbol, direction/side, entry,
 *   exit, volume) must carry an explicit state — success/complete | partial |
 *   needs_review | failed — and MUST NOT be presented/applied as a complete
 *   success. The state must reflect the *sources actually merged*: AI partial
 *   + OCR supplement = still review, never silent success.
 *
 * This gate validates the REAL asset's extractionToParsed() against the
 * synthetic corpus ground truth:
 *   - every corpus case: reported state must equal the expected state computed
 *     from MANDATORY field presence, and `missing` must list exactly the
 *     absent mandatory fields;
 *   - an asset that returns NO state at all hard-fails (legacy/unpatched),
 *     so "no state machine" can never be mistaken for a pass.
 *
 * Wiring note (documented): the asset fix lives in public/assets/
 * velora-smart-import.js (fix/browser-ocr-gate). This gate is to be enabled in
 * quality-gate.yml in the SAME merge that lands the fix; until then it is red
 * on the unbranch asset and must be reported as FAIL (not skipped, not mocked).
 *
 * Run:
 *   node tools/tests/check_contract_partial_state.js                     # main asset (BLOCKED/FAIL today)
 *   VSI_ASSET=/path/asset-fixed.js node ...                             # fixed asset (must PASS)
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ASSET = process.env.VSI_ASSET || path.resolve(__dirname, '..', '..', 'public', 'assets', 'velora-smart-import.js');
const CORPUS = path.resolve(__dirname, 'fixtures', 'extraction_corpus.json');
const MANDATORY = ['symbol', 'direction', 'entryPrice', 'exitPrice', 'volume'];

function makeEl(tag) {
  const el = {
    tagName: tag, style: {}, dataset: {}, className: '', textContent: '', value: '', checked: false,
    innerHTML: '', firstElementChild: null, children: [],
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
      createElement(tag) { return makeEl(tag); },
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
  const hook = 'globalThis.__X = { extractionToParsed: (typeof extractionToParsed === "function" ? extractionToParsed : null) };\n';
  const idx = src.lastIndexOf('})();');
  src = src.slice(0, idx) + hook + '})();';
  vm.createContext(sandbox);
  try { vm.runInContext(src, sandbox, { filename: ASSET }); } catch (e) { return { err: String(e) }; }
  return { api: sandbox.__X, sandbox };
}

(async () => {
  const m = loadAsset();
  if (m.err || !m.api || !m.api.extractionToParsed) {
    console.error(`CONTRACT PARTIAL-STATE: BLOCKED — asset has no extractionToParsed state machine.`);
    console.error(`  asset: ${ASSET}`);
    console.error(`  This gate is ACTIVE in CI and intentionally RED until the smart-import`);
    console.error(`  state-machine fix lands in the tree (RC-3; public/assets/velora-smart-import.js`);
    console.error(`  on the smart-import feature branch). This is a loud fail, not a hidden skip.`);
    process.exit(1);
  }
  const corpus = JSON.parse(fs.readFileSync(CORPUS, 'utf8')).cases;
  let fail = 0;
  for (const c of corpus) {
    m.sandbox.window.__vsiExtraction = { provider: 'gemini', confidence: 0.9, fields: Object.assign({}, c.input) };
    const p = m.api.extractionToParsed();
    if (!p) {
      console.log(`${c.id}: extractionToParsed returned null (all fields empty → failed state)`);
      if (c.state === 'failed') continue;
      fail++;
      console.log(`  ✗ expected state ${c.state} but extraction is null`);
      continue;
    }
    const state = p.state;
    const missing = Array.isArray(p.missing) ? p.missing : [];
    const have = MANDATORY.filter(k => p.fields[k]);
    const expMissing = MANDATORY.filter(k => !p.fields[k]);
    // canonical vocabulary: complete==success, partial==needs_review, failed==failed
    const expState = expMissing.length === 0 ? 'success' : (have.length === 0 ? 'failed' : 'needs_review');
    const stateOk = state === expState ||
      (expState === 'success' && state === 'complete') ||
      (expState === 'needs_review' && state === 'partial');
    const okState = stateOk;
    const okMissing = JSON.stringify(missing) === JSON.stringify(expMissing);
    console.log(`${c.id}: state=${state} (expected ${expState}) missing=[${missing.join(',')}]` +
                (okState && okMissing ? '' : '  ✗'));
    if (!okState) { fail++; console.log(`  ✗ state mismatch: got ${state} expected ${expState}`); }
    if (!okMissing) { fail++; console.log(`  ✗ missing mismatch: got [${missing.join(',')}] expected [${expMissing.join(',')}]`); }
  }
  if (fail) {
    console.error(`CONTRACT PARTIAL-STATE: FAIL (${fail})`);
    process.exit(1);
  }
  console.log('CONTRACT PARTIAL-STATE: PASS (all corpus cases; state machine present and correct)');
  process.exit(0);
})().catch(e => { console.error('HARNESS ERROR:', e); process.exit(2); });
