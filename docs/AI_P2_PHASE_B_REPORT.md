# Phase B — Admin-Managed Global AI Route & `ai_gemini_relay_route` Feature Flag

**Scope:** Make the global AI route/default behavior and the `ai_gemini_relay_route`
feature flag *safely manageable from the Admin Panel*, preserving existing
per-feature route overrides and backward compatibility.

**Result:** ✅ **COMPLETE — READY FOR REVIEW.** Full chain proven
(Super Admin → Admin Panel → Admin API → DB → runtime resolver → `GeminiProvider`),
all 16 Phase B checks green, zero regressions, HEAD unchanged, no commit/push/merge/deploy.

---

## 1. Final verdict

**READY FOR REVIEW**

| Verdict input | Status |
|---|---|
| Runtime chain (Admin PUT → DB → resolver → provider) | ✅ Proven (HTTP contract + in-process + fresh-instance) |
| RBAC (read admin, modify Super Admin-only; server-side) | ✅ Enforced in router middleware; verified by test |
| Audit (actor, action, timestamp, old/new route; no secrets) | ✅ `ai.route.updated` / `ai.route.reset` |
| Backward compatibility + per-feature precedence | ✅ Preserved |
| Localization (en/fa canonical + build) | ✅ Fresh (`ARTIFACT_FRESHNESS_OK`) |
| Regression | ✅ 0 new failures vs pristine HEAD |
| Git state | ✅ HEAD `742636930…` unchanged; 63 dirty, no commit |

**One explicit design deviation surfaced for confirmation** (see §9): the
"modify" permission name used is a new `P_AI_ROUTE_MANAGE` (`aiRouteManage`,
Super Admin-only) rather than the acceptance-draft's suggested
`P_INTEGRATIONS_MANAGE`. The core invariant (modify = Super Admin only) is met
exactly; this is a naming choice, not a semantic change.

---

## 2. What "global AI route" means here (single authority)

Before Phase B, route selection was split across three places (`GeminiProvider::getRoute`
env→flag, plus per-feature chain `route`). Phase B introduced **one resolver** that is
the sole authority for the *default* route, so runtime and Admin panel can never disagree.

New: `api/src/AI/Services/AiRouteResolver.php`

```php
public const ROUTE_DIRECT  = 'direct';      // direct Gemini API
public const ROUTE_RELAY   = 'n8n_relay';    // via N8nGeminiRelayTransport
public const SETTING_GLOBAL_ROUTE = 'ai_route_default';   // key in ai_global_settings
```

Sources returned by `resolveWithSource()`: `admin`, `env`, `legacy_flag`, `default`.

### Setting keys (new, key–value table, no duplicate config system)

`api/database/migrations/v1.4_ai_global_route.sql` (+ `_rollback.sql`):

```sql
CREATE TABLE IF NOT EXISTS ai_global_settings (
    setting_key     VARCHAR(64)     NOT NULL,
    setting_value   VARCHAR(64)     NULL,
    updated_by      BIGINT UNSIGNED NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB ...;
```

- InnoDB, utf8mb4, idempotent (`IF NOT EXISTS`), rollback provided, no env-specific
  seeds, no secrets.
- **Why a new table and not `ai_feature_flags`:** `ai_feature_flags` is
  `(feature_name PK, enabled TINYINT, rollout_percentage)` — boolean-only; it cannot
  store a tri-state route string (`direct` / `n8n_relay` / unset). Reusing it would
  be a type abuse. This is a **generic settings** table (1 row), not a second
  "configuration system."
- `schema.sql` updated to add `ai_global_settings` after `ai_feature_providers`.

---

## 3. Exact route precedence (documented + implemented)

Single documented string in `EffectiveConfigService::precedence()`:

```
route = chain-row route override
      > Admin global AI route (ai_global_settings)
      > GEMINI_ROUTE env
      > ai_gemini_relay_route (DB feature flag)
      > direct
```

Note: this **preserves** the established per-feature > env > flag > direct ordering
(per the standing instruction not to silently change it) and injects the Admin
global route **between per-feature and env**. A value explicitly saved by the Admin
is read *before* env, so `GEMINI_ROUTE` can never silently override an explicit
admin decision; env still wins for *inherited* (not-saved) state.

`AiRouteResolver::resolve()` (authoritative):

```
admin_global (ai_global_settings)  -> if valid, return [route, source='admin']
GEMINI_ROUTE env                   -> if direct|n8n_relay, return [route, source='env']
ai_gemini_relay_route flag enabled -> n8n_relay, source='legacy_flag'
otherwise                          -> direct,  source='default'
```

