# VELORA — Engineering Operations Handbook

**نقطه ورود رسمی مستندات پروژه — قبل از هر اقدامی این سند خوانده شود.**

| Document Control | |
|---|---|
| شناسه سند | `VELORA-OPS-README` |
| نسخه | 1.9.0 |
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

> **قرارداد بستهٔ انتشار (Release Package Contract) — از `Structure.pdf` §6:**
> `IN TREE + PACKAGE = 451` (فایل‌های لازم runtime تولید) و
> `IN TREE, NOT PACKAGE = 179` (سورس، ابزار، اسناد، secret/runtime، دامپ، بکاپ، artifactهای legacy).
> سند مرجع صریحاً می‌گوید بسته باید **allowlisted** باشد، نه deny-list.
> این معیار حاکم بر هر تصمیم دربارهٔ محتوای deployment است — نه منیفست‌های ریشه (OC-12).

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

### دفاع لایه‌ای برای Production (وضعیت ۲۰۲۶-۰۸-۱۷)

| لایه | مکانیزم | وضعیت | یادداشت |
|:--:|---|:--:|---|
| ۱ | **Deployment branch policy** روی Environment `production` | ✅ **فعال** — فقط `main` | اعمال‌شده از API؛ الگو: `main` (تنها rule در دسترس این پلن) |
| ۲ | **Required Reviewers** | 🔴 **غیرممکن در پلن فعلی** | GitHub با `422` رد می‌کند — OC-8. نیازمند Pro/Team |
| ۳ | **گارد داخل workflow** (`confirm_production_writes`) | ✅ **فعال** | تنها لایه‌ای که probe نوشتاری را واقعاً متوقف می‌کند |
| ۴ | **انتشار تولید فقط دستی** (`workflow_dispatch`) | ✅ **فعال** | تریگر `push` حذف شد (`f2a857b`) — دیگر هیچ push ای انتشار تولید را آغاز نمی‌کند |
| ۵ | نبودِ Secret های `FTP_*` | ✅ فعال (عملی، نه طراحی‌شده) | خطای امن است نه گارد؛ با B-4 از بین می‌رود |

> ⚠️ **قاعده:** تا زمانی که لایه ۲ فعال نشده، **لایه ۳ تنها گارد تأیید واقعی است و حذف یا دورزدن آن ممنوع است.**
> نکته: `can_admins_bypass = true` است؛ یعنی مالک می‌تواند لایه ۱ را دور بزند.

### ماتریس اتصال FTP (Connection Matrix) — مرجع قطعی

> این بخش در ۲۰۲۶-۰۸-۱۷ افزوده شد. نبودِ آن باعث شد یک جلسه کامل صرف
> کشف دوبارهٔ اطلاعاتی شود که قبلاً به‌دست آمده بود. **قبل از هر کار FTP
> این جدول خوانده شود.**

| مؤلفه | مقدار | منبع |
|---|---|---|
| میزبان | `185.164.72.148` | Secret `STAGING_FTP_SERVER` (همان IP که DNS استیجینگ برمی‌گرداند) |
| نام کاربری | `piknet` — **اکانت کلی cPanel** | Secret `STAGING_FTP_USERNAME` |
| رمز | — | Secret `STAGING_FTP_PASSWORD` (هرگز در چت/سند/لاگ) |
| پروتکل | `ftp` ساده + `set ftp:ssl-allow no` | OC-2 — FTPS روی این هاست reset می‌شود |
| ابزار | `lftp` با `mirror -R --continue` و حلقه retry | OC-2 |
| **نقطه ورود پس از لاگین** | **`/home/piknet/` (home اکانت)** | ⬇ اثبات پایین |

#### اثبات نقطهٔ ورود (تا دوباره آزمایش نشود)

`setup-staging-private.yml` خط ۴۱ این دستور را با موفقیت اجرا می‌کند:

```
mirror -R --continue --no-perms --no-symlinks private/ velora_private_staging/
```

`velora_private_staging/` طبق §3 **خواهرِ** `public_html/` است، نه داخل آن. نوشتن در
این مسیرِ نسبی فقط زمانی ممکن است که نقطهٔ ورود `/home/piknet/` باشد. همزمان
`deploy-staging.yml` خط ۱۱۹ به `public_html/staging.veloratrade.ir/` می‌نویسد و
موفق است. هر دو از یک نقطهٔ ورود ⇒ **ورود = home. قطعی، نه استنتاج.**

#### مقدار `server-dir` / مقصد mirror

