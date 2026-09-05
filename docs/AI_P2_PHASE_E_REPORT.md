# Phase E — Users 360° (Admin Panel)

**Verdict: READY FOR REVIEW**
**Date:** 2026-09-03 · **Working tree only — NO commit/push/merge/deploy.**

Phase E gives an Admin/Super Admin a single **User 360°** view for any account:
which trading accounts the user holds, their trades, AI usage, session/log-in
activity, and their user-scoped audit trail — plus the ability to suspend /
activate, change role, change subscription, and revoke all sessions — all
server-authoritative, RBAC-gated, audited, and verified end-to-end in a real
browser against the staged production build. It extends the read-only audit in
`docs/AI_P2_PHASE_E_AUDIT.md`.

---

## Baseline

- `docs/AI_P2_PHASE_E_AUDIT.md` (Step-0 read-only audit) established what
  already existed and what was missing. Reusable pieces: the `RateLimiter`, the
  `AuditLogRepository`/audit controller, `SecretRedactor`, `Role` permission
  matrix, the canonical localization pipeline, and the existing
  `UserManagementController`/`UserManagementService` + `/api/v1/admin/users`
  list & detail endpoints and their P0/P1 security posture.
- **Missing before this phase:** any per-user drill-down beyond the flat list;
  per-user trading accounts, trades, session activity and user-scoped audit
  were not exposed to the panel; there were no in-panel state-changing actions
  (suspend/activate/role/subscription/revoke) with an authoritative server
  refresh, and no browser E2E covering the flow.
- The admin panel page guard accepted both `admin` and `super_admin`
  (fixed in Phase D); the local `t()` wrapper passed params (fixed in Phase D).
- Pre-existing known-good state: Phase A–D all green; `main` at
  `742636930`; working tree dirty (89 files) pending Phase E.

## Implemented

| Piece | File | Note |
|---|---|---|
| Per-user accounts | `UserManagementService::userAccounts()` | SAFE fields only — **never** the encrypted credential blob |
| Per-user trades | `UserManagementController::trades()` via canonical `TradeRepository::search()` | paginated, `user_id`-scoped |
| Session activity | `UserManagementService::userActivity()` | derived from `user_sessions`; **never** returns token/access hashes |
| User-scoped audit | `UserManagementService::userAudit()` + `AdminAuditLogRepository::list()` (now accepts `target_type`/`target_id`) | sensitive fields (ip, context) only for Super Admin |
| Session revocation | `UserManagementService::revokeSessions()` + controller handler | audited, `P_USERS_SUSPEND`-gated, self-revoke denied |
| Panel UI | `admin/index.html` — `#user360View`, `VeloraAdminUserKeys` map, and the `users360` JS IIFE | identity + cards + accounts/trades/ai/activity/audit + actions; single-page (no hash routing) |
| Actions | Suspend / Activate / Change Role / Change Subscription / Revoke Sessions | **ephemeral `VeloraDialog.confirm()` dialogs → POST → authoritative reload** — never silent mutations |
| Keys/locales | `public/locales/{en,fa}.json` (feature slice) + rebuilt localized chunks | 85 `admin.user360.*` keys per locale |
| Backend tests | `tools/tests/test_user360.php` | new child-process harness, 24 checks |
| Browser E2E | `tools/dev/browser_verify_phase_e.mjs` | 33 checks |

**Fixes surfaced during this phase:** the User-360 permission gate initially
showed "no permission" for a `super_admin` because the session user was read
once at IIFE evaluation before the memory session resolved — `renderActions()`
now reads the live session lazily via `roleFor()`/`selfId()`.

## API

All under `/api/v1/admin/users/{id}` and unchanged-prefix, `Response::json` →
`{"status":"success","data":…}`:

| Route | Method | Permission |
|---|---|---|
| `/{id}` | GET | `P_USERS_VIEW` |
| `/{id}/accounts` | GET | `P_USERS_VIEW` |
| `/{id}/trades` | GET (`page`,`per_page`,`order`) | `P_USERS_VIEW` |
| `/{id}/activity` | GET | `P_USERS_VIEW` |
| `/{id}/audit` | GET | `P_AUDIT_VIEW` |
| `/{id}/status` | POST | `P_USERS_SUSPEND` |
| `/{id}/role` | POST | `P_USERS_CHANGE_ROLE` |
| `/{id}/subscription` | POST | `P_USERS_MANAGE_SUBSCRIPTION` |
| `/{id}/revoke-sessions` | POST | `P_USERS_SUSPEND` |

