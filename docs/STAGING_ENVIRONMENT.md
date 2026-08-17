# VELORA — STAGING ENVIRONMENT (سند دائمی)

> **منبع حقیقت ساختاری (Structural Source of Truth):**
> قبل از هر تغییر ساختاری در پروژه، علاوه بر این سند، آخرین نسخه
> **`docs/03_PROJECT_STRUCTURE_BASELINE.md`** (03_VELORA_PROJECT_STRUCTURE_BASELINE)
> باید بررسی شود و به‌عنوان مرجع ساختاری پروژه در نظر گرفته شود.

---

## ⚖️ قانون دائمی برای همه جلسات و نسخه‌های بعد

> **«قبل از هر عملیات مربوط به Staging، Testing، Migration یا Deployment،
> ابتدا `docs/STAGING_ENVIRONMENT.md` و آخرین نسخه
> `03_VELORA_PROJECT_STRUCTURE_BASELINE` را مطالعه کن و قوانین آن‌ها را رعایت کن.»**

---

## ۱) تعریف و اصول محیط Staging

- **Staging محیط رسمی تست پروژه است**: `staging.veloratrade.ir`
- Staging **کاملاً از Production جداست** — از نظر فایل‌ها، دیتابیس، Secretها و مسیر runtime خصوصی.
- **دیتابیس Staging از دیتابیس Production جداست.** هیچ داده واقعی کاربران نباید وارد Staging شود.
- `schema.sql` (اسکیمای canonical با ۱۶ جدول) در دیتابیس Staging **Import شده است**؛
  قبل از اجرای هر Migration بعدی، وضعیت فعلی اسکیمای Staging باید بررسی شود
  (`SHOW TABLES` / `DESCRIBE`) — Migration نباید کورکورانه دوباره اجرا شود.
  یادآوری: Migration نسخه v0.2 با گرامر MySQL 8 ناسازگار است (یافته F-13 در Baseline).
- **Secretهای Production نباید در Staging استفاده شوند.** بدون استثنا.
- **`.env` (و `velora.env`) هرگز نباید داخل GitHub Commit شود.**
- **JWT Secret و Encryption Key** مربوط به Staging باید **مستقل از Production** تولید شوند —
  توکن‌های صادرشده در یک محیط نباید در محیط دیگر معتبر باشند.
- **MetaAPI Token و سایر API Secretهای Production** (SMTP، Translation و غیره)
  **نباید در Staging استفاده شوند.** در Staging یا خالی می‌مانند یا مقدار sandbox/تستی می‌گیرند.
- **هرگونه تغییر Production خارج از محدوده تست Staging است** و نیاز به دستور و تأیید صریح جداگانه دارد.
- **قبل از Deployment به Production، Staging باید کامل تست و تأیید شود.**
- **Deployment به Production فقط از نسخه تأییدشده** (کامیت/بسته مشخص و ردیابی‌پذیر) انجام می‌شود،
  **نه از فایل‌های دستی و نامشخص.**

## ۲) قانون استفاده از FTP

- FTP **فقط برای انتقال فایل به Staging** استفاده می‌شود.
  > **اصلاحیه ۲۰۲۶-۰۸-۱۷:** این قاعده در زمانی نوشته شد که تنها استیجینگ در جریان بود.
  > با آماده‌سازی انتشار تولید (B-4)، قاعده به این صورت اصلاح می‌شود:
  > **FTP برای هر دو محیط مجاز است، اما انتقال به Production تنها با تأیید صریح مالک
  > و از طریق `deploy.yml` (فقط `workflow_dispatch`) انجام می‌شود** — مطابق NP-1 و NP-8.
  > مقصد تولید: `public_html/` — هرگز ریشه حساب. جزئیات اتصال: `README.md` §3.
  > ⚠️ هر دو محیط از یک اکانت (`piknet`) استفاده می‌کنند؛ تا رفع B-10 تنها تفاوت
  > عملی، مسیر مقصد است — یک اشتباه تایپی می‌تواند تولید را بازنویسی کند (OC-10/OC-11).
- **اطلاعات ورود FTP هرگز نباید در Repository ذخیره شود** — نه در کد، نه در workflow، نه در مستندات.
- **رمز FTP، Secretها، Tokenها و Private Keyها نباید در GitHub قرار بگیرند**
  (تنها استثنا: GitHub Actions **Encrypted Secrets** که خارج از محتوای مخزن نگهداری می‌شوند).
