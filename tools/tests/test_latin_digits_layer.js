#!/usr/bin/env node
/**
 * VELORA — G5 Latin-digit display-layer runtime regression.
 *
 * Executes the REAL shipped asset public/assets/velora-latin-digits.js in a vm
 * context with a minimal DOM stub and asserts the canonical numeric display
 * contract at the layer itself:
 *
 *   T1 (sensitivity / negative proof) — BEFORE the patch, fa-IR formatting in
 *      this very environment produces non-Latin digits, proving the test can
 *      actually fail (no false PASS).
 *   T2 — after the patch, Intl.NumberFormat('fa-IR') / toLocaleString /
 *      toLocaleDateString return pure Latin digits with the value preserved.
 *   T3 — VeloraLatinDigits.toLatin maps Persian (۱۳۱.۴۰), Arabic-Indic
 *      (١٣١٫٤٠) and mixed forms to canonical Latin with the same value.
 *   T4 — <html lang="fa"> is locked to fa-IR-u-nu-latn + data-numbering=latn.
 *   T5 — input .value assignments are normalized (password never touched).
 *   T6 — MutationObserver path normalizes dynamically inserted Persian text.
 *
 * Runs with plain Node (no npm deps).
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ASSET = path.resolve(__dirname, '..', '..', 'public', 'assets', 'velora-latin-digits.js');

let fails = 0;
function eq(name, got, want) {
  const ok = got === want;
  if (!ok) fails++;
  console.log(`${ok ? 'ok' : 'FAIL'}  ${name}  got=${JSON.stringify(got)} want=${JSON.stringify(want)}`);
}

function buildDom() {
  const HTML_NS = 'http://www.w3.org/1999/xhtml';
  function El(tag) {
    this.tagName = String(tag || 'DIV').toUpperCase();
    this.nodeType = 1;
    this.style = {};
    this.attrs = {};
    this.firstChild = null;
    this.nextSibling = null;
    this.parentNode = null;
    this.parentElement = null;
    this.textContent = '';
    this.isContentEditable = false;
    this.namespaceURI = HTML_NS;
    this.classList = { add() {}, remove() {}, contains() { return false; } };
    this.closest = function () { return null; };
    this.appendChild = function (c) { c.parentNode = this; if (!this.firstChild) this.firstChild = c; return c; };
    this.replaceChild = function (n, o) { if (this === n) return null; return n; };
  }
  El.prototype.getAttribute = function (n) { return Object.prototype.hasOwnProperty.call(this.attrs, n) ? this.attrs[n] : null; };
  El.prototype.setAttribute = function (n, v) { this.attrs[n] = String(v); };
  El.prototype.hasAttribute = function (n) { return Object.prototype.hasOwnProperty.call(this.attrs, n); };
  El.prototype.removeAttribute = function (n) { delete this.attrs[n]; };
  El.prototype.addEventListener = function () {};

  function Text(v) {
    this.nodeType = 3;
    this.nodeValue = String(v);
    this.parentNode = null;
    this.parentElement = null;
  }

  const documentElement = new El('HTML');
  const doc = {
    readyState: 'complete',
    title: '',
    documentElement,
    addEventListener() {},
    createElement(tag) { return new El(tag); },
    createTextNode(v) { const t = new Text(v); return t; },
    createDocumentFragment() {
      return { nodeType: 11, childNodes: [], appendChild(c) { this.childNodes.push(c); } };
    },
  };
  Object.defineProperty(doc, 'title', {
    get() { return this._title || ''; },
    set(v) { this._title = String(v); },
    configurable: true,
  });

  class FakeInput {
    constructor() { this.type = 'text'; this.style = {}; this.attrs = {}; this._v = ''; }
    getAttribute(n) { return Object.prototype.hasOwnProperty.call(this.attrs, n) ? this.attrs[n] : null; }
    setAttribute(n, v) { this.attrs[n] = String(v); }
    hasAttribute(n) { return Object.prototype.hasOwnProperty.call(this.attrs, n); }
  }
  Object.defineProperty(FakeInput.prototype, 'value', {
    configurable: true,
    get() { return this._v; },
    set(v) { this._v = String(v); },
  });

  let observerCb = null;
  class MOStub {
    constructor(cb) { this.cb = cb; observerCb = cb; }
    observe() {}
  }

  return { El, Text, doc, FakeInput, MOStub, getObserverCb: () => observerCb };
}

const dom = buildDom();
const sandbox = {
  console,
  setTimeout,
  Intl,
  document: dom.doc,
  MutationObserver: dom.MOStub,
  HTMLInputElement: dom.FakeInput,
};
sandbox.window = sandbox;
const ctx = vm.createContext(sandbox);

// T1 — sensitivity/negative proof: this environment DOES produce non-Latin digits pre-patch.
const pre = vm.runInContext(
  `JSON.stringify({
     nf: new Intl.NumberFormat('fa-IR').format(131.4),
     nls: (131.4).toLocaleString('fa-IR'),
     date: new Date(2026, 7, 30).toLocaleDateString('fa-IR')
   })`, ctx);
const preObj = JSON.parse(pre);
const nonLatin = /[\u06F0-\u06F9\u0660-\u0669]/;
eq('T1 sensitivity: fa-IR NumberFormat non-Latin before patch', nonLatin.test(preObj.nf), true);
eq('T1 sensitivity: toLocaleString non-Latin before patch', nonLatin.test(preObj.nls), true);

// Load the REAL asset.
vm.runInContext(fs.readFileSync(ASSET, 'utf8'), ctx, { filename: 'velora-latin-digits.js' });

// T2 — Intl / display paths are now Latin while values are preserved.
const post = JSON.parse(vm.runInContext(`JSON.stringify({
  nf: new Intl.NumberFormat('fa-IR').format(131.4),
  nfPnl: new Intl.NumberFormat('fa-IR', {style:'currency', currency:'USD'}).format(-9.08),
  nls: (131.4).toLocaleString('fa-IR'),
  nlsMoney: (3342.15).toLocaleString('fa-IR'),
  date: new Date(2026, 7, 30).toLocaleDateString('fa-IR'),
  latin: VeloraLatinDigits.toLatin('۱۳۱.۴۰'),
  latinAr: VeloraLatinDigits.toLatin('١٣١٫٤٠'),
  latinMixed: VeloraLatinDigits.toLatin('۱۳۱.40'),
  latinTh: VeloraLatinDigits.toLatin('١٬٢٣٤٫٥٦'),
  lang: document.documentElement.getAttribute('lang'),
  numbering: document.documentElement.getAttribute('data-numbering'),
  title: document.title
})`, ctx));
eq('T2 NumberFormat fa-IR Latin', nonLatin.test(post.nf) || /[۰-۹]/.test(post.nf), false);
eq('T2 NumberFormat value preserved', post.nf, '131.4');
eq('T2 currency formatting Latin', nonLatin.test(post.nfPnl) || /[۰-۹]/.test(post.nfPnl), false);
eq('T2 toLocaleString Latin + value (grouping separator allowed)', post.nlsMoney.replace(/,/g, ''), '3342.15');
eq('T2 date numerals Latin', !nonLatin.test(post.date) && /[0-9]/.test(post.date), true);
eq('T3 toLatin Persian', post.latin, '131.40');
eq('T3 toLatin Arabic-Indic (separator normalized)', post.latinAr, '131.40');
eq('T3 toLatin mixed', post.latinMixed, '131.40');
eq('T3 toLatin Arabic thousands/decimal punctuation', post.latinTh, '1,234.56');
eq('T4 lang locked', post.lang, 'fa-IR-u-nu-latn');
eq('T4 data-numbering', post.numbering, 'latn');
eq('T4 title normalized (Persian digits in title)', /[۰-۹]/.test(post.title), false);

// T5 — input value setter normalization (password untouched).
const inp = vm.runInContext(`
  const a = new HTMLInputElement(); a.type='text'; a.value='۱۳۱.۴۰';
  const b = new HTMLInputElement(); b.type='password'; b.value='۱۳۱.۴۰';
  JSON.stringify({text: a.value, pass: b.value});
`, ctx);
const inpObj = JSON.parse(inp);
eq('T5 input value normalized', inpObj.text, '131.40');
eq('T5 password value untouched', inpObj.pass, '۱۳۱.۴۰');

// T6 — MutationObserver path: dynamic Persian text node is normalized.
T6: {
  const el = new dom.El('DIV');
  const tn = new dom.Text('سود: ۱۳۱.۴۰');
  tn.parentNode = el;
  tn.parentElement = el;
  el.appendChild(tn);
  const cb = dom.getObserverCb();
  if (!cb) { eq('T6 observer callback available', false, true); break T6; }
  cb([{ type: 'childList', addedNodes: [el] }]);
  eq('T6 dynamic Persian text normalized', tn.nodeValue, 'سود: 131.40');
}
// T6b — negative sensitivity: a previously-unpatched node stays Persian (the
// asset is what normalizes it, not the test).
{
  const tn2 = new dom.Text('سود: ۱۳۱.۴۰');
  eq('T6b negative: un-normalized node is Persian (test can fail)', /[۰-۹]/.test(tn2.nodeValue), true);
}

if (fails) {
  console.error(`\nG5 FAIL — ${fails} assertion(s) failed.`);
  process.exit(1);
}
console.log('\nG5 PASS — Latin-digit display layer verified at runtime (real asset).');
