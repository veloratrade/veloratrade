# VELORA — Engineering Operations Handbook

**نقطه ورود رسمی مستندات پروژه — قبل از هر اقدامی این سند خوانده شود.**

| Document Control | |
|---|---|
| شناسه سند | `VELORA-OPS-README` |
| نسخه | 1.5.0 |
| وضعیت | ACTIVE |
| آخرین به‌روزرسانی | 2026-08-17 |
| مالک | veloratrade (Project Owner) |
| دامنه اعتبار | کل مخزن `veloratrade/veloratrade` و محیط‌های Staging/Production |
| مرجع نسخه پایه | Git tag: **`docs-baseline-v1`** (کامیت `06408c9`) |
| چرخه بازبینی | پس از هر عملیات مؤثر بر محیط‌ها یا اسناد، در همان جلسه |

---

## 1. سلسله‌مراتب اسناد (Document Hierarchy)

مطالعه به ترتیب زیر **الزامی** است. در صورت تعارض، سند بالاتر حاکم است؛ تعارض باید گزارش شود، نه تفسیر.

| اولویت | سند | نقش | الزام |
|:---:|---|---|---|
| P1 | [`03_PROJECT_STRUCTURE_BASELINE.md`](./03_PROJECT_STRUCTURE_BASELINE.md) | **Structural Source of Truth** — معماری، مسیرها، ۲۰ یافته ممیزی | قبل از هر تغییر ساختاری |
| P2 | [`STAGING_ENVIRONMENT.md`](./STAGING_ENVIRONMENT.md) | سیاست محیط‌ها، جداسازی، FTP، Deployment | قبل از هر عملیات محیطی |
| P3 | [`01_SECURITY_CHECKLIST.md`](./01_SECURITY_CHECKLIST.md) | ممیزی امنیتی دائمی (۳۹ حوزه) | قبل از هر Release |
| P4 | [`04_STRUCTURE_COMPLIANCE_CHECKLIST.md`](./04_STRUCTURE_COMPLIANCE_CHECKLIST.md) | راستی‌آزمایی انطباق ساختار | پس از تغییرات ساختاری |
| — | `pdf/` | نسخ منبع امضاشده با SHA-256 (غیرقابل ویرایش) | مرجع اختلاف |

## 2. سیاست‌های غیرقابل‌مذاکره (Non-Negotiable Policies)

| # | سیاست | مبنا |
|:---:|---|---|
| NP-1 | هیچ تغییری در **Production** بدون دستور صریح و مکتوب مالک — «دسترسی داشتن» مجوز نیست | Change-Control Rule, Baseline |
| NP-2 | هیچ Secret واقعی در مخزن، مستندات، لاگ یا خروجی چت — تنها مخزن مجاز: **GitHub Encrypted Secrets** | STAGING_ENVIRONMENT §2, §6 |
| NP-3 | اجرای Migration فقط پس از بازرسی وضع موجود اسکیما (`SHOW TABLES` / `DESCRIBE`) و روی محیط غیر تولیدی | STAGING_ENVIRONMENT §1 |
| NP-4 | یافته‌های OPEN (F-01…F-20) فقط با تأیید صریح، به‌صورت تک‌به‌تک و با کامیت مستقل رفع/به‌روزرسانی می‌شوند | Baseline §8 |
| NP-5 | `localized/**` خروجی build است — ویرایش دستی مطلقاً ممنوع؛ اصلاح فقط از مسیر سورس/کاتالوگ + rebuild | Baseline §3 |
| NP-6 | Deployment فقط از نسخه ردیابی‌پذیر (کامیت مشخص) و از طریق workflow — هرگز فایل دستی نامشخص | STAGING_ENVIRONMENT §1 |
| NP-7 | tag مرجع `docs-baseline-v1` هرگز جابه‌جا یا بازنویسی نمی‌شود؛ هر اصلاح سند = کامیت جدید روی main | این سند §5 |
| NP-8 | قبل از هر انتقال FTP، مقصد دقیق اعلام و تأیید می‌شود؛ انتقال به Production بدون تأیید ممنوع | STAGING_ENVIRONMENT §2 |

## 3. ماتریس محیط‌ها (Environment Matrix)

| مؤلفه | Production | Staging |
|---|---|---|
| URL | `veloratrade.ir` | `staging.veloratrade.ir` |
| Docroot | `public_html/` | `public_html/staging.veloratrade.ir/` |
| Private Root | `/home/piknet/velora_private/` | `/home/piknet/velora_private_staging/` |
| Env file | `{PR}/config/velora.env` (0600) | `{PRS}/config/velora.env` (0600) |
| Database | تولید — 🔴 ممنوعِ لمس | مستقل؛ `schema.sql` وارد شده |
| `APP_ENV` | `production` | `staging` |
| Deploy | `deploy.yml` — **عملاً غیرفعال** (Secrets تولید ست نشده؛ عمدی) | `deploy-staging.yml` — فعال، فقط دستی |
| Indexing | مجاز | ممنوع (`robots` + `X-Robots-Tag`) |
| وضعیت فعلی | UNTOUCHED ✅ | **OPERATIONAL ✅** (همه تست‌ها PASS — 2026-08-17) |

