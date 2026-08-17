# 🚀 VELORA — از اینجا شروع کن (READ FIRST)

> این فایل «نقطه ورود» برای هر جلسه/agent/توسعه‌دهنده جدید است.
> قبل از هر اقدامی، این فایل و سپس اسناد زیر را به ترتیب بخوان.

## ۱) ترتیب اجباری مطالعه اسناد

| # | سند | نقش |
|---|---|---|
| 1 | `docs/03_PROJECT_STRUCTURE_BASELINE.md` | 🏛 منبع حقیقت ساختاری (Source of Truth) — نسخه مرجع: tag `docs-baseline-v1` |
| 2 | `docs/STAGING_ENVIRONMENT.md` | قوانین دائمی Staging، FTP و Deployment |
| 3 | `docs/01_SECURITY_CHECKLIST.md` | چک‌لیست امنیتی — قبل از هر release اجرا شود |
| 4 | `docs/04_STRUCTURE_COMPLIANCE_CHECKLIST.md` | چک‌لیست انطباق ساختار |

## ۲) قوانین طلایی (بدون استثنا)

- ❌ هیچ تغییری در **Production** بدون دستور و تأیید صریح مالک پروژه.
- ❌ هیچ Secret واقعی (رمز، توکن، کلید) در مخزن یا مستندات — فقط GitHub Encrypted Secrets.
- ❌ Migration دیتابیس کورکورانه اجرا نشود — اول وضعیت فعلی اسکیما بررسی شود (schema.sql در DB استیجینگ Import شده است).
- ❌ یافته‌های OPEN در Baseline (۲۰ مورد، F-01 تا F-20) بدون تأیید حذف/تغییر نشوند.
- ❌ فایل‌های `localized/**` هرگز دستی ویرایش نشوند (خروجی build هستند).
- ✅ هر تغییر سند → کامیت جدید؛ tag مرجع `docs-baseline-v1` هرگز جابه‌جا نمی‌شود.
- ✅ Deploy فقط از طریق workflow های مخزن — نه فایل دستی نامشخص.

## ۳) وضعیت فعلی پروژه (آخرین به‌روزرسانی: 2026-08-17)

- 🌐 **Production:** `veloratrade.ir` — دست‌نخورده؛ deploy تولید عمداً غیرفعال (Secrets تولید FTP_* ست نشده‌اند).
- 🧪 **Staging:** `staging.veloratrade.ir` — مستقر و سبز. همه تست‌های چک‌لیست PASS
  (health، fa/en، dashboard→login با DB وصل، گاردهای امنیتی، noindex).
- 🗄 DB استیجینگ: مستقل، schema.sql وارد شده، مقادیر اتصال در `velora.env` روی هاست تنظیم شده.
- 🔴 یافته‌های امنیتی/ساختاری Baseline: هر ۲۰ مورد هنوز **OPEN** — منتظر تأیید برای رفع.

## ۴) عملیات رایج — دقیقاً چطور

| کار | روش |
|---|---|
| استقرار مجدد Staging | اجرای workflow **`Deploy Staging`** (`deploy-staging.yml`) — دستی (workflow_dispatch) از برنچ main |
| به‌روزرسانی env استیجینگ | آپدیت Secret `STAGING_VELORA_ENV` → اجرای workflow **`Setup Staging Private Root`** |
| Secrets موجود (فقط نام) | `STAGING_FTP_SERVER`, `STAGING_FTP_USERNAME`, `STAGING_FTP_PASSWORD`, `STAGING_VELORA_ENV` |
| تست سلامت پس از هر استقرار | چک‌لیست بخش ۵ سند `STAGING_ENVIRONMENT.md` (health, fa/en, dashboard, api/.env, robots) |
| مقایسه با Baseline اولیه | `git diff docs-baseline-v1..main -- docs/` |

## ۵) نکات فنی که نباید دوباره کشف شوند

- FTP هاست از IPهای خارج از ایران **مسدود** است؛ از سندباکس مستقیم وصل نشو — فقط GitHub Actions کار می‌کند (با lftp + حلقه retry، پروتکل ftp ساده نه ftps).
- HTTPS استیجینگ از IP خارجی از طریق fetch عادی صفحات جواب می‌دهد؛ برای تست از همان استفاده کن.
- کد، هر `APP_ENV` غیر از `dev` را production-grade حساب می‌کند: `JWT_SECRET` ≥ ۳۲ کاراکتر، `APP_ENCRYPTION_KEY` باید **base64 دقیقاً ۳۲ بایت** باشد، CORS wildcard ممنوع.
- env استیجینگ در هاست: `/home/piknet/velora_private_staging/config/velora.env` (0600) — env تولید: `/home/piknet/velora_private/config/velora.env` — **به دومی دست نزن.**
- Migration `v0.2_metaapi_bridge.sql` با MySQL 8 ناسازگار است (F-13).

## ۶) کارهای باز (backlog)

1. رفع ۲۰ یافته OPEN طبق جدول اولویت P0→P2 در Baseline (بخش ۸) — هر کدام فقط با تأیید صریح.
2. تصمیم درباره فعال‌سازی deploy تولید: ست کردن Secrets تولید + الزام required reviewers روی environment `production` (الان protection ندارد).
3. چرخش دوره‌ای credentials (FTP/token) پس از هر دوره کاری.
