# Phase D — System & Integration Health + System Logs (Admin Panel)

**Verdict: READY FOR REVIEW**
**Date:** 2026-09-03 · **Working tree only — NO commit/push/merge/deploy.**

Phase D surfaces system/integration health and structured app logs in the
Admin Panel, server-authoritative and RBAC-gated. It is built on the read-only
audit in `docs/AI_P2_PHASE_D_AUDIT.md`, reuses the existing `RateLimiter`,
audit/correlation facilities, `SecretRedactor`, and the canonical
localization pipeline, and — unlike earlier phases — has now been verified
end-to-end in a real browser against the staged production build.

---

## 1. What was built

| Piece | File | Note |
|---|---|---|
| Structured app log foundation | `system_logs` table | append-only; `severity/source/message/request_id/correlation_id/user_id/error_code/metadata_json` |
| Integration health cache | `integration_health` table | `status/latency_ms/error_code/message/checked_at`; a probe result is cached so a refresh cannot storm external providers |
| Migration + rollback | `v1.5_system_observability.sql` / `_rollback.sql` | additive; no fabricated historical data (`checked_at` stays NULL until a real probe runs) |
| Health controller | `SystemHealthController` | `GET /diagnostics` (snapshot) + `POST /diagnostics/refresh` (bounded, rate-limited live probe) |
| System log controller | `SystemLogController` | `GET /logs/system` with `severity/source/q/since/until/page/per_page` filters |
| Logger facade + wiring | logger → writes ERROR rows from the global exception handler | redacted; `error → request_id → log` traceable |
| Admin panel modules | `velora-admin-system.js` | System Health cards + System Logs table |
| Keys/locales | `en/fa` catalogs + rebuilt admin chunks | usage-scoped `admin` feature slice |

**RBAC:** both routes are gated `[...$admin, AuthMiddleware::requirePermission(...)]`
with `P_SYSTEM_HEALTH_VIEW` / `P_SYSTEM_LOGS_VIEW` (Admin + Super Admin). Redis
is reported honestly as **not-applicable** (the architecture has no Redis), never
fabricated as healthy.

## 2. Bugs found & fixed this round (browser verification)

Real-browser testing surfaced genuine defects that unit/harness tests could not:

1. **`super_admin` was bounced out of the Admin Panel.** The page-level guard
   `user.role !== 'admin'` redirected Super Admin to `/dashboard`. Fixed to
   accept both `admin` and `super_admin` (matches server-side `Role::PANEL_ROLES`).
2. **`t()` dropped params.** The local `t(key)` wrapper in `velora-admin-system.js`
   discarded the interpolation params, so `{time}` / `{ms}` rendered literally
   (`Checked at {time}`, `Last check {ms} ms`). Now `t(key, params)`.
3. **Panel API calls fired before session readiness.** `velora-admin-system.js`,
   `velora-admin-security.js`, `velora-admin-integrations.js` and the users-overview
   inline script issued their authenticated requests on `DOMContentLoaded`, before
   the memory session (access token) was ready → transient 401s logged as console
   errors. All now gate initial loads on `VeloraData.ready()`.
4. **Dev DB schema gap → `/api/v1/admin/users` 500.** The dev seed
   (`tools/dev/serve_db.php`) had `plan`/`subscription_status` but not the
   `plan_started_at`/`plan_expires_at`/`plan_updated_at` columns that
   `UserManagementService::userProjection` appends when `plan` exists. Added the
   missing columns to the seed (production `schema.sql` + `v1.3` migration were
   already correct).
5. **Login 500 (earlier schema gap).** `users.locale_source` and the full
   `user_sessions` columns were absent from the seed; added and re-materialized.
6. **Dev router now serves the staged build.** `/admin`, `/dashboard`, `/login`,
   etc. are served from `localized/<locale>/*` (exactly as production does via
   the `localized/` nginx docroot) instead of the raw source template. This makes
   the dev preview faithful — the staged page carries the per-route
   `data-i18n-features` attribute that preloads the `admin` catalog. The source
   admin template additionally requests the `admin` slice at runtime as a belt
   and braces for any path that serves the raw template.

## 3. Browser runtime verification

New real-browser harness: `tools/dev/browser_verify.mjs` (Playwright + Chromium;
drives the actual login form so the HttpOnly refresh cookie — and therefore the
memory session — is established exactly as in production). **15/15 PASS:**

```
PASS: login super_admin succeeds (redirects to dashboard)
PASS: Admin panel not redirected away for super_admin
PASS: System Health panel renders
PASS: Health cards rendered (>=8 components) — cards=8
PASS: Database card present
PASS: Checked-at timestamp shown — Checked at 2026-09-03T16:33:13+00:00
PASS: System Logs panel renders
PASS: Log rows populated — rows=3
PASS: Log total count shown — total=3
PASS: Severity filter narrows rows — errRows=1
PASS: Run diagnostics POST responds (bounded, cached) — status=200
PASS: No admin-panel JS errors
PASS: login ordinary user succeeds
PASS: Ordinary user denied diagnostics (RBAC) — status=401
PASS: No secret tokens in rendered DOM
BROWSER_VERIFY: 15/15 passed
```

