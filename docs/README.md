# VELORA — Engineering Operations Handbook

**نقطه ورود رسمی مستندات پروژه — قبل از هر اقدامی این سند خوانده شود.**

| Document Control | |
|---|---|
| شناسه سند | `VELORA-OPS-README` |
| نسخه | 1.1.0 |
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
| OC-6 | Runner گیت‌هاب به هاست دسترسی دارد؛ سندباکس چت ندارد | تست HTTP از چت ممکن است، انتقال فایل خیر |

## 7. وضعیت جاری و کارهای باز (Living Section)

**آخرین وضعیت (2026-08-17):**
- Staging مستقر و عملیاتی؛ کل Smoke Suite سبز؛ DB متصل.
- Production دست‌نخورده؛ pipeline تولید عمداً بدون Secrets.
- اسناد پایه ثبت و tag شده (`docs-baseline-v1`).

**Backlog (به ترتیب اولویت):**
| # | مورد | اولویت | وضعیت |
|:---:|---|:---:|---|
| B-1 | رفع یافته‌های P0: F-01, F-02 (هاردنینگ runtime/دامپ‌ها) و F-03, F-04 (checkout/محتوای EN) | P0 | ⏳ منتظر تأیید مالک |
| B-2 | رفع یافته‌های P1: F-05…F-14 | P1 | ⏳ |
| B-3 | رفع یافته‌های P2: F-15…F-20 | P2 | ⏳ |
| B-4 | فعال‌سازی کنترل‌شده deploy تولید: Secrets تولید + **Required Reviewers** روی environment `production` | — | ⏳ تصمیم مالک |
| B-5 | چرخش credentials پس از پایان دوره کاری جاری (FTP piknet، FTP staging، PAT) | 🔴 | ⏳ سمت مالک |
| B-6 | **Roadmap:** سند `Roadmap.pdf` نیازمند **ویرایش توسط مالک** است؛ تا پایان ویرایش و تأیید صریح، `docs/02_ROADMAP.md` ساخته نشود و Roadmap وارد مخزن (از جمله `docs/pdf/`) نشود — فعلاً فقط در حالت بررسی | — | ⏳ منتظر ویرایش مالک |

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
