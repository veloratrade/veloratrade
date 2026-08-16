# راهنمای استقرار و ساختار فایل‌های پروژه VELORA (نسخه 0.2 — پل ابری MetaApi)

**تاریخ به‌روزرسانی:** 2026-08-13  
**نسخه معماری چندزبانه:** `2026.08.13.7`  
**وضعیت:** آزمون‌های محلی و قراردادها موفق؛ بررسی SMTP، MySQL و worker ترجمه باید در محیط production انجام شود  

---

## 1. ساختار پوشه‌ها و فایل‌های مهم پروژه

این پروژه به گونه‌ای سازماندهی شده است که هم به صورت وب‌سرویس پایتون/نود و هم به صورت مستقیم روی هاست اشتراکی (cPanel / DirectAdmin) با PHP و MySQL یا SQLite کار کند:

### الف) پوشه‌های صفحات رابط کاربری (Frontend - HTML / JS / CSS)
- **`/dashboard/index.html`** — داشبورد اصلی معامله‌گری (KPIها، نمودار سرمایه 30 روزه، کارت‌های حساب متاتریدر متصل، و لیست معاملات اخیر)
- **`/markets/index.html`** — بازارها و واچ‌لیست فارکس و کریپتو (طلا، یورو، بیت‌کوین، شاخص‌ها و تقویم اقتصادی)
- **`/intelligence/index.html`** — تحلیل هوش مصنوعی (VELORA AI Insights، نرخ برد استراتژی‌ها و چت‌بات پرسش از ژورنال)
- **`/trades/index.html`** — ژورنال معاملات (جدول معاملات با جزئیات ورود، خروج، R-Multiple و استراتژی)
- **`/trades/new/index.html`** — ویزارد ثبت معامله جدید (انتخاب نماد، ماشین‌حساب ریسک به ریوارد و ثبت سمت سرور)
- **`/accounts/connect/index.html`** — صفحه اتصال ابری متاتریدر (ویزاد اتصال سریع MT4 / MT5 با پسورد اینوستور)
- **`/wallet/index.html`** — کیف پول و حساب‌ها (موجودی کل، اکویتی لحظه‌ای، مارجین آزاد و درگیر)
- **`/performance/index.html`** — عملکرد معامله‌گری (تفکیک بازدهی ماهانه، Profit Factor و Drawdown)
- **`/news/index.html`** — اخبار و رویدادها (فید زنده اخبار اقتصادی فارکس و کریپتو)
- **`/profile/index.html`** — پروفایل و امنیت (مشخصات کاربر اصلی، تغییر رمز عبور و تنظیمات نشست)
- **`/support/index.html`** — پشتیبانی و راهنما (تیکتینگ و سوالات متداول اتصال متاتریدر)
- **`/admin/index.html`** — پنل مدیریت سیستم (مدیریت کاربران و نظارت بر پلتفرم)

### ب) پوشه وب‌سرویس و بک‌اند (Backend - PHP & Database)
- **`/api/src/Accounts/MetaApiService.php`** — سرویس اصلی پل ابری MetaApi (اتصال MT4/MT5، استخراج خودکار Balance، Equity، Leverage، Currency، Margin و سینک معاملات)
- **`/api/src/Accounts/AccountController.php`** — کنترلر اندپوینت‌های `/connect-metaapi`، `/detect-server`، `/sync` و وضعیت اتصال
- **`/api/src/Accounts/AccountRepository.php`** — لایه دسترسی به دیتابیس (ذخیره اطلاعات رمزنگاری‌شده با `AES-256-GCM`)
- **`/api/database/migrations/v0.2_metaapi_bridge.sql`** — فایل مایگریشن پایگاه داده برای افزودن ستون‌های MetaApi به جدول `trading_accounts`

---

## 2. مهم‌ترین تغییرات و بهینه‌سازی‌های نهایی‌شده

1. **یکپارچگی و طراحی لوکس سایدبار (Desktop & Mobile):**
   - سایدبار در تمام 21 صفحه به صورت 10 تایی و تمیز (`داشبورد`، `بازارها`، `Trading Intelligence`، `ژورنال معاملات`، `کیف پول`، `عملکرد`، `اخبار`، `پروفایل`، `پشتیبانی` و `مدیریت`) پیاده‌سازی شد.
   - رنگ‌بندی طلایی سلطنتی با اشباع بالا (**Saturated Royal Gold**: آیکون‌ها `#ffb703` و متن `#f1c453`) در هر دو حالت دسکتاپ و موبایل کشویی اعمال شد.
   - تب‌های اضافی «ثبت معامله» و «اتصال متاتریدر» از سایدبار حذف شدند.

