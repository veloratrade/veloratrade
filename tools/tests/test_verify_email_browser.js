#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..', '..');
const files = process.argv.length > 2
  ? process.argv.slice(2)
  : [
      path.join(root, 'verify-email/index.html'),
      path.join(root, 'localized/fa/verify-email/index.html'),
      path.join(root, 'localized/en/verify-email/index.html'),
    ];

async function check(file) {
  const html = fs.readFileSync(file, 'utf8');
  const scripts = [...html.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/gi)].map((m) => m[1]);
  const source = scripts.find((script) => script.includes("hashParams.get('token')"));
  if (!source) throw new Error(`${file}: verification script not found`);

  const elements = new Map(['icon', 'title', 'message', 'loginBtn', 'resendBtn'].map((id) => [id, {
    id,
    className: '',
    textContent: '',
    style: { display: 'none' },
  }]));
  const token = 'a'.repeat(64);
  let requestCall = null;
  let replacedUrl = null;
  const context = {
    URLSearchParams,
    location: {
      search: '?campaign=test',
      hash: `#token=${token}`,
      pathname: '/fa/verify-email/',
    },
    history: {
      replaceState(_state, _title, url) { replacedUrl = url; },
    },
    document: {
      title: 'Verify',
      getElementById(id) { return elements.get(id); },
    },
    VeloraLocale: {
      locale: 'fa',
      t(key) { return key; },
      errorMessage() { return 'error'; },
    },
    VeloraData: {
      request(endpoint, options) {
        requestCall = { endpoint, options };
        return Promise.resolve({ verified: true });
      },
    },
    console,
  };

  vm.runInNewContext(source, context, { filename: file });
  await new Promise((resolve) => setImmediate(resolve));

  const assert = (condition, message) => {
    if (!condition) throw new Error(`${file}: ${message}`);
  };
  assert(requestCall !== null, 'API request was not made');
  assert(requestCall.endpoint === '/api/v1/auth/verify-email', 'wrong endpoint');
  assert(requestCall.options.method === 'POST', 'request is not POST');
  assert(requestCall.options.body.token === token, 'fragment token was not copied to JSON body');
  assert(requestCall.options.body.notificationLocale === 'fa', 'locale missing from body');
  assert(requestCall.options.token === '', 'authorization token must remain empty');
  assert(replacedUrl === '/fa/verify-email/?campaign=test', 'capability was not removed from browser history');
  assert(!replacedUrl.includes(token), 'capability leaked into clean URL');
  assert(elements.get('loginBtn').style.display === 'inline-flex', 'success UI was not shown');
}

(async () => {
  for (const file of files) await check(file);
  console.log(`Verify-email JS behavior: PASS (${files.length} file(s), 9 assertions each)`);
})().catch((error) => {
  console.error(error.message);
  process.exit(1);
});
