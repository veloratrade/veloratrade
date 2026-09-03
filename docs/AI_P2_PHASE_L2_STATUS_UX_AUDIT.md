# AI P2 — PHASE L2 STATUS UX AUDIT

**Date:** 2026-09-04 · **Scope:** read-only audit of the existing integration-status *presentation* layer
before modifying anything. **No deploy, no commit, no production access.**
**HEAD:** `742636930ae26cb6e645e1b59137c62b79fa8943` (Phase A–L.1 working tree intact).

---

## Canonical localization mechanism (determined, reuse — do NOT create a second system)

The project uses **one** i18n stack:

- **Inline key map** in `admin/index.html`: `window.VeloraAdminAIKeys = { 'verify':'admin.ai.verify', ... }`
  maps a short *stem* → full dotted key. `K(stem)` returns the stem's full key (or `''` if unlisted).
- **`VeloraLocale.t(key)`** translates a full key at runtime; **`VeloraLocale.date(...)`** formats dates.
- **Runtime source of truth = feature chunks** served from `/public/locales/chunks/{locale}/{feature}.json`
  (`featureCatalogBase` in `velora-locale-registry.js`). The **admin** feature chunk is
  `chunks/{locale}/admin.json`.
- **Master/source catalogs** = `public/locales/{en,fa}.json`. The generator
  `tools/localization/build_localized_static.py` derives chunks + `feature-manifest.json` +
  CSP artifacts + localized pages from `admin/index.html` (canonical) + master catalogs.
- **Gates:** `validate_localization.py` (catalog schema, **FA/EN keyset parity**, placeholder parity,
  brand-token policy, referenced-key existence, **chunk == master** equality, chunk metadata
  count/bytes/sha256, chunk identity/version, cross-locale chunk keyset parity, CSP/static sync) and
  `check_hardcoded_ui.py` (no new hardcoded UI literals outside the catalog).

**Consequence:** adding a translated string requires keeping all of these consistent — master `en`+`fa`,
the **admin feature chunk** in both locales, `feature-manifest.json` metadata (messages/bytes/sha256), and
the **localized admin pages** — which is exactly what the canonical generator produces. So the correct,
lowest-risk workflow is: edit **admin/index.html** + **master en/fa catalogs** + **the panels** and then
**run the generator** to regenerate the checksum-coupled artifacts consistently, then run all gates.

---

## Current status rendering (what needs to change)

| Backend Status | Existing UI | FA Label | EN Label | CSS State | Action |
|---|---|---|---|---|---|
| `NOT_CONFIGURED` | AI panel prints raw enum `NOT_CONFIGURED`; integrations panel prints `configured/notConfigured` | پیکربندی نشده (exists for probe) | Not configured | `.ai-muted` (neutral) through `credentialBadge` only | 📝 map → localized label + neutral badge |
| `VALID` | AI panel prints raw `VALID` | None | None | `.ai-ok` (green via `credentialBadge` only for configured) | 📝 add `admin.ai.status.valid` → success badge |
| `INVALID_CREDENTIAL` | AI panel raw enum; integrations `AUTH_FAILED`→`authFailed` only | احراز هویت ناموفق بود | Authentication failed | none (text only) | 📝 add distinct label → error badge |
| `RATE_LIMITED` | AI panel raw enum | None | None | none | 📝 add → warning badge |
| `INSUFFICIENT_PERMISSION` | AI panel raw enum | None | None | none | 📝 add → warning badge |
| `PROVIDER_UNAVAILABLE` | AI panel raw enum | سرویس در دسترس نیست (probe `serviceUnavailable`) | Service unavailable | none | 📝 add → unavailable badge |
| `NETWORK_ERROR` | AI panel raw enum; probe maps to `networkError` | خطای شبکه | Network error | none | 📝 add → error badge |
| `TIMEOUT` | AI panel raw enum (testConnection); probe `timeout` | مهلت اتصال به پایان رسید | Connection timed out | none | 📝 add → error badge |
| `PROVIDER_ERROR` | AI panel raw enum | None | None | none | 📝 add → error badge |
| `QUOTA_EXCEEDED` | AI panel raw enum | None | None | none | 📝 add → warning badge |
| `UNKNOWN` | AI panel raw enum | نامشخص | Unknown | none | 📝 add → unavailable/neutral badge |
| `EXPIRED` / `REVOKED` / `DISABLED` / `REGION_RESTRICTED` / `UNVERIFIED` | AI panel raw enum | None | None | none | 📝 add → map to error/warning/neutral |

### Existing presentation helpers
- **AI panel (`velora-admin-ai.js`):** renders `vc.status` **raw**(`t(K('credentialStatus'))+': '+vc.status`). No
  status→label mapping, no status color class, no technical/details area, no latency/errorCode display.
  Test/verify buttons exist but only re-fetch overview (no inline result state).
- **Integrations panel (`velora-admin-integrations.js`):** has `statusLabel(status)` → `admin.integrations.result.*`
  and `statusText(reachability)`, plus a `busy/markBusy` guard that already prevents duplicate submissions
  (sets the test button `disabled` + "Testing…"). Good — reuse the disable/“Testing…” pattern for the AI panel.

### Existing CSS status classes (`admin/index.html`)
- `.ai-badge` pill base; `.ai-ok` (green/success), `.ai-off` (red/error), `.ai-muted` (neutral/off).
- **No warning (`.ai-warn`) and no distinct "unavailable" class** exist yet. §6 requires neutral / success /
  warning / error / unavailable to be visually distinguishable. Add `.ai-warn` (amber) and `.ai-unavail`
  (muted-gray, clearly "unavailable"), matching the existing color palette (no arbitrary new hues).
- Design tokens are CSS variables (`--txt`, `--muted`, `--faint`, `--border`); no semantic success/warning/
  danger variables exist, so the badge classes use the same rgba palette style as `.ai-ok/.ai-off`.

### Truthfulness (Phase L.1 — preserve, do NOT weaken)
Backend returns authoritative, machine-readable enums and safe metadata (`status, verified, checkedAt,
lastCheckedAt, errorCode, latencyMs`). Credential **value** never reaches the client (only `configured:bool`).
The presentation layer must only translate these enums; it must never fabricate HEALTHY from a non-empty
form field, never leak a secret, and never render a raw provider response body.

---

## Plan (single-source mapping, no second translation system)

1. Add ONE authoritative `window.VeloraStatusPresentation` enum→`{key, cls}` map in `admin/index.html`
   (alongside the existing inline key maps) covering **all** provider-credential + probe statuses, each
   referencing a single full i18n key (reusing `admin.integrations.result.*` for probe semantics and adding
   `admin.ai.status.*` for provider-credential semantics — mapped explicitly, not assumed equivalent).
2. Expose shared `veloraStatusLabel(status)` + `veloraStatusCls(status)` helpers consumed by **both** panels.
3. AI panel: replace raw enum with localized badge; keep raw enum in a muted "technical" detail line; show
   latency + last-checked + safe errorCode when present; show inline "Testing…" + disable during test (reuse
   the integrations `markBusy` pattern so duplicate submissions are prevented).
4. Integrations panel: reuse the shared mapper; apply the status class to the status badge.
5. Add `.ai-warn` + `.ai-unavail` badge classes (existing palette).
6. Add FA/EN master keys + regenerate chunk/manifest/localized via the canonical generator; run all gates.
7. Add focused presentation-mapping tests (≥20 meaningful assertions, no fatal reliance on real credentials).
8. Browser verification if Playwright/Chromium available; else report `BROWSER_VERIFICATION_UNAVAILABLE`.
