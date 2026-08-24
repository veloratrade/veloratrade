#!/usr/bin/env node
'use strict';

/**
 * VELORA — trades/new regression spec.
 *
 * Route: trades/new/index.html  (slug: trades-new)
 *
 * Self-contained Playwright spec: serves the repository tree over a local
 * static HTTP server and stubs the JSON API from the network layer, so it
 * runs locally and in CI without a backend or staging environment.
 *
 * Regression coverage (all previously broken by the start() initializer
 * crashing before the symbol list was ever rendered):
 *   1. page load produces ZERO uncaught JavaScript errors;
 *   2. the symbol list populates (>= 60 items) without user interaction;
 *   3. the list scrolls with the desktop mouse wheel;
 *   4. the list scrolls with mobile touch;
 *   5. the dropdown adapts its max-height to a short (keyboard) viewport;
 *   6. search/filter works;
 *   7. selecting a symbol updates the hidden input + trigger button and
 *      records a recent symbol;
 *   8. starring a symbol persists to localStorage;
 *   9. the manual trade submission fires POST /api/v1/trades with a valid body.
 *
 * Both the FA root template and the EN localized build are exercised.
 *
 * Usage: node tools/e2e/trades-new.spec.js   (needs `playwright` requireable)
 */

const http = require('http');
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const ROOT = path.resolve(__dirname, '..', '..');
const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.ico': 'image/x-icon',
  '.txt': 'text/plain; charset=utf-8',
  '.webmanifest': 'application/manifest+json',
};

const SESSION_BODY = JSON.stringify({
  data: { tokens: { accessToken: 'spec.fake.token', user: { fullName: 'Spec User', email: 'spec@velora.test', role: 'user' } } },
});
const SYMBOLS_BODY = JSON.stringify({ data: { symbols: ['EUR/USD', 'XAU/USD', 'BTC/USDT'] } });

const failures = [];
function step(name, ok, detail) {
  console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  -- ' + detail : ''}`);
  if (!ok) failures.push(`${name}: ${detail || 'failed'}`);
}

function startServer() {
  return new Promise((resolve) => {
    const server = http.createServer((req, res) => {
      const urlPath = decodeURIComponent(req.url.split('?')[0]);
      let file = path.join(ROOT, urlPath);
      if (urlPath.endsWith('/')) file = path.join(file, 'index.html');
      fs.readFile(file, (err, data) => {
        if (err) { res.writeHead(404, { 'Content-Type': 'text/plain' }); res.end('not found'); return; }
        res.writeHead(200, { 'Content-Type': MIME[path.extname(file)] || 'application/octet-stream' });
        res.end(data);
      });
    });
    server.listen(0, '127.0.0.1', () => resolve({ server, base: `http://127.0.0.1:${server.address().port}` }));
  });
}

/** Registers API stubs. Playwright matches the LAST registered route first,
 *  so the catch-all must be installed BEFORE the specific ones. */
async function stubApi(page, captured) {
  await page.route('**/api/**', (route) => route.fulfill({
    status: 404, contentType: 'application/json', body: '{"error":{"code":"NOT_FOUND","message":"stub"}}',
  }));
  await page.route('**/api/v1/auth/refresh', (route) => route.fulfill({
    contentType: 'application/json', body: SESSION_BODY,
  }));
  await page.route('**/api/v1/trades/symbols*', (route) => route.fulfill({
    contentType: 'application/json', body: SYMBOLS_BODY,
  }));
  await page.route('**/api/v1/trades', (route) => {
    if (route.request().method() !== 'POST') return route.fulfill({ status: 404, body: '{}' });
    captured.push({ url: route.request().url(), body: route.request().postDataJSON() });
    return route.fulfill({ contentType: 'application/json', body: '{"data":{"trade":{"id":1014}}}' });
  });
}

async function openDropdown(page) {
  await page.click('#symBtn');
  await page.waitForTimeout(300);
  return page.evaluate(() => document.getElementById('symDropdown').classList.contains('show'));
}

