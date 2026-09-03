# Phase G — Billing + Subscription Control Plane

**Verdict:** ✅ **COMPLETE — Verdict C: no billing system, honest observability-only control plane.** No code commit / push / merge / deploy was performed. The working tree remains protected (all Phase A–G work is uncommitted).

---

## 1. Architecture classification

**Classification C — No billing system exists in the repository.**

The read-only Step-0 audit (`docs/AI_P2_PHASE_G_AUDIT.md`) established, with file / DB / runtime evidence, that there is **no** payment provider, no invoices, no payments, no subscription plans table, and no billing runtime consumer. The only real, authoritative billing-adjacent state is:

- `users.plan` (`free` / `pro`), `users.subscription_status`, and the plan lifecycle timestamps (`plan_started_at`, `plan_expires_at`, `plan_updated_at`) — sourced from `v1.3_admin_management.sql`. This is the **single authoritative source** for a user's plan + subscription state.
- Entitlements derived from real runtime data: trading-account limit (`Config::get('metaapi.max_accounts_per_user', 10)`) and per-user AI usage (`ai_requests`) plus the internal AI provider budget (`ai_provider_quotas`).

Per the phase rules, the correct Phase G result when there is no billing architecture is to **prove that fact** and implement **only the smallest safe observability foundation backed by a real runtime consumer.** That is precisely what Phase G does. **No billing entities were fabricated.**

### Critical domain separation (verified, never conflated)

Each of these is a distinct axis and is kept separate in both the API and the UI — none is inferred from another:

| Axis | Values | Source |
|---|---|---|
| **Role** | `user` / `admin` / `super_admin` | `users.role` (RBAC map) |
| **Plan** | `free` / `pro` | `users.plan` |
| **Subscription Status** | `none` / `active` / `past_due` / `grace` / `expired` / `cancelled` | `users.subscription_status` |
| **Account Status** | `active` / `suspended` | `users.status` |
| **Entitlement** | trading-account limit, AI usage | `Config` + `ai_requests` + `ai_provider_quotas` |

`role=admin ⇒ paid plan` is **forbidden** and never implied. The UI renders them as visibly separate fields (e.g. `Account Status: Active · Role: User · Plan: Pro · Subscription: Active · AI requests 1,000/month`).

---

## 2. Implemented

### Backend (server-authoritative, read-only)
- **`api/src/Admin/BillingService.php`** — `overview()` and `user(int $id)`. Plan + subscription-status definitions come from the existing ENUMs. Plan/status distribution is computed from the live `users` table. Entitlements come from runtime-backed sources. Every item with **no** authoritative source reports `available:false` with an explicit reason. Throws `NotFoundException` (USER_NOT_FOUND) for unknown users.
- **`api/src/Admin/BillingController.php`** — `overview()` and `user()`. **No billing mutation endpoint was introduced** (see §3).
- **`api/src/Auth/Role.php`** — added `P_BILLING_VIEW` (`billing.view`) to the admin + super_admin permission maps, following the existing permission-naming convention.
- **`api/index.php`** — two read-only routes:
  - `GET /api/v1/admin/billing` — requires `P_BILLING_VIEW`
  - `GET /api/v1/admin/billing/users/{id}` — requires `P_USERS_VIEW`

### Frontend (`admin/index.html`)
- `VeloraAdminBillingKeys` inline key map (same forward-compatible pattern as the AI / Flag / User key maps; maps catalog keys, never raw UI strings).
- `#billingPanel` (Billing & Subscription) with `loadBilling()`, `bOverview()`, `initBilling()`, refresh, error/loading states, and honest `b-unavail` styling.
- **User-360 Billing block** (`#u360Billing`, "Billing & Entitlements") with `loadUserBilling()` + `billingHtml()`, wired into `loadUser360()` — shows per-user plan/status/dates + real entitlements (trading accounts used/limit, AI requests/tokens). No fake provider or history.
- Reuses existing `VeloraData` / `VeloraDialog` / escaping helpers / permission logic / localization / admin CSS. No duplicated session or RBAC logic. Server-authoritative.