| محیط | مقدار صحیح | وضعیت فعلی در کد |
|---|---|---|
| Staging | `public_html/staging.veloratrade.ir/` | ✅ درست |
| **Production** | **`public_html/`** | 🔴 `deploy.yml` هنوز `./` دارد — ناقض §2 سند P2 («هرگز مسیر مبهم یا ریشه حساب») |

> ⚠️ **تلهٔ ابزار:** در `FTP-Deploy-Action` مقدار `server-dir` نسبت به نقطهٔ ورود
> تفسیر می‌شود؛ در `lftp mirror` مقصد صریح است. اگر روزی ابزار عوض شد، این مقدار
> باید بازبینی شود.

#### قاعدهٔ Secret — برای جلوگیری از پرسش تکراری

**agent هرگز به مقدار رمز نیاز ندارد و دسترسی به آن هم ندارد.** GitHub مقدار Secret
را از REST API برنمی‌گرداند (حتی با توکن `admin: true`)؛ فقط نام و تاریخ را می‌دهد.
مقدار صرفاً هنگام اجرا داخل runner تزریق و در لاگ ماسک می‌شود. نوشتن
`${{ secrets.NAME }}` در workflow کافی است.
**فرستادن رمز در چت هیچ قابلیتی اضافه نمی‌کند و فقط یک نشت جدید است (NP-2).**

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

**۱۲ بررسی خواندنی** (هر دو محیط، مگر ذکر شود):

| تست | Staging | Production |
|---|---|---|
| `GET /health` | 200 + **`data.status == "ok"`** (پاکت B-8) | همان |
| `GET /fa/` و `/en/` | 200 | همان |
| `GET /` | 200 + هدر `X-VELORA-Locale` | همان |
| `GET /dashboard` بدون سشن | 302 → `/fa/login/` (اثبات اتصال DB) | همان |
| `GET /api/.env` | 403/404 + اسکن بدنه برای نشت | همان |
| `GET /locale-router.php` مستقیم | 403/404 | همان |
| `robots.txt` | `Disallow: /` | در دسترس (ایندکس مجاز) |
| هدر `X-Robots-Tag` | `noindex, nofollow` | **نباید** noindex باشد |
| هدر CSP | موجود | همان |
| `sitemap.xml` | **404 — عمداً نیست** | **200 — باید باشد** |
| مسیر ناموجود | 404 | همان |

**۳ probe نوشتاری** — استیجینگ آزاد؛ تولید فقط با `WRITE-TO-PRODUCTION`:
Origin جعلی → 403 • logout با Origin درست → `loggedOut:true` • تطابق هش منیفست (فقط تولید)

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
| OC-8 | **پلن حساب (GitHub Free برای مخزن خصوصی) از Required Reviewers و Wait Timer پشتیبانی نمی‌کند.** تلاش برای تنظیم آن‌ها از API با خطای صریح رد می‌شود: `422 — Please ensure the billing plan supports the required reviewers protection rule`. این محدودیت صورتحساب است، نه کمبود دسترسی: توکن `admin: true` دارد | تنها protection rule در دسترس این پلن: **Deployment branch policy** (اعمال شد، فقط `main`). بنابراین گارد سطح‌تأیید باید **داخل workflow** بماند و حذف آن مجاز نیست. برای فعال‌سازی Required Reviewers: ارتقا به GitHub Pro/Team یا عمومی‌کردن مخزن |
| OC-9 | `deploy.yml` روی **هر push به main** اجرا می‌شد؛ تنها مانع رسیدن فایل به تولید، نبودِ Secret های `FTP_*` بود — خطای امن، نه گارد. ۲۷ اجرا، همه با شکست `Input required and not supplied: server` | **رفع شد ۲۰۲۶-۰۸-۱۷** (`f2a857b`): تریگر `push` حذف شد؛ فقط `workflow_dispatch` باقی ماند. راستی‌آزمایی عملی: push بعدی، `CI` و `CSP Guard` را اجرا کرد ولی `Deploy` را **نه**. منطق/مسیر/Secret/FTP بایت‌به‌بایت دست‌نخورده |
| OC-10 | **اکانت FTP «محدود» در واقع محدود نیست.** کامنت خطوط ۱۲ و ۱۶ `deploy-staging.yml` می‌گوید «اکانت FTP محدود به docroot استیجینگ» و «`velora_private_staging` با اکانت محدود قابل آپلود نیست». هر دو نادرست‌اند: مقدار Secret اکانت کلی `piknet` است و `setup-staging-private.yml` عملاً همان مسیر را از طریق FTP می‌سازد | اکانت `piknet` به **کل `/home/piknet/`** دسترسی دارد — شامل `public_html/` تولید و `velora_private/config/velora.env` تولید. جداسازی محیط‌ها در لایهٔ FTP **وجود ندارد**؛ فقط محتوای workflowها آن را حفظ می‌کند. رفع: ساخت اکانت FTP جداگانه در cPanel محدود به docroot استیجینگ → B-10 |
| OC-11 | **مرز واقعی دسترسی — سه لایهٔ دفاعی §3 این مسیر را نمی‌بندند.** زنجیره: PAT با `admin` → نوشتن روی `main` → هر workflow روی `main` می‌تواند `${{ secrets.STAGING_FTP_* }}` را بخواند → آن اکانت `piknet` است → دسترسی کامل به تولید | لایه‌های branch policy / گارد workflow / انتشار دستی فقط جلوی **خطای سهوی** را می‌گیرند، نه نوشتن عمدی یک workflow جدید. تنها کنترل‌های مؤثر: محدودکردن دامنهٔ PAT، اکانت FTP جدا (B-10)، و چرخش credential (B-5) |
| OC-12 | **منیفست‌های ریشه مرجع بستهٔ انتشار نیستند.** `DEPLOYMENT_MANIFEST.json` (۴۹۷ فایل، مورخ 2026-08-13) خودش `_next/**` (۴۰ فایل)، `api/error_log` و `api/init-sqlite.php` را فهرست کرده — یعنی فایل‌هایی که نباید منتشر شوند. `MANIFEST.json`/`PATCH_MANIFEST.json`/`PACKAGE_MANIFEST.json` هم بسته‌های تاریخی دیگری‌اند (۱۲۸/۱۱۱/۱۰۷) | برای ساخت allow-list به هیچ‌کدام استناد نشود. مرجع معتبر: تفکیک **۴۵۱ / ۱۷۹** در `Structure.pdf` §6 (بند «Working Tree vs Production Boundary») — یافته F-11 همین را هشدار داده بود |

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