async function wheelScroll(page, amount) {
  const cdp = await page.context().newCDPSession(page);
  const point = await page.evaluate(() => {
    const r = document.getElementById('symList').getBoundingClientRect();
    return { x: Math.round(r.left + r.width / 2), y: Math.round(r.top + 60) };
  });
  await cdp.send('Input.dispatchMouseEvent', { type: 'mouseWheel', x: point.x, y: point.y, deltaX: 0, deltaY: amount });
  await page.waitForTimeout(250);
  return page.evaluate(() => document.getElementById('symList').scrollTop);
}

async function touchScroll(page) {
  const cdp = await page.context().newCDPSession(page);
  const point = await page.evaluate(() => {
    const r = document.getElementById('symList').getBoundingClientRect();
    return { x: Math.round(r.left + r.width / 2), y: Math.round(r.top + 70) };
  });
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [point] });
  for (let i = 1; i <= 8; i++) {
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: point.x, y: point.y - i * 30 }] });
    await page.waitForTimeout(16);
  }
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await page.waitForTimeout(350);
  return page.evaluate(() => document.getElementById('symList').scrollTop);
}

async function runLocale(browser, base, label, pagePath) {
  console.log(`\n== ${label} (${pagePath})`);
  const pageErrors = [];
  const capturedPosts = [];

  // ---------- desktop ----------
  const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
  page.on('pageerror', (e) => pageErrors.push(String(e).slice(0, 160)));
  await stubApi(page, capturedPosts);
  await page.goto(`${base}${pagePath}?mode=manual`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1200);

  step(`${label}: no uncaught JS error on load`, pageErrors.length === 0, pageErrors.join(' | ') || 'clean');
  step(`${label}: manual form rendered`, await page.evaluate(() => getComputedStyle(document.querySelector('.form-grid')).display !== 'none'));

  const shown = await openDropdown(page);
  const counts = await page.evaluate(() => ({
    items: document.querySelectorAll('#symList .sym-item').length,
    clientH: document.getElementById('symList').clientHeight,
    scrollH: document.getElementById('symList').scrollHeight,
  }));
  step(`${label}: dropdown opens`, shown);
  step(`${label}: symbol list populated (>=60)`, counts.items >= 60, `items=${counts.items}`);
  step(`${label}: list actually overflows (scrollable content)`, counts.scrollH > counts.clientH, `scrollH=${counts.scrollH} clientH=${counts.clientH}`);
  step(`${label}: desktop wheel scrolls the list`, (await wheelScroll(page, 480)) > 0);

  // search / filter
  await page.fill('#symSearch', 'XAU');
  await page.waitForTimeout(250);
  const filtered = await page.evaluate(() => document.querySelectorAll('#symList .sym-item').length);
  step(`${label}: search filter narrows results`, filtered > 0 && filtered < counts.items, `filtered=${filtered} of ${counts.items}`);
  await page.fill('#symSearch', '');
  await page.waitForTimeout(250);

  // star a NON-default symbol and verify persistence (readStars() seeds the
  // DEFAULT_STARS on first read, so toggle an unlisted one for a clean check)
  await page.fill('#symSearch', 'SOL');
  await page.waitForTimeout(250);
  await page.click('#symList .sym-item .sym-star');
  await page.waitForTimeout(200);
  const starred = await page.evaluate(() => JSON.parse(localStorage.getItem('velora_starred_symbols') || '[]'));
  step(`${label}: star persists to localStorage`, Array.isArray(starred) && starred.indexOf('SOL/USDT') !== -1, JSON.stringify(starred));
  await page.fill('#symSearch', '');
  await page.waitForTimeout(250);

  // select a symbol
  await page.click('#symList .sym-item');
  await page.waitForTimeout(250);
  const selected = await page.evaluate(() => ({
    hidden: document.getElementById('symbol').value,
    btnText: document.getElementById('symBtnText').textContent,
    recents: JSON.parse(localStorage.getItem('velora_recent_symbols') || '[]'),
  }));
  step(`${label}: selection updates hidden input`, selected.hidden !== '', `hidden="${selected.hidden}"`);
  step(`${label}: selection updates trigger button`, selected.btnText !== '' && selected.btnText !== 'انتخاب نماد...', `btn="${selected.btnText}"`);
  step(`${label}: selection recorded as recent`, Array.isArray(selected.recents) && selected.recents.length > 0, JSON.stringify(selected.recents));

  // manual submission end-to-end (stubbed API)
  await page.fill('#entry', '61000');
  await page.fill('#exit', '64200');
  await page.fill('#volume', '0.5');
  await page.evaluate(() => {
    document.getElementById('openTime').value = '2026-08-24T10:00';
    document.getElementById('closeTime').value = '2026-08-24T12:00';
  });
  await page.click('#submitBtn');
  await page.waitForTimeout(900);
  const post = capturedPosts[0];
  step(`${label}: submit fires POST /api/v1/trades`, !!post);
  if (post) {
    const b = post.body || {};
    step(`${label}: payload has symbol/entry/exit/volume`, !!(b.symbol && b.entryPrice && b.exitPrice && b.volume), JSON.stringify({ symbol: b.symbol, entryPrice: b.entryPrice, volume: b.volume }));
    step(`${label}: payload has contractSize + direction`, b.contractSize !== undefined && !!b.direction, `contractSize=${b.contractSize} direction=${b.direction}`);
  }
  await page.close();

  // ---------- mobile ----------
  const mobile = await browser.newPage({ viewport: { width: 390, height: 700 }, hasTouch: true, isMobile: true });
  mobile.on('pageerror', (e) => pageErrors.push('mobile: ' + String(e).slice(0, 160)));
  await stubApi(mobile, capturedPosts);
  await mobile.goto(`${base}${pagePath}?mode=manual`, { waitUntil: 'domcontentloaded' });
  await mobile.waitForTimeout(1200);
  await openDropdown(mobile);
  step(`${label}: mobile touch scrolls the list`, (await touchScroll(mobile)) > 0);
  await mobile.close();

  // ---------- short viewport (keyboard-open emulation) ----------
  const short = await browser.newPage({ viewport: { width: 390, height: 430 }, hasTouch: true, isMobile: true });
  short.on('pageerror', (e) => pageErrors.push('short: ' + String(e).slice(0, 160)));
  await stubApi(short, capturedPosts);
  await short.goto(`${base}${pagePath}?mode=manual`, { waitUntil: 'domcontentloaded' });
  await short.waitForTimeout(1000);
  await openDropdown(short);
  const fit = await short.evaluate(() => {
    const drop = document.getElementById('symDropdown');
    const bottom = drop.getBoundingClientRect().bottom;
    const limit = (window.visualViewport && window.visualViewport.height) || window.innerHeight;
    return { bottom: Math.round(bottom), limit: Math.round(limit), inlineMaxH: drop.style.maxHeight, computedMaxH: getComputedStyle(drop).maxHeight };
  });
  step(`${label}: dropdown fits a short (keyboard) viewport`, fit.bottom <= fit.limit + 2, JSON.stringify(fit));
  step(`${label}: inline max-height now wins over stylesheet`, /\d+px/.test(fit.inlineMaxH) && fit.computedMaxH === fit.inlineMaxH, JSON.stringify(fit));
  await short.close();

  step(`${label}: no uncaught JS error across all scenarios`, pageErrors.length === 0, pageErrors.join(' | ') || 'clean');
}

