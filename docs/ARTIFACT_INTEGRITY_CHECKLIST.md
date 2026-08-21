# VELORA — Artifact Integrity Checklist

> **Purpose:** جلوگیری از **Artifact Drift** و تضمین سه چیز:
> 1. artifact تولیدی از **source فعلی** ساخته شده است،
> 2. artifact متعلق به **همان commit release** است،
> 3. artifact روی سرور **همان artifact تأییدشده CI** است.
>
> این سند **مکمل** `docs/RELEASE_CHECKLIST.md` و `docs/QUALITY_GATE_MATRIX.md` است، نه جایگزین آنها.
> مبنای طراحی: یافته‌های **Phase 4.3 — Artifact Integrity Gate Design** (گزارش جلسهٔ ۲۰۲۶-۰۸-۲۱).

---

| Document Control | |
|---|---|
| شناسه سند | `VELORA-ARTIFACT-INTEGRITY-CHECKLIST` |
| نسخه | 1.0.0 |
| وضعیت | **ACTIVE** |
| آخرین به‌روزرسانی | 2026-08-21 |
| مالک | veloratrade (Project Owner) |
| دامنه اعتبار | کل مخزن `veloratrade/veloratrade` و محیط‌های Staging/Production |
| ارتباط | `docs/RELEASE_CHECKLIST.md` (انتشار) · `docs/QUALITY_GATE_MATRIX.md` (bug→test) |

---

## Legend وضعیت هر آیتم

| علامت | معنا |
|---|---|
| 🟢 `EXISTS` | امروز توسط ابزار/workflow موجود اجرا می‌شود — قابل راستی‌آزمایی همین حالا |
| 🔧 `IMPL-NEEDED` | اینجا مستند شده ولی **نیازمند implementation آینده** است (طبق طرح Phase 4.3) |
| 📋 `DOC-ONLY` | قاعدهٔ مالکیتی / چک انسانی — اتوماسیون پیش‌بینی نشده |

---

## 0. Artifact Provenance

> هدف: هر artifact قابل ردیابی به یک release-id و commit مشخص باشد.

- [ ] 🟢 `EXISTS` — `release-id` با الگوی `^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$` معتبر است (اجرا: `validate_release_id` در `tools/localization/build_csp_artifacts.py`)
- [ ] 🟢 `EXISTS` — `release-id` در `public/locales/csp-manifest.json` و `localized/.csp-release.json` **یکسان** ثبت شده است
- [ ] 🟢 `EXISTS` — `release-id` هرگز از ساعت سیستم تولید نمی‌شود (اجرا: `test_release_id_is_never_generated_from_time` در `test_build_csp_artifacts.py`)
- [ ] 🟢 `EXISTS` — `commitSha` (git SHA لحظهٔ build) در `csp-manifest.json` و `.csp-release.json` ثبت و اعتبارسنجی می‌شود
- [ ] 🟢 `EXISTS` — `sourceDigest` (هش کانونیکال ورودی‌های source: قالب‌های HTML ریشه + `public/locales/{fa,en}.json` + `manifest.json` + `routes.json` + `feature-map.json`) ثبت و با source فعلی تطبیق داده می‌شود
- [ ] 🟢 `EXISTS` — منیفست‌ها **provenance** حمل می‌کنند: `commitSha + sourceDigest + releaseId` هر سه حاضر هستند

> ⚠️ **وضعیت فعلی (۲۰۲۶-۰۸-۲۱):** `releaseId=2026.08.21.1` · `routeCount=61` · `policyVersion=2` · `localizationVersion=2026.08.13.11`.
> `commitSha` و `sourceDigest` اکنون وجود دارند و توسط TEST-26 و گاردهای provenance اعتبارسنجی می‌شوند؛ اتصال `commitSha` به SHA فعلی `main` به‌صورت جداگانه enforce نمی‌شود.

---

## 1. Source → Artifact Freshness

> هدف: ثابت شود artifact با source فعلی هم‌خوان است (regenerate → diff صفر).

- [ ] 🟢 `EXISTS` — artifact در **محیط موقت** بازتولید می‌شود (`build_localized_static.build()` در temp dir، بدون promote)
- [ ] 🟢 `EXISTS` — خروجی بازتولیدشده با artifact کامیت‌شده **byte-to-byte برابر** است (مقایسه با `generated_state_digest()`)
- [ ] 🟢 `EXISTS` — هیچ drift در این اهداف تولیدی نیست:
  - [ ] `localized/` (شامل `localized/.csp-release.json` — حیاتی، OC-4)
  - [ ] `public/locales/`
  - [ ] `public/locales/chunks/`
- [ ] 🟢 `EXISTS` (جزئی) — سازگاری **داخلی** CSP (HTML↔منیفست) با `python tools/localization/build_csp_artifacts.py --check` (در `csp-guard.yml`) چک می‌شود؛ ولی این فقط «خودسازگاری» است، نه «تازگی نسبت به source»

> **ریشهٔ رویداد Phase 4.2:** قالب source تغییر کرد اما `localized/**` بازتولید نشد؛ CSP `--check` سبز بود چون artifact قدیمی با منیفستِ خودش خودسازگار بود. این بند همان شکاف را می‌بندد.

