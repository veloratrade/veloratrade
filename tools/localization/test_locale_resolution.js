#!/usr/bin/env node
'use strict';

/* Regression coverage for server-aligned first-paint locale resolution. */
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.resolve(__dirname, '..', '..');
const bootstrap = fs.readFileSync(path.join(ROOT, 'public/assets/velora-locale-bootstrap.js'), 'utf8');
const registry = JSON.parse(fs.readFileSync(path.join(ROOT, 'public/locales/manifest.json'), 'utf8'));

function resolve(options = {}) {
  const attributes = {
    'data-route-locale': options.declaredRoute === undefined ? 'fa' : options.declaredRoute,
    'data-velora-prelocalized': options.prelocalized || ''
  };
  const classes = new Set();
  const root = {
    lang: '', dir: '',
    getAttribute(name) { return attributes[name] || null; },
    setAttribute(name, value) { attributes[name] = String(value); },
    classList: { add(name) { classes.add(name); }, remove(name) { classes.delete(name); } }
  };
  const storage = new Map();
  if (options.stored) storage.set(registry.storageKey, options.stored);
  const cookie = options.cookie ? `${registry.cookieKey}=${encodeURIComponent(options.cookie)}` : '';
  const window = {
    __VELORA_LOCALE_REGISTRY__: registry,
    location: { pathname: options.pathname || '/' },
    navigator: {
      language: options.language || '',
      languages: options.languages === undefined ? [options.language || ''] : options.languages
    },
    localStorage: { getItem(key) { return storage.has(key) ? storage.get(key) : null; } }
  };
  const context = { window, document: { documentElement: root, cookie }, Object, String, Error, decodeURIComponent };
  vm.runInNewContext(bootstrap, context, { filename: 'velora-locale-bootstrap.js' });
  return {
    locale: window.__VELORA_LOCALE__, lang: root.lang, dir: root.dir,
    dataLocale: attributes['data-locale'], booting: classes.has('velora-locale-booting')
  };
}

function expect(name, options, expected) {
  const actual = resolve(options);
  for (const [key, value] of Object.entries(expected)) {
    if (actual[key] !== value) throw new Error(`${name}: expected ${key}=${JSON.stringify(value)}, got ${JSON.stringify(actual[key])}`);
  }
}

expect('Persian server HTML paints immediately', { language: 'fa-IR', prelocalized: 'fa' }, {
  locale: 'fa', lang: 'fa-IR', dir: 'rtl', dataLocale: 'fa', booting: false
});
expect('English server HTML paints immediately', { language: 'en-US', prelocalized: 'en' }, {
  locale: 'en', lang: 'en-GB', dir: 'ltr', booting: false
});
expect('German browser falls back to English', { language: 'de-DE', prelocalized: 'en' }, {
  locale: 'en', lang: 'en-GB', dir: 'ltr', booting: false
});
expect('Arabic browser falls back to English', { language: 'ar-SA', prelocalized: 'en' }, {
  locale: 'en', dir: 'ltr', booting: false
});
expect('Primary browser language is authoritative', { language: 'de-DE', languages: ['de-DE', 'fa-IR'], prelocalized: 'en' }, {
  locale: 'en', dir: 'ltr'
});
expect('Cookie choice wins over browser and storage', { cookie: 'en', stored: 'fa', language: 'fa-IR', prelocalized: 'en' }, {
  locale: 'en', dir: 'ltr', booting: false
});
expect('Migrated localStorage choice wins when cookie absent', { stored: 'fa', language: 'de-DE', prelocalized: 'en' }, {
  locale: 'fa', dir: 'rtl', booting: true
});
expect('Server-declared locale is used without browser preference', { language: '', languages: [], declaredRoute: 'en', prelocalized: 'en' }, {
  locale: 'en', dir: 'ltr', booting: false
});
expect('Explicit locale URL wins over persisted and browser choices', {
  pathname: '/en/dashboard/', cookie: 'fa', stored: 'fa', language: 'fa-IR', declaredRoute: 'en', prelocalized: 'en'
}, {
  locale: 'en', dir: 'ltr', booting: false
});

/* F-03 regression: [data-velora-localized-href] anchors are rewritten with the
   resolved locale prefix so navigation (e.g. pricing CTA -> /checkout/) keeps
   the active locale. Already-prefixed and external links stay untouched. */
function resolveAnchors(options, anchorHrefs) {
  const anchors = anchorHrefs.map((href) => {
    const attrs = { href, 'data-velora-localized-href': '' };
    return {
      getAttribute(name) { return attrs[name] === undefined ? null : attrs[name]; },
      setAttribute(name, value) { attrs[name] = String(value); },
      get href() { return attrs.href; }
    };
  });
  const attributes = {
    'data-route-locale': options.declaredRoute === undefined ? 'fa' : options.declaredRoute,
    'data-velora-prelocalized': options.prelocalized || ''
  };
  const root = {
    lang: '', dir: '',
    getAttribute(name) { return attributes[name] || null; },
    setAttribute(name, value) { attributes[name] = String(value); },
    classList: { add() {}, remove() {} }
  };
  const window = {
    __VELORA_LOCALE_REGISTRY__: registry,
    location: { pathname: options.pathname || '/' },
    navigator: {
      language: options.language || '',
      languages: options.languages === undefined ? [options.language || ''] : options.languages
    },
    localStorage: { getItem() { return null; } },
    setTimeout() {}
  };
  const documentMock = {
    documentElement: root,
    cookie: options.cookie ? `${registry.cookieKey}=${encodeURIComponent(options.cookie)}` : '',
    readyState: 'complete',
    addEventListener() {},
    querySelectorAll(selector) {
      if (selector !== '[data-velora-localized-href]') throw new Error('unexpected selector: ' + selector);
      return anchors;
    }
  };
  const context = { window, document: documentMock, Object, String, Error, decodeURIComponent };
  vm.runInNewContext(bootstrap, context, { filename: 'velora-locale-bootstrap.js' });
  return anchors.map((anchor) => anchor.href);
}

function expectAnchors(name, options, hrefs, expected) {
  const actual = resolveAnchors(options, hrefs);
  if (JSON.stringify(actual) !== JSON.stringify(expected)) {
    throw new Error(`${name}: expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
  }
}

expectAnchors('English page rewrites checkout CTA to /en/', { pathname: '/en/', declaredRoute: 'en', prelocalized: 'en', language: 'fa-IR' },
  ['/checkout/?plan=professional'], ['/en/checkout/?plan=professional']);
expectAnchors('Persian page rewrites checkout CTA to /fa/', { pathname: '/fa/', declaredRoute: 'fa', prelocalized: 'fa', language: 'en-GB' },
  ['/checkout/?plan=professional'], ['/fa/checkout/?plan=professional']);
expectAnchors('Cookie locale drives rewrite on unprefixed page', { cookie: 'en', prelocalized: 'en', language: 'fa-IR' },
  ['/checkout/'], ['/en/checkout/']);
expectAnchors('Already-prefixed, root, and external links are preserved', { pathname: '/en/', declaredRoute: 'en', prelocalized: 'en' },
  ['/en/checkout/', '/', 'https://example.com/x', '//cdn.example.com/y'],
  ['/en/checkout/', '/en/', 'https://example.com/x', '//cdn.example.com/y']);

console.log('LOCALE_RESOLUTION_TEST_OK explicit_locale_url=true cookie_priority=true browser_primary=true unsupported_browser=en prelocalized_first_paint=true localized_href_rewrite=true');
