# Phase G — Billing + Subscription: Step-0 Read-Only Audit

**Date:** 2026-09-03 · **Read-only — no modifications for this audit.** Working tree only; Phase A–F preserved. `HEAD 742636930ae26cb6e645e1b59137c62b79fa8943`, `main`.

## Architecture classification: **C — No billing system exists**

The repository has **no** authoritative external payment/billing integration and
**no** billing entity tables. It does have a small, manual, internal-only
subscription layer on `users` (v1.3). Evidence below.

---

## 1. Billing / subscription domain search

| Search | Result | Evidence |
|---|---|---|
| `stripe` / `paddle` / `paypal` SDK, client, or config | **none** | `grep -rli` over `api/`, `public/` → no provider SDK; `api/config/config.php`, `.env.example` contain no provider credentials |
| Payment / billing / invoice / payment tables | **none** | `grep -rilE 'CREATE TABLE.*(payment|billing|invoice|subscriptions|plans|entitlement|customer)'` over `api/database/`, `api/init-sqlite.php` → **zero** |
| `billing_transactions` / `invoices` / `payments` / `subscriptions` tables | **none** | none in `schema.sql` or any `migrations/*.sql` |
| Checkout flow | **static UI placeholder only** | `checkout/index.html` (10 lines): region/method selection JS toggles a hidden result message; **no `/api/*` call**, no provider, no redirect. The "continue" button just unhides "the gateway opens in the next step". Empty. |
| Webhook receiver (billing) | **none** | Only `MetaApiWebhookController` (`POST /api/v1/webhooks/metaapi`) — trading-position data, no payment/billing/invoice logic |
| `customer`, `entitlement`, `subscription_id`, `provider` reference | **none** | no such column anywhere |
| Plan/pricing entity tables | **none** — plans exist only as an **enum constraint** on `users.plan` | `v1.3_admin_management.sql`: `users.plan ENUM('free','pro')` |

## 2. What authoritative subscription state actually exists

Additive, internal, **manual** subscription layer on `users` (v1.3), explicitly
RBAC-neutral:

```
users.plan             ENUM('free','pro')             NOT NULL DEFAULT 'free'
users.subscription_status ENUM('none','active','past_due','grace','expired','cancelled') DEFAULT 'none'
users.plan_started_at  DATETIME NULL
users.plan_expires_at  DATETIME NULL
users.plan_updated_at  DATETIME NULL
```

- **Source of truth for plan:** `users.plan` (local, set by admin).
- **Source of truth for subscription status:** `users.subscription_status` (local, set by admin).
- **Provider of record:** **none** — no `provider` column, no `subscription_id`, no customer mapping.
- **Mutation path (existing, safe, audited):** `UserManagementService::setSubscription()` →
  `POST /api/v1/admin/users/{id}/subscription`, gated `P_USERS_MANAGE_SUBSCRIPTION`,
  rate-limited, audited as `user.subscription.change`. It documents itself:
  *"Manual internal subscription change (no external payment integration — documented gap)."*
- **Idempotency:** `setSubscription` is idempotent (set fields to given values).

## 3. Runtime consumers (authoritative eligibility for a Phase G surface)

| Data | Authoritative? | Runtime consumer | Phase G relevance |
|---|---|---|---|
| `users.plan` / `users.subscription_status` | Yes (local) | **Admin-only** (`AdminOverviewService`, `UserManagementController/Service`); **never** grants authorization (`Role.php` documents Pro ≠ admin) | Surfact as read-only state + reuse existing audited mutation |
| `metaapi.max_accounts_per_user` (config default 10) | Yes (config.php → `METAAPI_MAX_ACCOUNTS_PER_USER`) | `AccountController::…` line 68, `MetaApiService` line 349 — **real per-user account entitlement** | Show as entitlement (limit vs used) |
| AI provider quota (`ai_provider_quotas`) | Yes (internal budget) | `AIProviderQuotaRepository` via `AIManager` | Show read-only; label internal budget, NOT per-user |
| Per-user AI usage (`ai_requests`) | Yes | `UserManagementService` (used by User 360 `aiUsage`) | Show real per-user usage/tokens |
| Trading accounts per user | Yes | `COUNT(trading_accounts)` | Real entitlement usage |
| **Billing customer ID / provider / billing email / trial / current-period amount / cancellation timestamp / renewal** | **NO authoritative field** | **none** | **Do NOT fabricate** |
| **Invoices / payments / history** | **none** | **none** | **Do NOT fabricate** |

## 4. Existing Admin Panel surfaces touched by the domain

- **Users 360** already shows Role / Plan / Subscription / Status as **separate cards** (Phase E) and exposes Plan+Subscription change.
- **Admin Overview** (`AdminOverviewService`) already reports plan distribution and, for revenue:
  `'revenue' => ['available' => false, 'reason' => 'No external payment/billing integration exists…']` — honest.
- **AI config / quotas** are in their own admin module.

## 5. Phase G scope decision (evidence-backed, anti-fabrication)

Because classification is **C** (no real billing system), Phase G implements only
the **safe, observable, administrative foundation that is actually backed** and
explicitly avoids inventing financial state:

**IMPLEMENT — read-only Billing & Subscription observability + reuse of the existing safe mutation:**
1. `P_BILLING_VIEW` permission (admin + super_admin), consistent naming.
2. `BillingService` (read-only): plan definitions **derived from the authoritative enum** (free/pro) with **price/currency/interval reported as NOT authoritative**; subscription-status definitions from the enum; real plan & subscription distribution from `users`; real per-user account + AI usage; real account entitlement limit; AI provider quota (labelled internal budget).
3. `BillingController` read endpoints:
   - `GET /api/v1/admin/billing` (P_BILLING_VIEW) — platform billing/subscription overview.
   - `GET /api/v1/admin/billing/users/{id}` (P_USERS_VIEW) — per-user billing/entitlement state (IDOR-safe).
4. Frontend: `#billingPanel` admin module + a **Billing & Entitlements** block in Users 360; `admin.billing.*` en/fa keys; honest "provider unavailable / no invoice data / plan price not authoritative" states.
5. **Reuse** the existing audited `setSubscription` mutation (no duplicate mutation infrastructure added).

**NOT IMPLEMENTED (evidence — no authoritative runtime consumer, per rules 4/5/24/25):**
- Payment provider client, checkout backend, webhook receiver, customer mapping — none exist; would be fabrication.
- Invoice/payment/subscription/plan entity **tables** — none exist; creating them would be fake billing state.
- Plan price / currency / interval (authoritative) — no source; reported unavailable.
- Trial state / cancellation timestamp / billing customer ID / provider / renewal amount — no field.
- Any revenue/MRR/ARR/churn analytics (explicitly Phase H; explicitly NOT fabricated).
- No new mutation API (`setSubscription` already exists & is audited; duplicating would be redundant).
- **No schema change** is required: the authoritative subscription columns already exist; adding tables would be fabrication.
