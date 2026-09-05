#!/usr/bin/env node
/**
 * VELORA — Phase L.2 frontend status presentation-map contract.
 *
 * Pure-node regression over the single-authoritative VeloraStatus mapper in
 * public/assets/velora-admin-ai.js (consumed by both the AI provider panel and
 * the Integrations panel). Pins the safety rules:
 *   - every authoritative backend status enum maps to a localized label key AND
 *     a distinct visual state class (no collapsing failures into one "Error")
 *   - EN and FA label text resolves through the catalog and is non-empty
 *   - the machine enum is NEVER replaced by the label (contract value)
 *   - unknown/unmapped enums fall back to the raw machine value, never a
 *     fabricated label (no inventing success/failure)
 *   - no secret / raw provider response is ever produced by these helpers
 *
 * Run with plain Node (no npm deps).
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.resolve(__dirname, '..', '..');
const fails = [];
function check(name, ok, detail) {
  console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? ' :: ' + detail : ''));
  if (!ok) fails.push(name);
}

/* ---- Load the browser IIFE in a sandbox with minimal DOM stubs. ---- */
function buildSandbox() {
  const created = {};
  const sandbox = {
    console,
    document: {
      readyState: 'complete',
      getElementById: function () { return null; },
      createElement: function (tag) {
        const node = { tagName: tag, className: '', id: '', textContent: '', setAttribute: function () {}, appendChild: function () {} };
        return node;
      },
      head: { appendChild: function () {} },
      addEventListener: function () {}
    },
    VeloraLocale: {
      t: function (key, data) {
        const EN = {
          'admin.ai.status.valid': 'Valid',
          'admin.ai.status.success': 'Success',
          'admin.ai.status.unverified': 'Unverified',
          'admin.ai.status.notConfigured': 'Not configured',
          'admin.ai.status.invalidCredential': 'Invalid credential',
          'admin.ai.status.authFailed': 'Authentication failed',
          'admin.ai.status.expired': 'Expired',
          'admin.ai.status.revoked': 'Revoked',
          'admin.ai.status.disabled': 'Disabled',
          'admin.ai.status.insufficientPermission': 'Insufficient permission',
          'admin.ai.status.quotaExceeded': 'Quota exceeded',
          'admin.ai.status.rateLimited': 'Rate limited',
          'admin.ai.status.regionRestricted': 'Region restricted',
          'admin.ai.status.providerUnavailable': 'Provider unavailable',
          'admin.ai.status.serviceUnavailable': 'Service unavailable',
          'admin.ai.status.unknown': 'Unknown status',
          'admin.ai.status.networkError': 'Network error',
          'admin.ai.status.timeout': 'Request timed out',
          'admin.ai.status.providerError': 'Provider error',
          'admin.ai.statusDetail': 'Technical status'
        };
        const FA = {
          'admin.ai.status.valid': 'معتبر',
          'admin.ai.status.success': 'موفق',
          'admin.ai.status.unverified': 'تأییدنشده',
          'admin.ai.status.notConfigured': 'پیکربندی نشده',
          'admin.ai.status.invalidCredential': 'اعتبارنامه نامعتبر',
          'admin.ai.status.authFailed': 'احراز هویت ناموفق',
          'admin.ai.status.expired': 'منقضی شده',
          'admin.ai.status.revoked': 'لغو شده',
          'admin.ai.status.disabled': 'غیرفعال',
          'admin.ai.status.insufficientPermission': 'مجوز ناکافی',
          'admin.ai.status.quotaExceeded': 'سهمیه تکمیل شده',
          'admin.ai.status.rateLimited': 'محدودیت نرخ درخواست',
          'admin.ai.status.regionRestricted': 'محدودیت منطقه‌ای',
          'admin.ai.status.providerUnavailable': 'ارائه‌دهنده در دسترس نیست',
          'admin.ai.status.serviceUnavailable': 'سرویس در دسترس نیست',
          'admin.ai.status.unknown': 'وضعیت نامشخص',
          'admin.ai.status.networkError': 'خطای شبکه',
          'admin.ai.status.timeout': 'مهلت درخواست پایان یافت',
          'admin.ai.status.providerError': 'خطای ارائه‌دهنده',
          'admin.ai.statusDetail': 'وضعیت فنی'
        };
        let v = (EN[key] !== undefined ? EN[key] : key);
        // Re-run under FA by flipping a global set by the test.
        if (sandbox.__locale === 'fa' && FA[key] !== undefined) v = FA[key];
        if (data && typeof v === 'string') {
          for (const k in data) v = v.replace('{' + k + '}', String(data[k]));
        }
        return v;
      }
    },
    __locale: 'en',
    confirm: function () { return true; },
    setTimeout: function () {},
    clearTimeout: function () {},
    // The template VeloraAdminAIKeys map (single source of the key literal);
    // K() resolves stem -> full catalog key.
    VeloraAdminAIKeys: {
      'statusDetail': 'admin.ai.statusDetail',
      'aiStatus.valid': 'admin.ai.status.valid',
      'aiStatus.success': 'admin.ai.status.success',
      'aiStatus.unverified': 'admin.ai.status.unverified',
      'aiStatus.notConfigured': 'admin.ai.status.notConfigured',
      'aiStatus.invalidCredential': 'admin.ai.status.invalidCredential',
      'aiStatus.authFailed': 'admin.ai.status.authFailed',
      'aiStatus.expired': 'admin.ai.status.expired',
      'aiStatus.revoked': 'admin.ai.status.revoked',
      'aiStatus.disabled': 'admin.ai.status.disabled',
      'aiStatus.insufficientPermission': 'admin.ai.status.insufficientPermission',
      'aiStatus.quotaExceeded': 'admin.ai.status.quotaExceeded',
      'aiStatus.rateLimited': 'admin.ai.status.rateLimited',
      'aiStatus.regionRestricted': 'admin.ai.status.regionRestricted',
      'aiStatus.providerUnavailable': 'admin.ai.status.providerUnavailable',
      'aiStatus.serviceUnavailable': 'admin.ai.status.serviceUnavailable',
      'aiStatus.unknown': 'admin.ai.status.unknown',
      'aiStatus.networkError': 'admin.ai.status.networkError',
      'aiStatus.timeout': 'admin.ai.status.timeout',
      'aiStatus.providerError': 'admin.ai.status.providerError'
    }
  };
  sandbox.window = sandbox;
  sandbox.globalThis = sandbox;
  sandbox.VeloraData = { ready: function () { return Promise.resolve({ role: 'admin' }); }, request: function () { return Promise.resolve({}); } };
  vm.createContext(sandbox);
  const src = fs.readFileSync(path.join(ROOT, 'public', 'assets', 'velora-admin-ai.js'), 'utf8');
  vm.runInContext(src, sandbox, { filename: 'velora-admin-ai.js' });
  return sandbox;
}
const sb = buildSandbox();
const VS = sb.VeloraStatus;
check('module exposes VeloraStatus mapper', !!(VS && VS.label && VS.cls && VS.meta));

