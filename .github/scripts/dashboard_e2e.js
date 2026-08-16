/**
 * Velora dashboard end-to-end check.
 *
 * Reproduces the two reported production symptoms in a real browser:
 *   1. the sign-in button spinning forever,
 *   2. logging out and landing back on the dashboard already signed in.
 *
 * Also collects console errors, failed requests and page crashes so any
 * other dashboard breakage shows up in CI instead of only on the host.
 */
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const EMAIL = process.env.TEST_EMAIL || 'ci@velora.test';
const PASSWORD = process.env.TEST_PASSWORD || 'CiTest1234!';
const SLOW_MS = 3000;

const failures = [];
const consoleErrors = [];
const failedRequests = [];
let logoutStatus = null;

function step(name, ok, detail) {
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  -- ' + detail : ''}`);
  if (!ok) failures.push(`${name}: ${detail || 'failed'}`);
}

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({
    locale: 'fa-IR',
    viewport: { width: 1280, height: 1200 },
  });
  const page = await ctx.newPage();

  page.on('console', (m) => {
    if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 200));
  });
  page.on('requestfailed', (r) => {
    failedRequests.push(`${r.method()} ${r.url().slice(0, 120)} - ${r.failure()?.errorText}`);
  });
  page.on('pageerror', (e) => consoleErrors.push('pageerror: ' + String(e).slice(0, 200)));

  // ---------------------------------------------------------------- sign in
  await page.goto(`${BASE}/login/`, { waitUntil: 'domcontentloaded' });
  await page.fill('#email', EMAIL);
  await page.fill('#password', PASSWORD);

  const t0 = Date.now();
  await page.click('#submitBtn');
  let reachedDashboard = true;
  try {
    await page.waitForURL(/dashboard/, { timeout: 20000 });
  } catch {
    reachedDashboard = false;
  }
  const loginMs = Date.now() - t0;

  step('login reaches the dashboard', reachedDashboard, `url=${page.url()}`);
  step(`login completes under ${SLOW_MS}ms`, loginMs < SLOW_MS, `took ${loginMs}ms`);

  // the spinner must not still be running once navigation settled
  const stillSpinning = await page
    .locator('#submitBtn[disabled], #submitBtn .spinner, #submitBtn .loading')
    .count()
    .catch(() => 0);
  step('submit button is not stuck spinning', stillSpinning === 0, `matches=${stillSpinning}`);

  if (!reachedDashboard) {
    console.log('\nCannot continue past sign-in.');
    await finish(browser);
    return;
  }

  // ------------------------------------------------------- dashboard render
  const bodyText = (await page.textContent('body').catch(() => '')) || '';
  step('dashboard rendered content', bodyText.trim().length > 200, `${bodyText.trim().length} chars`);
  step(
    'dashboard shows no server error',
    !/INTERNAL_ERROR|Internal server error|خطای داخلی/i.test(bodyText),
    'error text found in page'
  );

  // ---------------------------------------------------------------- log out
  // velora-dialog.js owns #logoutBtn via a capture-phase document listener and
  // renders the shared .velora-dialog confirmation, so that is what a real user
  // clicks. Anything else would be testing markup the browser never reaches.
  const logoutBtn = page.locator('#logoutBtn');
  if (await logoutBtn.count()) {
    await logoutBtn.first().scrollIntoViewIfNeeded();
    await logoutBtn.first().click();

    const confirm = page.locator('.velora-dialog-confirm');
    try {
      await confirm.waitFor({ state: 'visible', timeout: 10000 });
      step('logout confirmation dialog opens', true, 'velora-dialog shown');
    } catch {
      step('logout confirmation dialog opens', false, 'no .velora-dialog-confirm appeared');
    }

    if (await confirm.count()) {
      await Promise.all([
        page
          .waitForResponse((r) => r.url().includes('/api/v1/auth/logout'), { timeout: 15000 })
          .then((r) => {
            logoutStatus = r.status();
            return r;
          })
          .catch(() => null),
        confirm.first().click().catch(() => {}),
      ]);
    }
    try {
      await page.waitForURL(/\/login/, { timeout: 15000 });
      step('logout redirects to the login page', true, page.url());
    } catch {
      step('logout redirects to the login page', false, `still at ${page.url()}`);
    }
  } else {
    step('logout control exists', false, '#logoutBtn not found');
  }

  step(
    'logout API returned 200',
    logoutStatus === 200,
    `status=${logoutStatus}`
  );

  const remaining = (await ctx.cookies()).map((c) => c.name);
  step(
    'refresh cookie cleared after logout',
    !remaining.includes('__Host-velora_refresh'),
    `cookies=${JSON.stringify(remaining)}`
  );

  // ------------------------------- the reported bug: dashboard after logout
  await page.goto(`${BASE}/dashboard/`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  const backOnDashboard = /dashboard/.test(page.url());
  step(
    'dashboard after logout does NOT auto-sign-in',
    !backOnDashboard,
    `landed on ${page.url()}`
  );

  // browser Back must not restore an authenticated view either
  await page.goBack().catch(() => {});
  await page.waitForTimeout(1000);
  const backButtonRestored = /dashboard/.test(page.url());
  step('back button does not restore the session', !backButtonRestored, `url=${page.url()}`);

  await finish(browser);
})().catch(async (e) => {
  console.error('harness crashed:', e);
  process.exit(1);
});

async function finish(browser) {
  await browser.close();

  if (consoleErrors.length) {
    console.log('\nConsole errors:');
    [...new Set(consoleErrors)].slice(0, 15).forEach((e) => console.log('  ' + e));
  }
  if (failedRequests.length) {
    console.log('\nFailed requests:');
    [...new Set(failedRequests)].slice(0, 15).forEach((r) => console.log('  ' + r));
  }

  if (failures.length) {
    console.log('\n' + failures.length + ' check(s) failed:');
    failures.forEach((f) => console.log('  - ' + f));
    process.exit(1);
  }
  console.log('\nAll dashboard checks passed.');
}
