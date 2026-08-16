'use strict';

// Dependency-free regression test for original-first rendering and stale-response fences.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((ok, fail) => { resolve = ok; reject = fail; });
  return { promise, resolve, reject };
}

function field(text) {
  return { textContent: text };
}

function contentNode({ id, hash, locale, title, summary, content }) {
  const fields = {
    title: field(title),
    summary: field(summary),
    content: field(content),
  };
  return {
    dataset: {
      contentType: 'news',
      contentId: id,
      sourceHash: hash,
      sourceLocale: locale,
    },
    querySelector(selector) {
      const match = selector.match(/data-content-field="([^"]+)"/);
      return match ? fields[match[1]] || null : null;
    },
    fields,
  };
}

function rootFor(node) {
  return {
    querySelectorAll() { return [node]; },
  };
}

async function flushPromises() {
  await Promise.resolve();
  await Promise.resolve();
  await new Promise((resolve) => setImmediate(resolve));
}

async function main() {
  const pending = [];
  const listeners = new Map();
  const document = {
    readyState: 'loading',
    addEventListener(name, callback) { listeners.set(name, callback); },
    querySelectorAll() { return []; },
  };
  const locale = {
    locale: 'en',
    normalize(value) { return String(value || '').toLowerCase().split('-')[0]; },
  };
  const data = {
    normalize: {
      content(item) {
        return {
          contentType: String(item.contentType || ''),
          contentId: String(item.contentId || ''),
          sourceLocale: String(item.sourceLocale || 'und'),
          sourceHash: String(item.sourceHash || ''),
          fields: { ...item.fields },
        };
      },
    },
    request(url, options) {
      assert.equal(url, '/api/v1/content-translations/lookup');
      assert.equal(options.method, 'POST');
      const call = deferred();
      call.options = options;
      pending.push(call);
      return call.promise;
    },
  };
  const window = { VeloraLocale: locale, VeloraData: data };
  const context = vm.createContext({ window, document, console, setImmediate });
  const scriptPath = path.resolve(__dirname, '../../public/assets/velora-dynamic-content.js');
  vm.runInContext(fs.readFileSync(scriptPath, 'utf8'), context, { filename: scriptPath });
  const dynamic = window.VeloraDynamicContent;

  // Original copy paints synchronously, and an old locale response cannot repaint after a switch.
  const node = contentNode({
    id: 'article-1', hash: 'hash-1', locale: 'fa',
    title: 'عنوان اصلی', summary: 'خلاصه اصلی', content: 'متن اصلی',
  });
  const root = rootFor(node);
  dynamic.bind(root);
  assert.equal(node.fields.title.textContent, 'عنوان اصلی');
  assert.equal(pending.length, 1);

  locale.locale = 'fa';
  dynamic.bind(root);
  assert.equal(node.fields.title.textContent, 'عنوان اصلی');
  assert.equal(pending.length, 1, 'source locale must not trigger a lookup');

  pending[0].resolve({
    targetLocale: 'en',
    translations: [{
      contentType: 'news', contentId: 'article-1', sourceHash: 'hash-1',
      fields: { title: 'Old English title', summary: 'Old English summary', content: 'Old English body' },
    }],
  });
  await flushPromises();
  assert.equal(node.fields.title.textContent, 'عنوان اصلی', 'stale locale response overwrote source copy');

  locale.locale = 'en';
  dynamic.bind(root);
  assert.equal(node.fields.title.textContent, 'Old English title', 'memory cache was not painted immediately');
  assert.equal(pending.length, 1, 'memory cache should avoid another lookup');

  // A response for an old source hash cannot overwrite a newly rendered source revision.
  node.dataset.sourceHash = 'hash-2';
  node.fields.title.textContent = 'نسخه دوم';
  node.fields.summary.textContent = 'خلاصه دوم';
  node.fields.content.textContent = 'متن دوم';
  dynamic.bind(root);
  assert.equal(node.fields.title.textContent, 'نسخه دوم');
  assert.equal(pending.length, 2);

  node.dataset.sourceHash = 'hash-3';
  node.fields.title.textContent = 'نسخه سوم';
  node.fields.summary.textContent = 'خلاصه سوم';
  node.fields.content.textContent = 'متن سوم';
  dynamic.bind(root);
  assert.equal(node.fields.title.textContent, 'نسخه سوم');
  assert.equal(pending.length, 3);

  pending[1].resolve({
    targetLocale: 'en',
    translations: [{
      contentType: 'news', contentId: 'article-1', sourceHash: 'hash-2',
      fields: { title: 'Stale revision', summary: 'Stale summary', content: 'Stale body' },
    }],
  });
  await flushPromises();
  assert.equal(node.fields.title.textContent, 'نسخه سوم', 'stale content identity overwrote the new revision');

  pending[2].resolve({
    targetLocale: 'en',
    translations: [{
      contentType: 'news', contentId: 'article-1', sourceHash: 'hash-3',
      fields: { title: 'Current revision', summary: 'Current summary', content: 'Current body' },
    }],
  });
  await flushPromises();
  assert.equal(node.fields.title.textContent, 'Current revision');
  assert.equal(node.dataset.contentLocale, 'en');

  console.log('DYNAMIC_CONTENT_TEST_OK original-first=true locale-fence=true identity-fence=true cache-reuse=true');
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