/* ---- Coverage: every supported backend enum maps to non-empty label + class ---- */
const BACKEND_STATES = [
  'NOT_CONFIGURED', 'VALID', 'INVALID_CREDENTIAL', 'RATE_LIMITED',
  'INSUFFICIENT_PERMISSION', 'PROVIDER_UNAVAILABLE', 'NETWORK_ERROR',
  'TIMEOUT', 'PROVIDER_ERROR', 'QUOTA_EXCEEDED', 'UNKNOWN',
  'SUCCESS', 'AUTH_FAILED', 'EXPIRED', 'REVOKED', 'DISABLED',
  'REGION_RESTRICTED', 'SERVICE_UNAVAILABLE', 'UNVERIFIED'
];
let mappedAll = true, distinctClasses = new Set();
for (const st of BACKEND_STATES) {
  const label = VS.label(st);
  const cls = VS.cls(st);
  if (!label || label === st) mappedAll = false; // label must be human text, not raw enum
  if (!/^velora-st-/.test(cls)) mappedAll = false;
  distinctClasses.add(cls);
}
check('every backend enum maps to a non-empty HUMAN label (not raw enum)', mappedAll);
check('distinct visual state classes used (not one generic error)', distinctClasses.size >= 5, 'classes=' + [...distinctClasses].join(','));

/* Distinct semantic classes: success(ok), warn, error, unavailable, muted/neutral. */
check('VALID -> ok class', VS.cls('VALID') === 'velora-st-ok');
check('INVALID_CREDENTIAL -> error class', VS.cls('INVALID_CREDENTIAL') === 'velora-st-err');
check('RATE_LIMITED -> warn class (not error)', VS.cls('RATE_LIMITED') === 'velora-st-warn');
check('INSUFFICIENT_PERMISSION -> warn class', VS.cls('INSUFFICIENT_PERMISSION') === 'velora-st-warn');
check('QUOTA_EXCEEDED -> warn class', VS.cls('QUOTA_EXCEEDED') === 'velora-st-warn');
check('PROVIDER_UNAVAILABLE -> unavailable class', VS.cls('PROVIDER_UNAVAILABLE') === 'velora-st-unavail');
check('NETWORK_ERROR -> error class', VS.cls('NETWORK_ERROR') === 'velora-st-err');
check('TIMEOUT -> error class', VS.cls('TIMEOUT') === 'velora-st-err');
check('PROVIDER_ERROR -> error class', VS.cls('PROVIDER_ERROR') === 'velora-st-err');
check('NOT_CONFIGURED -> muted/neutral class', VS.cls('NOT_CONFIGURED') === 'velora-st-muted');
check('UNKNOWN -> unavailable class', VS.cls('UNKNOWN') === 'velora-st-unavail');