---

## 2. Manifest Integrity

> هدف: هر سه منیفست با محتوای واقعی artifact سازگار باشند.

- [ ] 🟢 `EXISTS` — `csp-manifest.json`: `htmlSha256` هر route با بایت‌های واقعی HTML برابر است (اجرا: `build_csp_artifacts.py --check` → `csp-guard.yml`)
  - > ⚠️ **محدودهٔ این بررسی:** فقط **self-consistency** artifact موجود را اثبات می‌کند؛ مالکیت source و تازگی نسبت به commit را اثبات نمی‌کند. برای آن، **TEST-26 Artifact Freshness** مورد نیاز است.
- [ ] 🟢 `EXISTS` — `.csp-release.json`: `cspManifestSha256` با بایت‌های `csp-manifest.json` برابر است
- [ ] 🟢 `EXISTS` — `releaseHtmlSha256` (مجموع) با هش‌های routeها سازگار است
- [ ] 🟢 `EXISTS` — `routeManifestSha256` با `tools/localization/routes.json` برابر است
- [ ] 🟢 `EXISTS` — `feature-manifest.json`: `sha256` هر chunk با فایل `public/locales/chunks/<locale>/<feature>.json` برابر است (اجرا: `validate_localization.py` در `gate-artifacts`)
- [ ] 🟢 `EXISTS` — `validate_localization.py` (هش CSP + هش chunk + برابری مجموعهٔ خروجی‌ها) در CI و job `gate-artifacts` اجرا می‌شود

---

## 3. CI Gate Protection

> هدف: شکست تازگی artifact، deploy را متوقف کند.

- [ ] 🟢 `EXISTS` — تست **TEST-26 — Artifact Freshness** وجود دارد: `tools/tests/test_artifact_freshness.py` (read-only، regenerate در temp + مقایسه byte)
- [ ] 🟢 `EXISTS` — TEST-26 به job `gate-artifacts` در `.github/workflows/quality-gate.yml` وصل شده است
- [ ] 🟢 `EXISTS` — aggregator `quality-gate` (release blocker) هر شکست گیت را → «Deployment stops» تبدیل می‌کند (الگوی موجود؛ پس از وصل TEST-26 خودکار پوشش می‌گیرد)
- [ ] 🟢 `EXISTS` — ردیف TEST-26 در `docs/QUALITY_GATE_MATRIX.md` (بخش Localization یا Release Validation) ثبت شده است

---

## 4. Deployment Verification

> هدف: چیزی که آپلود می‌شود، همان artifact تأییدشده باشد.

- [ ] 🟢 `EXISTS` — artifact **قبل از upload** تأیید می‌شود: `deploy.yml` و `deploy-staging.yml` پیش از آپلود، `quality-gate.yml` و `csp-guard.yml` را اجرا می‌کنند
- [ ] 🟢 `EXISTS` — بستهٔ deploy **provenance** حمل می‌کند (`localized/.csp-release.json` شامل `commitSha` و `sourceDigest` است و با bundle مخزن مقایسه می‌شود)
- [ ] 🟢 `EXISTS` — **بعد از upload** provenance روی سرور راستی‌آزمایی می‌شود (`lftp get` → مقایسهٔ پنج field provenance با bundle تأییدشده)
- [ ] 🔧 `IMPL-NEEDED` — artifact روی سرور با artifact تأییدشده **به‌صورت full byte-to-byte** برابر باشد؛ در صورت mismatch، deploy متوقف و هشدار صادر شود
- [ ] 🟢 `EXISTS` — **تأیید provenance بعد از upload و قبل از اعلام موفقیت deploy انجام می‌شود** — healthcheck علاوه بر آن availability را بررسی می‌کند
- [ ] 🔧 `IMPL-NEEDED` — اهداف تولیدی با re-upload اجباری (یا `--ignore-time`) آپلود شوند؛ امروز `lftp mirror --continue --only-newer` **بدون `--delete`** است و فایل stale روی سرور باقی می‌ماند

---

## 5. Rollback Protection

> هدف: در هر لحظه بتوان به آخرین وضعیت خوب برگشت.

- [ ] 🟢 `EXISTS` — **backup قبل از هر write**: `deploy.yml` بکاپ کامل docroot را قبل از آپلود می‌گیرد (RB-6) و به‌صورت artifact نگه می‌دارد
- [ ] 🟢 `EXISTS` — **last-known-good commit** ثبت می‌شود: خلاصهٔ deploy شامل `github.sha` است
- [ ] 🟢 `EXISTS` — **rollback reference** ثبت می‌شود: نام artifact `pre-deploy-backup-<sha>`
- [ ] 🔧 `IMPL-NEEDED` — **automatic rollback در mismatch** صریح تعریف و در workflow اجرا شود: mismatch پس از آپلود → توقف + restore خودکار از بکاپ + block release (توقف deploy وجود دارد، اما restore خودکار هنوز پیاده‌سازی نشده است)

---

## 6. Ownership Rules

> قواعد مالکیتی — قابل نقض نیستند (مبنای NP-5 و سیاست‌های §2 README).

