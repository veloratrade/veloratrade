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
- [ ] 🔧 `IMPL-NEEDED` — `commitSha` (git SHA لحظهٔ build) در `csp-manifest.json` و `.csp-release.json` ثبت شود
- [ ] 🔧 `IMPL-NEEDED` — `sourceDigest` (هش کانونیکال ورودی‌های source: قالب‌های HTML ریشه + `public/locales/{fa,en}.json` + `manifest.json` + `routes.json` + `feature-map.json`) ثبت و معتبر باشد
- [ ] 🔧 `IMPL-NEEDED` — منیفست‌ها **provenance** حمل کنند: `commitSha + sourceDigest + releaseId` هر سه حاضر باشند

> ⚠️ **وضعیت فعلی (۲۰۲۶-۰۸-۲۱):** `releaseId=2026.08.21.1` · `routeCount=61` · `policyVersion=2` · `localizationVersion=2026.08.13.11`.
> `commitSha` و `sourceDigest` هنوز وجود ندارند — اتصال artifact به commit امروز **برقرار نیست**.

---

## 1. Source → Artifact Freshness

> هدف: ثابت شود artifact با source فعلی هم‌خوان است (regenerate → diff صفر).

- [ ] 🔧 `IMPL-NEEDED` — artifact در **محیط موقت** بازتولید شود (`build_localized_static.build()` در temp dir، بدون promote)
- [ ] 🔧 `IMPL-NEEDED` — خروجی بازتولیدشده با artifact کامیت‌شده **byte-to-byte برابر** باشد (مقایسه با `generated_state_digest()`)
- [ ] 🔧 `IMPL-NEEDED` — هیچ drift در این اهداف تولیدی نباشد:
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
- [ ] 🔧 `IMPL-NEEDED` — `feature-manifest.json`: `sha256` هر chunk با فایل `public/locales/chunks/<locale>/<feature>.json` برابر باشد — این چک امروز در `validate_localization.py` هست ولی **در CI وصل نیست**؛ باید در گیت اجرا شود
- [ ] 🔧 `IMPL-NEEDED` — `validate_localization.py` (هش CSP + هش chunk + برابری مجموعهٔ خروجی‌ها) وارد CI شود

---

## 3. CI Gate Protection

> هدف: شکست تازگی artifact، deploy را متوقف کند.

- [ ] 🔧 `IMPL-NEEDED` — تست **TEST-26 — Artifact Freshness** ایجاد شود: `tools/tests/test_artifact_freshness.py` (read-only، regenerate در temp + مقایسه byte)
- [ ] 🔧 `IMPL-NEEDED` — TEST-26 به گیت مناسب وصل شود: جایگاه پیشنهادی **داخل `gate-static`** (کم‌هزینه) یا job جدید `gate-artifacts` در `.github/workflows/quality-gate.yml`
- [ ] 🟢 `EXISTS` — aggregator `quality-gate` (release blocker) هر شکست گیت را → «Deployment stops» تبدیل می‌کند (الگوی موجود؛ پس از وصل TEST-26 خودکار پوشش می‌گیرد)
- [ ] 🔧 `IMPL-NEEDED` — ردیف TEST-26 در `docs/QUALITY_GATE_MATRIX.md` (بخش Localization یا Release Validation) ثبت شود

---

## 4. Deployment Verification

> هدف: چیزی که آپلود می‌شود، همان artifact تأییدشده باشد.

- [ ] 🟢 `EXISTS` — artifact **قبل از upload** تأیید می‌شود: `deploy.yml` و `deploy-staging.yml` پیش از آپلود، `quality-gate.yml` و `csp-guard.yml` را اجرا می‌کنند
- [ ] 🔧 `IMPL-NEEDED` — بستهٔ deploy **provenance** حمل کند (فایل `localized/.csp-release.json` از قبل آپلود می‌شود — با افزودن `commitSha/sourceDigest` همین فایل provenance کامل می‌شود)
- [ ] 🔧 `IMPL-NEEDED` — **بعد از upload** هش/provenance روی سرور راستی‌آزمایی شود (`lftp get` → مقایسه `releaseHtmlSha256` و `commitSha` با commit جاری)
- [ ] 🔧 `IMPL-NEEDED` — artifact روی سرور با artifact تأییدشده **برابر** باشد؛ در صورت mismatch، deploy متوقف و هشدار صادر شود
- [ ] 🔧 `IMPL-NEEDED` — **تأیید باید بعد از upload و قبل از اعلام موفقیت deploy انجام شود** — healthcheck فقط availability را اثبات می‌کند، نه صحت artifact منتشرشده
- [ ] 🔧 `IMPL-NEEDED` — اهداف تولیدی با re-upload اجباری (یا `--ignore-time`) آپلود شوند؛ امروز `lftp mirror --continue --only-newer` **بدون `--delete`** است و فایل stale روی سرور باقی می‌ماند