### 🔧 کار نیمه‌تمام: مهاجرت `deploy.yml` به allow-list

> **جلسهٔ بعد از اینجا ادامه دهد — از صفر شروع نکند.**
> تصمیم‌ها گرفته شده‌اند؛ فقط نگارش و آزمایش مانده.

**وضعیت:** طراحی تأییدشده • کدنویسی **شروع نشده** • `deploy.yml` دست‌نخورده

#### تصمیم‌های قطعی مالک (تأییدشده ۲۰۲۶-۰۸-۱۷)

| # | تصمیم |
|:--:|---|
| ۱ | **گزینه B: مهاجرت به allow-list** — هم‌راستا با `Structure.pdf` §6 |
| ۲ | `server-dir` → **`public_html/`** (اثبات در §3) |
| ۳ | روش انتقال → **`lftp` + `ftp` ساده + `--continue` + retry×12** (الگوی موفق استیجینگ) |
| ۴ | Secrets تولید **فعلاً اضافه نشوند** |
| ۵ | **امنیت و rollback مهم‌تر از سرعت انتشار** |

#### چهار مانع باقی‌مانده

| # | مانع | جزئیات |
|:--:|---|---|
| ۱ | **تعیین دقیق ۴۵۱ فایل** | شبیه‌سازی allow-list عدد **۴۸۴** داد؛ Baseline می‌گوید **۴۵۱**. اختلاف **۳۳** تایی احتمالاً از F-19 (دارایی‌های بلااستفاده: `asset-icons2` ۶۰ فایل، `symbols` ۱۴۸ فایل، ۹ فایل `backup`) و ۸ فایل `README-persian-*.txt` داخل `api/src/**` است. **باید تک‌به‌تک تعیین تکلیف شود** |
| ۲ | **گاردهای پیش از آپلود** | `deploy.yml` **هیچ گاردی ندارد**؛ استیجینگ ۷ گارد دارد. حداقل ۱۱ گارد لازم است (شامل `*.pdf` برای B-6 و `.csp-release.json` برای OC-4) |
| ۳ | **طرح rollback** | **وجود ندارد.** بدون آن انتشار یک‌طرفه است. گزینه‌ها: بکاپ `public_html/` پیش از انتشار، یا انتشار مجدد از کامیت قبلی |
| ۴ | **تعارض سند P2** | `STAGING_ENVIRONMENT.md` §2 خط ۱: «FTP **فقط** برای انتقال فایل به Staging». فعال‌سازی تولید این قاعده را نقض می‌کند — باید اصلاح یا استثنا ثبت شود |

#### روش آزمایش مصوب (پیش از هر انتشار تولید)

