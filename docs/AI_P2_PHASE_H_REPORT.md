# AI P2 — Phase H (Analytics + Revenue Intelligence) Completion Report

**Release tag:** `2026.09.03.phaseH`
**Baseline git HEAD (unchanged):** `742636930ae26cb6e645e1b59137c62b79fa8943`
**Date:** 2026-09-03 · **Timezone of all analytics:** UTC
**Status:** ✅ COMPLETE and verified. **NO commit / push / PR / merge / deploy has been performed.**

---

## 1. Outcome

Phase H delivered a read-only, evidence-first **Analytics + Revenue Intelligence** module with a clean
separation of Product / Operational / Financial analytics. Every derived metric is backed by an
authoritative source; no metric is fabricated, sampled, or zero-filled. Because the platform has **no
authoritative billing/revenue source**, the entire financial branch is surfaced as
**`available:false` + `reason:NO_BILLING_SOURCE`** — never as zeros, plan counts, or fabricated revenue.

### Verification summary

| Layer | Harness | Result |
|---|---|---|
| Backend service/contract | `tools/tests/test_analytics_h.php` | **53 checks / 0 failures** |
| Real-browser E2E | `tools/dev/browser_verify_phase_h.mjs` | **32 / 32 passed** |
| Live RBAC (direct API) | manual / live | unauth 401 · ordinary 403 · admin 200 · super_admin 200 |
| Live validation | manual / live | invalid → 422 · SQL injection → 422 · valid custom → 200 |
| Localization build | `build_localized_static.py` | `LOCALIZED_BUILD_OK` (templates=29 html=61 chunks=36) |
| Localization gates | `localization_gate.py`, `check_key_references.py`, `check_frozen_hash_keys.py`, `validate_localization.py` | per-route catalog OK; reference-completeness PASS; frozen hash intact (`879/879`); issues=0 |

---

## 2. Separation of analytics pillars (invariant maintained)

| Pillar | Domain | Source(s) | Financial? |
|---|---|---|---|
| **Product** | Users, AI requests | `users`, `ai_requests` | No |
| **Operational** | System health, integrations | `system_logs`, `integration_health` | No |
| **Financial** | Revenue / MRR / ARR / churn / LTV / volume / refunds | **(none available)** | **`NO_BILLING_SOURCE`** |

**Never mixed.** Trading P&L is explicitly labelled **trading performance, NOT platform revenue**
(`isRevenue:false`). AI `cost` is the internal AI-provider budget, not revenue.

### Data-source matrix

| Metric | Authoritative source | Notes |
|---|---|---|
| Total users / new users | `users` | count by `created_at`; status from `users.status` |
| Role / locale / status distribution | `users` | `role`, `locale`, `status` |
| Registration trend | `users.created_at` | UTC day buckets |
| Trading net P&L, volume | `trades` | `profit_loss`, `amount`/volume — **performance, not revenue** |
| AI requests / tokens / cost | `ai_requests` | dedicated parameterized path (canonical `AIRequestRepository::usageStats()` is **MySQL-only** and not reused); `cost` = internal AI budget |
| AI provider history | `ai_requests` | provider column |
| System errors | `system_logs` | `IS NOT NULL`-safe; `IS NOT NULL` used instead of `role !== NULL` |
| Integration health | `integration_health` | status/counters |

**Revenue, MRR, ARR, churn, LTV, payment volume, refunds** → **no authoritative source** →
`available:false`, `reason:"NO_BILLING_SOURCE"`.

**Audit:** no billing/payment/subscription/pricing tables exist; deprecated/removed statuses are not
present; no exports; **zero schema change**; no PII/secrets returned.

---

## 3. API surface (read-only, `P_ANALYTICS_VIEW`)

All under `/api/v1/admin/analytics/*` gated by `[...$admin, AuthMiddleware::requirePermission(Role::P_ANALYTICS_VIEW)]`.

| Route | Method | Purpose |
|---|---|---|
| `overview` | GET | Product + operational summary snapshot |
| `users` | GET | Product: users, distributions, registration trend |
| `trading` | GET | Trading performance (net P&L / volume / by symbol) |
| `ai` | GET | AI requests, tokens, provider history |
| `operations` | GET | System errors, integration health |
| `revenue` | GET | Financial — **always** `available:false` + `NO_BILLING_SOURCE` |

Every response carries `range.{start,end,label,presentation,timezone}`. Presets: `today`, `7d`, `30d`,
`90d`, `all`, and `custom`. `custom` requires both `start` and `end`, bounded to ≤ **366 days**; `all`
= `1970-01-01`. Dates validated server-side; invalid → `422` `fields.{start|end}.code=INVALID`;
**SQL-injection attempt → 422** (parameterized queries). Default range = `30d`.