- [ ] 📋 `DOC-ONLY` — هیچ artifact **دستی اصلاح نشود**: `localized/**` خروجی build است؛ ویرایش دستی مطلقاً ممنوع (NP-5) — اصلاح فقط از مسیر source/کاتالوگ + rebuild
- [ ] 📋 `DOC-ONLY` — **Generated artifactها نباید بدون تغییر source تغییر کنند.** هر تغییر مستقیم در `localized/**`، generated manifestها (`csp-manifest.json`، `.csp-release.json`، `feature-manifest.json`) یا generated chunks، **violation** محسوب می‌شود. اصلاح فقط از مسیر: `source change → regeneration → validation → release` انجام شود.
- [ ] 📋 `DOC-ONLY` — هر تغییر source **نیازمند regeneration** است: قالب/کاتالوگ/route تغییر کرد → `build_localized_static.py --release-id <id>` اجرا و خروجی commit شود
- [ ] 📋 `DOC-ONLY` — **release بدون artifact verification ممنوع است**: اگر گیت تازگی (TEST-26) یا CSP قرمز باشد، deploy انجام نمی‌شود
- [ ] 📋 `DOC-ONLY` — `release-id` فقط با تأیید مالک انتخاب می‌شود و در هر release یکتا باشد

---

## خلاصهٔ وضعیت — doc-only در برابر impl-needed

| بخش | ماهیت |
|---|---|
| §0 Artifact Provenance | ۶ آیتم 🟢 موجود · ۰ آیتم 🔧 آینده |
| §1 Source→Artifact Freshness | ۴ آیتم 🟢 موجود · ۰ آیتم 🔧 آینده |
| §2 Manifest Integrity | ۶ آیتم 🟢 موجود · ۰ آیتم 🔧 آینده |
| §3 CI Gate Protection | ۴ آیتم 🟢 موجود · ۰ آیتم 🔧 آینده |
| §4 Deployment Verification | ۴ آیتم 🟢 موجود · ۲ آیتم 🔧 آینده (full server byte-to-byte + forced re-upload) |
| §5 Rollback Protection | ۳ آیتم 🟢 موجود · ۱ آیتم 🔧 آینده (رفتار mismatch) |
| §6 Ownership Rules | ۴ آیتم 📋 صرفاً مستند/سیاستی — اتوماسیون لازم ندارند |

**Implementation Backlog (خارج از این سند — نیازمند تأیید جداگانه):**

| # | کار | فایل‌هایی که تغییر می‌کنند | وضعیت |
|---|---|---|---|
| 1 | TEST-26 Artifact Freshness (regenerate + byte-compare) | NEW `tools/tests/test_artifact_freshness.py` | 🟢 پیاده‌سازی شد |
| 2 | افزودن `commitSha` + `sourceDigest` | `tools/localization/build_csp_artifacts.py` · `build_localized_static.py` | 🟢 پیاده‌سازی شد |
| 3 | حالت `--check` (dry-run) برای builder | `build_localized_static.py` | 🟢 پیاده‌سازی شد |
| 4 | وصل TEST-26 + `validate_localization.py` به CI | `.github/workflows/quality-gate.yml` | 🟢 پیاده‌سازی شد |
| 5 | provenance + verify پس از آپلود + re-upload اجباری | `.github/workflows/deploy.yml` · `deploy-staging.yml` | 🟡 provenance/verify پیاده‌سازی شد؛ re-upload اجباری باقی است |
| 6 | به‌روزرسانی اسناد (ردیف TEST-26 + ارجاع از RELEASE_CHECKLIST) | `docs/QUALITY_GATE_MATRIX.md` · `docs/RELEASE_CHECKLIST.md` | 🟢 پیاده‌سازی شد |

---

## رابطه با اسناد موجود

- **`docs/RELEASE_CHECKLIST.md`** — چک‌لیست انسانی کل انتشار (Auth/Email/Security/Localization/Deploy). این سند **زیرمجموعهٔ تخصصی** همان دامنهٔ Deploy است، ولی عمیق‌تر: فقط روی provenance/freshness/integrity artifact تمرکز دارد. آیتم‌های این سند که اتومات شوند، به‌صورت ارجاع به TEST-26 و گیت مربوطه در RELEASE_CHECKLIST ظاهر می‌شوند.
- **`docs/QUALITY_GATE_MATRIX.md`** — نگاشت bug→test→gate. ردیف TEST-26 (پس از implementation) در آنجا ثبت می‌شود؛ این سند سطح انسانی/رویه‌ای همان ردیف است.
- **`AGENTS.md`** — سیاست‌های کلی (NP-5، بند ۱۳ minimal footprint، §8 امنیت) حاکم بر همهٔ این اسناد باقی می‌ماند.

---

*Created in Phase 4.3 (Artifact Integrity Checklist). Documentation-only — the document itself modifies no production code, schema, env, test, or workflow. وضعیت‌های 🔧/🟢 این سند با implementation موجود در main هماهنگ شده‌اند؛ موارد باقی‌مانده پیش از هر Release بازبینی شوند.*