```
۰. probe خواندنی مسیر FTP        ← نیازمند Secrets تولید (فعلاً بلوکه)
۱. ساخت بستهٔ تولید → آپلود به public_html/staging.veloratrade.ir/_prodtest/
۲. راستی‌آزمایی بسته: شمارش فایل + هر ۱۱ گارد سبز
۳. اجرای healthcheck-staging.yml (اطمینان از سالم ماندن استیجینگ)
۴. حذف _prodtest/
۵. lftp mirror --dry-run روی مقصد واقعی تولید (بدون نوشتن حتی یک بایت)
۶. انتشار واقعی — فقط با تأیید صریح مالک
۷. healthcheck-production.yml (خودکار از deploy.yml صدا زده می‌شود)
```

#### ایرادهای شناخته‌شدهٔ `deploy.yml` (ممیزی READ-ONLY)

| مورد | خط | وضعیت |
|---|:--:|---|
| `server-dir: ./` → ریشه حساب | ۵۳ | 🔴 ناقض §2 سند P2 |
| `protocol: ftps` | ۵۲ | 🟠 ناقض OC-2 — خطر آپلود نیمه‌کاره |
| `exclude` ناقص → **۶۰۴** فایل به‌جای ۴۵۱ | ۵۵-۷۳ | 🔴 شامل `_next/`، `install.php`، `diag.php`، `error_log`، **`docs/pdf/Roadmap.pdf`** (نقض B-6) |
| بدون گارد پیش از آپلود | — | 🔴 |
| کامنت خط ۴ «فقط روی push به main» | ۴ | ⚠️ منسوخ (OC-9) |
| کامنت خط ۶ «تأیید دستی environment» | ۶ | ⚠️ نادرست (OC-8) |

> **تعدیل مهم:** فایل‌های `install.php`/`diag.php`/`mail-*`/`test-*` توسط
> `api/.htaccess` از دسترسی وب مسدود می‌شوند و آن فایل هم آپلود می‌شود.
> پس **آسیب‌پذیری فوری نیست** — ولی نقض دفاع در عمق است.

**Backlog (به ترتیب اولویت):**
| # | مورد | اولویت | وضعیت |
|:---:|---|:---:|---|
| B-1 | رفع یافته‌های P0: F-01, F-02 (هاردنینگ runtime/دامپ‌ها) و F-03, F-04 (checkout/محتوای EN) | P0 | ⏳ منتظر تأیید مالک |
| B-2 | رفع یافته‌های P1: F-05…F-14 | P1 | ⏳ |
| B-3 | رفع یافته‌های P2: F-15…F-20 | P2 | ⏳ |
| B-4 | **فعال‌سازی کنترل‌شده deploy تولید:** ① افزودن Secrets `FTP_SERVER`/`FTP_USERNAME`/`FTP_PASSWORD` ② Required Reviewers — 🔴 **در پلن فعلی غیرممکن (OC-8)**، نیازمند ارتقا به Pro/Team ③ پیش‌نیاز: اصلاح `deploy.yml` طبق طرح §7 | — | ⏳ تصمیم مالک |
| B-5 | چرخش credentials پس از پایان دوره کاری جاری (FTP piknet، FTP staging، PAT) | 🔴 | ⏳ سمت مالک |
| B-6 | **Roadmap:** نسخه فعلی `Roadmap.pdf` صرفاً جهت حفاظت در `docs/pdf/Roadmap.pdf` آرشیو شده (SHA-256: `0a0df01b3fede02233902074b1b22ca4b444741d12701e1a91303278e962b9d3`) — **وضعیت: LOCKED / DO NOT USE**. نیازمند ویرایش توسط مالک است؛ تا اعلام صریح مالک: از محتوای آن در هیچ سند/تصمیمی استفاده نشود، `docs/02_ROADMAP.md` ساخته نشود، و وارد هیچ بسته deployment نشود | — | ⏳ منتظر ویرایش مالک |
| B-7 | اصلاح `healthcheck.yml` | P1 | ✅ **انجام شد ۲۰۲۶-۰۸-۱۷** — rename + حذف schedule + گارد نوشتاری |
| B-8 | قرارداد پاکت پاسخ API مستند نبود | P2 | ✅ **انجام شد ۲۰۲۶-۰۸-۱۷** — در Baseline §4 ثبت شد و تست‌ها با آن هم‌راستا شدند |
| B-9 | **گارد واقعی تولید:** ① branch policy فقط `main` → ✅ **انجام شد** ② Required Reviewers → 🔴 **مسدود توسط پلن** (OC-8) — نیازمند ارتقا به GitHub Pro/Team؛ تصمیم مالک ③ حذف تریگر `push` از `deploy.yml` → ✅ **انجام شد ۲۰۲۶-۰۸-۱۷** ④ اصلاح کامنت گمراه‌کننده خط ۶ `deploy.yml` («وابسته به environment برای تأیید دستی» — در این پلن ممکن نیست) | 🔴 P0 | ⏳ بندهای ②④ باز |
| B-10 | **اکانت FTP اختصاصی استیجینگ:** ساخت اکانت در cPanel محدود به `public_html/staging.veloratrade.ir/` و جایگزینی `STAGING_FTP_*`. تا آن زمان Secret استیجینگ عملاً کلید کل حساب است (OC-10/OC-11) | 🔴 P0 | ⏳ سمت مالک |