Per-feature `route` still wins **at call time** in `GeminiProvider::generate()`
(`$options['route']` override validated against the allowlist, else resolver default)
and in `AIManager::generate()/extract()` (`$callOptions['route']` / `$routeOverride`).

---

## 4. HTTP contract (Admin API — smallest surface, reuses routing/RBAC/audit)

`api/src/Admin/AiGlobalRouteController.php`, wired in `api/index.php`:

| Method | Path | Gate | Meaning |
|---|---|---|---|
| GET | `/api/v1/admin/ai/route` | `adminOnly` + `P_AI_MANAGE` | Read effective/configured/source |
| PUT | `/api/v1/admin/ai/route` | `adminOnly` + `P_AI_ROUTE_MANAGE` | Save global route |
| DELETE | `/api/v1/admin/ai/route` | `adminOnly` + `P_AI_ROUTE_MANAGE` | Reset → inherit legacy |

Request body (PUT): `{ "route": "n8n_relay" | "direct" }`.

**Validation (server-side):** value must be exactly one of the existing route
constants (`AiRouteResolver::ROUTE_DIRECT` / `ROUTE_RELAY`); anything else is
rejected with `422 ValidationException` / code `INVALID_AI_ROUTE` (tested:
arbitrary strings, and a `sql; DROP TABLE users` payload).

**Response** (`safeStatus()`, no secrets):

```json
{ "route": {
    "configured": "n8n_relay" | null,     // explicitly-saved Admin value (null = inherit)
    "effective":  "n8n_relay"|"direct",   // what runtime uses
    "source":     "admin"|"env"|"legacy_flag"|"default",
    "allowed":    ["direct","n8n_relay"],
    "providerEffective": "..."            // GeminiProvider::getRoute() as it runs today
} }
```

**Audit:** `AdminAuditLogRepository::record()` for `ai.route.updated`
(metadata `old_route`/`new_route`) and `ai.route.reset` (metadata
`old_route`/`effective_after`). No secrets.

---

## 5. Runtime consumption (this is the proof, not just a UI toggle)

- `GeminiProvider::getRoute()` now returns `(new AiRouteResolver())->resolve()` —
  the runtime GeminI default is the *same* value the Admin panel saved.
- `EffectiveConfigService::getConfig()` emits `globalRoute =
  {configured, effective, source, allowed}` and a documented `precedence` block —
  the panel and the runtime read the same single source.
- **Test proves it:** `global-ai-route` "Runtime consumer" check confirms
  `GeminiProvider::getRoute()` returns the Admin-managed value after a DB save via a
  **fresh resolver instance** (i.e. re-reading from DB, not in-memory cache).

---

## 6. RBAC (Role ≠ Plan; unchanged semantics)

| Capability | Permission | Roles |
|---|---|---|
| View / read | `P_AI_MANAGE` | admin, super_admin |
| Modify (save/reset) | `P_AI_ROUTE_MANAGE` (new) | **super_admin only** |

- Authorization is **server-side** (`AuthMiddleware::requirePermission(...)` in the
  router). The frontend also disables controls for a non-super Admin, but that is
  **UX only** — the server is the boundary and returns 403. Verified by test:
  admin (non-super) write → `PERMISSION_DENIED`; super_admin write → allowed.
- `pro` remains a subscription **plan**, never a role (unchanged). No role semantics
  changed; no new role introduced.

---

## 7. Admin Panel UI

`admin/index.html` (in the existing **AI Settings** panel) adds a new
"Global AI Route" subsection, and `public/assets/velora-admin-ai.js` adds a
`globalRoute` module (`ROUTE_BASE = /api/v1/admin/ai/route`).

**Configured / Source / Effective** are kept distinct (no fabricated connectivity):

- Configured: `نندn8n_relay` → **Configured: n8n Relay**; nothing saved →
  **Configured: Not set — inherits** (`admin.ai.routeUnset`).
- Effective: always the runtime value.
- Source: Admin Panel / Environment (GEMINI_ROUTE) / Legacy flag / Default.

**Frontend security rules honored:** state only from `GET` (no optimistic writes);
after a write we **re-fetch** authoritative state; no secrets involved (route is not a
credential); `textContent` only (no unsafe innerHTML for these values); no
localStorage/sessionStorage for configuration; authenticated requests only.

