# VELORA — Bilingual Checklist (fa/en)

## 0. Non-Negotiable Bilingual Development Rules

> **Scope:** applies to every new feature, route, page, API endpoint, and email template.
> These are development-time rules, not release-time checks — follow them while writing
> code, not only before shipping it. Violating any rule below is a defect, not a style
> preference.

1. **All user-facing text must come from localization catalogs.**
   `public/locales/fa.json` / `public/locales/en.json` (and their per-feature chunks under
   `public/locales/chunks/{fa,en}/*.json`) are the only authored source of user-facing copy.
   No feature may introduce displayed text any other way.

2. **No hardcoded user-facing Persian/English strings** in HTML, JS, PHP, API responses,
   or email templates. New code must reference a catalog key
   (`data-i18n` / `data-i18n-content` / `t()` / catalog lookup), never a literal string.
   Enforced by `tools/localization/check_hardcoded_ui.py` (PR-01 V-3); any narrow, reviewed
   exception must be allowlisted in `tools/localization/allowlist-hardcoded.json` with a
   `category`, `reason`, and `resolution_pr` — never silently ignored.

3. **New routes/features must have:**
   - `fa` coverage and `en` coverage (no locale-only orphan page);
   - proper route registration in `tools/localization/routes.json`, so the build produces
     both locale outputs from the **same** canonical template — never two hand-authored
     page copies;
   - the localization keys the new UI needs, added to both catalogs before/with the feature,
     reusing existing keys wherever the copy already exists instead of adding duplicates.
   Enforced by `tools/localization/route_contract.py` (route/output/locale integrity) and
   `tools/localization/validate_localization.py` (per-route fa/en output-set comparison).

4. **New API errors must use `messageKey`, not raw translated text.**
   Follow the existing contract (`ApiException::messageKey()`, e.g.
   `errors.validation.format`, `auth.emailVerified`): the API returns a stable
   `code`/`messageKey`/`params` triple; `message` stays a language-neutral fallback for logs.
   The UI resolves `messageKey` through the catalog — it never renders a hardcoded string
   from the backend.

5. **New email templates must resolve locale using the existing locale resolution system**
   (`LocaleManager::resolve()` plus the `users.locale` / `notificationLocale` fallback chain
   already used by `AuthService`, `PasswordService`, and `NotificationService`). Do not invent
   a parallel locale-detection path for a new email. Covered by TEST-09
   (`tools/tests/test_email_localization.php`) and the PR-05 contract
   (`tools/tests/test_email_locale_resolution.php`).

6. **SEO metadata and structured data must support both locales.** `<title>`, meta
   description, Open Graph tags, canonical/hreflang links, and any `application/ld+json`
   block (`headline`, `description`, `mainEntityOfPage`, etc.) must all resolve per-locale
   through the same build pipeline (`tools/localization/build_localized_static.py`'s
   `render_html()` / `update_route_seo()`) — never hardcoded in one language. Verified by
   `validate_localization.py`'s generated-metadata checks.

7. **New features must update or add localization tests when needed.** If a feature adds
   new catalog keys, a new route, a new email, or new structured data, its PR must show the
   relevant test(s) passing (or add one) — see `docs/QUALITY_GATE_MATRIX.md` for the current
   Risk → Test → Gate mapping. Do not ship user-facing copy or a new bilingual surface with
   zero test coverage.

**Automated enforcement reference:**

| Rule area | Tool |
|---|---|
| Hardcoded copy freeze | `tools/localization/check_hardcoded_ui.py` |
| Frozen/no-new-hashed-key catalog governance | `tools/localization/check_frozen_hash_keys.py` |
| fa/en output parity, SEO metadata, catalog completeness | `tools/localization/validate_localization.py` |
| Route/locale-output contract | `tools/localization/route_contract.py` |
| Email locale resolution | `tools/tests/test_email_localization.php`, `tools/tests/test_email_locale_resolution.php` |