> **قاعده نگهداری:** هر جلسه‌ای که یکی از موارد بالا را تغییر داد، موظف است همین بخش را در همان جلسه به‌روزرسانی و commit کند.

## 8. پروتکل شروع جلسه جدید (Session Bootstrap Protocol)

### ورودی موردنیاز از مالک

```
① دسترسی به مخزن   ② جمله: «طبق docs/README.md ادامه بده»
```

> 🔴 **هشدار امنیتی (درس ۲۰۲۶-۰۸-۱۷):** نسخهٔ قبلی این بند «GitHub PAT معتبر» را
> به‌عنوان ورودی می‌خواست. نتیجه این شد که یک PAT با دسترسی `admin` در متن چت
> ثبت شد و طبق OC-11 عملاً معادل دسترسی کامل به تولید بود.
>
> **ترتیب ترجیح برای دادن دسترسی:**
> ۱. **Deploy key** — کلید خصوصی داخل سندباکس تولید می‌شود، فقط کلید عمومی در
>    Settings → Deploy keys ثبت می‌شود؛ پس از پایان کار حذف می‌گردد.
> ۲. **PAT با کمترین دامنه** — فقط همین مخزن، فقط Contents + Actions، انقضای کوتاه.
> ۳. اگر PAT در چت آمد: پس از پایان جلسه **حتماً revoke شود** (B-5).
>
> **هرگز در چت فرستاده نشود:** رمز FTP، محتوای `velora.env`، رمز دیتابیس،
> JWT/Encryption key، MetaAPI token. agent به هیچ‌کدام نیاز ندارد — §3 «قاعدهٔ Secret».

### گام‌های agent

```
1. clone مخزن → خواندن کامل این سند
2. خواندن §3 (ماتریس محیط‌ها + ماتریس اتصال FTP + دفاع لایه‌ای)
3. خواندن §6 کامل — درس‌های ثبت‌شده؛ هیچ‌کدام دوباره کشف نشود
4. خواندن §7 — وضعیت جاری، backlog، و «کارهای نیمه‌تمام»
5. خواندن اسناد P1/P2 (و P3/P4 در صورت ارتباط با مأموریت)
6. اعلام برنامه اقدام + دامنه دقیق → انتظار برای تأیید مالک
7. اجرا طبق §5 → گزارش → به‌روزرسانی §7 در همان جلسه
```

### قواعد رفتاری الزامی (Behavioural Rules)

این‌ها از اشتباهات واقعی جلسات قبل استخراج شده‌اند:

| # | قاعده | ریشه |
|:--:|---|---|
| BR-1 | **تعارض گزارش شود، نه تفسیر.** اگر سند با کد یا با دستور مالک نمی‌خواند، متوقف شو و بپرس | §1 |
| BR-2 | **نتیجهٔ ابزار را قبل از گزارش راستی‌آزمایی کن.** دو بار در ۲۰۲۶-۰۸-۱۷ تست قرمز شد و هر دو بار نقص خودِ تست بود، نه محیط | §7 |
| BR-3 | **قبل از rename/حذف، وابستگی‌ها را grep کن.** `healthcheck.yml` یک `uses:` در `deploy.yml` داشت که در آستانهٔ شکستن بود | OC-7 |
| BR-4 | **`000`/timeout از سندباکس ≠ خرابی سرویس.** OC-1 است | OC-1، OC-6 |
| BR-5 | **فرض دسترسی نکن — آزمایش کن.** «PAT اجازه ندارد» غلط بود؛ مشکل پلن صورتحساب بود | OC-8 |
| BR-6 | **هر ادعای وضعیت باید شاهد اجرایی داشته باشد** (شماره run، خروجی API، کد وضعیت) | §7 |
| BR-7 | **کامیت اتمیک؛ کد و مستندات جدا.** هر تغییر با گزارش CHANGED/ADDED/DELETED/UNCHANGED/SERVER ACTION | §5 |
