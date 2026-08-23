# VELORA — Localization Closure Checklist (fa/en)

> **Status of this document:** merged closure checklist. It carries forward every item from
> the existing bilingual governance checklist, [`05_BILINGUAL_CHECKLIST.md`](./05_BILINGUAL_CHECKLIST.md)
> (PR-02, static-catalog / route-build scope), and adds the full-product coverage items
> discovered by the **Velora FA/EN Localization — Final Gap Audit** (dashboard/API/backend,
> user-preference persistence, and future-feature readiness — see
> `velora_complete_localization_audit.md`). No original item was deleted; duplicates across
> the two source documents were consolidated into a single row with combined evidence.
>
> This document does not replace `05_BILINGUAL_CHECKLIST.md`, which remains the canonical
> development-time rule set (§0) and the automated-enforcement reference table for the
> static-catalog/build system. This document is the **closure-verification snapshot**:
> what was actually checked, its current pass/fail state, and whether any gap blocks
> declaring localization complete for the whole product.
>
> **Baseline commit:** `1d05cc1c36ece6ef8754eab3971a9a21c6d3f506` (2026-08-23).
> **Key facts confirmed at this commit:** EN catalog = 1455 keys, FA catalog = 1455 keys,
> 0 missing/extra in either direction; 18 feature chunks per locale; 879 frozen hashed keys;
> hardcoded-UI allowlist = 15 entries / 241 allowlisted Persian literals across 15 files;
> `validate_localization.py` → routes=29, canonical=29, localized=61, locales=2, issues=0.

---

## 1. Translation completeness

| # | Checklist item | Status | Evidence | Blocking |
|---|---|---|---|---|
| 1.1 | `fa.json` ↔ `en.json` key sets identical — 0 missing in either direction | PASS | Flattened-key diff at baseline commit: EN=1455, FA=1455, missing-in-FA=0, missing-in-EN=0 (also confirmed via `validate_localization.py`, prior closure Phase 3 parity check) | No |
| 1.2 | No empty `messages.*` value in either catalog | PASS | PR-01 V-7 report-only check (`report_catalog_anomalies.py`); no new empties introduced since prior closure | No |
| 1.3 | Placeholder parity — every `{placeholder}` set matches across locales | PASS | TEST-19 (`tools/tests/test_email_key_parity.php`) part of `pytest`/quality-gate suite; 0 mismatches confirmed at prior closure Phase 3 (chunk totals EN=1277/FA=1277, 0 mismatches) | No |
| 1.4 | FA/EN consistency — a given key means the same thing in both locales | PASS | Manual review during PR-14 fix and orphan-key classification; no cross-locale meaning bug found (see Errors & Dead Ends note: initial V3-010–014 false alarm was corrected) | No |
| 1.5 | Fallback never leaks raw keys — unknown locale resolves via manifest fallback | PASS | TEST-20 (`tools/tests/test_locale_fallback.php`); `LocaleManager` manifest fallback = `en` | No |
| 1.6 | Email copy (`email.*`) actually translated in both catalogs | PASS | TEST-09 (`tools/tests/test_email_localization.php`); confirmed server-side `$t()` closure in `NotificationService.php`/`EmailTemplate.php` resolves via `LocaleManager::translateFor()`, not hardcoded strings | No |

## 2. RTL/LTR contract

| # | Checklist item | Status | Evidence | Blocking |
|---|---|---|---|---|
| 2.1 | `<html>` attributes correct: `lang="fa" dir="rtl"` / `lang="en" dir="ltr"`; `data-route-locale` + `data-velora-prelocalized` match | PASS | Prior closure Phase 3 direct check: FA total=29, FA with `dir=rtl`=29, EN incorrectly RTL=0 | No |
| 2.2 | RTL layout — no mirrored-icon/flipped-arrow/clipped-copy regressions on FA pages | PASS | Swept across all 29 route templates during static closure; no regression found | No |
| 2.3 | LTR exceptions hold in RTL context (numbers, currency, ticker symbols, technical terms) | PASS | `unicode-bidi:isolate` / `direction:ltr` rules in `velora-localization.css`, verified present | No |
| 2.4 | Digits are Latin in FA (`numberingSystem: latn`) | PASS | `velora-latin-digits.js` + companion CSS guard confirmed in place | No |
| 2.5 | No language-branch styling — direction driven only by `[dir=rtl]`/`[dir=ltr]` selectors | PASS | No hardcoded `lang`/locale-string CSS branches found | No |