---

> **Purpose:** the human-side governance checklist for the Velora bilingual system.
> The automated gates catch regressions in CI; this document is the manual surface
> every release and every new user-facing feature must pass.
>
> **Automated counterpart:** `.github/workflows/quality-gate.yml` (TEST-19 / TEST-20 /
> TEST-26, PR-01 V-3 / V-4 / V-7) and `.github/workflows/csp-guard.yml`.
> Bug→test mapping lives in [`QUALITY_GATE_MATRIX.md`](./QUALITY_GATE_MATRIX.md).
> Base rule: if Translation completeness, RTL/LTR, Catalog governance, or SEO parity
> fails anywhere → **release stops**.

**Key facts (baseline, `main` @ `910ebd7`):**

| Fact | Value |
|---|---|
| Catalog source of truth | `public/locales/fa.json` + `public/locales/en.json` |
| Messages per catalog | 1451 (FA) / 1451 (EN) — 0 missing keys, 0 placeholder mismatch |
| Frozen hashed keys | 879 (PR-01 `frozen-hash-keys.json`) — no new `-8hex` keys |
| Feature chunks | 18 per locale (`public/locales/chunks/{fa,en}/*.json`) |
| Hardcoded-copy freeze | 265 allowlisted literals across 25 files (PR-01) |
| Default / fallback locale | `fa` (default) / `en` (fallback for missing keys) |

---

## 1. Translation completeness

- [ ] **Missing keys:** `fa.json` ↔ `en.json` key sets identical — 0 missing in either direction
      (enforced by TEST-19, `tools/tests/test_email_key_parity.php`)
- [ ] **Empty translations:** no empty `messages.*` value in either catalog
      (report-only today via PR-01 V-7; treat new empties as release-blockers)
- [ ] **Placeholder parity:** every value that uses `{placeholders}` carries the same set of
      placeholders in both locales (TEST-19 covers this)
- [ ] **FA/EN consistency:** a given key means the same thing in both locales; no key silently
      re-purposed across locales
- [ ] **Fallback never leaks raw keys:** unknown locale resolves via manifest fallback,
      never a raw `dot.path` key or empty text (TEST-20, `tools/tests/test_locale_fallback.php`)
- [ ] **Email copy:** `email.*` subjects/bodies actually translated in both catalogs (TEST-09,
      `tools/tests/test_email_localization.php`)

## 2. RTL/LTR contract

- [ ] **`<html>` attributes correct:** `lang="fa" dir="rtl"` for FA pages,
      `lang="en" dir="ltr"` for EN pages; generated outputs carry
      `data-route-locale` + `data-velora-prelocalized` matching the page locale
- [ ] **RTL layout:** no mirrored-icon / flipped-arrow / clipped-copy regressions on FA pages;
      sweep every page class at least once per release
- [ ] **LTR exceptions hold in RTL context:** numbers, currency symbols/codes, ticker symbols,
      and technical terms stay LTR and isolated (`unicode-bidi:isolate`, `direction:ltr` —
      enforced in `public/assets/velora-localization.css`)
- [ ] **Digits are Latin in FA:** financial/UI numbers render with Latin digits
      (`numberingSystem: latn`; `velora-latin-digits.js` + `-css` guard this)
- [ ] **No language-branch styling:** direction is driven by `[dir=rtl]` / `[dir=ltr]`
      selectors only, never by hardcoded `lang`/locale strings in CSS

## 3. Catalog governance

- [ ] **Single source of truth:** `public/locales/{fa,en}.json` is the only authored
      translation source; `localized/**` is build output (NP-5 — never hand-edit)
- [ ] **No new user-facing copy outside the catalog:** no hardcoded UI strings in HTML/JS/PHP
      (PR-01 V-3 — `tools/localization/check_hardcoded_ui.py`); any new legit exception must
      be allowlisted with `category` + `reason` + `resolution_pr`