2. **نوار هدر مینیمال بالای صفحه (Top Navigation Bar):**
   - در تمام صفحات غیر از داشبورد، دکمه سبز/زمردی **«＋ ثبت معامله»** در سمت چپ هدر قرار دارد.
   - آواتار، برچسب سیستم زنده، دکمه بازگشت و عنوان صفحه از نوار هدر حذف شدند تا هدر کاملاً خلوت، شیک و مینیمال باشد.

3. **حذف انیمیشن‌های مزاحم و پاک‌سازی DOM:**
   - انیمیشن دایره سفید (`veloraSymShimmer`) از فایل آیکون‌ها (`/public/assets/symbol-icons.js`) حذف شد.
   - تمام تگ‌های بسته‌کننده اضافی که باعث اسکرول به پایین و بریدگی صفحات می‌شدند پاک‌سازی شدند و محتوا دقیقاً در بالای صفحه رندر می‌شود.

4. **حالت تستی هوشمند (Fallback Mode) و امنیت جاوااسکریپت:**
   - در صورت عدم شارژ پنل MetaApi، وب‌سرویس بدون خطا با داده‌های شبیه‌سازی‌شده واقعی کار می‌کند و به محض شارژ حساب، موجودی و معاملات واقعی بروکر به طور زنده جایگزین می‌شود.
   - تمامی تخصیص‌های DOM با شروط ایمنی محافظت شده و هیچ‌گونه ریدایرکت یا خطای جاوااسکریپتی رخ نمی‌دهد.

---

## 3. راهنمای پیاده‌سازی روی هاست واقعی (cPanel / هاست اشتراکی)

1. **آپلود فایل‌ها:**  
   محتوای پچ را در ریشه سایت واقعی استخراج کنید: `/home/piknet/public_html/` روی `https://veloratrade.ir`
2. **تنظیم دیتابیس:**  
   در phpMyAdmin دیتابیس خود را ایجاد کرده و فایل مایگریشن `/api/database/migrations/v0.2_metaapi_bridge.sql` را ایمپورت کنید.