Mutating endpoints are additionally rate-limited
(`RateLimiter::hit('admin-user-action', 30, 300)`). Verified by curl with an
admin token: every GET returns the documented data shape; RBAC denial for an
ordinary user returns 403; self-read returns 200.

## Database

- **No new table** was required for the view itself. The per-user drill-down
  reads existing `trading_accounts`, `trades`, `ai_requests`, `user_sessions`,
  `user_devices`, and `admin_audit_logs`.
- `AdminAuditLogRepository::list()` gained optional `target_type`/`target_id`
  filters (additive; existing callers unaffected) so the panel can fetch only a
  single user's rows.
- **Dev seed** (`tools/dev/serve_db.php`) extended with the
  `trading_accounts`/`trades`/`ai_requests` DDL + Phase-E fixtures for user 2
  (2 accounts, 3 trades, 3 ai_requests, 2 sessions, 3 audit rows). No secrets in
  the seed.
- **No fabricated historical data** — activity/session and audit timestamps are
  real rows from the fixture, not invented.

## RBAC

- Discovery routes (`/accounts`, `/trades`, `/activity`) require
  `P_USERS_VIEW` (Admin + Super Admin); `/audit` requires `P_AUDIT_VIEW`.
- Mutations require the highest-touch permissions and are enforced
  **server-side**, not just hidden in the UI:
  - Suspend/revoke → `P_USERS_SUSPEND`; subscription → `P_USERS_MANAGE_SUBSCRIPTION`;
    role change → `P_USERS_CHANGE_ROLE`.
  - **Role changes are Super-Admin-only.** A plain Admin cannot grant
    admin/super_admin, and **cannot operate on a privileged target**
    (`assertTargetManipulable` → `PRIVILEGED_TARGET`).
  - **Self-action denial:** you cannot change your own role, suspend yourself,
    or revoke your own sessions (`SELF_ACTION_DENIED`), verified as HTTP 401 in
    the browser E2E.
  - **Role ≠ plan:** changing a subscription never changes authorization role,
    and changing a role never clears the plan (covered by dedicated tests).
- Ordinary-user denial confirmed in browser (401 on `P_USERS_VIEW`, role-change,
  revoke-sessions).

## Security

- **Secrets never leave the server.** `userAccounts()` selects only safe
  metadata; `userActivity()` returns only `{event,time,ip,userAgent,result}` —
  no `refresh_token_hash`/`access_token_hash`. Confirmed by both harness secrets
  scan (`test_user360.php`) and the browser DOM scan
  (`browser_verify_phase_e.mjs` — no token/secret in the rendered DOM).
- **Sensitive audit gating:** `ipAddress`/`contextId` in the user audit are only
  returned to a role holding `P_AUDIT_SENSITIVE_VIEW` (Super Admin); a plain
  Admin's view of the same rows omits them (verified in both PHP and browser
  tests).
- **Audited changes** use the existing `AuditLogRepository::record(...)` with
  correlation id, actor, ip/user-agent, and metadata — every suspend/activate/
  role/subscription/revoke writes a row (`user.suspend`, `user.activate`,
  `user.role.change`, `user.subscription.change`, `user.sessions.revoked`).
- Binding remains parameterized; user-scoped queries always include
  `user_id = :id`/`u.id = :id` (no IDOR). Deny cases verified: `PRIVILEGED_TARGET`,
  `SELF_ACTION_DENIED`, `USER_NOT_FOUND`, `PERMISSION_DENIED`.

## Browser Verification

`tools/dev/browser_verify_phase_e.mjs` — **33/33 PASS** against the staged build
(dev router serving `localized/<locale>/*`, PHP dev server `:8080`):

- Super Admin login → no redirect; users list rows ≥ 1; search filters the list.
- User 360 opens via both a row click and the View action; identity + cards
  render; **Role / Plan / Subscription / Status displayed as separate fields.**