- [ ] **Semantic keys for new features:** new work uses namespaced semantic keys
      (e.g. `trades.form.stopLoss.label`), never free text
- [ ] **Hashed keys are frozen:** the 879 `-8hex`-suffixed keys are locked
      (PR-01 V-4 — `tools/localization/check_frozen_hash_keys.py`); no new hashed keys
- [ ] **Catalog edits rebuild artifacts:** any catalog/source change ships regenerated
      `localized/**` + `csp-manifest.json` + `.csp-release.json`, or the CSP guard fails
      (see [`ARTIFACT_INTEGRITY_CHECKLIST.md`](./ARTIFACT_INTEGRITY_CHECKLIST.md))

## 4. Feature development workflow

Every new user-facing feature must verify, **in the same PR**:

- [ ] **FA translation** present for every new key
- [ ] **EN translation** present for every new key
- [ ] **Glossary terms** reviewed against the financial glossary
      (e.g. Drawdown/افت سرمایه, Equity/ارزش ویژه, Profit Factor/فاکتور سود, Win Rate/نرخ برد)
- [ ] **Do-not-translate terms** left untranslated
      (`AI`, `API`, `MT4`, `MT5`, `MetaAPI`, `VELORA`, `Stripe`, `CSP`, `JWT`, ticker symbols)
- [ ] **Localization validation** green — TEST-19 parity, TEST-20 fallback,
      PR-01 V-3/V-4 freeze checks, and chunk coverage all pass

## 5. SEO and page parity

- [ ] **Localized page coverage:** every SEO page exists under both `localized/fa/**` and
      `localized/en/**` (no locale-only orphans)
- [ ] **Metadata parity:** `<title>`, meta description, and OG tags translated per locale —
      no Persian meta on EN pages, no English meta on FA pages
- [ ] **hreflang consistency:** in-page `rel="alternate" hreflang` links point at the correct
      locale URLs, `hreflang="x-default"` present; `sitemap.xml` locale entries consistent
      with the live routes
- [ ] **Route coverage:** public SEO routes (`/fa`, `/en`, blog, privacy, terms, support,
      404, auth pages) resolve to their localized output without redirect loops

## 6. Release integration

| Automated check | Gate | File |
|---|---|---|
| TEST-19 — FA/EN key parity | `gate-static` | `tools/tests/test_email_key_parity.php` |
| TEST-20 — fallback for unknown locale | `gate-static` | `tools/tests/test_locale_fallback.php` |
| TEST-26 — artifact freshness / provenance | `gate-artifacts` | `tools/tests/test_artifact_freshness.py` |
| PR-01 V-3 — hardcoded UI freeze | `gate-static` | `tools/localization/check_hardcoded_ui.py` |
| PR-01 V-4 — frozen hash keys | `gate-static` | `tools/localization/check_frozen_hash_keys.py` |
| PR-01 V-7 — catalog anomaly report (report-only) | `gate-static` | `tools/localization/report_catalog_anomalies.py` |
| PR-09 — orphan catalog-key report (report-only) | `gate-static` | `tools/localization/report_orphan_catalog_keys.py` |
| PR-09 — route/E2E coverage contract | `gate-static` | `tools/localization/route_e2e_contract.py` |
| TEST-09 — email localization fa/en | `gate-contract` | `tools/tests/test_email_localization.php` |
| CSP guard — manifest ↔ HTML match | `gate-secrets` / `csp-guard.yml` | `tools/localization/build_csp_artifacts.py --check` |

- [ ] §1–§5 of this checklist run and green for the exact commit being released
- [ ] Any new hardcoded copy or new hashed key is **fixed or explicitly allowlisted** before merge,
      never silently waived

---

*Created as PR-02 (documentation only). Grounded in `LOCALIZATION_ARCHITECTURE.md` and the
PR-01 freeze baseline. No runtime, catalog, or CI behavior was changed by this document.*