- **مقصد FTP باید قبل از هر انتقال تأیید شود** — مسیر صریح
  `public_html/staging.veloratrade.ir/` برای docroot استیجینگ؛ هرگز مسیر مبهم یا ریشه حساب.
- **انتقال فایل به Production بدون تأیید صریح مجاز نیست.**

## ۳) معماری جداسازی Staging (اطلاعات غیرمحرمانه)

| جزء | Production | Staging |
|---|---|---|
| Docroot | `public_html/` | `public_html/staging.veloratrade.ir/` |
| Private Root (env/data/logs) | `/home/piknet/velora_private/` | `/home/piknet/velora_private_staging/` |
| فایل env | `{PRIVATE_ROOT}/config/velora.env` | `{PRIVATE_ROOT_STAGING}/config/velora.env` |
| دیتابیس | DB تولید | DB مستقل staging (فقط schema تمیز) |
| `APP_ENV` | `production` | `staging` |
| `APP_DEBUG` | `false` — همیشه | فقط در Staging قابل فعال‌سازی |
| ایمیل | SMTP واقعی | `MAIL_DRIVER=log` (بدون ارسال واقعی) |
| MetaAPI | Token واقعی | خالی/غیرفعال |
| SEO | ایندکس مجاز | `robots.txt: Disallow: /` + هدر `X-Robots-Tag: noindex, nofollow` |
| sitemap و فایل تأیید Search Console | دارد | **عمداً ندارد** |

- مجوزهای الزامی سمت سرور: پوشه‌های private `0700`، فایل `velora.env` برابر `0600`.
- تفاوت `.htaccess` استیجینگ با Production فقط دو مورد مصوب است:
  ① مقدار `SetEnv VELORA_PRIVATE_ROOT` ② افزودن هدر `X-Robots-Tag: noindex, nofollow`.

## ۴) فرآیند Deployment استیجینگ (مرجع)

- Workflow دستی: `.github/workflows/deploy-staging.yml` — فقط `workflow_dispatch`؛
  بسته استیجینگ را می‌سازد (با حذف فایل‌های Production-only و ممنوعه) و با FTP منتقل می‌کند.
- Workflow راه‌اندازی یک‌باره private root: `.github/workflows/setup-staging-private.yml` —
  محتوای env را از GitHub Encrypted Secret می‌خواند و هرگز در لاگ چاپ نمی‌کند.
- Secrets موردنیاز در GitHub Actions (فقط نام — بدون مقدار):
  `STAGING_FTP_SERVER`، `STAGING_FTP_USERNAME`، `STAGING_FTP_PASSWORD`، `STAGING_VELORA_ENV`.
- فایل‌هایی که **هرگز** نباید وارد بسته استیجینگ شوند: هر `*.sql` (به‌ویژه دامپ‌های داده‌دار)،
  هر `*.env*`، `api/storage/**`، لاگ‌ها، `docs/**`، `tools/**`، `_next/**`، `_database/**`،
  اسکریپت‌های نصب/تست/تشخیصی (`install.php`، `diag.php`، `mail-*.php`، `test-*.php` و مشابه)،
  `sitemap.xml` و فایل تأیید Search Console.
- گاردهای خودکار داخل workflow قبل از آپلود، نبودِ این فایل‌ها را تأیید می‌کنند.

## ۵) چک‌لیست تست پس از هر استقرار Staging

```
GET /health          → 200 JSON
GET /                → 200 + هدر X-VELORA-Locale
GET /fa/  و  /en/    → 200
GET /api/.env        → 403/404 (هرگز محتوا)
GET /locale-router.php (مستقیم) → 403/404
مسیر ناموجود        → 404 لوکال‌شده
هدرها               → X-Robots-Tag: noindex + CSP
```

## ۶) محدودیت امنیتی این سند

این سند فقط اطلاعات **غیرمحرمانه** دارد. هیچ Secret واقعی، رمز FTP، رمز دیتابیس،
JWT Secret، Encryption Key، MetaAPI Token، SMTP Password یا Private Key
نباید داخل این سند یا هیچ جای دیگر Repository ذخیره شود.