## 3. Catalog governance

| # | Checklist item | Status | Evidence | Blocking |
|---|---|---|---|---|
| 3.1 | Single source of truth: `public/locales/{fa,en}.json`; `localized/**` is build output only (NP-5 — never hand-edit) | PASS | Confirmed via `docs/README.md` NP-5 and `build_localized_static.py --check` → `ARTIFACT_FRESHNESS_OK` | No |
| 3.2 | No new user-facing copy outside the catalog (PR-01 V-3) | PASS | `check_hardcoded_ui.py` final clean pass: 15 allowlist entries, 15 files, 241 Persian literals, 0 new violations, 0 drift/orphans | No |
| 3.3 | Semantic keys for new features (namespaced, no free text) | PASS | Verified in PR-14 fix (`landing.ai.intent.*` keys added following existing namespace convention) | No |
| 3.4 | Hashed keys are frozen — no new `-8hex` keys (PR-01 V-4) | PASS | `check_frozen_hash_keys.py`; 879 frozen keys unchanged | No |
| 3.5 | Catalog edits rebuild artifacts (`localized/**`, `csp-manifest.json`, `.csp-release.json`) | PASS | Rebuild invocation logged for PR-14 fix: `LOCALIZED_BUILD_OK templates=29 html=61 feature_chunks=36 csp_routes=61` | No |
| 3.6 | Orphan catalog-key report reviewed (report-only, PR-09) | PARTIAL | 177 EN orphans classified: Category A=10 (false-positive, dynamic construction), Category B=56 (duplicate-with-live-twin, future removal candidate), Category C=111 (no evidence, needs product-owner review). None removed without consensus, per no-deletion instruction | No (report-only; not a release gate) |

## 4. Feature development workflow

| # | Checklist item | Status | Evidence | Blocking |
|---|---|---|---|---|
| 4.1 | FA translation present for every new key | PASS | Verified for PR-14's 4 new keys (`landing.ai.intent.lose/revenge/edge/setup`) | No |
| 4.2 | EN translation present for every new key | PASS | Same as above — identical-shape EN counterparts added | No |
| 4.3 | Glossary terms reviewed against financial glossary | PASS | No glossary violations found during audit | No |
| 4.4 | Do-not-translate terms left untranslated (`AI`, `API`, `MT4`, `MT5`, `MetaAPI`, `VELORA`, `Stripe`, `CSP`, `JWT`, ticker symbols) | PASS | Confirmed unchanged across catalogs | No |
| 4.5 | Localization validation green (TEST-19/20, PR-01 V-3/V-4, chunk coverage) | PASS | Full suite: `pytest tools/localization/ -q` → 109 passed, 20 subtests passed | No |

## 5. SEO and page parity

| # | Checklist item | Status | Evidence | Blocking |
|---|---|---|---|---|
| 5.1 | Localized page coverage — every SEO page exists under both `localized/fa/**` and `localized/en/**` | PASS | `validate_localization.py` → routes=29, canonical=29, localized=61 (29×2 + shared), locales=2, issues=0 | No |
| 5.2 | Metadata parity — `<title>`, meta description, OG tags translated per locale | PASS | Verified by `validate_localization.py` generated-metadata checks | No |
| 5.3 | hreflang consistency — `rel="alternate" hreflang"` + `x-default`; `sitemap.xml` consistent with live routes | PASS | No mismatches found | No |
| 5.4 | Route coverage — public SEO routes resolve without redirect loops | PASS | 29/29 routes resolve correctly | No |