## 4. Runbook — عملیات استاندارد

### RB-1: استقرار مجدد Staging
```
پیش‌شرط: تغییرات روی برنچ main باشد
اجرا:     GitHub → Actions → "Deploy Staging" → Run workflow (ref: main)
Secrets:  خودکار خوانده می‌شوند — نیازی به credential نیست
پس‌شرط:   اجرای کامل RB-3
```

### RB-2: به‌روزرسانی env استیجینگ
```
1. مقدار جدید کامل env → Secret «STAGING_VELORA_ENV» (Settings → Secrets → Actions)
2. GitHub → Actions → "Setup Staging Private Root" → Run workflow
3. راستی‌آزمایی: /health باید 200 برگرداند
⚠️ الزامات فرمت: JWT_SECRET ≥ 32 chars | APP_ENCRYPTION_KEY = base64 دقیقاً 32 بایت | CORS بدون wildcard
```

### RB-3: راستی‌آزمایی پس از استقرار (Smoke Suite)

**ساختار (از ۲۰۲۶-۰۸-۱۷):** منطق بررسی فقط در یک فایل زندگی می‌کند تا بین محیط‌ها شکاف نیفتد.

```
healthcheck-suite.yml        ← تنها محل منطق مشترک (workflow_call، مستقیم اجرا نمی‌شود)
   ├── healthcheck-staging.yml      wrapper نازک → staging  + write_probes=true
   └── healthcheck-production.yml   wrapper نازک → production + گارد تأیید
```

| محیط | نحوه اجرا | probe نوشتاری |
|---|---|---|
| Staging | Actions → «Health Check (Staging)» → Run workflow | ✅ فعال — محیط امن، DB مستقل |
| Production | Actions → «Health Check (Production)» → Run workflow | ⛔ فقط با نوشتن دقیق `WRITE-TO-PRODUCTION` در ورودی `confirm_production_writes` |

- **قاعده نام‌گذاری:** نام فایل workflow باید دامنه‌اش را اعلام کند (درس OC-7).
- **افزودن بررسی جدید:** فقط در `healthcheck-suite.yml`. تفاوت‌های محیطی با شرط `IS_STAGING` مدل می‌شوند (مثلاً استیجینگ باید `noindex` بدهد و تولید نباید).
- **probe نوشتاری چرا حذف نشد:** تست گارد same-origin ذاتاً به درخواست تغییردهنده نیاز دارد؛ با GET تبدیل به تست تزئینی می‌شود.
- ⚠️ اجرای این تست‌ها از سندباکس چت ممکن نیست (OC-1/OC-6).

| تست | نتیجه موردانتظار |
|---|---|
| `GET /health` | 200 + JSON `status:ok` |
| `GET /fa/` و `/en/` | 200، محتوای زبان صحیح |
| `GET /dashboard` بدون سشن | redirect به login (اثبات اتصال DB) |
| `GET /api/.env` | 404 JSON — بدون نشت محتوا |
| `GET /locale-router.php` مستقیم | 403 |
| `robots.txt` | `Disallow: /` |
| هدر پاسخ HTML | `X-Robots-Tag: noindex` + CSP |

### RB-4: مقایسه اسناد با نسخه پایه
```
git diff docs-baseline-v1..main -- docs/
```

### RB-5: چرخش Credential
```
1. تغییر رمز در cPanel
2. به‌روزرسانی Secret متناظر در GitHub (فقط توسط مالک)
3. اجرای RB-1 برای اطمینان از سلامت pipeline
```

## 5. مدیریت تغییر (Change Management)

```
درخواست → بررسی اسناد P1/P2 → اعلام دامنه دقیق (فایل‌ها/مقصد) → تأیید مالک
        → کوچک‌ترین تغییر ممکن → کامیت اتمیک با پیام استاندارد → راستی‌آزمایی
        → گزارش CHANGED / ADDED / DELETED / UNCHANGED / SERVER ACTION → به‌روزرسانی §7 همین سند
```
- قالب پیام کامیت: `type(scope): summary` — مانند `docs:`, `ci(staging):`, `fix(api):`
- هر رفع یافته Baseline: ارجاع به شناسه (`F-xx`) در پیام کامیت الزامی است.

## 6. درس‌های عملیاتی ثبت‌شده (Operational Constraints — Do Not Rediscover)

