# VELORA — Permanent Release Checklist

> **Usage:** run through this checklist for every Production deploy (and as smoke for Staging).
> The **Quality Gate** (`.github/workflows/quality-gate.yml`) enforces the automated parts;
> this document covers the human-verifiable release surface. Base rule: if Auth Flow,
> Email Flow, Security Regression, or Localization fails anywhere → **Deployment stops.**
> Bug→test mapping: `docs/QUALITY_GATE_MATRIX.md`.

---

## 0. Pre-Release

- [ ] `quality-gate` aggregate check is **green** on the exact commit being released
- [ ] Full local regression suite green — 29 PHP + 3 Python = **32/32** locally (plus the browser-JS contract `test_verify_email_browser.js`, executed in CI `gate-browser`)
- [ ] `git status` shows only intended changes; no stray files
- [ ] **No secret/env values** in the diff (`velora.env`, keys, tokens — never committed)
- [ ] CSP guard (CSP + secret-scan) green on the commit
- [ ] Docs drift acceptable: `bash tools/velora-status.sh` — SESSION_STATE/PROJECT_STATE reviewed
- [ ] GitHub Actions billing sanity: no unexpected long-running workflows (zero-cost policy)

## 1. Authentication (gate-auth + smoke)

- [ ] Register → new account receives verification email (unverified login blocked: `EMAIL_NOT_VERIFIED`)
- [ ] Verify email via fragment link (`/verify-email#token=`) → verified; welcome email arrives (fa/en per locale)
- [ ] Login (verified) → token pair; wrong password → clean **401** (never 500), < 3s
- [ ] Forgot password (verified user) → link `/reset-password#token=` (**fragment**, not query) → reset works, sessions revoked
- [ ] Forgot password (unverified/unknown) → same generic response, **no email** (A1/A7/A11)
- [ ] Change password → all sessions revoked; password-changed email arrives
- [ ] Refresh rotation works; replay of old refresh token rejected; logout revokes session
- [ ] Password policy identical across register / reset / change (`min 8 + letter + digit`)
- [ ] Smoke on staging: `POST /api/v1/auth/register` (temp) → verify → login → logout

## 2. Email (gate-contract + smoke)

- [ ] FA template: RTL, Persian subject/body/footer («قوانین / حریم خصوصی / تماس»)
- [ ] EN template: LTR, English subject/body/footer, **no Persian anywhere** (TEST-09 contract)
- [ ] No raw i18n key (e.g. `achievements.*`) anywhere in rendered HTML (A3)
- [ ] Footer links point at the **current environment** host (no hardcoded prod on staging) (A10)
- [ ] Footer links open for guests: `/terms` `/privacy` `/support` → **200** (no 302 to login) (A4)
- [ ] Manage-preferences link + RFC 2369 `List-Unsubscribe` header present (A9)
- [ ] Preference rules: welcome/achievements/trades honor opt-out; security emails always sent (A8)
- [ ] CID icons render (7 dedicated PNGs) + logo loads over HTTPS from the active environment
- [ ] Plain-text alternative generated; provider message id retained; no API key in any error/log

## 3. Security (gate-security + gate-secrets)

- [ ] CSP guard green (HTML ↔ CSP artifact match, deterministic checker)
- [ ] Secret-scan green (no real hashes/emails/keys in the public repo)
- [ ] Security headers live on env: `nosniff` (API), restrictive CSP on denial paths; HSTS/XFO verified at edge (`curl -sI https://<env>/ | grep -i "strict-transport\|x-frame"`)
- [ ] Rate limits enforced: login 8/300s · register 5/3600s · forgot 4/3600s+per-account ≤3/h · resend 4/3600s → **429** beyond cap; X-Forwarded-For spoof does not bypass
- [ ] Reset tokens: fragment transport, sha256-only storage, 1h TTL, single-use; verification tokens sha256-only, 24h TTL
- [ ] Enumeration probes (register/resend/forgot, unknown vs known vs verified) return indistinguishable responses

## 4. Localization (gate-static + gate-contract)

- [ ] `fa.json` ↔ `en.json` key parity = 0 diff (TEST-19)
- [ ] Unknown locale → manifest fallback (en), never empty/raw key (TEST-20)
- [ ] `email.*` subjects actually translated (not duplicated strings) in both catalogs

## 5. Deployment

- [ ] Deploy triggered manually; `quality_gate` job in the deploy workflow is **green** before upload starts
- [ ] Backup of current docroot completes before any write (built into deploy workflow — RB-6)
- [ ] Post-deploy within 10 minutes on Production:
  - [ ] `GET /health` → **200** `{"status":"ok"}`
  - [ ] `GET /` → 200, `X-VELORA-Locale` present
  - [ ] Login smoke with a real verified account (wrong password → 401)
  - [ ] `GET /support` (guest) → **200** (footer-link guard)
  - [ ] Reset-email live sample: link contains `#token=`
  - [ ] `X-Robots-Tag: noindex` **absent** on production (present on staging)
- [ ] **Rollback reference:** previous deploy backup path + last-known-good commit hash recorded in the deploy run summary; if any smoke item fails → restore backup immediately and block release

---

*Created in Phase 3.2 (Quality Gate integration); refreshed in Phase 3.4 (documentation only). Update this checklist when a new release-blocking gate is added.*