## 6. Production served-routes coverage *(new — full-product audit)*

| # | Checklist item | Status | Evidence | Blocking |
|---|---|---|---|---|
| 6.1 | Identify what is actually served in production vs. build artifacts sitting in-repo | PASS | `deploy.yml` allow-list packaging copies only `locale-router.php`, `404.html`, `checkout/index.html`, `localized/`, `public/`, `api/` — confirmed via direct read of the packaging step | No |
| 6.2 | Production explicitly guards against shipping unlocalized/unaudited build output | PASS | `deploy.yml` pre-upload guard step fails the build if any `_next/` path exists in the deploy package (`chk "بدون docs/tools/_next"`) | No |
| 6.3 | `locale-router.php` (the real front controller) routes exclusively to the catalog-driven `localized/{locale}/...` tree for every app route (`dashboard`, `trades`, `admin`, `wallet`, `login`, etc.) | PASS | Confirmed by direct grep of `locale-router.php`: `$localizedBase = realpath($root . '/localized/' . $locale)`; back/forward-cache guard list references `admin/index.html`, `dashboard/index.html`, `trades/index.html`, `trades/new/index.html`, `wallet/index.html` all under `localized/` | No |
| 6.4 | Orphaned/unused Next.js build (`_next/`) identified, scoped, and confirmed dormant | PASS (flag for follow-up) | `_next/` has no accompanying source (no `.tsx`/`.jsx`/`next.config.*` anywhere in repo); introduced in PR #26 (`feat/pr03-user-locale-preference`); excluded from both `deploy.yml` (hard guard) and `deploy-staging.yml` (never copied) | No (dormant, not deployed) — see Remaining Blockers/Non-blocking |

## 7. Dashboard/trades/admin/wallet/checkout localization verification *(new — full-product audit)*

| # | Checklist item | Status | Evidence | Blocking |
|---|---|---|---|---|
| 7.1 | `localized/en/dashboard/index.html` / `localized/fa/dashboard/index.html` — catalog-driven, zero hardcoded literals | PASS | `check_hardcoded_ui.scan()` run directly against this file: no violations found | No |
| 7.2 | `localized/en/trades/index.html` (+ `trades/new/`) — catalog-driven, zero hardcoded literals | PASS | Same scanner run: no violations found | No |
| 7.3 | `localized/en/admin/index.html` — catalog-driven, zero hardcoded literals | PASS | Same scanner run: no violations found | No |
| 7.4 | `localized/en/wallet/index.html` — catalog-driven, zero hardcoded literals | PASS | Same scanner run: no violations found | No |
| 7.5 | `checkout/index.html` — deployed to production, part of the same build pipeline | PASS | Confirmed present in `deploy.yml` packaging step (`mkdir -p "$DEST/checkout" && cp checkout/index.html`); part of the 29 canonical routes already validated | No |
| 7.6 | `_next/` compiled Next.js dashboard/trades/admin bundle — same-named routes, but a *separate*, unaudited mechanism | FAIL (scoped as non-deployed) | Confirmed hardcoded, Persian-only UI strings baked into all 20 chunk files with **no English equivalent** in either build-hash variant (e.g. `dashboard/page-54e0b502fc4aa20c.js`: 205 Persian char-runs incl. "بالانس", "معاملات اخیر", zero matches for "Balance"/"Recent trades"); no catalog usage, no i18n library signature found | No (not served — see §6.2–6.4) but tracked as a real gap if ever activated |

## 8. API messageKey localization *(new — full-product audit)*

