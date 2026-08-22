# VELORA — Quality Gate Matrix

> **Purpose:** permanent mapping `Risk/Bug → Test ID → Test File → Expected Behavior → CI Gate → Current Status`.
> **Golden rule (owner directive):** no bug fix is accepted unless its pin test already exists and is red before the fix.
> **Base:** repo `veloratrade/veloratrade`, branch `main`, baseline HEAD `89b0ef5` (audit baseline; Phases 2.1–4.7 + 3.1–3.4 changes pending commit at refresh time).
> **Status legend:** 🟢 green pin (passes today, guards regressions) · ✅ **Fixed + Protected by TEST-ID** (audit bug closed in Phases 4.1–4.7; its pin is green and permanently enforced in CI). No 🔴 FAIL-by-design or ⏳ pending-wiring states remain.
> **CI wiring:** every gate below is **Integrated into GitHub Actions Quality Gate** (`.github/workflows/quality-gate.yml`, Phase 3.2). The aggregator job `quality-gate (aggregate — release blocker)` is the single release-blocking check; legacy `ci.yml` jobs continue to run their original coverage in parallel.

---

## 1. Authentication

| Risk / Bug | Test ID | Test File | Expected Behavior | Gate | Status |
|---|---|---|---|---|---|
| BUG-A1 — forgot-password issues token to unverified user | TEST-01 | `tools/tests/test_forgot_password_unverified.php` | Unverified user: no `password_resets` row, no email | `gate-auth` | ✅ Fixed + Protected by TEST-01 |
| Forgot happy path (verified user) | TEST-02 | `tools/tests/test_forgot_password_verified.php` | 1 fresh token row, sha256-only, email logged+sent, token redacted in log | `gate-auth` | 🟢 |
| Old tokens invalidated on new request | TEST-03 | `tools/tests/test_password_reset_invalidation.php` | Previous tokens die when a new one is issued | `gate-auth` | 🟢 |
| Reset token single-use | TEST-04 | `tools/tests/test_reset_token_single_use.php` | Consumed token can never be replayed | `gate-auth` | 🟢 |
| Reset token expiry (1h TTL) | TEST-05 | `tools/tests/test_reset_token_expiry.php` | Expired token rejected | `gate-auth` | 🟢 |
| Change-password state machine | TEST-10 | `tools/tests/test_change_password_state.php` | Wrong current rejected; sessions revoked; notification sent | `gate-auth` | 🟢 |
| Token consumption atomicity (legacy) | — | `tools/tests/test_auth_token_consumption.php` | verify/reset consume + session revoke | `gate-auth` + ci.yml `php` job | 🟢 |
| MySQL connection resilience (legacy, PR #4) | — | `tools/tests/test_database_connection_resilience.php` | Connect retried; clean 503 on exhaustion, never fatal | `gate-auth` + ci.yml `php` job | 🟢 |
| BUG-A5 — password policy differs per flow | TEST-12 | `tools/tests/test_password_policy_consistency.php` | register = reset = change-password rules, same verdict for same candidate | `gate-auth` | ✅ Fixed + Protected by TEST-12 |
| BUG-A7 — resend-verification leaks verified status | TEST-13 | `tools/tests/test_resend_verification_enumeration.php` | Identical response for unknown / unverified / verified | `gate-security` | ✅ Fixed + Protected by TEST-13 |
| Login gate for unverified users | TEST-22 | `tools/tests/test_unverified_login_protection.php` | `EMAIL_NOT_VERIFIED`; verified users get token pair | `gate-auth` | 🟢 |
| Session rotation / logout / change-pw revoke | TEST-23 | `tools/tests/test_session_rotation_revoke.php` | Rotation kills old pair; logout revokes; change-pw revokes all | `gate-auth` | 🟢 |
| Rate limits on auth endpoints | TEST-24 | `tools/tests/test_rate_limit_enforcement.php` | login 8/300 · register 5/3600 · forgot 4/3600 · resend 4/3600; 429 after cap | `gate-security` | 🟢 |
| Boot health + login latency smoke | — | ci.yml inline steps (`php` job) | `/health` 200; wrong password = clean 401 < 3s | ci.yml `php` job | 🟢 |

## 2. Email System

| Risk / Bug | Test ID | Test File | Expected Behavior | Gate | Status |
|---|---|---|---|---|---|
| Resend driver contract (endpoint, sender, plain-text, CID, key redaction) | — | `tools/tests/test_resend_mailer.php` | 32 assertions on outbound payload | `gate-contract` + ci.yml `php` job | 🟢 |
| Gold Outline template (dir, logo env-aware, CID icon, env-based footer) | — | `tools/tests/test_email_template_gold_outline.php` | 25 assertions on rendered HTML | `gate-contract` + ci.yml `php` job | 🟢 |
| CID icon asset integrity | — | `tools/tests/test_notification_email_icons.py` | 7/7 icons valid PNG | `gate-static` + ci.yml `python` job | 🟢 |
| Email asset validation (logo + icons, sizes) | TEST-08 | `tools/tests/test_email_asset_validation.php` | 162 assertions on committed assets | `gate-static` | 🟢 |
| BUG-A4 — `/support` behind login breaks footer link | TEST-07 | `tools/tests/test_email_footer_links.php` | terms/privacy/support links valid AND `/support` public | `gate-contract` | ✅ Fixed + Protected by TEST-07 |
| BUG-A3 — raw i18n keys in achievement emails | TEST-06 | `tools/tests/test_achievement_email_content.php` | Rendered copy, never `achievements.*` keys | `gate-contract` | ✅ Fixed + Protected by TEST-06 |
| BUG-A8 — welcome email skips preference gate | TEST-14 | `tools/tests/test_welcome_email_preference.php` | `canSend(uid,'welcome')` consulted; opt-out honored | `gate-contract` | ✅ Fixed + Protected by TEST-14 |
| BUG-A9 — no preferences API / unsubscribe | TEST-15 | `tools/tests/test_email_preference_contract.php` | Preferences endpoint + unsubscribe/List-Unsubscribe presence | `gate-contract` | ✅ Fixed + Protected by TEST-15 |
| BUG-A11 — no per-account forgot cap | TEST-16 | `tools/tests/test_forgot_per_account_limit.php` | ≤3 reset emails per account per hour | `gate-auth` | ✅ Fixed + Protected by TEST-16 |
| BUG-A12 — `email_notifications.user_id=0` silent drop | TEST-17 | `tools/tests/test_notification_user_integrity.php` | Invalid user reference rejected loudly, never coerced to 0 | `gate-auth` | ✅ Fixed + Protected by TEST-17 |
| BUG-A10 — hardcoded prod links on staging | TEST-18 | `tools/tests/test_email_url_environment.php` | All email URLs derived from `FRONTEND_URL`; no localhost, no cross-env | `gate-contract` | ✅ Fixed + Protected by TEST-18 |

## 3. Security

| Risk / Bug | Test ID | Test File | Expected Behavior | Gate | Status |
|---|---|---|---|---|---|
| BUG-A2 — reset token in query string (logs/history) | TEST-11 | `tools/tests/test_reset_token_transport_security.php` | Fragment transport + `history.replaceState` scrub | `gate-security` | ✅ Fixed + Protected by TEST-11 |
| Enumeration via resend (BUG-A7) | TEST-13 | `tools/tests/test_resend_verification_enumeration.php` | Uniform responses | `gate-security` | ✅ Fixed + Protected by TEST-13 |
| XSS via user-controlled values (name, UA, symbol, achievement) | TEST-21 | `tools/tests/test_email_xss_escaping.php` | All 7 templates HTML-escape variables | `gate-security` | 🟢 |
| Security headers contract (CSP/nosniff + edge HSTS/XFO noted) | TEST-25 | `tools/tests/test_security_headers_contract.php` | nosniff in API responses; CSP guard wired into deploys | `gate-security` | 🟢 |
| CSP manifest consistency | — | `tools/localization/build_csp_artifacts.py --check` (via csp-guard.yml) | HTML ↔ CSP artifact match | `gate-secrets` (workflow_call) + csp-guard push/PR triggers | 🟢 |
| Secret-scan | — | csp-guard.yml `secret-scan` job | No committed secrets | `gate-secrets` (workflow_call) + csp-guard push/PR triggers | 🟢 |
| Zero-cost workflow policy | — | `tools/check_github_cost_guard.py` | No paid-minute abuse | `gate-static` + ci.yml `python` job | 🟢 |
| Session-proof / doc-freshness tooling | — | `tools/tests/test_velora_status.py` | `tools/velora-status.sh` proof code + drift reports behave | `gate-static` | 🟢 |

## 4. Localization

| Risk / Bug | Test ID | Test File | Expected Behavior | Gate | Status |
|---|---|---|---|---|---|
| BUG-A6 — notificationLocale ignored (fa-only emails) | TEST-09 | `tools/tests/test_email_localization.php` | locale=en ⇒ ltr + English copy; fa ⇒ rtl | `gate-contract` | ✅ Fixed + Protected by TEST-09 |
| FA/EN key parity drift | TEST-19 | `tools/tests/test_email_key_parity.php` | Identical key sets in `fa.json`/`en.json`; manifest sane | `gate-static` | 🟢 |
| Broken fallback for unknown locales | TEST-20 | `tools/tests/test_locale_fallback.php` | Unknown locale ⇒ manifest fallback, never raw/empty text | `gate-static` | 🟢 |
| Raw keys in achievement copy (BUG-A3) | TEST-06 | `tools/tests/test_achievement_email_content.php` | Translated titles/descriptions | `gate-contract` | ✅ Fixed + Protected by TEST-06 |
| Verify-page browser contract (fragment) | — | `tools/tests/test_verify_email_contract.py` + `test_verify_email_browser.js` | `#token=` fragment honored, query scrubbed | `gate-browser` + ci.yml `python` job | 🟢 |
| Artifact freshness / provenance (Phase 4.3) | TEST-26 | `tools/tests/test_artifact_freshness.py` | commitSha + sourceDigest recorded; clean regeneration byte-identical; stale artifact ⇒ `source changed but generated artifact is stale` | `gate-artifacts` | 🟢 |
| Hardcoded UI freeze (PR-01 V-3/V-0) | — | `tools/localization/check_hardcoded_ui.py` (+ `allowlist-hardcoded.json`) | New Persian-script literal in shared JS / inline scripts ⇒ fail unless allowlisted; count drift / orphan entry ⇒ fail | `gate-static` | 🟢 |
| Hashed catalog key freeze (PR-01 V-4) | — | `tools/localization/check_frozen_hash_keys.py` (+ `frozen-hash-keys.json`) | Any new `-8hex`-suffixed catalog key ⇒ fail; new keys must be semantic | `gate-static` | 🟢 |
| Catalog anomaly report (PR-01 V-7, report-only) | — | `tools/localization/report_catalog_anomalies.py` | Prints empty/identical/duplicate/no-Persian anomalies; never blocks (until P6) | `gate-static` | 🟢 |

## 5. Release Validation

**Release rules (owner directive):** a release is blocked if any of these gates fail —
`Auth Flow` (`gate-auth`) · `Email Flow` (`gate-static` + `gate-contract`) · `Security Regression` (`gate-security` + `gate-secrets`) · `Localization` (`gate-static` + `gate-contract`).
Deployed via `workflow_call` inside `deploy.yml` and `deploy-staging.yml`: the `quality_gate` job runs the full gate set **before** any upload; if the aggregate fails → **deployment stops**.

| Gate job (quality-gate.yml) | Contents | Wiring |
|---|---|---|
| `gate-static` | php -l, Python compile, cost-guard, CID icons, velora_status, TEST-08/19/20, PR-01 i18n freeze (hardcoded UI + frozen hash keys + anomaly report) | ✅ Integrated into GitHub Actions Quality Gate |
| `gate-contract` | mailer/template/footer/i18n/URL/preference contracts (TEST-06/07/09/14/15/18 + resend + gold-outline) | ✅ Integrated into GitHub Actions Quality Gate |
| `gate-auth` | Pattern A integration (TEST-01…05, 10, 12, 16, 17, 22, 23 + token consumption + DB resilience) | ✅ Integrated into GitHub Actions Quality Gate |
| `gate-security` | TEST-11, 13, 21, 24, 25 | ✅ Integrated into GitHub Actions Quality Gate |
| `gate-secrets` | CSP guard + secret-scan via `csp-guard.yml` (`workflow_call`) | ✅ Integrated into GitHub Actions Quality Gate |
| `gate-browser` | verify/reset page contracts (`test_verify_email_contract.py` + `test_verify_email_browser.js`) | ✅ Integrated into GitHub Actions Quality Gate |
| `gate-e2e` | dashboard Playwright E2E — push-to-`main` only (PR coverage: ci.yml `dashboard` job; no duplicate browser runs) | ✅ Integrated into GitHub Actions Quality Gate |
| `gate-artifacts` | TEST-26 artifact freshness (regenerate + byte-compare + provenance) + `validate_localization.py` (CSP hashes, chunk hashes, output consistency) | ✅ Integrated into GitHub Actions Quality Gate |
| **`quality-gate`** | aggregator — `needs:` all 8 gates, `if: always()`; any failure/cancellation ⇒ job fails and release stops. Candidate single **Required Status Check** (owner enables it in repo settings) | ✅ Integrated into GitHub Actions Quality Gate |

**Fix status (Phases 4.1–4.7 — all 12 audit bugs closed, pins green, wired into CI):**
A1→TEST-01 · A2→TEST-11 · A3→TEST-06 · A4→TEST-07 · A5→TEST-12 · A6→TEST-09 · A7→TEST-13 · A8→TEST-14 · A9→TEST-15 · A10→TEST-18 · A11→TEST-16 · A12→TEST-17

**CI inventory completed in Phase 3.4:** `test_database_connection_resilience.php` (row §1, `gate-auth`) and `test_velora_status.py` (row §3, `gate-static`) are now listed here; both were already running in `quality-gate.yml` and remain in `ci.yml`.

**Permanent Release Checklist:** `docs/RELEASE_CHECKLIST.md` — created in Phase 3.2, refreshed in Phase 3.4.

**Artifact Integrity Checklist:** `docs/ARTIFACT_INTEGRITY_CHECKLIST.md` — created in Phase 4.3; its automated surface is TEST-26 (`gate-artifacts`) plus the provenance guards in `deploy.yml` / `deploy-staging.yml`.

**PR-01 i18n freeze baseline:** 265 hardcoded Persian literals across 25 files (shared JS + inline scripts in generated localized HTML) and 879 frozen hashed catalog keys. The earlier hand-audit estimated ~227 violations; the delta is from tokenizer accuracy (distinct string+regex literals, comments stripped via the `build_localized_static.py` tokenizer) plus newly discovered sources — `emotion-icons.js` and Persian JSON-LD meta strings in 13 localized blog pages.

**Human-side bilingual governance:** [`docs/05_BILINGUAL_CHECKLIST.md`](./05_BILINGUAL_CHECKLIST.md) is the manual counterpart to the automated localization gates — run §1–§5 for every release and every new user-facing feature.

---
*Document created in Phase 3.1 (test engineering). Gates wired in Phase 3.2. Audit bugs A1–A12 closed in Phases 4.1–4.7. Refreshed in Phase 3.4 (documentation only — no production code, schema, env, test, or workflow was modified).*
