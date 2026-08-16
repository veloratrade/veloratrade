#!/usr/bin/env node
'use strict';

/* Proves runtime loads only the active locale's declared feature chunks. */
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.resolve(__dirname, '..', '..');
const registrySource = fs.readFileSync(path.join(ROOT, 'public/assets/velora-locale-registry.js'), 'utf8');
const localizationSource = fs.readFileSync(path.join(ROOT, 'public/assets/velora-localization.js'), 'utf8');
if (Buffer.byteLength(registrySource) > 10000 || registrySource.includes('__VELORA_PRELOADED_CATALOGS__')) {
  throw new Error('Registry still embeds full catalogs');
}

async function run(locale) {
  const html = fs.readFileSync(path.join(ROOT, 'localized', locale, 'dashboard', 'index.html'), 'utf8');
  const featureMatch = html.match(/data-i18n-features="([^"]+)"/);
  if (!featureMatch) throw new Error(`Missing feature declaration for ${locale}`);
  const featureString = featureMatch[1];
  const features = featureString.split(',');
  const attributes = {
    'data-i18n-features': featureString,
    'data-velora-prelocalized': locale,
    'data-locale': locale
  };
  const classes = new Set();
  const root = {
    nodeType: 1,
    attributes: [],
    classList: { add(value) { classes.add(value); }, remove(value) { classes.delete(value); } },
    getAttribute(name) { return attributes[name] || null; },
    setAttribute(name, value) { attributes[name] = String(value); },
    hasAttribute(name) { return Object.prototype.hasOwnProperty.call(attributes, name); },
    querySelectorAll() { return []; }
  };
  const urls = [];
  const document = {
    readyState: 'complete',
    documentElement: root,
    body: { dir: '', appendChild() {} },
    cookie: '',
    querySelector() { return {}; },
    querySelectorAll() { return []; },
    addEventListener() {},
    dispatchEvent() {},
    createElement() { throw new Error('Unexpected element creation'); }
  };
  const window = { __VELORA_LOCALE__: locale, localStorage: { setItem() {} } };
  const context = {
    window,
    document,
    console,
    fetch(url) {
      urls.push(url);
      const pathname = String(url).split('?', 1)[0];
      const expectedPrefix = `/public/locales/chunks/${locale}/`;
      if (!pathname.startsWith(expectedPrefix)) return Promise.reject(new Error(`Cross-locale/full catalog fetch: ${url}`));
      const diskPath = path.join(ROOT, pathname.replace(/^\//, ''));
      const payload = JSON.parse(fs.readFileSync(diskPath, 'utf8'));
      return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(payload) });
    },
    CustomEvent: function CustomEvent(type, options) { this.type = type; this.detail = options && options.detail; }
  };
  vm.runInNewContext(registrySource, context, { filename: 'velora-locale-registry.js' });
  vm.runInNewContext(localizationSource, context, { filename: 'velora-localization.js' });
  await window.VeloraLocale.ready;
  if (urls.length !== features.length) throw new Error(`${locale}: expected ${features.length} feature fetches, got ${urls.length}`);
  if (urls.some(url => /\/public\/locales\/(?:fa|en)\.json/.test(url))) throw new Error(`${locale}: full catalog fetched`);
  if (window.VeloraLocale.t('common.user') === 'common.user') throw new Error(`${locale}: common feature unavailable`);
  if (classes.has('velora-locale-booting')) throw new Error(`${locale}: copy remained concealed`);
  return { features: features.length, fetches: urls.length };
}

Promise.all([run('fa'), run('en')]).then(([fa, en]) => {
  console.log(`FEATURE_CATALOG_TEST_OK fa_features=${fa.features} en_features=${en.features} active_locale_only=true full_catalog_fetches=0 registry_bytes=${Buffer.byteLength(registrySource)}`);
}).catch((error) => {
  console.error(error && error.stack || error);
  process.exitCode = 1;
});