| # | Checklist item | Status | Evidence | Blocking |
|---|---|---|---|---|
| 8.1 | Every `ApiException` carries a `messageKey`, defaulted by HTTP status if omitted | PASS | `api/src/Core/Exceptions/ApiException.php`: `defaultMessageKey()` maps every status (401/403/404/409/422/429/etc.) to an `errors.*` key | No |
| 8.2 | `Response::error()` JSON contract exposes `messageKey` as the UI-facing field; `message` is documented as a language-neutral log/compat fallback only | PASS | `api/src/Core/Response.php` code comment: "UI clients render messageKey through their own locale catalog. `message` is a stable, language-neutral compatibility fallback." | No |
| 8.3 | Every distinct `messageKey` referenced anywhere in `api/src/**/*.php` exists in both `en.json` and `fa.json` | PASS | Cross-check script: 93 distinct keys referenced across the whole API layer; 0 missing in EN, 0 missing in FA | No |
| 8.4 | End-to-end resolution verified: API `messageKey` → client `ApiError` → `errorMessage()` → `t(key, params)` catalog lookup | PASS | Traced `public/assets/velora-data.js` (`ApiError` construction with `messageKey`) → `public/assets/velora-localization.js` `errorMessage()` (`t(key, params, '')` with `errors.unknown` fallback) | No |
| 8.5 | No client-side code renders a raw `error.message` to the user instead of the translated `messageKey` | PASS | Grep of `velora-data.js` and all `localized/en/**/*.html` for direct `error.message`/`err.message` display found no bypass of `errorMessage()` | No |

## 9. Validation messages *(new — full-product audit)*

| # | Checklist item | Status | Evidence | Blocking |
|---|---|---|---|---|
| 9.1 | Field-level validation is language-neutral and key-based, not literal sentences | PASS | `api/src/Core/Validation.php` docblock: "Field errors are semantic descriptors rather than rendered sentences." Every rule (`required`, `email`, `string`, `min`, `max`, etc.) emits `messageKey` (`errors.validation.required`, `errors.validation.email`, `errors.validation.minLength`, ...) | No |
| 9.2 | Structured `params` carried alongside `messageKey` for interpolation (e.g. `{min}`, `{max}`) | PASS | Confirmed in `Validation::errors()` — e.g. `minLength` returns `['min' => (int) $arg]` | No |
| 9.3 | Trade-specific validation (`TradeService`, `TradeExitController`, `TradeRepository`, `PnlCalculator`) follows the same contract | PASS | Confirmed consistent `messageKey` usage across all trade validation call sites (`errors.validation.range`, `errors.validation.positive`, `errors.validation.numeric`, `errors.validation.decimal`, `errors.trades.accountNotOwned`, etc.) | No |

## 10. Auth messages *(new — full-product audit)*

| # | Checklist item | Status | Evidence | Blocking |
|---|---|---|---|---|
| 10.1 | Auth success/error responses carry `messageKey`, not literal copy | PASS | `AuthController.php`: `auth.emailAlreadyVerified`, `auth.emailVerified`, `auth.passwordChanged`, `auth.passwordResetSentIfRegistered`, `auth.emailPreferences`, `auth.emailPreferencesUpdated`, `auth.passwordReset` all confirmed present and catalog-backed | No |
| 10.2 | Rate limiting responses are key-based | PASS | `api/src/Core/RateLimiter.php`: throws `ApiException(..., 'errors.rateLimited')` | No |
| 10.3 | Locale-preference update endpoint validates against the manifest and returns clear error on unsupported locale | PASS | `AuthController::updatePreferences()` checks `$i18n->supports($locale)`, returns `422 UNSUPPORTED_LOCALE` otherwise | No |

## 11. Email localization *(new — full-product audit)*

| # | Checklist item | Status | Evidence | Blocking |
|---|---|---|---|---|
| 11.1 | Server-rendered emails (verification, welcome, password reset, etc.) resolve copy via server-side translation, not hardcoded strings | PASS | `NotificationService.php` / `EmailTemplate.php`: `$t = static fn(...) => $i18n->translateFor($lang, $key, $params)`, used for every subject/body/CTA string (`email.verification.*`, `email.welcome.*`, etc.) | No |
| 11.2 | Emails resolve locale using the existing resolution system, not a parallel path | PASS | `NotificationService::resolveEmailLocale($savedLocale, $clientLocale)` — reuses `LocaleManager::getInstance()->supports()`/`resolve()`, same manifest-backed registry the UI uses | No |
| 11.3 | Covered by dedicated email-locale tests | PASS | TEST-09 (`tools/tests/test_email_localization.php`) and PR-05 contract (`tools/tests/test_email_locale_resolution.php`); also `tools/localization/test_backend_localization.php` and `test_server_locale.php` present in-repo | No |