/** Default mode: hero active, manual form hidden (no flash), boot handover done. */
async function runDefaultMode(browser, base, label, pagePath) {
  console.log(`\n== ${label} default mode / anti-flash (${pagePath})`);
  const pageErrors = [];
  const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
  page.on('pageerror', (e) => pageErrors.push(String(e).slice(0, 160)));
  await stubApi(page, []);
  await page.goto(`${base}${pagePath}`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1400);
  const state = await page.evaluate(() => ({
    bootClass: document.documentElement.classList.contains('velora-hero-boot'),
    panelClass: (document.querySelector('main .panel') || {}).className || '',
    heroVisible: !!document.getElementById('vsiDrop'),
    formDisplay: getComputedStyle(document.querySelector('.form-grid')).display,
  }));
  step(`${label}: default = screenshot hero active`, state.heroVisible && /vsi-hero/.test(state.panelClass), JSON.stringify(state.panelClass));
  step(`${label}: manual form hidden after load (no flash)`, state.formDisplay === 'none', `display=${state.formDisplay}`);
  step(`${label}: boot class handed over`, state.bootClass === false);
  // manual entry remains one click away
  await page.click('#vsiToManual');
  await page.waitForTimeout(350);
  const manualBack = await page.evaluate(() => ({
    formDisplay: getComputedStyle(document.querySelector('.form-grid')).display,
    backLink: !!document.getElementById('vsiBackHero'),
  }));
  step(`${label}: manual entry reachable from hero`, manualBack.formDisplay !== 'none' && manualBack.backLink, JSON.stringify(manualBack));
  await page.close();
  step(`${label}: default mode produced no JS errors`, pageErrors.length === 0, pageErrors.join(' | ') || 'clean');
}