### Localization
- 35 `admin.billing.*` keys added to both `public/locales/en.json` and `public/locales/fa.json`.
- Localized static rebuilt: `LOCALIZED_BUILD_OK templates=29 html=61 feature_chunks=36 csp_routes=61 releaseId=2026.09.03.phaseG`.

### Tests
- **`tools/tests/test_billing_g.php`** — backend harness, **24/24 PASS** (overview honesty, per-user subscription/entitlement state, role≠plan≠subscription≠account-status separation, IDOR 404, RBAC user/admin/super, no-secret scan, no-new-mutation reflection). Spawns isolated child processes over a temp SQLite DB.
- **`tools/dev/browser_verify_phase_g.mjs`** — Playwright E2E, **27/27 PASS** (super_login, panel render/plans/statuses, honest unavailable, entitlements, User-360 billing + per-user state, read-only audit, DOM/network secret hygiene, console errors, ordinary-user RBAC denial, admin allowed, IDOR 404, FA/EN module presence, refresh reload, mobile no-overflow).

---

## 3. Not implemented (and intentionally so — evidence requires it)

These are reported **honestly** as NOT-IMPLEMENTED because there is no authoritative runtime source:

- **Payment provider** — no Stripe/PayPal/gateway integration; `provider.available=false` (reason: "no external payment/billing integration").
- **Invoices / payments / plans tables** — not created. Adding `billing_transactions` / `invoices` / `payments` / `subscriptions` tables with no runtime consumer would be fabrication (rule: new tables require a documented runtime consumer).
- **Price / currency / interval** — not authoritative; every plan reports `price.available=false`.
- **Trial / cancellation / reactivation** — not authoritative; no trial period, no `cancelled_at`, no billing-customer-id.
- **Workflow history** — `history.available=false`.
- **Revenue / MRR / ARR / churn / cohort / LTV analytics** — **deliberately not built** (rule 19). Phase H handoff only.

### Mutation endpoints
**No new billing mutation endpoint was introduced.** Plan/subscription changes remain on the single existing, audited mutation path: `POST /api/v1/admin/users/{id}/subscription` (`P_USERS_MANAGE_SUBSCRIPTION`, audited as `user.subscription.change` in `UserManagementService`/`UserManagementController`). Phase G deliberately did **not** duplicate or simulate a provider operation by editing a local status field (rule 5).

---

## 4. API surface

| Method | Path | Auth | Notes |
|---|---|---|---|
| `GET` | `/api/v1/admin/billing` | `P_BILLING_VIEW` | Overview: provider, plans, plan/status distribution, entitlements, history; all unavailable fields honest. |
| `GET` | `/api/v1/admin/billing/users/{id}` | `P_USERS_VIEW` | Per-user subscription + entitlement state; unknown user → 404. |

No POST/PATCH/DELETE billing routes.

---

## 5. Database

**No schema change.** `users.*` (v1.3) is authoritative and suffices. No new tables, no fabricated historical data, no fake seed rows. Existing FKs/indexes untouched.

---

## 6. RBAC

- `P_BILLING_VIEW` → admin + super_admin (following the existing permission architecture; no second authorization system).
- Per-user billing endpoint additionally requires `P_USERS_VIEW`.
- Ordinary user → `403` for both billing and per-user endpoints.
- No privilege escalation. No IDOR (`/users/2` vs `/users/3` isolation guaranteed; unknown user → 404).

---

## 7. Audit

No new mutation to audit. Phase G is read-only. The existing subscription-change mutation (`user.subscription.change`) remains the only mutation path and is already audited via the existing infrastructure. No secrets / cards / tokens are ever placed in headings, logs, audit metadata, or errors.

---

## 8. Security