## 12. Notification localization *(new — full-product audit)*

| # | Checklist item | Status | Evidence | Blocking |
|---|---|---|---|---|
| 12.1 | Notification payload content (subject/body/CTA) is key-based, same mechanism as email | PASS | `NotificationService.php` uses the identical `$t()` closure pattern across all notification-sending methods (verification, welcome, password change, etc.) | No |
| 12.2 | No hardcoded English or Persian literal found in notification-sending code paths | PASS | Grep of `NotificationService.php` for literal quoted UI strings found only `$t('email.*')` calls, no hardcoded copy | No |

## 13. User locale persistence *(new — full-product audit)*

| # | Checklist item | Status | Evidence | Blocking |
|---|---|---|---|---|
| 13.1 | DB schema has a dedicated locale-preference field with provenance tracking | PASS | `api/database/migrations/add_user_locale_preference.sql`: adds `users.locale` (`VARCHAR(35) DEFAULT 'fa'`), `users.locale_source` (`default\|browser\|cookie\|user`), `users.locale_updated_at` | No |
| 13.2 | Authenticated users can persist an explicit language choice via a real API endpoint | PASS | `AuthController::updatePreferences()` validates + calls `UserRepository::updateLocalePreference($userId, $canonical, 'user')` | No |
| 13.3 | Saved preference is read back and applied on subsequent requests | PASS | `locale-router.php`: `SELECT locale FROM users WHERE id = :id` inside the session-guarded lookup, degrades gracefully to cookie/browser if the column/row read fails | No |
| 13.4 | Preference persists correctly across logout | PASS | Logout flow (`AuthController::logout()`, client `logout()` in `velora-data.js`) does not clear the `velora_locale` cookie — correct, since locale is a device/browser preference independent of auth session | No |

## 14. Locale priority chain: URL → saved user locale → cookie → browser → default *(new — full-product audit)*

| # | Checklist item | Status | Evidence | Blocking |
|---|---|---|---|---|
| 14.1 | Explicit locale-prefixed URL is authoritative over all other signals | PASS | `locale-router.php` comment: "an explicit supported locale URL prefix is authoritative" | No |
| 14.2 | Signed-in user's saved locale (`users.locale`) outranks cookie/browser but never an explicit URL prefix | PASS | `locale-router.php`: "a signed-in user's saved language preference (users.locale) outranks the anonymous cookie/browser choice, but never an explicit locale prefix" | No |
| 14.3 | Manual-choice cookie (`velora_locale`) is next in priority | PASS | Final resolution line confirmed: `$locale = $savedLocale ?? $cookieLocale ?? $browserLocale ?? $default;` | No |
| 14.4 | `Accept-Language` header (browser) is used when no saved/cookie value exists | PASS | Same resolution chain; first-visit detection documented: Persian browser locale → `fa`, any other/unknown → manifest fallback (`en`) | No |
| 14.5 | Manifest default is the final fallback | PASS | `$default = strtolower((string) ($manifest['defaultLocale'] ?? $fallback))`, with `$fallback` used if `defaultLocale` isn't enabled | No |
| 14.6 | Same priority order reused for server-side email locale resolution (not a separate ad hoc scheme) | PASS | `NotificationService::resolveEmailLocale($savedLocale, $clientLocale)` checks saved locale first, then client-hint, else `null` → caller falls back to `LocaleManager::getLanguage()` manifest default | No |
| 14.7 | Degrades gracefully if `users.locale` column/migration isn't present yet (no hard failure) | PASS | `locale-router.php` comment: "a not-yet-migrated users table degrades to cookie/browser instead of breaking the session check" | No |

## 15. Future feature localization workflow *(new — full-product audit)*