/** Smart import blocked: boot hides the form, watchdog restores it (fallback). */
async function runBootFallback(browser, base, label, pagePath) {
  console.log(`\n== ${label} boot fallback / smart import blocked (${pagePath})`);
  const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
  await stubApi(page, []);
  await page.route('**/velora-smart-import.js*', (route) => route.abort());
  await page.goto(`${base}${pagePath}`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(500);
  const duringBoot = await page.evaluate(() => ({
    bootClass: document.documentElement.classList.contains('velora-hero-boot'),
    formDisplay: getComputedStyle(document.querySelector('.form-grid')).display,
  }));
  step(`${label}: form hidden during boot window`, duringBoot.bootClass && duringBoot.formDisplay === 'none', JSON.stringify(duringBoot));
  await page.waitForTimeout(2200);
  const afterWatchdog = await page.evaluate(() => ({
    bootClass: document.documentElement.classList.contains('velora-hero-boot'),
    formDisplay: getComputedStyle(document.querySelector('.form-grid')).display,
  }));
  step(`${label}: watchdog restores manual form`, afterWatchdog.bootClass === false && afterWatchdog.formDisplay !== 'none', JSON.stringify(afterWatchdog));
  await page.close();
}

/** Dashboard/trades headers: photo-trade entry point removed; single Add Trade remains. */
async function runHeaderEntryPoints(browser, base, label, pagePath) {
  console.log(`\n== ${label} header entry points (${pagePath})`);
  const pageErrors = [];
  const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
  page.on('pageerror', (e) => pageErrors.push(String(e).slice(0, 160)));
  await stubApi(page, []);
  await page.goto(`${base}${pagePath}`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1400); // dash-injector retries were at 200/800ms
  const header = await page.evaluate(() => ({
    injectedPhotoBtn: !!document.getElementById('vsiDashLink'),
    vsiDashFlag: typeof window.__vsiDashLink !== 'undefined',
    addTrade: (() => { const a = document.querySelector('.velora-nav-right a.btn-gold, a.velora-btn-back[href*="/trades/new"]'); return a ? a.getAttribute('href') : null; })(),
  }));
  step(`${label}: injected photo-trade button removed`, !header.injectedPhotoBtn && !header.vsiDashFlag);
  step(`${label}: Add Trade entry point present`, !!header.addTrade, header.addTrade || 'not found');
  await page.close();
  step(`${label}: header page produced no JS errors`, pageErrors.length === 0, pageErrors.join(' | ') || 'clean');
}

(async () => {
  const { server, base } = await startServer();
  const browser = await chromium.launch();
  let crashed = false;
  try {
    await runLocale(browser, base, 'FA', '/trades/new/index.html');
    await runLocale(browser, base, 'EN', '/localized/en/trades/new/index.html');
    await runDefaultMode(browser, base, 'FA', '/trades/new/index.html');
    await runDefaultMode(browser, base, 'EN', '/localized/en/trades/new/index.html');
    await runBootFallback(browser, base, 'FA', '/trades/new/index.html');
    await runHeaderEntryPoints(browser, base, 'FA', '/dashboard/index.html');
    await runHeaderEntryPoints(browser, base, 'FA', '/trades/index.html');
    await runHeaderEntryPoints(browser, base, 'EN', '/localized/en/dashboard/index.html');
  } catch (e) {
    crashed = true;
    console.error('SPEC CRASHED:', e.message);
  } finally {
    await browser.close();
    server.close();
  }
  if (crashed || failures.length) {
    console.error(`\nTRADES_NEW_SPEC: FAIL (${failures.length} failing step(s))`);
    process.exit(1);
  }
  console.log('\nTRADES_NEW_SPEC: PASS');
})().catch((e) => { console.error('SPEC CRASHED:', e.message); process.exit(1); });