**No fake "connected":** the panel shows *configuration* (Configured/Effective/Source)
and never an "n8n Connected" claim just because route = `n8n_relay`. Connectivity/
workflow validity remain separate concerns (see Phase A `GeminiCredentialVerifier`).

---

## 8. Localization (canonical pipeline only)

- Added 18 keys to canonical `public/locales/en.json` + `public/locales/fa.json`
  (`admin.ai.*`).
- One key (`admin.ai.routeRelay`) initially had identical EN/FA text; FA corrected
  to `رله n8n (سرویس ابری)` so the EN/FA-parity check passes.
- **Rebuilt via the canonical builder** using the canonical commit SHA:
  `python3 tools/localization/build_localized_static.py --release-id 2026.09.03.phase14 --commit-sha 742636930…`
  → `LOCALIZED_BUILD_OK templates=29 html=61 feature_chunks=36 csp_routes=61`.
- `--check` → **`ARTIFACT_FRESHNESS_OK`**.
- No manual patch of generated files; no hardcoded Persian (use `data-i18n`/`K()`).

---

## 9. Design notes / deviations + why

1. **New permission name `P_AI_ROUTE_MANAGE` for modify.** The acceptance draft
   suggested reusing `P_INTEGRATIONS_MANAGE`. I introduced a dedicated
   `aiRouteManage` (Super Admin-only) so the AI-route capability is independently
   grantable/auditable and to avoid conflating "AI route" with "integrations."
   **Super Admin-only modify is satisfied exactly.** *(Confirm if you prefer the
   literal `P_INTEGRATIONS_MANAGE` name — a one-line change in `Role.php` +
   `index.php` + `test_global_ai_route.php`.)*

2. **New key–value table, not `ai_feature_flags`** (boolean-only; can't hold a route
   string) and not a duplicate config system. Documented in the migration header.

3. **`ai_gemini_relay_route` flag retained as `legacy_flag` fallback** (not deleted),
   now *below* env and admin per the preserved precedence. Safe — no behavior change
   for existing deployments that relied on it, and the Admin value now dominates.

4. **Migration file is the MySQL form** (matches repo `schema.sql`/InnoDB);
   the *runtime tests* run against SQLite via a portable `SELECT-then-INSERT-or-UPDATE`
   in `AIGlobalSettingRepository::set()` (no MySQL-only `ON DUPLICATE KEY`/`VALUES()`
   /`NOW()`), so the repo works on both backends.

---

## 10. Tests

**New Phase B harness:** `tools/tests/test_global_ai_route.php` — **16 checks, 0 failures.**

| # | Area | Check |
|---|---|---|
| 1–2 | RBAC | user → `ADMIN_REQUIRED`; admin may READ (`P_AI_MANAGE`) |
| 3–4 | RBAC | admin (non-super) write → `PERMISSION_DENIED`; super_admin may write |
| 5 | Validation | arbitrary route → `INVALID_AI_ROUTE` |
| 6 | Persistence | saved route read back by a **fresh resolver instance** |
| 7 | Case A | admin=`n8n_relay`, no env → `n8n_relay` (source=admin) |
| 8 | Case B | admin=`n8n_relay`, ENV=`direct` → **admin wins** (env never overrides) |
| 9 | Case C | no admin, ENV=`n8n_relay` → `n8n_relay` (source=env) |
| 10 | Case D | no admin/env/flag → `direct` (source=default) |
| 11 | Reset | reset clears → inherit legacy (configured null, effective falls back) |
| 12 | Layering | explicit per-feature `direct` **wins** over admin global `n8n_relay` |
| 13 | Layering | per-feature `n8n_relay` wins over admin global `direct` |
| 14 | Legacy flag | flag enables `n8n_relay` as `legacy_flag` when no admin/env set |
| 15 | Audit | change records `ai.route.updated` with old/new route, no secret |
| 16 | Runtime | `GeminiProvider::getRoute()` returns the Admin-managed route |

**Adjusted/green existing suites (all confirm no regression in the touched layer):**

| Suite | Result |
|---|---|
| `test_relay_config` (Phase A) | PASS 13 |
| `test_admin_ai_config` | PASS 44 |
| `test_admin_ai_ui` | PASS 47 |
| `test_feature_routing` | PASS 34 |
| `test_effective_config` | PASS 19 |
| `test_admin_panel` | PASS 48 |
| `test_provider_verification` | PASS 47 |
| `test_provider_verify_api` | PASS 22 |
| `test_verification_gate` | PASS 14 |