| # | Checklist item | Status | Evidence | Blocking |
|---|---|---|---|---|
| 15.1 | New route/feature registration path is documented and machine-enforced | PASS | `tools/localization/routes.json` + `route_contract.py` — single routing input, validated loader (`RouteDefinition`, `RouteContractError`) | No |
| 15.2 | Feature-scoped catalog chunking policy exists for new page groups without core changes | PASS | `tools/localization/feature-map.json`: `always`, `serverOnly`, `sharedFeatureThreshold`, `aliases` — self-documented via its own `description` field | No |
| 15.3 | A dedicated, discoverable **developer onboarding guide** exists for adding a localized feature (naming exact steps: add keys → register route/alias → run gate → rebuild) | **FAIL** | No such guide exists. `docs/README.md` mentions `localized/**` only in passing (NP-5); `feature-map.json` is functional but not a tutorial; no `tools/localization/README.md` found | **No** (process gap, not a release-blocking defect — existing tooling still catches violations) |

## 16. Localization gate usage *(new — full-product audit)*

| # | Checklist item | Status | Evidence | Blocking |
|---|---|---|---|---|
| 16.1 | A single composed pass/fail gate exists, wrapping catalog-parity + hardcoded-UI checks | PASS | `tools/localization/localization_gate.py` — `run_gate(root)` + CLI `main()`, composes `validate_localization()` and `check_hardcoded_ui` scan/validate; no new detection logic or dependencies | No |
| 16.2 | Gate has unit test coverage (clean pass, parity-break fail, new-hardcoded-literal fail) | PASS | `tools/localization/test_localization_gate.py` — 3 tests, all passing | No |
| 16.3 | Gate is documented as part of the required pre-merge process | **FAIL** | Gate exists and works (`LOCALIZATION_GATE_OK`/`LOCALIZATION_GATE_FAILED`) but is not referenced anywhere in `docs/README.md`'s Non-Negotiable Policies or in `05_BILINGUAL_CHECKLIST.md`'s "Automated enforcement reference" table, and is not wired into `.github/workflows/quality-gate.yml` | **No** (per no-CI-redesign constraint from the closure task, this was intentionally not wired into CI; documented as a known limitation, not a blocker) |

## 17. Developer localization guide *(new — full-product audit)*

| # | Checklist item | Status | Evidence | Blocking |
|---|---|---|---|---|
| 17.1 | A concise, single-document guide exists that a new contributor can follow end-to-end for a localized feature | **FAIL** | Confirmed absent — see 15.3. Existing knowledge is distributed across `05_BILINGUAL_CHECKLIST.md` (rules), `feature-map.json` (policy), and individual tool docstrings (mechanics), with no single onboarding entry point | **No** (non-blocking improvement, tracked below) |
| 17.2 | Guide would need to reference: catalog files, `routes.json`/`feature-map.json` registration, `localization_gate.py`, and the do-not-translate/glossary rules already in §4 of `05_BILINGUAL_CHECKLIST.md` | N/A | Scoping note only — no such guide to evaluate yet | No |

---

## Remaining known limitations (carried forward + new)

- Orphan catalog-key categories B (56) and C (111) — flagged for product-owner review, not deleted (§3.6).
- `_next/` Next.js dashboard bundle — dormant, unaudited, Persian-only hardcoded strings; excluded from both deploy pipelines today, but tracked as a real localization gap if ever revived (§6.4, §7.6).
- No developer onboarding document for the localization workflow (§15.3, §17.1).
- `localization_gate.py` not documented in `05_BILINGUAL_CHECKLIST.md`'s enforcement table and not wired into CI (§16.3) — a deliberate scope decision from the prior closure task (no CI redesign without approval), not an oversight.

---

*This document supersedes no prior checklist; it consolidates `05_BILINGUAL_CHECKLIST.md`
(static-catalog/build governance) with the full-product Final Gap Audit findings
(dashboard, API/backend, user-preference persistence, future-feature readiness).
No application code, configuration, or CI workflow was modified to produce this document.*
