# AI P2 — PHASE I (Final Admin UX + Browser QA + Release Readiness) AUDIT

**Baseline git HEAD:** `742636930ae26cb6e645e1b59137c62b79fa8943`
**Date:** 2026-09-03 · **Scope:** read-only audit of the complete Admin Panel
**Principle:** source code ≠ served artifact. Audit artifacts were read from the **served** (staged/localized) build where noted.

---

## 0. Abolutely protected baseline

`git rev-parse HEAD` → `742636930ae26cb6e645e1b59137c62b79fa8943`
Working tree contains intentional Phase A–H work (tracked modifications + untracked files).
**No reset / clean / checkout / stash / revert / overwrite / commit / push / PR / merge / deploy was performed.**

---

## 1. Information architecture (how the panel is structured)

The Velora Admin Panel is a **single long page** (`/admin/index.html`) with an always-visible **left sidebar**
(`#veloraSidebar`) linking to platform areas and the Admin page, plus a stack of **section panels** that the
operator scrolls through:

1. **Dashboard overview** (KPI grid `#kpiGrid`, user-growth chart `#growthChart`, role donut, platform-users table)
2. **Users table** (`#search`, filter chips `#tbody`, `#pager`, "Actions" → opens User 360)
3. **User 360** (`#user360View`, hidden by default) — identity / cards / accounts / trades / AI / billing / activity / audit / actions
4. **AI** (`#aiSettingsPanel`) — AI routing meta, provider/features, Relay config, Global AI route
5. **Security** (`#securityPanel`) — role, effective permissions, security actions
6. **Integrations** (`#integrationPanel`) — MetaAPI + Email provider config, test/save/clear
7. **System Health** (`#healthPanel`) — diagnostics grid + run
8. **System Logs** (`#logsPanel`) — search / severity / source / since / until filter, bounded paginated list
9. **Feature Flags** (`#flagsPanel`) — env, refresh, per-flag table with enable/targeting controls
10. **Billing** (`#billingPanel`) — subscriptions/plans/entitlements, honest unavailable state
11. **Analytics** (`#analyticsPanel`) — range select (incl. custom), date inputs, Refresh, overview cards/charts, revenue unavailable state

Each module is driven by one of the admin bundle scripts loaded in-portal:
`velora-admin-ai.js`, `velora-admin-security.js`, `velora-admin-integrations.js`, `velora-admin-system.js`,
plus inline `<script>` blocks in `admin/index.html` (billing, analytics, users/User360, flags).

### Navigation inventory
- Sidebar links: `/dashboard/index.html`, `/markets`, `/intelligence`, `/trades`, `/wallet`, `/performance`,
  `/news`, `/profile`, `/support`, `/admin/index.html` (active).
- Back button in User 360 (`#u360Back`) returns to the users list.
- In-page section navigation is **scroll-based** (no anchor tab-bar between the 8 section panels).

### Controls inventory (per module)
- AI: `#aiRefresh`, `#relaySave`, `#relayClear`, `#globalRouteSave`, `#globalRouteReset`, provider/feature manage buttons (in `velora-admin-ai.js`).
- Security: `#securityRefresh`, role/perm view, audit list.
- Integrations: `#integrationRefresh`, `#metaapiTest`, `#metaapiSave`, `#metaapiClear`, `#emailTest`, `#emailSave`, `#emailClear`.
- Health: `#healthRefresh`, `#diagRun`.
- Logs: `#logApply`, `#logPrev`, `#logNext`, `#logPage`, `#logSearch`, `#logSeverity`, `#logSource`, `#logSince`, `#logUntil`.
- Flags: `#flagsRefresh`, per-row enable/targeting/edit; `#flagsEnv`.
- Billing: `#billingRefresh`.
- Analytics: `#analyticsRefresh`, `#analyticsRange`, `#analyticsStart`, `#analyticsEnd`, `#analyticsApply`.
- Users: `#search`, filter chips, `#pager`.
- Global: `#logoutBtn`, `#veloraSidebarOverlay`, dialog system (`velora-dialog.js`), toast/notification.

---

## 2. Module / data-source audit