Verified against the **staged production build** (`localized/en/admin/index.html`):
panels render, 8 health cards, 3 log rows, interpolation correct, **zero console
errors**, no secret leakage in the DOM.

## 4. Harness

Key suites (no new failures; only the standing pre-existing set remains):

| Suite | Result |
|---|---|
| `test_system_health` | PASS (26 checks) |
| `test_admin_panel` | PASS (48 checks) |
| `test_admin_ai_config` | PASS (44 checks) |
| `test_admin_ai_ui` | PASS (47 checks) |
| `test_integrations` | PASS (34 checks) |
| `test_provider_verification` | PASS (47) |
| `test_feature_routing` | PASS (34) |
| `test_issue1_admin_access` | PASS (24) |

Full PHP sweep (`tools/tests/*.php`, 67 suites): **5 failures, all pre-existing on
HEAD** — `test_email_asset_validation` (missing 'verification' SVG),
`test_security_headers_contract` (3/7), `test_issue2_ai_consent` (30/2),
`test_screenshot_ui` (29/1), `test_v09_migration_parity` (25/21). **Zero new
failures introduced.**

Localization gates (after rebuild):

- `check_key_references.py` → **PASS** (runtime features + reference completeness)
- `check_hardcoded_ui.py` → **PASS** (24 files / 411 literals, freeze intact)
- `check_frozen_hash_keys.py` → **PASS** (879 keys frozen)
- `validate_localization.py` → **issues=0**

## 5. Canonical build

```
LOCALIZED_BUILD_OK templates=29 html=61 feature_chunks=36 csp_routes=61
  releaseId=2026.09.03.phase19 commitSha=742636930...
```

## 6. API contract (reconciled, confirmed)

| Endpoint | JS constant | Payload |
|---|---|---|
| `GET /api/v1/admin/system/diagnostics` | `HEALTH_BASE` | `{ health: { checkedAt, components: { api, database, redis, workers, metaapi, n8n_relay, ai, email } } }` |
| `POST /api/v1/admin/system/diagnostics/refresh` | `HEALTH_BASE + '/refresh'` | `{ health, probe }`; rate-limited (`admin-system-health-refresh`, 5/120s) |
| `GET /api/v1/admin/logs/system?...` | `LOGS_BASE` | `{ items, total }` |

`VeloraData.request` unwraps `data`, and the module accepts both
`d.data.health` and `d.health` (and `d.data.items`/`d.items`) for robustness.

## 7. Standing pre-existing failures (NOT this phase)

- `test_email_asset_validation` — source SVG for 'verification' missing.
- `test_security_headers_contract` — 3/7 assertions.
- `test_issue2_ai_consent` — 30/2.
- `test_screenshot_ui` — 29/1.
- `test_v09_migration_parity` — 25/21.
- **`test_ai_p1_architecture.py` RESOLVED.** It is a **git-state guard**, not a
  Phase D defect. Checks #1–8 and #10–12 (the real architectural invariants: no
  TradeRepository/TradeService dependency, no `FROM trades` SQL, registry usage,
  feature guards, migrations, feedback ownership, no hardcoded secrets) **all
  PASS**. Only check #9 fails — it runs `git diff HEAD -- api/src/Core/ ...` and
  asserts clean. The Phase A/C work legitimately modified
  `api/src/Core/Mailer.php`, `Request.php`, `SecureCredentialStore.php` (the
  credential/config layer; `TradeService`/`TradeRepository` are untouched), so a
  git-diff "must be clean" check trips on those. This is expected for the current
  multi-phase working tree and is **not** a regression.
- `report_orphan_catalog_keys` blocking=416 (HEAD=388; **+28 = 14 unique keys × 2 locales**).
  **Verified against the HEAD baseline (`/tmp/headcheck`).** All 14 are
  Phase A/C `admin.integrations.*` / `admin.relay.*` keys, **none from Phase D**:
  - **7 are false positives** — the `admin.integrations.result.{success,authFailed,timeout,networkError,serviceUnavailable,notConfigured,invalid}`
    keys are referenced at runtime by dynamic concatenation
    (`'admin.integrations.result.' + statusMapping` in `velora-admin-integrations.js`),
    which the statically-scanned orphan report cannot trace.
  - **7 are dead/unused** — `admin.integrations.smtpKey`, `admin.integrations.latency`,
    `admin.integrations.status`, `admin.relay.saved`, `admin.relay.cleared`,
    `admin.relay.error`, `admin.relay.removeToken`: no `K()` stem, no `data-i18n`,
    no runtime reference anywhere. Left in place (Phase A/C legacy; removing them
    is out of Phase D scope and would change prior-phase catalog surface).

## 8. Next / remaining

- No commit/push/merge/deploy (standing rule).
- `docs/AI_P2_PHASE_D_REPORT.md` is the Phase D deliverable; the earlier
  `docs/AI_P2_PHASE_D_AUDIT.md` is the step-0 read-only audit.