| شناسه | محدودیت | راهکار تأییدشده |
|---|---|---|
| OC-1 | فایروال هاست، FTP/SSH را از IPهای خارج از ایران **silent-drop** می‌کند | انتقال فایل فقط از GitHub Actions |
| OC-2 | هاست، کانال داده FTPS و اتصال‌های طولانی را reset می‌کند (ECONNRESET) | `lftp` + پروتکل ftp ساده + حلقه retry با `--continue` (پیاده‌شده در workflow) |
| OC-3 | کد، هر `APP_ENV ≠ dev` را production-grade می‌داند و fail-closed است | فرمت‌های RB-2 دقیقاً رعایت شود |
| OC-4 | `localized/.csp-release.json` فایل مخفی حیاتی است | در کپی/بسته‌بندی با glob ساده جا نماند |
| OC-5 | Migration `v0.2` با گرامر MySQL 8 ناسازگار است | یافته F-13 — قبل از اجرا dialect بررسی شود |
| OC-6 | Runner گیت‌هاب به هاست دسترسی دارد؛ سندباکس چت ندارد | انتقال فایل فقط از Actions. **اصلاح ۲۰۲۶-۰۸-۱۷:** تست HTTP از چت هم برای `staging.veloratrade.ir` ممکن **نیست** (نتیجه: timeout/`000`) — این خرابی استیجینگ نیست، همان silent-drop بند OC-1 است. تست استیجینگ فقط از Actions |
| OC-7 | نام `healthcheck.yml` دامنه‌اش را پنهان می‌کرد: در واقع **تولید** را تست می‌کرد، `schedule` روزانه داشت و شبانه روی `/api/v1/auth/logout` تولید **POST** می‌زد. یک جلسه آن را به‌اشتباه ابزار تست استیجینگ فرض کرد | **رفع شد ۲۰۲۶-۰۸-۱۷:** به `healthcheck-production.yml` تغییر نام یافت، `schedule` حذف شد، و probe نوشتاری پشت گارد `confirm_production_writes` رفت. قاعده: **نام فایل باید دامنه‌اش را اعلام کند** |
| OC-8 | Environment «production» در گیت‌هاب وجود دارد ولی `protection_rules` آن **صفر** است؛ کامنت `deploy.yml` («وابسته به environment برای تأیید دستی») توصیف واقعیت نبود. گره‌زدن یک job به Environment بدون rule، هیچ محافظتی ایجاد نمی‌کند و فقط ظاهر امن می‌سازد | تا زمان تنظیم Required Reviewers توسط مالک، محافظت باید **داخل کد** باشد (گارد صریح)، نه متکی به تنظیمات UI. بررسی با: `GET /repos/{owner}/{repo}/environments/production` |
| OC-9 | `deploy.yml` روی **هر push به main** اجرا می‌شود (نه فقط دستی). تنها چیزی که مانع رسیدن فایل به تولید می‌شود، نبودِ Secret های `FTP_*` است — خطای امن، ولی گارد نیست. ۲۳ اجرا، همه با شکست `Input required and not supplied: server` | اگر B-4 اجرا و Secret ها اضافه شوند، هر push مستقیماً روی تولید می‌نشیند. پیش از آن باید تریگر `push` بازبینی و Required Reviewers فعال شود — ثبت‌شده در B-9 |

## 7. وضعیت جاری و کارهای باز (Living Section)

**آخرین وضعیت (2026-08-17):**
- **Staging عملیاتی — راستی‌آزمایی خودکار شد ✅** آخرین اجرا پس از یکپارچه‌سازی
  (run `32021663165`، کامیت `34b7509`): **۱۵ از ۱۵ سبز** — ۱۲ بررسی خواندنی + ۳ probe نوشتاری.
  گارد same-origin واقعاً تست شد: `Origin: evil.example` → **403**؛ logout با Origin درست →
  `{"status":"success","data":{"loggedOut":true}}` (مطابق قرارداد پاکت، B-8).
  شواهد کلیدی: `/health` → `data.status=ok` • `/dashboard` بدون سشن → `302 → /fa/login/`
  (اثبات اتصال DB) • `/api/.env` → `404` بدون نشت • `locale-router.php` → `403` •
  `X-Robots-Tag: noindex, nofollow` • CSP موجود • `sitemap.xml` → `404` (عمدی) •
  مسیر ناموجود → `404`.
- Production دست‌نخورده؛ pipeline تولید عمداً بدون Secrets. هیچ درخواستی در این جلسه به Production ارسال نشد.
- اسناد پایه ثبت و tag شده (`docs-baseline-v1`).
- **معماری Smoke Suite یکپارچه شد:** منطق مشترک در `healthcheck-suite.yml` و دو wrapper نازک
  (`healthcheck-staging.yml`، `healthcheck-production.yml`). هدف: نبود شکاف بین محیط‌ها —
  افزودن یک بررسی در یک‌جا، هر دو محیط را پوشش می‌دهد.