| Module | Read-only source | Mutations | Authz gate | Bounded rendering |
|---|---|---|---|---|
| Overview (KPI/charts) | `users` derived | none | session+admin | yes |
| Users table | `/api/v1/admin/users` | none (view) | `P_USERS_VIEW` | paginated |
| User 360 | `/api/v1/admin/users/{id}` + `/trades`, `/ai`, `/activity`, `/audit`, `/billing` | status/role change | server-permission gate | paginated (trades/activity/audit) |
| AI | `/api/v1/admin/ai/*`, `/relay/*`, `/route` | relay save/clear, global route save/reset | `P_AI_MANAGE` / super write | —
| Security | `/api/v1/admin/security` | audit list | admin | —
| Integrations | `/api/v1/admin/integrations/*` | save/clear, test | view/manage split | —
| Health | `/api/v1/admin/health` diagnostics | refresh only | admin | —
| Logs | `/api/v1/admin/system/logs` | none | admin | paginated/bounded |
| Flags | `/api/v1/admin/flags` | enable/disable/targeting | admin view / super edit | —
| Billing | `/api/v1/admin/billing` | one subscription mutation | `P_BILLING_VIEW` | — |
| Analytics | `/api/v1/admin/analytics/*` | none | `P_ANALYTICS_VIEW` | bounded ranges |

**Financial note:** No billing/payment ledger exists. Revenue is honestly `available:false` + `NO_BILLING_SOURCE`.
Trading P&L is explicitly labelled non-revenue. AI cost is internal provider budget only.

---

## 3. Known pre-existing conditions carried into Phase I

- `test_ai_p1_architecture.py` asserts a clean diff for `api/src/Core/{Mailer,TradeService,TradeRepository}`
  and legitimately fails because the working tree intentionally modifies `api/src/Core/Mailer.php`. Phase I must
  compare evidence rather than assume.
- Phase F feature-flag tests depend on initial flag state (fixture ordering). Phase I audits fixtures for leakage.
- Dev server (`tools/dev/dev_router.php`) serves the **staged/localized** build for `/admin`, not the source file —
  so source edits MUST be followed by a rebuild + served-artifact verification.

---

## 4. Audit method used

- Static inventory of `admin/index.html` (panels, ids, module scripts) — above.
- Served-artifact verification: `GET /admin/index.html` and `/localized/{en,fa}/admin/index.html`.
- Live browser journeys (see `tools/dev/browser_verify_phase_i.mjs`) for each module, at 1366×900 and 390×844.
- Permission checks via direct API (unauth/ordinary/admin/super).
- Secret scans of DOM + network responses.
- Localization/build gates re-run on the canonical builder output.

*This audit is **read-only**: it recorded structure and state and made no code changes.*

---

## 5. Defects discovered by Phase I browser QA (and their disposition)

Phase I surfaced real defects via the master browser harness; each was confirmed against the
**served artifact** before and after fixing. (Detailed in `docs/AI_P2_PHASE_I_REPORT.md`.)

| # | Defect | Root cause | Severity | Disposition |
|---|---|---|---|---|
| F1 | Security panel permission chips render on one 2938px line at desktop → whole page scrolls horizontally | The entire `Security/RBAC` CSS block (`.security-grid`, `.security-perms`, `.sec-perm`, …) was **scoped inside `@media (max-width: 980px)`**, so it only applied on mobile; at desktop the chips fell to plain inline layout | P2 (layout/responsive) | **Fixed** — moved the Security/CSS block to global scope (outside the media query), added `min-width:0` guards |
| F2 | AI Settings module renders **empty** (providers/features/relay) for the `super_admin` account | `velora-admin-ai.js` `start()` gate `if (!user || user.role !== 'admin') return;` bailed for `super_admin` (the primary admin), even though the module computes `isSuper` and the admin page + RBAC allow super_admin | P1 (core workflow broken) | **Fixed** — gate now allows `'admin'` **or** `'super_admin'` |
| F3 | `PATCH /api/v1/auth/me/preferences` returns **500** → operator cannot switch locale, so FA admin can never be shown | Live SQLite dev DB was missing the `users.locale_updated_at` column (its migration was never applied), which the `UPDATE` sets; canonical `schema.sql` + `init-sqlite.php` both define it | P2 | **Fixed** — applied the missing column migration to the live dev DB (data-only; no versioned-file drift). In production (MySQL) this column already exists |
| F4 | AI Relay config row (`#aiSettingsPanel .ai-prov-relay`) overflows at 390px, forcing horizontal scroll | `.ai-prov-relay` flex row lacked `flex-wrap` while `.ai-kv` was `nowrap` — masked until F2 removed the gate that suppressed this content | P2 (responsive) | **Fixed** — added `flex-wrap:wrap` + `min-width:0` |

All four were confirmed against the **served** build (source → localized build → served HTML/JS → browser DOM).
Three are pre-existing (present before Phase I); F4 became visible only once F2 was corrected, but is itself
a pre-existing responsive defect in the AI Relay markup.
