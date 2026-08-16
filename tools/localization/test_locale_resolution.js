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

console.log('LOCALE_RESOLUTION_TEST_OK explicit_locale_url=true cookie_priority=true browser_primary=true unsupported_browser=en prelocalized_first_paint=true');