3. **تنظیم متغیرهای محیطی (`.env`):**  
   در پوشه `api/` فایل `.env` را ایجاد و توکن واقعی MetaApi خود را قرار دهید:
   ```env
   فایل `api/.env` فعلی هاست را نگه دارید. مقادیر واقعی همین سرور:
   دامنه `https://veloratrade.ir`، دیتابیس `piknet_velora`، ایمیل `no-reply@veloratrade.ir`.
   توکن MetaApi و رمزها را از `.env` موجود کپی کنید؛ این پچ آن فایل را جایگزین نمی‌کند.
   ```
4. **تنظیم Cron Job برای سینک پس‌زمینه:**  
   دستور زیر را به عنوان کرون جاب 1 دقیقه‌ای در هاست اضافه کنید:
   ```bash
   * * * * * php /home/piknet/public_html/api/workers/metaapi_sync_worker.php
   ```

---

## 4. استقرار معماری چندزبانه build/server-time روی cPanel و LiteSpeed

### فایل‌های الزامی

تمام archive نسخه `2026.08.13.7` باید با حفظ ساختار در document root استخراج شود. این موارد را حذف نکنید:

- `.htaccess` — تفکیک API/static و ارسال درخواست صفحه به resolver؛
- `locale-router.php` — انتخاب زبان و سرو HTML آماده؛
- `localized/fa/` و `localized/en/` — خروجی build و تنها منبع first paint؛
- `public/locales/` — manifest، catalogها و chunkهای feature؛
- `public/assets/velora-locale-*.js` و `velora-localization.js`؛
- `tools/localization/` فقط در بسته source/release نگهداری می‌شود و `.htaccess` دسترسی HTTP به آن را می‌بندد.

`mod_rewrite` باید فعال و `AllowOverride` برای `.htaccess` مجاز باشد. LiteSpeed از قواعد Apache-compatible موجود استفاده می‌کند.

### ترتیب انتخاب زبان

برای URLهای عادی مانند `/dashboard/`:

1. cookie انتخاب دستی `velora_locale`؛
2. primary value هدر `Accept-Language`؛
3. default manifest فقط در نبود زبان مرورگر.

زبان primary پشتیبانی‌نشده، از جمله `de-DE` و `ar-SA`، به English/LTR می‌رود. URL صریح `/fa/...` یا `/en/...` برای SEO و اشتراک‌گذاری، همان زبان را قطعی می‌کند.

### build پیش از آپلود

در root پروژه اجرا شود:

```bash
python3 tools/localization/normalize_brand.py
python3 tools/localization/sync_registry.py
python3 tools/localization/build_localized_static.py
python3 tools/localization/validate_localization.py
```

نتیجه مورد انتظار این نسخه:

```text
LOCALE_REGISTRY_SYNC_OK locales=2 version=2026.08.13.7 bytes=893 preloaded_messages=0
LOCALIZED_BUILD_OK templates=28 html=59 feature_chunks=34
LOCALIZATION_VALIDATION_OK locales=2 keys=1196 references=1050 html=88
```

دایرکتوری `localized/` generated است؛ آن را دستی ویرایش نکنید. source page انگلیسی جداگانه نگهداری نمی‌شود.

### تنظیم cache

- HTML negotiated: `private, max-age=0, must-revalidate` + `Vary: Cookie, Accept-Language`؛
- assetهای versioned: public immutable؛
- JSON chunkها: public با `stale-while-revalidate`؛
- صفحه‌ها `ETag` و `Last-Modified` دارند و `If-None-Match` معتبر باید پاسخ `304` بگیرد.

پس از جایگزینی نسخه قبلی، cache کامل HTML در LiteSpeed/CDN را یک بار purge کنید. query نسخه assetها (`?v=2026.08.13.7`) مانع استفاده از runtime قدیمی می‌شود.

### تست سریع production

```bash
curl -sS -H 'Accept-Language: fa-IR' https://veloratrade.ir/dashboard/ | grep -o '<html[^>]*>'
curl -sS -H 'Accept-Language: de-DE' https://veloratrade.ir/dashboard/ | grep -o '<html[^>]*>'
curl -sS -H 'Cookie: velora_locale=fa' -H 'Accept-Language: de-DE' https://veloratrade.ir/dashboard/ | grep -o '<html[^>]*>'
curl -sS https://veloratrade.ir/en/dashboard/ | grep -o '<html[^>]*>'
curl -I https://veloratrade.ir/public/locales/chunks/en/dashboard.json
```

انتظار:

- `fa-IR` → `lang="fa" dir="rtl"`؛
- `de-DE` بدون cookie → `lang="en" dir="ltr"`؛
- cookie فارسی → فارسی؛
- `/en/dashboard/` → انگلیسی مستقل از cookie/browser؛
- chunk dashboard با HTTP 200 و content type JSON.

### Cron مربوط به ترجمه محتوای dynamic

provider ترجمه نباید از request وب اجرا شود. worker جداگانه را متناسب با نرخ ingestion زمان‌بندی کنید:

```bash
* * * * * php /home/piknet/public_html/api/workers/content_translation_worker.php >> /home/piknet/logs/velora-translation.log 2>&1
```

credentialهای provider فقط در environment/secret هاست قرار بگیرند. endpoint عمومی فقط cache lookup انجام می‌دهد؛ خرابی worker یا provider نباید نمایش محتوای اصلی یا داده‌های real-time را متوقف کند.

### بررسی‌های اجباری پس از استقرار

1. migration و race worker روی نسخه واقعی MySQL؛
2. SMTP واقعی و نمایش RTL/LTR ایمیل‌ها؛
3. provider ترجمه فقط از CLI sandbox؛
4. جریان‌های auth با دیتابیس disposable؛
5. صفحات responsive فارسی و انگلیسی در مرورگر واقعی؛
6. latency داشبورد/معاملات با catalog/provider unavailable؛
7. canonical و `hreflang` مسیرهای `/fa/` و `/en/`؛
8. عدم دسترسی HTTP به `localized/`, `tools/`, storage و فایل‌های secret.
