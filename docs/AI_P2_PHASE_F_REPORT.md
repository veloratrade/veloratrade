# Phase F — System Settings + Feature Flags (control plane)

**Verdict: READY FOR REVIEW**
**Date:** 2026-09-03 · **Working tree only — NO commit/push/merge/deploy.**
`HEAD 742636930ae26cb6e645e1b59137c62b79fa8943`, `main`.

Phase F delivers a **centralized, server-authoritative Feature Flag control
plane** in the Admin Panel, built entirely on the repository's existing
configuration/resolver architecture (rule 1, 3, 4). It deliberately does **not**
introduce a generic settings store or a second configuration system, and it does
**not** expose infrastructure/bootstrap secrets.

---

## Audit

The read-only audit is in `docs/AI_P2_PHASE_F_AUDIT.md`. Key findings that
shaped the implementation:

- **No generic `system_settings`/`feature_flags` second config system exists.**
  Actual runtime settings are routed through **domain-scoped** stores managed by
  dedicated admin controllers (`ai_global_settings` — AI global route;
  `ai_feature_providers` — AI chains; integration secret stores — MetaAPI/Email/
  n8n relay), plus the read-only effective-config resolver. A generic
  key-value settings table would **duplicate** (rule 4) and would have **no
  runtime consumer** (rule 5 → do not invent settings).
- **Infrastructure/bootstrap secrets already live outside the panel.**
  `config.php` handles DB credentials, `APP_ENCRYPTION_KEY`, `JWT_SECRET`, CORS
  — none exposed by any admin API; `SecureCredentialStore` returns presence
  booleans only. **Nothing to change and nothing leaked.**
- **Feature flags already exist and are runtime-consumed** (`ai_feature_flags` +
  `AIFeatureFlagRepository` + `AIFeatureGuard`, used by `ScreenshotExtractController`,
  `TradeAnalyzerService`, `WeeklyReportService`) with a **deterministic**
  `crc32(feature:user) % 100` rollout. The **genuine, evidence-backed gap**: no
  admin API, no RBAC permission, no audit, no UI to manage them. This is Phase
  F's deliverable.
- **Settings the repository actually supports at runtime are already
  admin-managed** (AI route, AI chains, integrations). No additional settings
  were invented (rules 4–5).
- **Environment identity** is established by deployment (`APP_ENV`); reused as
  read-only metadata, no second detector (rule 16).
- **No cache layer/Redis** to invalidate — flags are read from the DB per call.

## Implemented

| Piece | File | Note |
|---|---|---|
| Flag permissions | `api/src/Auth/Role.php` | `P_FEATURE_FLAGS_VIEW` (admin+super) + `P_FEATURE_FLAGS_EDIT` (super only), wired into `permissionMap()` |
| Flag repository | `api/src/AI/Repositories/AIFeatureFlagRepository.php` | actor-aware, success-reporting `setFlag()` (portable cross-driver upsert, same as `AIGlobalSettingRepository`), `get()`, `CANONICAL_FLAGS`; `isEnabled()`/`all()` semantics unchanged (rule 17) |
| Flag controller | `api/src/Admin/FeatureFlagController.php` (NEW) | `index()` (canonical + persisted + effective + env + runtime) and `update()` (enable/disable/rollout, validation, rate-limited, audited) |
| Routes | `api/index.php` | `GET /api/v1/admin/feature-flags` (view), `PATCH /api/v1/admin/feature-flags/{feature}` (edit, super-admin) |
| Schema (additive) | `api/database/schema.sql` + `v1.6_feature_flags.sql` (+ `_rollback.sql`), `api/init-sqlite.php` | add `updated_by BIGINT NULL` to `ai_feature_flags`; rollback drops only that column |
| Dev seed | `tools/dev/serve_db.php` | flags DDL + canonical flag seed + a manager (admin) user for RBAC testing |
| Localization | `public/locales/{en,fa}.json` | 33 `admin.flags.*` keys each, FA/EN parity |
| Panel UI | `admin/index.html` | `VeloraAdminFlagKeys` map + Feature Flags panel (table, Enable/Disable/Edit-targeting with `VeloraDialog.confirm` + server refresh, loading/empty/error/unauth states, responsive overflow containment) |
| Backend tests | `tools/tests/test_feature_flags.php` (NEW) | 25 checks |
| Browser E2E | `tools/dev/browser_verify_phase_f.mjs` (NEW) | 22 checks |

**No generic System Settings form was added** — the runtime settings the repo
supports are already managed (AI route, AI chains, integrations); bootstrap
secrets stay in `config.php`/env; `P_SETTINGS_VIEW`/`P_SETTINGS_MANAGE` remain
(reserved, wired) for future use. See "NOT IMPLEMENTED" below.

## Settings