- Unauthenticated → `401`. Ordinary user → `403`. Admin/super_admin → `200` (admin) with per-user scoping. Unknown user → `404`.
- No API secret / webhook secret / credential / token is exposed in responses, DOM, HTML, logs, audit metadata, or errors.
- No webhooks exist for billing (nothing to validate).

---

## 9. Localization

- 35 `admin.billing.*` keys in EN + FA.
- `localization_gate` → `LOCALIZATION_GATE_OK` (24 allowlist, 0 drift).
- `check_key_references` → PASS (only 2 pre-existing WARNs for dynamic key construction in `velora-localization.js`).
- `check_frozen_hash_keys` → PASS (879 hashed keys, 879 frozen).
- `validate_localization` → PASS (issues=0).
- Localized static rebuild green. The plan-table column header in the billing overview uses the billing `status` key (no cross-module coupling).

---

## 10. Tests / Regression

| Suite | Result |
|---|---|
| `test_billing_g.php` | **24/24 PASS** |
| `browser_verify_phase_g.mjs` | **27/27 PASS** |
| `test_admin_panel.php` | 48/48 PASS |
| `test_user360.php` | 24/24 PASS |
| `test_feature_flags.php` | 25/25 PASS |
| `test_security_static_gates.py` | OK (8 tests) |
| `test_verification_gate.php` | 14/14 PASS |
| `test_admin_ai_config.php` | 44/44 PASS |
| `test_admin_ai_ui.php` | 47/47 PASS |
| AI locale contract runtime (G8) | PASS |
| Localization gates | All PASS |
| `browser_verify_phase_e.mjs` | 33/33 PASS |
| `browser_verify_phase_f.mjs` | 22/22 PASS† |
| `browser_verify.mjs` (Phase D) | 15/15 PASS |

† Phase F's enable/disable/targeting tests consume their own persisted flag state (a feature flag must start disabled for its "Enable" button to appear). They were run against a clean fixture state (both `ai_weekly_report` and `ai_trade_analysis` reset to disabled). This is a test-fixture precondition, **not** a Phase G regression — Phase G touches no feature-flag code.

### Pre-existing failure (not Phase G)
`tools/tests/test_ai_p1_architecture.py` fails its "Core/* untouched" assertion because of an earlier-phase modification to `api/src/Core/Mailer.php` (Phase A–D carry-over). **No Phase G code lives under `api/src/Core/`.** Reported as pre-existing.

---

## 11. Git state

- **HEAD:** `742636930ae26cb6e645e1b59137c62b79fa8943`
- **Working tree:** dirty across all Phase A–G work (uncommitted). Phase G files:
  - `?? api/src/Admin/BillingController.php`
  - `?? api/src/Admin/BillingService.php`
  - `?? tools/tests/test_billing_g.php`
  - `?? docs/AI_P2_PHASE_G_AUDIT.md`
  - ` M admin/index.html`
  - ` M api/index.php`
  - ` A api/src/Auth/Role.php`
  - ` M public/locales/en.json`, `public/locales/fa.json`
  - ` M localized/en/admin/index.html`, `localized/fa/admin/index.html` (rebuilt)
  - ` M public/locales/csp-manifest.json`, `localized/.csp-release.json` (rebuilt releaseId `2026.09.03.phaseG`)
- **NO COMMIT. NO PUSH. NO MERGE. NO DEPLOY.**

---

## 12. Final principles honored

`Role ≠ Plan` · `Plan ≠ Subscription` · `Subscription ≠ Account Status` · `Entitlement ≠ Authorization` · one authoritative billing source (`users.*` v1.3) · provider state never simulated · secrets never in the Admin UI · no fake invoices/payments/revenue/subscriptions · no second authorization/config system · no invented runtime consumers. Because the repo has **no real billing architecture**, Phase G's correct outcome is a defensible, honest observability control plane backed by real runtime state — which is exactly what was delivered.