---

## 5. Rollback Protection

> هدف: در هر لحظه بتوان به آخرین وضعیت خوب برگشت.

- [ ] 🟢 `EXISTS` — **backup قبل از هر write**: `deploy.yml` بکاپ کامل docroot را قبل از آپلود می‌گیرد (RB-6) و به‌صورت artifact نگه می‌دارد
- [ ] 🟢 `EXISTS` — **last-known-good commit** ثبت می‌شود: خلاصهٔ deploy شامل `github.sha` است
- [ ] 🟢 `EXISTS` — **rollback reference** ثبت می‌شود: نام artifact `pre-deploy-backup-<sha>`
- [ ] 🔧 `IMPL-NEEDED` — **رفتار در mismatch** صریح تعریف و در workflow اجرا شود: mismatch پس از آپلود → توقف + restore از بکاپ + block release (امروز verify پس از آپلود اصلاً وجود ندارد)

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
| §0 Artifact Provenance | ۳ آیتم 🟢 موجود · ۳ آیتم 🔧 آینده (`commitSha`, `sourceDigest`) |
| §1 Source→Artifact Freshness | ۱ آیتم 🟢 جزئی · ۳ آیتم 🔧 آینده (TEST-26) |
| §2 Manifest Integrity | ۴ آیتم 🟢 موجود · ۲ آیتم 🔧 آینده (اتصال validate_localization به CI) |
| §3 CI Gate Protection | ۱ آیتم 🟢 موجود · ۳ آیتم 🔧 آینده (TEST-26 + wiring) |
| §4 Deployment Verification | ۱ آیتم 🟢 موجود · ۴ آیتم 🔧 آینده (provenance + verify پس از آپلود) |
| §5 Rollback Protection | ۳ آیتم 🟢 موجود · ۱ آیتم 🔧 آینده (رفتار mismatch) |
| §6 Ownership Rules | ۴ آیتم 📋 صرفاً مستند/سیاستی — اتوماسیون لازم ندارند |

**Implementation Backlog (خارج از این سند — نیازمند تأیید جداگانه):**

| # | کار آینده | فایل‌هایی که تغییر می‌کنند |
|---|---|---|
| 1 | TEST-26 Artifact Freshness (regenerate + byte-compare) | NEW `tools/tests/test_artifact_freshness.py` |
| 2 | افزودن `commitSha` + `sourceDigest` | `tools/localization/build_csp_artifacts.py` · `build_localized_static.py` |
| 3 | حالت `--check` (dry-run) برای builder | `build_localized_static.py` |
| 4 | وصل TEST-26 + `validate_localization.py` به CI | `.github/workflows/quality-gate.yml` |
| 5 | provenance + verify پس از آپلود + re-upload اجباری | `.github/workflows/deploy.yml` · `deploy-staging.yml` |
| 6 | به‌روزرسانی اسناد (ردیف TEST-26 + ارجاع از RELEASE_CHECKLIST) | `docs/QUALITY_GATE_MATRIX.md` · `docs/RELEASE_CHECKLIST.md` |

---

## رابطه با اسناد موجود

- **`docs/RELEASE_CHECKLIST.md`** — چک‌لیست انسانی کل انتشار (Auth/Email/Security/Localization/Deploy). این سند **زیرمجموعهٔ تخصصی** همان دامنهٔ Deploy است، ولی عمیق‌تر: فقط روی provenance/freshness/integrity artifact تمرکز دارد. آیتم‌های این سند که اتومات شوند، به‌صورت ارجاع به TEST-26 و گیت مربوطه در RELEASE_CHECKLIST ظاهر می‌شوند.
- **`docs/QUALITY_GATE_MATRIX.md`** — نگاشت bug→test→gate. ردیف TEST-26 (پس از implementation) در آنجا ثبت می‌شود؛ این سند سطح انسانی/رویه‌ای همان ردیف است.
- **`AGENTS.md`** — سیاست‌های کلی (NP-5، بند ۱۳ minimal footprint، §8 امنیت) حاکم بر همهٔ این اسناد باقی می‌ماند.

---

*Created in Phase 4.3 (Artifact Integrity Checklist). Documentation-only — the document itself modifies no production code, schema, env, test, or workflow. وضعیت آیتم‌های 🔧/🟢 در زمان نگارش (مرحلهٔ طراحی Phase 4.3) ثبت شده است؛ پس از پیاده‌سازی گیت، پیش از هر Release بازبینی شوند.*
