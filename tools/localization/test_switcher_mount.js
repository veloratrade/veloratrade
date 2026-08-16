#!/usr/bin/env node
'use strict';

/* Regression coverage for navigation-first, responsive-safe locale switcher placement. */
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.resolve(__dirname, '..', '..');
const source = fs.readFileSync(path.join(ROOT, 'public/assets/velora-localization.js'), 'utf8');
const css = fs.readFileSync(path.join(ROOT, 'public/assets/velora-localization.css'), 'utf8');
const registry = JSON.parse(fs.readFileSync(path.join(ROOT, 'public/locales/manifest.json'), 'utf8'));
if (!css.includes('[data-placement="inline"]') || !css.includes('[data-placement="dock"]')) {
  throw new Error('Switcher CSS must distinguish inline and fallback placements');
}
if (!css.includes('inset-block-end:') || !css.includes('inset-inline-end:') || !/@media\(max-width:540px\)/.test(css)) {
  throw new Error('Switcher CSS must use logical fallback placement and a narrow-screen layout');
}

class Element {
  constructor(tagName) {
    this.tagName = String(tagName || '').toUpperCase();
    this.attributes = Object.create(null);
    this.children = [];
    this.parentNode = null;
    this.firstChild = null;
    this.className = '';
    this.textContent = '';
    this.value = '';
    this.listeners = Object.create(null);
    this.beforeSelector = null;
    this.beforeElement = null;
  }
  setAttribute(name, value) { this.attributes[name] = String(value); }
  getAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null; }
  hasAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attributes, name); }
  appendChild(child) {
    child.parentNode = this;
    this.children.push(child);
    this.firstChild = this.children[0] || null;
    return child;
  }
  insertBefore(child, before) {
    child.parentNode = this;
    const index = before ? this.children.indexOf(before) : -1;
    if (index >= 0) this.children.splice(index, 0, child);
    else this.children.unshift(child);
    this.firstChild = this.children[0] || null;
    return child;
  }
  querySelector(selector) { return selector === this.beforeSelector ? this.beforeElement : null; }
  querySelectorAll() { return []; }
  addEventListener(type, listener) { this.listeners[type] = listener; }
}

async function render(kind) {
  const classes = new Set();
  const rootAttributes = { 'data-i18n-features': 'common,errors' };
  const root = new Element('html');
  root.nodeType = 1;
  root.lang = '';
  root.dir = '';
  root.classList = { add(value) { classes.add(value); }, remove(value) { classes.delete(value); } };
  root.getAttribute = name => rootAttributes[name] || null;
  root.setAttribute = (name, value) => { rootAttributes[name] = String(value); };
  root.hasAttribute = name => Object.prototype.hasOwnProperty.call(rootAttributes, name);
  root.querySelectorAll = () => [];

  const body = new Element('body');
  const candidates = Object.create(null);
  let expectedTarget = body;
  let expectedBefore = null;
  if (kind === 'landing') {
    expectedTarget = new Element('div');
    expectedBefore = new Element('button');
    expectedTarget.appendChild(new Element('nav'));
    expectedTarget.appendChild(expectedBefore);
    expectedTarget.beforeSelector = '#nav-toggle';
    expectedTarget.beforeElement = expectedBefore;
    candidates['#header .nav'] = expectedTarget;
  } else if (kind === 'app') {
    expectedTarget = new Element('div');
    expectedBefore = new Element('a');
    expectedTarget.appendChild(expectedBefore);
    candidates['.velora-nav-right'] = expectedTarget;
  } else if (kind === 'slot') {
    expectedTarget = new Element('div');
    candidates['[data-velora-locale-slot]'] = expectedTarget;
  }

  const document = {
    readyState: 'complete',
    documentElement: root,
    body,
    cookie: '',
    querySelector(selector) {
      if (selector === '[data-velora-locale-switcher]') return null;
      return candidates[selector] || null;
    },
    querySelectorAll() { return []; },
    addEventListener() {},
    dispatchEvent() {},
    createElement(tagName) { return new Element(tagName); }
  };
  const window = {
    __VELORA_LOCALE_REGISTRY__: registry,
    __VELORA_LOCALE__: 'en',
    location: { pathname: '/', assign() {} },
    localStorage: { setItem() {} }
  };
  const context = {
    window,
    document,
    console,
    Intl,
    Map,
    Set,
    Promise,
    Object,
    Array,
    String,
    Number,
    Date,
    Error,
    encodeURIComponent,
    fetch() {
      return Promise.resolve({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ messages: { 'common.language': 'Language' } })
      });
    },
    CustomEvent: function CustomEvent(type, options) { this.type = type; this.detail = options && options.detail; }
  };
  vm.runInNewContext(source, context, { filename: 'velora-localization.js' });
  await window.VeloraLocale.ready;

  const switcher = expectedTarget.children.find(child => child.attributes['data-velora-locale-switcher'] === '');
  if (!switcher) throw new Error(`${kind}: switcher did not mount in expected target`);
  const select = switcher.children.find(child => child.attributes['data-velora-locale-select'] === '');
  if (!select) throw new Error(`${kind}: locale select missing`);
  const enabledLocales = Object.entries(registry.locales).filter(([, meta]) => meta.enabled !== false);
  if (select.children.length !== enabledLocales.length) {
    throw new Error(`${kind}: options are not registry-driven`);
  }
  const placement = switcher.attributes['data-placement'];
  if (kind === 'fallback' && placement !== 'dock') throw new Error('fallback: expected dock placement');
  if (kind !== 'fallback' && placement !== 'inline') throw new Error(`${kind}: expected inline placement`);
  if ((kind === 'landing' || kind === 'app') && expectedTarget.children.indexOf(switcher) >= expectedTarget.children.indexOf(expectedBefore)) {
    throw new Error(`${kind}: switcher did not mount before the existing action/menu target`);
  }
  return placement;
}

Promise.all(['slot', 'landing', 'app', 'fallback'].map(render)).then(results => {
  console.log(`SWITCHER_MOUNT_TEST_OK slot=${results[0]} landing=${results[1]} app=${results[2]} fallback=${results[3]} registry_driven=true`);
}).catch(error => {
  console.error(error && error.stack || error);
  process.exitCode = 1;
});
