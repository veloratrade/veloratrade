#!/usr/bin/env node
'use strict';

/* R1 regression: after a successful login the browser must navigate to a
   locale-prefixed dashboard (/{locale}/dashboard/) so the server does not
   re-negotiate locale via users.locale/cookie at a moment when the preference
   may not yet be persisted. Both the canonical source template and every
   generated localized login page are scanned. */

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..', '..');

function read(rel) {
  return fs.readFileSync(path.join(ROOT, rel), 'utf8');
}

function assert(cond, message) {
  if (!cond) throw new Error(message);
}

const targets = [
  'login/index.html',
  'localized/fa/login/index.html',
  'localized/en/login/index.html',
];

for (const rel of targets) {
  const html = read(rel);

  // 1. A locale-aware helper must exist and be used.
  assert(/function\s+dashboardUrl\s*\(/.test(html), `${rel}: missing dashboardUrl() helper`);
  assert(/dashboardUrl\s*\(\)/.test(html), `${rel}: dashboardUrl() is not invoked`);

  // 2. The only direct window.location.replace('/dashboard') literals must be gone.
  const bad = html.match(/window\.location\.replace\(\s*['"]\/dashboard['"]\s*\)/g) || [];
  assert(bad.length === 0, `${rel}: found unprefixed /dashboard redirect(s): ${bad.join('; ')}`);

  // 3. After setSession, navigation must use dashboardUrl(), not a literal
  //    (allowing for the R3 persistence-await block between them).
  const setSessionIdx = html.indexOf('VeloraData.setSession');
  assert(setSessionIdx !== -1, `${rel}: missing VeloraData.setSession call`);
  const after = html.slice(setSessionIdx, setSessionIdx + 1200);
  assert(/location\.replace\(\s*dashboardUrl\s*\(\s*\)\s*\)/.test(after),
    `${rel}: post-setSession navigation is not dashboardUrl()`);
}

// 4. Register payload must include the UI locale so users.locale is correct
//    on first login (R4).
for (const rel of ['register/index.html', 'localized/en/register/index.html', 'localized/fa/register/index.html']) {
  const html = read(rel);
  assert(/(?:["']?locale["']?)\s*:\s*VeloraLocale\.locale/.test(html),
    `${rel}: register payload does not send locale: VeloraLocale.locale`);
}

console.log('POST_LOGIN_REDIRECT_TEST_OK files=' + targets.length + ' locale_prefixed_dashboard=true register_sends_locale=true');