/* ---- EN label text (exact human strings, not raw enums) ---- */
check('EN VALID -> "Valid"', VS.label('VALID') === 'Valid');
check('EN INVALID_CREDENTIAL -> "Invalid credential"', VS.label('INVALID_CREDENTIAL') === 'Invalid credential');
check('EN RATE_LIMITED -> "Rate limited"', VS.label('RATE_LIMITED') === 'Rate limited');
check('EN NOT_CONFIGURED -> "Not configured"', VS.label('NOT_CONFIGURED') === 'Not configured');
check('EN PROVIDER_UNAVAILABLE -> "Provider unavailable"', VS.label('PROVIDER_UNAVAILABLE') === 'Provider unavailable');
check('EN UNKNOWN -> "Unknown status"', VS.label('UNKNOWN') === 'Unknown status');

/* ---- FA label text (requires sandbox locale flip) ---- */
sb.__locale = 'fa';
check('FA VALID -> معتبر', VS.label('VALID') === 'معتبر');
check('FA INVALID_CREDENTIAL -> اعتبارنامه نامعتبر', VS.label('INVALID_CREDENTIAL') === 'اعتبارنامه نامعتبر');
check('FA RATE_LIMITED -> محدودیت نرخ درخواست', VS.label('RATE_LIMITED') === 'محدودیت نرخ درخواست');
check('FA NOT_CONFIGURED -> پیکربندی نشده', VS.label('NOT_CONFIGURED') === 'پیکربندی نشده');
check('FA PROVIDER_UNAVAILABLE -> ارائه‌دهنده در دسترس نیست', VS.label('PROVIDER_UNAVAILABLE') === 'ارائه‌دهنده در دسترس نیست');
check('FA INSUFFICIENT_PERMISSION -> مجوز ناکافی', VS.label('INSUFFICIENT_PERMISSION') === 'مجوز ناکافی');
sb.__locale = 'en';

/* ---- Machine enum is the contract: label() must NOT equal enum for mapped states ---- */
let enumNotLabel = true;
for (const st of BACKEND_STATES) if (VS.label(st) === st) enumNotLabel = false;
check('label() never returns the raw machine enum for mapped states', enumNotLabel);

/* ---- Unknown/unmapped fallback honest (no fabricated text) ---- */
check('unmapped enum falls back to raw machine value', VS.label('SOME_FUTURE_STATE') === 'SOME_FUTURE_STATE');
check('unmapped enum gets muted class', VS.cls('SOME_FUTURE_STATE') === 'velora-st-muted');
check('null status -> empty/raw (no label)', VS.label(null) === '' || VS.label(null) === null || VS.label(undefined) === '');
check('meta() returns null for unknown (no fabricated entry)', VS.meta('NOPE') === null);

/* ---- No secret / no raw provider response in any produced label ---- */
function assertNoSecret(label) {
  if (typeof label !== 'string') return true;
  const lower = label.toLowerCase();
  return !/api[_ -]?key|bearer|authorization|token|secret|sk-[a-z0-9]{8,}/i.test(lower);
}
let noSecret = true;
for (const st of BACKEND_STATES) {
  if (!assertNoSecret(VS.label(st))) noSecret = false;
  if (!assertNoSecret(VS.label(st + 'extra'))) noSecret = false;
}
check('no label embeds api key / bearer / token / secret / sk- pattern', noSecret);

/* ---- Static guard: the mapper lives in a single file (no second mapping). ---- */
const aiSrc = fs.readFileSync(path.join(ROOT, 'public', 'assets', 'velora-admin-ai.js'), 'utf8');
const intSrc = fs.readFileSync(path.join(ROOT, 'public', 'assets', 'velora-admin-integrations.js'), 'utf8');
check('integrations panel DELEGATES to VeloraStatus (no duplicate taxonomy)', /\bVeloraStatus\b/.test(intSrc) && /VeloraStatus\.label/.test(intSrc));
check('integrations panel keeps a guarded fallback for missing mapper', /VeloraStatus && .*VeloraStatus\.label/.test(intSrc));

/* ---- Static guard: no raw backend enum appears as user-visible status text in HTML ---- */
const adminTpl = fs.readFileSync(path.join(ROOT, 'admin', 'index.html'), 'utf8');
check('admin template does not hardcode backend enums as visible status text', !/>(VALID|INVALID_CREDENTIAL|RATE_LIMITED|PROVIDER_UNAVAILABLE|NOT_CONFIGURED|UNKNOWN)</.test(adminTpl));

console.log('\n' + (fails.length ? fails.length + ' FAIL' : 'ALL PASS') + ' (status presentation map)');
process.exit(fails.length ? 1 : 0);