### Controller/service files
- `api/src/Admin/AnalyticsService.php` (all queries parameterized; bounded ranges; UTC; `never` wrapped)
- `api/src/Admin/AnalyticsController.php` (6 methods)
- `api/src/Auth/Role.php` (`P_ANALYTICS_VIEW` on `ADMIN` + `SUPER_ADMIN`)
- `api/index.php` (6 routes, after billing routes)

---

## 4. Frontend (`admin/index.html`)

- **`#analyticsPanel`** — toolbar (Range select incl. **custom**, date inputs enabled only for custom,
  Apply, Refresh), error box, body container.
- **Charts/cards all derive from real API payloads** — no `Math.random`, no demo data, no fabricated charts.
- **Revenue section always renders the honest unavailable state** (`a-unavail` + `rev.note || revenueNote`);
  never zero-value cards.
- Module-specific locale keys (`VeloraAdminAnalyticsKeys` → `admin.analytics.*`, 69 keys × en/fa).
- Responsive to **390px** (panel `scrollWidth == clientWidth`, verified live).
- Empty-data and API-error states are explicit; refresh works; localization EN + FA module presence verified.

---

## 5. Security, authorization & honesty invariants

- RBAC enforced on every analytics route (401 unauth, 403 ordinary, 200 admin/super_admin — all live-verified).
- No second analytics source of truth; no second authorization/audit system.
- Reads are **read-only** → no mutation audit events emitted for reads.
- No secrets in DOM or analytics network responses (verified live in browser E2E).
- `Analytics ≠ Billing`, `Trading P&L ≠ Revenue`, `Unavailable ≠ Zero`, `No fake financial data`.

---

## 6. Regression results (all verified green)

| Suite | Result |
|---|---|
| Phase D browser (`browser_verify.mjs`) | 15 / 15 |
| Phase E browser (`browser_verify_phase_e.mjs`) | 33 / 33 |
| Phase F browser (`browser_verify_phase_f.mjs`) | 22 / 22 *(with flag-reset precondition)* |
| Phase G browser (`browser_verify_phase_g.mjs`) | 27 / 27 |
| `test_admin_panel.php` | 48 checks / 0 failures |
| `test_user360.php` | 24 / 0 |
| `test_feature_flags.php` | 25 / 0 |
| `test_billing_g.php` | 24 / 0 |
| Localization gates | catalog OK · references PASS · frozen 879/879 · issues=0 |
| Security static gates | OK |

**Pre-existing, unchanged:** `test_ai_p1_architecture.py` still asserts on
`api/src/Core/Mailer.php` being modified (a pre-existing working-tree change). Phase H touched **no**
`api/src/Core/*` file, so this is **NOT a Phase H regression** — identical to the state at Phase A–G.

---

## 7. Files created / modified in Phase H

**Created**
- `api/src/Admin/AnalyticsService.php`
- `api/src/Admin/AnalyticsController.php`
- `tools/tests/test_analytics_h.php`
- `tools/dev/browser_verify_phase_h.mjs`
- `tools/dev/_add_h_locales.py` (helper that added the 69 `admin.analytics.*` keys × en/fa)
- `docs/AI_P2_PHASE_H_AUDIT.md`

**Modified**
- `api/src/Auth/Role.php` (`P_ANALYTICS_VIEW`)
- `api/index.php` (6 analytics routes)
- `admin/index.html` (`#analyticsPanel` CSS + HTML + Phase H JS module + `VeloraAdminAnalyticsKeys`)
- `public/locales/en.json` / `public/locales/fa.json` (analytics keys)
- `localized/en/admin/index.html` / `localized/fa/admin/index.html` (regenerated by builder)
- `public/locales/feature-manifest.json`, `public/locales/csp-manifest.json`, `.csp-release.json`
  (regenerated downstream by the builder)

---

## 8. Known / residual notes

- **No billing source** is the honest, intended outcome. Revenue intelligence is intentionally
  `available:false` until a real billing table + ledger are introduced; it must never be zero-filled.
- The custom-range option was **added** during verification (the original markup lacked a
  `<option value="custom">`, making the custom Date range unusable); the Apply button also now enables
  the date inputs defensively. Fix is in `admin/index.html` and the regenerated localized builds.
- Analytics queries are read-only and parameterized; `all` range is bounded from `1970-01-01`.

---

## 9. Deliverables

- Backend harness: `tools/tests/test_analytics_h.php`
- Browser E2E: `tools/dev/browser_verify_phase_h.mjs`
- Audit: `docs/AI_P2_PHASE_H_AUDIT.md`
- This report: `docs/AI_P2_PHASE_H_REPORT.md`

**No commit / push / PR / merge / deploy was performed. HEAD = `742636930ae26cb6e645e1b59137c62b79fa8943`.**