- `healthcheck.yml` → `healthcheck-production.yml` تغییر نام یافت؛ ارجاع `deploy.yml` و دو ارجاع
  در سند ۰۴ همزمان اصلاح شدند. `schedule` شبانه حذف شد (OC-7).
- دو نقص در اولین اجراهای واقعی کشف و رفع شد (`a39b902`, `58ab606`):
  ① مسیر آزمایشی غیر ASCII قبل از ارسال خطای encoding می‌داد و قرارداد ۴۰۴ عملاً تست نمی‌شد؛
  ② شرط `/health` به‌جای `data.status` روی فیلد سطح بالا بررسی می‌شد.
  **درس:** هر دو قرمزِ اولیه نقص تست بودند نه نقص استیجینگ — یک گیت تست‌نشده خودش منبع سیگنال کاذب است.
- 🔴 **یافته باز (OC-8/OC-9، ثبت در B-9):** Environment `production` صفر protection rule دارد و
  `deploy.yml` روی هر push به main اجرا می‌شود. تنها مانع فعلی رسیدن فایل به تولید، **نبودِ Secret**
  است نه یک گارد. تا تنظیم Required Reviewers توسط مالک، ادعای «تأیید دستی تولید» معتبر نیست.

**Backlog (به ترتیب اولویت):**
| # | مورد | اولویت | وضعیت |
|:---:|---|:---:|---|
| B-1 | رفع یافته‌های P0: F-01, F-02 (هاردنینگ runtime/دامپ‌ها) و F-03, F-04 (checkout/محتوای EN) | P0 | ⏳ منتظر تأیید مالک |
| B-2 | رفع یافته‌های P1: F-05…F-14 | P1 | ⏳ |
| B-3 | رفع یافته‌های P2: F-15…F-20 | P2 | ⏳ |
| B-4 | فعال‌سازی کنترل‌شده deploy تولید: Secrets تولید + **Required Reviewers** روی environment `production` | — | ⏳ تصمیم مالک |
| B-5 | چرخش credentials پس از پایان دوره کاری جاری (FTP piknet، FTP staging، PAT) | 🔴 | ⏳ سمت مالک |
| B-6 | **Roadmap:** نسخه فعلی `Roadmap.pdf` صرفاً جهت حفاظت در `docs/pdf/Roadmap.pdf` آرشیو شده (SHA-256: `0a0df01b3fede02233902074b1b22ca4b444741d12701e1a91303278e962b9d3`) — **وضعیت: LOCKED / DO NOT USE**. نیازمند ویرایش توسط مالک است؛ تا اعلام صریح مالک: از محتوای آن در هیچ سند/تصمیمی استفاده نشود، `docs/02_ROADMAP.md` ساخته نشود، و وارد هیچ بسته deployment نشود | — | ⏳ منتظر ویرایش مالک |
| B-7 | اصلاح `healthcheck.yml` | P1 | ✅ **انجام شد ۲۰۲۶-۰۸-۱۷** — rename + حذف schedule + گارد نوشتاری |
| B-8 | قرارداد پاکت پاسخ API مستند نبود | P2 | ✅ **انجام شد ۲۰۲۶-۰۸-۱۷** — در Baseline §4 ثبت شد و تست‌ها با آن هم‌راستا شدند |
| B-9 | **گارد واقعی تولید:** ① تنظیم **Required Reviewers** روی Environment `production` (فقط از Settings → Environments؛ از API با PAT ممکن نیست) ② تصمیم درباره تریگر `push` در `deploy.yml` (OC-9) ③ اصلاح کامنت گمراه‌کننده `deploy.yml` | 🔴 P0 | ⏳ سمت مالک |

> **قاعده نگهداری:** هر جلسه‌ای که یکی از موارد بالا را تغییر داد، موظف است همین بخش را در همان جلسه به‌روزرسانی و commit کند.

## 8. پروتکل شروع جلسه جدید (Session Bootstrap Protocol)

```
ورودی موردنیاز از مالک:  ① GitHub PAT معتبر  ② جمله: «طبق docs/README.md ادامه بده»

گام‌های agent:
1. clone مخزن → خواندن این سند به‌طور کامل
2. خواندن اسناد P1 و P2 (و P3/P4 در صورت ارتباط با مأموریت)
3. بررسی §7 برای وضعیت جاری و backlog
4. اعلام برنامه اقدام + دامنه دقیق → انتظار برای تأیید مالک
5. اجرا طبق §5 → گزارش → به‌روزرسانی §7
```