Not implemented as a generic module — with evidence:

| Setting family | Status | Evidence / reasoning |
|---|---|---|
| General (site name, default locale, timezone) | **NOT IMPLEMENTED** | No centralized runtime consumer. `config.php`/env are bootstrap-level; locale & timezone are **per-user**; site name is not runtime-consumed. Implementing would invent settings (rule 5) with no consumer. |
| Maintenance mode | **NOT IMPLEMENTED (gap reported)** | No centralized request gate exists; implementing would scatter `if (maintenance_mode)` (rule 12) — the architecture gap is documented rather than papered over. |
| Security (session/rate-limit/password policy) | **NOT IMPLEMENTED** | These are bootstrap/hard-coded in `config.php` (`jwt_ttl`, refresh cookie contract) and `AuthMiddleware`; making them runtime-editable would weaken auth and isn't backed by a safe consumer. No plaintext/insecure setting exposed. |
| AI (provider/model/route/fallback/limits) | **Already managed** by `AIConfigController`/`AiGlobalRouteController` (Phase A/B) | Reused as-is; not duplicated. |
| Email / Integrations (provider/transport/n8n/MetaAPI) | **Already managed** by `IntegrationsController`/`RelayConfigController` + `IntegrationConfigResolver` (Phase A/C) | Secret-bearing values live in `SecureCredentialStore`/`ai_provider_credentials`; only presence booleans returned. |
| Operational (retries/timeouts/queue/cache) | **NOT IMPLEMENTED** | No runtime consumer; no Redis/cache layer to configure; not safe to expose. |

Dangerous settings (would disable auth/weaken security) are **not** exposed; no
"dangerous settings" bypass was created (rule 11).

## Feature Flags

The canonical, runtime-consumed flag set is exposed and manageable:
`ai_screenshot_extraction`, `ai_trade_analysis`, `ai_weekly_report`,
`ai_assistant` (matches `v0.5` seed + `AIFeatureGuard`).

Runtime chain (server source of truth; the panel never bypasses the resolver):

```
Admin Panel (Enable / Disable / Edit-targeting)
   ↓  VeloraData.request (authenticated)
API  PATCH /api/v1/admin/feature-flags/{feature}
   ↓  AuthMiddleware::requirePermission(P_FEATURE_FLAGS_EDIT)   [super_admin]
FeatureFlagController  →  validate (canonical feature, enabled bool, 0–100 rollout)
   ↓
AIFeatureFlagRepository::setFlag()  →  ai_feature_flags (persisted, updated_by)
   ↓  AuditLogRepository::record(...)  feature_flag.enabled/.disabled/.updated
   ↓  (no cache; read-per-call)
AIFeatureGuard / AIFeatureFlagRepository::isEnabled()
   ↓
Runtime consumer (ScreenshotExtract / TradeAnalyzer / WeeklyReport) + Admin read-back
```

- **Enable/Disable** → `PATCH {enabled:true|false}`; rollout defaults to 100/0.
- **Targeting** → deterministic percentage rollout, stable per user, evaluated
  server-side (reused `crc32(feature:user) % 100`); no arbitrary user targeting
  (rule 15).
- **Environment** → surfaced as read-only metadata from `APP_ENV`; flags are
  single-tenant runtime switches (no second detector, no `environment` column).
- **RBAC** → View = admin+super_admin; **Edit = super_admin only** (flags are
  server-authoritative switches for ALL users ⇒ privileged). Admin denied
  `feature_flags.edit`; ordinary user denied both.
- **Audit** → every mutation writes `feature_flag.enabled/.disabled/.updated`
  with safe old→new metadata (`feature`, `old_enabled`, `new_enabled`,
  `old_rollout`, `new_rollout`, `environment`) — never a secret.

## Runtime Chain

```
    ADMIN PANEL (Feature Flags)
        │
        ▼
   Authorized API  (bearer token + requirePermission)
        │
        ▼
  FeatureFlagController  ──validate──▶  AIFeatureFlagRepository (ai_feature_flags)
        │                                     │
        │                                     ▼
        │                                  AuditLogRepository (feature_flag.*)
        │
        ▼
  AIFeatureGuard / isEnabled()  (centralized resolver)
        │
        ▼
   Runtime Consumer
```

## Security

Tests executed (backend harness + browser E2E):
- **Authorization**: ordinary user → 403 on both view and edit; admin → 200 view
  but **403 edit** (super-admin only); super_admin → 200 both. Confirmed via
  `AuthMiddleware::requirePermission` unit tests and a real browser against the
  staged build.
- **Privilege escalation prevention**: flags have zero influence on role /
  permissions / subscription; role-change/auth/security settings are **not**
  editable (no surface); an admin cannot grant itself `feature_flags.edit`.