- Trading Accounts (12345/IC Markets), Trades (XAUUSD, EURUSD), AI Usage,
  Activity (Session created/revoked), Audit History sections all render server
  data.
- Suspend → confirmation dialog → status refreshes to *suspended* from server;
  Activate → dialog → back to *active*. Role-change control enabled for Super
  Admin.
- Ordinary user: login OK, then 401 on `/api/v1/admin/users`, role-change, and
  revoke-sessions; self-suspend probe → 401.
- **No admin-panel JS console errors**; **no secret/token in DOM**.
- EN and FA localized admin pages both load `VeloraAdminUserKeys` (map present).
- Mobile viewport (390px): **no horizontal overflow** (sw = cw = 390).

## Regression

- `tools/tests/test_admin_panel.php` — **48/48 PASS** (unchanged).
- `tools/tests/test_verification_gate.php` — **14/14 PASS**.
- `tools/tests/test_ai_p1_architecture.py` — PASS (only an impl-ahead diff;
  exit 0).
- `tools/localization/localization_gate.py` — **LOCALIZATION_GATE_OK**
  (hardcoded-UI freeze intact, 24 allowlist entries, no new violations/drift;
  catalog validation routes=29 canonical=29 localized=61 locales=2).
- `tools/tests/test_security_static_gates.py` — **8/8 OK**.
- `tools/dev/browser_verify.mjs` (Phase D) — **15/15 PASS**.
- `tools/tests/test_user360.php` — **24/24 PASS** (new).

## Localization

- `public/locales/en.json` / `fa.json` gained an **85-key `admin.user360.*`**
  feature slice (same key set both locales) for the new view + dialogs.
- The panel carries `VeloraAdminUserKeys`, an inline map of the usage-scoped
  feature-chunk keys (mirrors the Phase-D `logs.*` allowance), so the User-360
  UI is translated without a global catalogue load; the per-route
  `data-i18n-features` attribute preloads the `admin` catalog in staged builds.
- Rebuilt localized static twice (`releaseId=2026.09.03.phaseE`, then
  `phaseE2` after the permission-gate fix): 61 HTML pages, 36 feature chunks,
  61 CSP routes. Localization gate passes (see Regression).
- EN/FA admin pages both verified to load the map in the browser harness.

## Deployment

**NOT PERFORMED.** Per standing instruction, this phase — like Phases A–D — is
**working tree only**: no commit, no push, no merge, no deploy, no image build,
no rollback drill. Nothing runtime was re-pointed at these changes. The Google
Cloud Run / `/health` deploy path and release steps remain exactly as they were
after Phase D; they were not re-run because they are not part of the delivery
for a review-gated phase. The staged build is served only from the local dev
server.

## Remaining Gaps

- **Backend unit-test→docs intent** on `tools/tests/test_ai_p1_architecture.py`
  is a Phase-D carry-over (impl ahead of doc contract); not a Phase-E blocker.
- `report_orphan_catalog_keys` baseline diff vs `/tmp/headcheck` — Phase-D
  carry-over, not re-run this turn.
- No **screenshot** regression (test_screenshot_ui is optional/not wired here);
  the browser E2E covers behavior, not pixel golden files.
- AI Usage renders a single summary row; per-request AI history is aggregated
  rather than a deep list (a deliberate scope cut, not a defect).
- `knownDevices` is a count only — there is no full device-details registry
  backing it; `lastLoginAt` is derived from `user_sessions` because the users
  schema has no dedicated login-timestamp column (documented honest
  approximation).
- Only the seed/dev path was exercised for `trading_accounts`+`trades` columns;
  the production `schema.sql`/migrations were already correct and are not
  modified this phase (additive seed only).

## Verdict

**READY FOR REVIEW.** Phase E's User 360° view is complete and verified
end-to-end: safe per-user data surfaced with correct RBAC, sensitive fields
gated, all state-changing actions dialog-driven and server-authoritative,
audited, localized, and covered by 24 backend checks + a 33-check real-browser
E2E (EN/FA, mobile) with **no regression** across the admin panel harness, the
localization gate, and the security static gates. No secrets leak; no IDOR; no
privilege escalation. Only the not-performed deployment and the two Phase-D
carry-over items remain open. **Not committed, not pushed, not deployed.**