### Regression diff against pristine HEAD

Ran the **entire** `tools/tests/` suite on BOTH the working tree and a
detached **clean HEAD** worktree, comparing the actual summary lines. Exactly the
same 3 suites fail in both, with identical failure counts:

| Suite | failure count | Note |
|---|---|---|
| `test_v09_migration_parity` | 21 | Pre-existing: probes `.github/workflows/ai-migration-staging.yml`, which is absent in this checkout (verified present in `git ls-files` / on the branch). |
| `test_issue2_ai_consent` | 2 | Pre-existing (consent-flow test), unrelated to route management. |
| `test_screenshot_ui` | 1 | Pre-existing (screenshot UI JS), unrelated. |

**No new failures introduced by Phase B.** (The known pre-existing
`test_gemini_transport_routing` 500 is unchanged and unchanged from its documented
pre-existing state — not a Phase B regression.)

### Browser verification

**NOT verified in a real browser.** The Admin Panel markup/JS were statically
validated (`test_admin_ai_ui.php` 47/47: chunk integrity, key coverage, relay +
global-route element presence, API targets, PUT/DELETE presence, `RELAY_BASE`/
`ROUTE_BASE` re-fetch) but not visually exercised — no browser is available in the
sandbox. Manual QA checklist for the real site: super Admin opens AI Settings →
Global AI Route → save `n8n_relay` → see Configured=n8n Relay / Source=Admin Panel /
Effective=n8n Relay; then set global to `direct` and confirm a per-feature `n8n_relay`
still overrides it; then reset and confirn it falls back to Environment/Default.

---

## 11. Security review

| Concern | Status |
|---|---|
| Secrets in localStorage/sessionStorage | ✅ None (no config stored client-side; re-fetch authoritative state) |
| Unsafe HTML / innerHTML for user data | ✅ `textContent` only for the route/source values |
| Secrets ever echoed to client | ✅ N/A (route is not a credential); Phase A relay token remains masked |
| Server-side authorization only boundary | ✅ Router middleware; frontend disable is UX-only |
| Arbitrary/injection route values | ✅ Allowlist-validated (`INVALID_AI_ROUTE`) |
| No-fabricated-connectivity | ✅ Configured kept separate from Reachable/Workflow |
| Audit records no secrets | ✅ `old_route`/`new_route` only |

---

## 12. Files (Phase B)

**New**
- `api/database/migrations/v1.4_ai_global_route.sql` + `_rollback.sql`
- `api/src/AI/Repositories/AIGlobalSettingRepository.php`
- `api/src/AI/Services/AiRouteResolver.php`
- `api/src/Admin/AiGlobalRouteController.php`
- `tools/tests/test_global_ai_route.php`

**Modified**
- `api/database/schema.sql` (add `ai_global_settings`)
- `api/src/AI/Providers/GeminiProvider.php` (`getRoute()` → resolver)
- `api/src/AI/Services/EffectiveConfigService.php` (`globalRoute` + `precedence` + dep)
- `api/src/Auth/Role.php` (`P_AI_ROUTE_MANAGE`)
- `api/index.php` (route wiring)
- `admin/index.html` (Global AI Route panel + keymap)
- `public/assets/velora-admin-ai.js` (globalRoute module)
- `public/locales/en.json` / `fa.json` (18 keys)
- Localization build artifacts (`localized/`, `public/locales/chunks/`, manifests)

**Audit references (read-only):** `AIRepository.php`, `FeatureRouter.php`,
`AIManager.php`, `ProviderCatalog.php`, `AIFeatureFlagRepository.php`,
`EffectiveConfigController.php`, `AIConfigController.php`,
`AdminAuditLogRepository.php`.

---

## 13. Git state

- HEAD: **`742636930ae26cb6e645e1b59137c62b79fa8943`** — **unchanged**.
- 63 working-tree files modified/added (Phase A artifacts + Phase B changes).
- **No commit, push, merge, or deploy performed.**
- No `reset`/`discard` used; all changes made via targeted edits.

---

## 14. Remaining n8n gaps (unchanged, out of Phase B scope)

The relay *transport* (`N8nGeminiRelayTransport`) is only reachable when
`route=n8n_relay` **and** the call is an extraction call, and its real connectivity
is still gated by the relay actually being up and the token being valid — that is a
deployment/runtime concern, not an Admin-route-management blocker. The route
config now correctly controls the *decision*; actual reachability is reported only
by the existing verifier (Phase A), not fabricated by the panel.