- **Secret hygiene**: no secret-shaped value in API responses, DOM, network
  responses, audit metadata, or test output (browser secret scans + harness
  regex). Credential values never read into flag data.
- **Injection / validation**: feature name allowlisted (canonical set),
  `enabled` boolean required, `rollout` integer bounded [0,100]; out-of-range /
  unknown values rejected server-side (422).
- **SSRF**: no new URL fields; integration URLs remain governed by
  `IntegrationConfigResolver` (rule 21).
- **Deterministic rollout** verified stable across repo reloads and per-user.

## Browser

`BROWSER_VERIFY_PHASE_F: 22/22 passed` (real Playwright/Chromium against the
staged build, `releaseId=2026.09.03.phaseF`): super_admin login; panel
renders + lists 4 flags; environment reported; enable → ON; disable → OFF;
edit-targeting → 25%; audit `feature_flag.*` recorded; no secrets in DOM or
network; **no console errors**; ordinary-user denied (view+edit, 403); admin
allowed view (200) but denied edit (403); EN and FA localized admin pages load
the panel + `VeloraAdminFlagKeys`; **mobile 390px no horizontal overflow**
(sw=cw=390).

## Regression

Pre-existing baseline failures (unchanged, documented — not introduced here):
- `tools/tests/test_ai_p1_architecture.py` — **FAILS (baseline)**. Its
  "Core/* untouched" assertion trips on the legitimate working-tree
  `api/src/Core/Mailer.php` modification from an earlier phase (Integration
  config). Phase F adds nothing under `api/src/Core/`.

Executed & green this round:
- `localization_gate.py` — `LOCALIZATION_GATE_OK` (24-allowlist freeze intact; catalog 29/29/61/2)
- `test_security_static_gates.py` — 8/8 OK
- `test_verification_gate.php` — 14/14
- `test_admin_panel.php` — 48/48
- `test_user360.php` — 24/24
- `test_feature_flags.php` — **25/25 (new)**
- `test_admin_ai_config.php` — 44 checks PASS; `test_admin_ai_ui.php` — 47 checks PASS; `test_ai_locale_contract_runtime.php` — PASS
- **Browser regressions**: Phase E `33/33`, Phase D `15/15`
- Individual gates: `check_key_references` PASS, `check_frozen_hash_keys` PASS (879/879), `validate_localization` issues=0

## Localization

- 33 `admin.flags.*` keys in each of `en.json`/`fa.json`, FA/EN parity.
- `VeloraAdminFlagKeys` inline feature-chunk map (mirrors `VeloraAdminUserKeys`,
  the documented allowance from Phase D), so the panel strings are translated
  without a global catalog load.
- Rebuilt static: `LOCALIZED_BUILD_OK templates=29 html=61 feature_chunks=36
  csp_routes=61 releaseId=2026.09.03.phaseF`.
- `check_key_references` **PASS** (runtime-features covered); `check_hardcoded_ui`
  **no new violations**; `check_frozen_hash_keys` **879/879**; `validate_localization`
  **issues=0**. EN/FA pages verified loading the map in the browser.

## Deployment

- Commit: **NOT PERFORMED**
- Push: **NOT PERFORMED**
- Merge: **NOT PERFORMED**
- Deploy: **NOT PERFORMED**
- Production verification: **NOT PERFORMED**

## Remaining Gaps

- **Generic System Settings page did not exist and was not created** (rule 4/5).
  If a reviewer wants one, it requires a runtime consumer for each field +
  centralized request gate for maintenance mode — both absent today. Reported,
  not fabricated.
- `test_ai_p1_architecture.py` baseline failure (described above) — a
  pre-existing working-tree delta, unaffected by Phase F; needs a Phase-D/A
  carry-over decision (not Phase F).
- `updated_by` is stored on the flag row for display; the canonical actor record
  remains the immutable audit log (`actor_user_id`). No cross-join needed for UI.
- The flag panel manages the AI runtime flag space; non-AI feature rollout (if
  any future feature wants a flag) would need a row added to
  `AIFeatureFlagRepository::CANONICAL_FLAGS` — a conscious single-source list.

## Verdict

**READY FOR REVIEW.** Phase F adds a centralized, server-authoritative Feature
Flag control plane — permission-gated (edit super-admin only), validated,
audited, deterministic-rollout, environment-aware, localized, and verified with
25 backend checks and a 22-check real-browser E2E — reusing the existing flag
store/guard/resolver. It exposes **no** infrastructure secrets, adds **no**
second config system, weakens no RBAC, and leaves Phase A–E behavior intact
(full regression green; the only failure is a documented pre-existing baseline
delta in `test_ai_p1_architecture.py`). Generic settings that the architecture
does not support are reported as NOT IMPLEMENTED rather than fabricated. **Not
committed, not pushed, not deployed.**
