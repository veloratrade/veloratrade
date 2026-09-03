# AI P2 — PHASE J (Final Release Gate + Production Deployment Readiness) AUDIT

**Baseline HEAD:** `742636930ae26cb6e645e1b59137c62b79fa8943` · **branch:** `main`
**Date:** 2026-09-04 · **Scope:** read-only evidence-first release gate. **No commit/push/PR/merge/deploy/cred-rotation.**

This is the complete evidence trail. Live re-verification was performed in this environment (PHP runtime +
server + Playwright/Chromium were re-provisioned) so that claims were **independently re-run**, not trusted
from the Phase I report.

---

## 0. Baseline record

- `git rev-parse HEAD` → `742636930ae26cb6e645e1b59137c62b79fa8943` (unchanged).
- `git branch --show-current` → `main`.
- Status: 30 tracked modifications, 0 staged additions (`^A`), 58 untracked files, 56 files changed in diffstat.
- All Phase A–I work (modified + untracked files) preserved. **No reset/clean/checkout/stash/revert/delete.**

## 1. Working-tree inventory (release scope)

**Tracked modifications (30):** `admin/index.html`, `api/database/schema.sql`, `api/index.php`,
`api/init-sqlite.php`, `api/src/AI/{Providers/GeminiProvider.php, Repositories/AIFeatureFlagRepository.php,
Services/AIManager.php, Services/FeatureRouter.php, Transports/N8nGeminiRelayTransport.php}`,
`api/src/Accounts/MetaApiService.php`, `api/src/Admin/AIConfigController.php`, `api/src/Admin/AdminController.php`,
`api/src/Auth/AuthMiddleware.php`, `api/src/Core/{Mailer.php, Request.php, SecureCredentialStore.php}`,
`api/src/Webhooks/MetaApiWebhookController.php`, `api/src/bootstrap.php`, `localized/{.csp-release.json,
en/admin/index.html, fa/admin/index.html}`, `public/assets/velora-admin-ai.js`,
`public/locales/{chunks/en|fa/admin.json, csp-manifest.json, en.json, fa.json, feature-manifest.json}`,
`tools/localization/allowlist-hardcoded.json`, `tools/tests/test_admin_ai_ui.php`.

**Untracked (58) — Phase A–I deliverables incl.:** 6 migrations with rollbacks (v1.4, v1.5, v1.6), ~22 new
`api/src/Admin|Core|AI/...` source files, 4 admin JS assets, 8 test harnesses, 2 dev scripts,
`node_modules/`, `package.json`/`lock`, and `docs/AI_P2_PHASE_*.md` reports.

**Dev-only / must-exclude from release:** `node_modules/`, `api/data/` (runtime/dev SQLite — empty & not git-ignored), `tools/dev/*`.

## 2. Phase I claims — independently re-verified (LIVE, not trusted)

| Claim | Verified? | Evidence |
|---|---|---|
| Phase I harness exists | ✅ | `tools/dev/browser_verify_phase_i.mjs` present |
| 73/73 PASS | ✅ | re-ran → `BROWSER_VERIFY_PHASE_I: 73/73 passed` |
| Served-artifact verification | ✅ | served `/admin`, `/localized/{en,fa}/admin`, `velora-admin-ai.js` all contain Phase I fixes |
| Phase D/E/F/G/H regression | ✅ | re-ran → D 15/15, E 33/33, F 22/22, G 27/27, H 32/32 |
| Localization gates | ✅ | gate OK, refs PASS, frozen 879/879, issues=0 |
| Security gates | ✅ | static gates OK |
| Known arch failure | ✅ | same Mailer.php assertion (see §10) |
| Working-tree protection | ✅ | HEAD unchanged |

## 3. Release-gate assessments result (summary — full detail in report)

- **Auth/RBAC** → PASS (independent live probe: 401 unauth / 403 user / 200 admin+super on 8 endpoints).
- **Security** → PASS (no secrets in DOM, network responses, or API output; RBAC + IDOR + escalation verified).
- **Billing** → PASS (honest `available:false`, no payment fields, no fake revenue).
- **Analytics** → PASS (honest `available:false / NO_BILLING_SOURCE`; trading P&L labelled non-revenue; bounded).
- **Observability** → PASS (diagnostics 200 structured; logs endpoint 200; RBAC enforced).
- **Feature flags** → PASS (view=admin 200, mutate=admin 403 / super 200 / user 403 — server-enforced split).
- **Build / served artifact** → PASS (build reproducible; artifacts contain fixes; browser consumes served output).
- **Database / migration safety** → **FAIL (P1)** — see §4.
- **Configuration** → identified as deployment prerequisites (see report §11).

## 4. CRITICAL FINDING — production schema gap (P1, BLOCKER)

`api/install.php` builds the production schema by executing **`database/schema.sql`** (install.php:166), and
the migration set is **not** applied by the installer and has **no automated runner** (files are manual-only,
per their own headers).

`schema.sql` (the production source of truth used by the installer) is **MISSING** four tables the delivered
application code requires:

| Table | Phase | Where it actually exists | In `schema.sql`? |
|---|---|---|---|
| `ai_provider_credentials` | A | migration `v1.2_provider_credentials.sql` | **ABSENT** |
| `admin_audit_logs` | C/E | migration `v1.3_admin_management.sql` | **ABSENT** |
| `system_logs` | D | migration `v1.5_system_observability.sql` | **ABSENT** |
| `integration_health` | D | migration `v1.5_system_observability.sql` | **ABSENT** |
| `ai_global_settings`, `ai_feature_flags.updated_by`, `sync_jobs`, `users.locale_updated_at` | B/F/D/PR-03 | present | PRESENT |

**Why it was hidden:** development works because the **dev-only** `tools/dev/serve_db.php` creates the *full*
schema (it defines `ai_provider_credentials`, `admin_audit_logs`, `system_logs`, `integration_health`,
`ai_global_settings`, plus an unused `integration_settings`). `serve_db.php` is not the canonical bootstrap
(`api/init-sqlite.php` is also missing these tables) and is never used in production. So a **clean production
install via install.php → schema.sql would be missing observability, audit, and provider-credential tables**,
and the corresponding modules (System Health/logs, audit log, AI credential verification) would fail at
runtime. This is exactly the "dev-only schema mutation that could hide a production failure" (§3.8).

**Classification:** **P1 — blocks release.** Production safety cannot be established via the automated path.

## 5. `users.locale_updated_at` root cause (Phase I 500) — confirmed RESOLVED, not a chain gap

- Defined in `schema.sql` AND `init-sqlite.php` → a **fresh** DB has the column.
- Committed, idempotent migration `add_user_locale_preference.sql` (in HEAD) adds it to an existing MySQL DB.
- The Phase I 500 occurred because the **live dev SQLite DB** (built by `serve_db.php`) was missing it — i.e.
  **stale dev state**, not an incomplete migration chain. The column was added to that DB and the endpoint now
  returns 200 (live-verified, fa and en).
- **Not a blocker.** Production must simply have the committed locale migration (or fresh schema) — both covered.

## 6. Migration safety properties assessed (§3 checklist)

| Property | Assessment |
|---|---|
| Additive? | Yes — all v1.2–v1.6 are `ADD`/`CREATE TABLE IF NOT EXISTS`, no drops/alters of existing data |
| Idempotent? | `CREATE TABLE IF NOT EXISTS`; `add_user_locale_preference` uses `information_schema` guards; `ALTER TABLE ... ADD COLUMN` (v1.6) is not guarded in SQL, but is additive and single-run |
| Production already contains columns? | Prod DB unknown to this audit (no environment access) — **must be verified against prod** |
| Clean-DB order? | Order v1.2→v1.3→v1.4→v1.5→v1.6 works; v1.4/`ai_global_settings` also duplicated in schema.sql (idempotent, safe) |
| Existing-DB? | Requires manual ordered application (no runner) |
| Rollbacks syntactically valid? | v1.4/v1.5/v1.6 rollbacks are well-formed `DROP TABLE`/`DROP COLUMN` |
| SQLite/MySQL consistency? | **NO** — `system_logs`/`integration_health`/`admin_audit_logs`/`ai_provider_credentials` are in migrations but not in `schema.sql`/`init-sqlite.php`; dev relies on `serve_db.php` |
| Dev-only mutation hides prod failure? | **YES** — `serve_db.php` creates tables absent from `schema.sql` (P1) |

## 7. Security scans (false-positive-aware)

- DOM secret scan: no secrets (refined regex no longer flags masked `metaapiTokenInput` id).
- API network responses: no secrets.
- `billing` API returns no payment/apikey fields; `provider.available=false`.
- `analytics` returns no revenue values.
- **No secret value printed in this report.**

## 8. Known architecture failure — precise bound

`test_ai_p1_architecture.py` asserts `Core/TradeService/TradeRepository should be untouched`. It fails because
`api/src/Core/Mailer.php` differs. Working tree modifies exactly: `Mailer.php`, `Request.php`,
`SecureCredentialStore.php` (tracked) + 4 new files (`IntegrationConfigResolver`, `IntegrationSettingsRepository`,
`SecretRedactor`, `SystemLogRepository`). **`TradeService` and `TradeRepository` are NOT modified.** The
`Mailer.php` change is intentional (Phase C/G): mail settings resolve with highest precedence to the
Admin-managed value via `IntegrationConfigResolver`. It is documented in Phases C/D/F/G/H/I reports and is
unit-covered by the integrations suites (all PASS). **Classification:** ACCEPTABLE PRE-EXISTING (the AI-architecture
invariant — AI not depending on Trades — is intact; Mailer is unrelated). Flagged for human confirmation (see report).

## 9. Operational sanity (§18)

- Analytics bounded (≤366d custom, UTC, `all` from 1970-01-01); logs paginated/bounded; users paginated.
- No evidence of unbounded list/table rendering or duplicate/multi-fetch requests in the served bundle.
- No premature optimization performed.

## 10. Conclusions

All functional, security, RBAC, localization, build, browser, and honest-state gates **PASS**. The **database /
production-schema gate FAILS** with a P1: the production install schema is incomplete for the delivered code, and
the gap is masked by dev-only seeding. Verdict: **BLOCKED**.
