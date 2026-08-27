# VELORA — Agent Operating Contract

این فایل قرارداد اجباری تمام Agentهایی است که روی مخزن `veloratrade/veloratrade` کار می‌کنند.
عبارت‌های **باید** و **ممنوع است** الزام قطعی هستند، نه پیشنهاد.

## 1. هدف

Agent باید برای فهم صحیح پروژه، اسناد و کد لازم را در Workspace بررسی کند؛ اما نباید محتوای کامل فایل‌ها، JSONها، Diffها یا Logها را داخل گفت‌وگو بازنشر کند.

**قاعده اصلی:**

> Read internally. Work directly in the repository. Report only concise conclusions and evidence.

## 2. پروتکل اجباری شروع جلسه

**گام صفرِ مطلق (پیش از هر واکشی دیگر):** Agent باید ابتدا فقط همین فایل `AGENTS.md` را به‌صورت تک‌فایل (raw/API) واکشی و کامل بخواند. Clone کامل مخزن — حتی «برای شروع» یا «برای فهم اولیه» — **ممنوع است** (بند ۱۳.۱). روش مجاز واکشی فقط: تک‌فایل یا `git sparse-checkout` محدود به فایل‌های مأموریت.

Agent باید به‌ترتیب:

1. همین فایل را کامل بخواند.
2. `docs/README.md` را برای فهم معماری، سیاست‌ها و تاریخچه عملیاتی بررسی کند.
3. `docs/SESSION_STATE.json` را برای مأموریت، تصمیم‌ها و قدم بعدی بررسی کند.
4. فقط metadata و بخش‌های لازم `docs/PROJECT_STATE.json` را بررسی کند؛ این فایل Snapshot است، نه منبع حقیقت.
5. وضعیت زنده را اجرا کند، ولی خروجی کامل را داخل چت نیاورد:

   ```bash
   bash tools/velora-status.sh > /tmp/velora-status.txt
   ```

6. فقط موارد لازم را از خروجی استخراج کند: `VELORA-RUN`، HEAD، sync/dirty، محیط‌ها، Actions، Drift، مأموریت فعال و قدم بعدی.
7. پیش از هر تغییر، برنامه کوتاه و دامنه فایل‌ها را اعلام و منتظر تأیید مالک بماند.

## 2.1 حالت Silent Bootstrap با عبارت «ایجنت بخون»

اگر پیام مالک شامل یکی از عبارت‌های زیر بود:

- `ایجنت بخون`
- `agent read`
- `پروژه رو بخون`
- `طبق AGENTS بخون`

Agent باید **Silent Bootstrap Mode** را اجرا کند.

### عملیات داخلی اجباری

در این حالت Agent باید بدون نمایش محتوا در چت:

1. `AGENTS.md` را کامل بخواند.
2. `docs/README.md` را کامل برای فهم معماری، قوانین و تاریخچه بررسی کند.
3. `docs/SESSION_STATE.json` را کامل بررسی کند.
4. `docs/PROJECT_STATE.json` را به‌عنوان Snapshot بررسی کند.
5. ساختار Git و فایل‌های اصلی پروژه را بررسی کند.
6. وضعیت زنده را با خروجی Redirectشده اجرا کند:

   ```bash
   bash tools/velora-status.sh > /tmp/velora-status.txt 2>&1
   ```

7. اعتبار HEAD، sync، working tree، محیط‌ها، Actions، Drift، مأموریت و قدم بعد را داخلی راستی‌آزمایی کند.
8. فقط کد اثبات اجرا را از فایل موقت استخراج کند.

### راستی‌آزمایی داخلی پیش از اعلام آمادگی (Bootstrap Self-Verification)

پیش از چاپ کد `VELORA-RUN-*`، Agent باید داخلی و صادقانه تأیید کند که **همه** بندهای زیر برقرارند. اگر حتی یک بند برقرار نیست، چاپ کد ممنوع است و Bootstrap ناقص اعلام می‌شود:

- [ ] `AGENTS.md` **کامل** خوانده شد — نه بخشی از آن.
- [ ] `docs/README.md` **کامل** خوانده شد — هر ۸ بخش، شامل §3 (ماتریس FTP)، §6 (درس‌ها)، §7 (وضعیت جاری و backlog) و §8 (BR-1…7).
- [ ] `docs/SESSION_STATE.json` کامل بررسی شد.
- [ ] `docs/PROJECT_STATE.json` (به‌عنوان Snapshot) بررسی شد.
- [ ] ردپای Workspace حداقلی است: هیچ clone کامل یا فایل حجیم غیرضروری وجود ندارد (بند ۱۳).

**قاعده:** کد `VELORA-RUN-*` گروگانِ خواندن کامل است. چاپ آن یعنی گواهی «همه را کامل خواندم و ردپای Workspace حداقلی است». چاپ کد با خواندن ناقص، ادعای بدون شاهد و نقض BR-6 است. «کافی بودنِ» خواندن، تفسیرپذیر نیست — کامل یعنی کامل (BR-1).

### خروجی مجاز Silent Bootstrap

اگر همه مراحل موفق بودند، پاسخ باید **دقیقاً کوتاه** باشد:

```text
VELORA-RUN-xxxxxxxx
پروژه، اسناد، وضعیت زنده و حافظه جلسه بررسی شد. آماده‌ام؛ منتظر دستور بعدی هستم.
```

در این حالت نمایش موارد زیر ممنوع است:

- خلاصه پروژه
- HEAD و Branch
- وضعیت Production یا Staging
- وضعیت Actions
- Active task یا Next action
- Drift
- فهرست فایل‌ها
- متن README یا JSON
- خروجی Script
- برنامه اقدام
- توصیه یا توضیح اضافه

این اطلاعات باید فقط در فهم داخلی Agent باقی بمانند تا مالک دستور بعدی را بدهد.

اگر Bootstrap شکست خورد، فقط این قالب مجاز است:

```text
Silent Bootstrap ناموفق بود: <یک دلیل کوتاه و پاک‌سازی‌شده>
```

Agent نباید برای توضیح خطا، Log یا فایل کامل را Paste کند.

پس از دریافت دستور بعدی مالک، Agent از Silent Mode خارج می‌شود و طبق سیاست خروجی مختصر این فایل عمل می‌کند.

## 2.2 آرشیو مقالات n8n (N8N Archive Agent)

اگر مأموریت مالک مربوط به هر یک از این موارد باشد:

- آرشیو مقالات n8n
- مقالات archived / approved از n8n
- انتشار مقاله از مسیر n8n به GitHub/سایت
- پردازش محتوای n8n → GitHub

Agent **باید** پیش از هر تغییر، فایل `docs/N8N_ARCHIVE_AGENT.md` را کامل بخواند و فقط طبق آن عمل کند.

قواعد غیرقابل‌مذاکره:

- منبع حقیقت تأیید مقاله **فقط n8n** است. Agent حق ندارد مقاله‌ای را approved اعلام کند.
- پردازش فقط وقتی مجاز است که در snapshot: `approval_status=approved` **و** `archive_status=archived`.
- در غیر این صورت: هیچ صفحهٔ سایتی نساز، هیچ PR مقاله‌ای باز نکن.
- Production را deploy نکن. PAT/JWT n8n را در Git یا چت نگذار.

## 2.3 سیاست اتصال n8n و اینستنس‌های یکبارمصرف (n8n Connection & Disposable Instance Policy)

اگر مأموریت مربوط به اتصال یا مهاجرت workflow یا Data Table بین اینستنس‌های n8n باشد، Agent باید `docs/N8N_INSTANCE_MIGRATION.md` را بخواند و فقط از `tools/n8n_migrate/` استفاده کند — نه از `tools/n8n_archive/`.

### 2.3.1 اتصال n8n

- اگر اتصال/integration واقعی (native) n8n در دسترس باشد، ترجیح با همان است.
- اگر اتصال native در دسترس نباشد ولی دسترسی معتبر API به n8n پشتیبانی شود، مالک می‌تواند در زمان اجرا یک n8n API key فراهم کند.
- API key صرفاً Secret زمان‌اجرا است.
- هرگز API key را در GitHub، فایل‌های مخزن، سورس، workflow JSON، مستندات، منیفست‌ها، fixtures، لاگ‌ها، کامیت‌ها یا گزارش‌ها ذخیره نکن.
- هرگز API key را چاپ یا افشا نکن.
- هرگز API key را داخل یک workflow n8n قرار نده.
- هرگز API key را commit یا push نکن.

### 2.3.2 اینستنس‌های یکبارمصرف n8n

مالک ممکن است اینستنس n8n را حذف و دوباره بسازد. بنابراین هرگز به‌صورت دائمی به اینها وابسته نباش:

- workflow IDs
- Data Table IDs
- credential IDs
- webhook IDs
- هر شناسه‌ی خاصِ اینستنس

منابع جاری باید پس از اتصال به اینستنس جدید، به‌صورت داینامیک کشف شوند.

### 2.3.3 آنچه در GitHub می‌ماند

فقط اطلاعات غیر-Secure قابل persist است:

- سیاست مهاجرت، ابزار مهاجرت، مستندات
- تعریف‌های workflow امن
- تعریف‌های Data Table امن
- schemaها
- نام منابع
- متادیتای مهاجرتِ بدون secret

هرگز persist نشود:

- n8n API key
- credential secrets
- OAuth tokens
- Telegram bot tokens
- OpenAI API keys
- Google OAuth secrets
- private keys
- webhook secrets

### 2.3.4 سیاست مهاجرت (SOURCE → TARGET)

وقتی مالک صریحاً مهاجرت را مجاز کرد:

**قابل انتقال:**

- تعریف workflow
- nodeها
- connectionها
- تنظیمات امن workflow
- تعریف Data Table
- schemaهای Data Table
- rows/data تنها با مجوز صریح

**هرگز قابل انتقال نیست:**

- credential secret values
- OAuth tokens/secrets
- API keys
- Telegram bot tokens
- OpenAI keys
- Google OAuth secrets
- هر داده‌ی خصوصی credential

نام/نوع credential فقط برای تعیین اینکه کدام credential باید دستی ساخته شود بازرسی می‌شود.

### 2.3.5 ایمنی

رفتار پیش‌فرض READ-ONLY است.

پیش از مهاجرت:

- SOURCE را بازرسی کن
- TARGET را بازرسی کن
- workflowها را مقایسه کن
- Data Tableها و schemaها را مقایسه کن
- IDهای جاری را کشف کن
- credentialهای لازم را با name/type شناسایی کن
- conflict و blocker را تشخیص بده
- گزارش مهاجرت تولید کن
- تا مجوز صریح مالک چیزی را اعمال نکن

هنگام مهاجرت:

- هرگز credential secrets را کپی نکن
- هرگز workflowها را خودکار activate نکن
- هرگز خودکار publish نکن
- هرگز خودکار execute نکن
- هرگز مالکیت Telegram webhook را خودکار تغییر نده
- workflowهای مهاجرت‌شده را تا مجوز صریح غیرفعال نگه دار
- روی schema mismatch توقف کن
- روی تطبیق مبهمِ منابع توقف کن
- هرگز برای ID قدیمی جایگزینِ حدسی نگذار

### 2.3.6 بازسازی n8n

وقتی مالک اینستنس جدید ساخت، ورودی‌های دستیِ مورد انتظار:

- URL اینستنس جدید n8n
- n8n API key جدید (اگر احراز API-key استفاده می‌شود)
- credential secrets که مالک داخل UI جدید n8n دستی می‌سازد

پس از دریافت اطلاعات اتصال جدید، Agent باید دوباره کشف کند:

- workflowها
- Data Tableها
- نام/نوع credentialها
- IDهای جاری
- capabilities

هرگز فرض نکن IDهای اینستنسِ حذف‌شده همچنان معتبرند.

### 2.3.7 مدیریت Secret

مالک ممکن است برای احراز زمان‌اجرا یک API key فراهم کند. Agent فقط از طریق مکانیزم امن runtimeِ پشتیبانی‌شده استفاده کند و هرگز آن را:

- در مخزن ذخیره نکند
- در GitHub ذخیره نکند
- در workflow JSON ذخیره نکند
- در منیفست مهاجرت وارد نکند
- در لاگ‌ها وارد نکند
- در test fixtures وارد نکند
- در پاسخ چاپ نکند

### 2.3.8 بدون مهاجرت خودکار credential

credential secrets همیشه توسط مالک روی اینستنس جدید n8n به‌صورت دستی ساخته می‌شود.

Agent فقط مجاز است گزارش دهد:

- نام credential
- نوع credential
- وجود یا عدم وجود روی TARGET

Agent هرگز نباید secret را استخراج یا مهاجرت کند.

## 3. سیاست فهم داخلی فایل‌ها

Agent مجاز و موظف است فایل‌های لازم را برای فهم پروژه بخواند. برای کنترل Context باید:

- ابتدا نقشه پروژه را با `find`، `git ls-files` و `grep` بسازد.
- اسناد بزرگ را در بخش‌های منطقی و مرتبط بررسی کند.
- پس از فهم اولیه، فقط فایل‌ها و بخش‌های مرتبط با مأموریت را دوباره بخواند.
- برای پیدا کردن وابستگی‌ها از `grep -RIn` استفاده کند.
- پیش از rename یا حذف، تمام referenceها را جست‌وجو کند.
- داده زنده Git/GitHub را بر ادعاهای فنی Snapshot قدیمی مقدم بداند.
- پیش از هر نتیجه‌گیریِ «قابل‌تعمیر نیست / سورس موجود نیست»، مکانِ دقیق را از `docs/03_PROJECT_STRUCTURE_BASELINE.md` (§3 و §7) بیاب و وجودِ فیزیکیِ فایل را راستی‌آزمایی کن. نبودِ فایل‌هایِ framework (مثل `package.json`/`.tsx`) برایِ پروژهٔ بدون-bundler دلیلِ «نبودِ سورس» نیست (OC-14/BR-9).

خواندن داخلی به معنی اجازه نمایش محتوا در چت نیست.

## 4. سیاست سخت‌گیرانه خروجی چت

### 4.1 مواردی که نمایش کامل آن‌ها ممنوع است

Agent نباید در commentary یا پاسخ نهایی این موارد را کامل Paste کند:

- `docs/README.md`
- `docs/SESSION_STATE.json`
- `docs/PROJECT_STATE.json`
- فایل‌های Source
- فایل‌های Workflow
- HTML/CSS/JavaScript/PHP کامل
- `git diff` کامل
- Log کامل Workflow یا Server
- خروجی کامل تست‌ها
- فهرست کامل Tree مخزن
- محتوای env یا Secret
- پاسخ خام API شامل داده غیرضروری

### 4.2 موارد مجاز در گزارش

Agent فقط باید این اطلاعات را گزارش کند:

- کد `VELORA-RUN-*`
- Branch و HEAD کوتاه
- وضعیت clean/sync
- وضعیت خلاصه Production و Staging
- وضعیت GitHub Actions
- مأموریت فعال و مانع
- Drift یا تعارض مهم
- برنامه اقدام کوتاه
- مسیر فایل‌های تغییرکرده
- نتیجه تست‌ها به‌صورت PASS/FAIL و شمارش
- Commit hash، Run ID و HTTP status لازم
- قدم بعدی و موارد نیازمند تأیید

### 4.3 سقف خروجی

مگر کاربر صریحاً جزئیات بخواهد:

- گزارش شروع جلسه: حداکثر 25 خط.
- گزارش میانی: حداکثر 3 جمله.
- Log خطا: حداکثر 30 خط Sanitized و فقط خطوط مرتبط.
- Code snippet: حداکثر 20 خط.
- Final report: خلاصه، ساختاریافته و بدون تکرار تاریخچه کامل.

### 4.4 استثنا

نمایش فایل یا Diff کامل فقط وقتی مجاز است که کاربر صریحاً همان فایل یا Diff کامل را درخواست کند و فایل Secret/PII نداشته باشد.

## 5. قواعد استفاده از ابزار برای جلوگیری از Context اضافی

Agent باید خروجی پرحجم را به فایل موقت هدایت کند و فقط Summary را چاپ کند.

### درست

```bash
bash tools/velora-status.sh > /tmp/status.txt
grep -E 'VELORA-RUN|کامیت|working tree|Production|Staging|نتیجه check' /tmp/status.txt

some-test-command > /tmp/test.log 2>&1
printf 'exit=%s\n' "$?"
tail -20 /tmp/test.log

git diff --stat
git diff --check
git status --short
```

### ممنوع

```bash
cat docs/README.md
cat docs/PROJECT_STATE.json
cat large.log
git diff
find . -type f
```

مگر اینکه خروجی کوچک، هدفمند و برای مأموریت ضروری باشد.

## 6. فرمت اولین پاسخ معتبر

> استثنا: اگر عبارت «ایجنت بخون» یا یکی از Triggerهای بخش 2.1 استفاده شد، فرمت Silent Bootstrap بر این بخش مقدم است.

در حالت عادی، اولین گزارش Agent باید فقط شامل این قالب باشد:

```text
VELORA-RUN-xxxxxxxx
HEAD: <short-sha> | branch: <branch> | clean/sync: <status>
Production: <summary>
Staging: <summary>
Actions: <enabled/disabled>
Active task: <id/status>
Drift: <none or concise list>
Exact next action: <one sentence>
Approval required: <yes/no and scope>
```

Agent نباید در اولین پاسخ تاریخچه طولانی پروژه را تعریف کند.

## 7. سیاست گزارش تغییرات

پس از کار، گزارش فقط باید شامل موارد زیر باشد:

```text
CHANGED: paths
ADDED: paths
DELETED: paths
TESTS: command/result summary
COMMIT: hash or none
PUSH: yes/no
WORKFLOW: name + Run ID or none
STAGING ACTION: concise summary or none
PRODUCTION ACTION: concise summary or none
REMAINING: concise list
```

Diff کامل، محتوای کامل فایل و Log خام نباید تکرار شوند.

## 8. امنیت و Secret

- Secret واقعی در Git، فایل، Artifact، Log یا چت ممنوع است.
- Agent نباید رمز، API key، env کامل، Cookie، 2FA یا Recovery Code درخواست کند.
- فقط نام Secretها قابل گزارش است، نه مقدار آن‌ها.
- خروجی خطا باید قبل از گزارش Sanitized شود.
- توکنِ ارسال‌شده در چت تا پایانِ پروژهٔ جاری معتبر و قابلِ استفادهٔ مجدد برای همهٔ عملیات‌های همان پروژه است؛ ادمین نیازی به ارسالِ مجدد ندارد (تصمیمِ مالک). در پایانِ پروژه rotate/revoke می‌شود. توکنِ داخلِ چت افشاشده فرض می‌شود ⟹ دامنهٔ آن باید حداقلی باشد (فقط همین مخزن).
- Repository عمومی است؛ PII، dump، runtime data و credential نباید وارد Commit شوند.

## 9. محیط‌ها و مجوز عملیات

- خواندن و تحلیل: مجاز.
- تغییر فایل: فقط پس از تأیید مالک.
- Commit و Push: فقط پس از تأیید مالک.
- اجرای Workflow: فقط پس از تأیید مالک.
- Staging write/deploy: فقط با تأیید صریح همان عملیات.
- Production write/deploy/config/database/test: فقط با تأیید صریح و موردی مالک.
- تأیید یک عملیات، مجوز عملیات بعدی نیست.

## 10. سیاست هزینه GitHub

- GitHub Actions فعلاً باید غیرفعال بماند مگر مالک صریحاً فعال‌سازی موقت را تأیید کند.
- قبل از فعال‌سازی، `python tools/check_github_cost_guard.py` باید PASS شود.
- فقط Standard Ubuntu Runner مجاز است.
- Larger runner، macOS، Windows، self-hosted، schedule، cache و package publishing بدون تأیید هزینه ممنوع‌اند.
- Actions پس از عملیات تأییدشده دوباره غیرفعال شود.
- اجرای فعال یا queued پس از پایان باید صفر باشد.

## 11. منابع حقیقت

ترتیب اعتبار:

1. دستور صریح فعلی مالک
2. `docs/README.md` و اسناد P1/P2
3. وضعیت زنده Git/GitHub و شواهد اجرایی
4. `docs/SESSION_STATE.json`
5. `docs/PROJECT_STATE.json` به‌عنوان Snapshot

در تعارض، Agent باید تعارض را کوتاه گزارش کند و حق تفسیر خودسرانه ندارد.

## 12. اصل نهایی

Agent باید پروژه را عمیق بفهمد، اما گفت‌وگو را سبک نگه دارد:

> Full understanding in Workspace; minimal evidence-based reporting in chat.

## 13. سیاست حجم Workspace و واکشی فایل (Minimal Footprint)

هدف این بخش جلوگیری از انباشت حجم غیرضروری در Workspace است. عبارت‌های **باید** و **ممنوع است** الزام قطعی هستند.

### 13.1 ممنوعیت Clone کامل

- Clone کامل مخزن در Workspace **ممنوع است**، مگر با تأیید صریح و موردی مالک.
- Agent نباید کل درخت مخزن یا Assetهای حجیم (مثل `public/assets/tesseract-data`، تصاویر نمونه، فایل‌های باینری بزرگ) را بدون نیاز مأموریت وارد Workspace کند.
- **این ممنوعیت از اولین لحظه جلسه برقرار است** — «هنوز AGENTS.md را نخوانده بودم» عذر نیست؛ ترتیب اجباری در §2 (گام صفرِ مطلق) همین را تضمین می‌کند: اول AGENTS.md تک‌فایل، بعد هر واکشی دیگر.
- اگر Clone کامل به هر دلیلی اتفاق افتاد (سهو، ابزار، عادت)، Agent موظف است **در همان لحظهٔ کشف** آن را حذف و نقض را کوتاه گزارش کند — نه در پایان جلسه.
- گارد خودکار: `tools/velora-status.sh` کلونِ کامل/عمیق و نشتِ credential را **مستقل از پرچمِ sparse** می‌گیرد (`FULL-CLONE-VIOLATION`/`CREDENTIAL-IN-CONFIG`)، در صورت نقض کدِ `VELORA-RUN` را چاپ نمی‌کند و `--check` را قرمز می‌کند (درس OC-13).

### 13.2 واکشی محدود به مأموریت

- Agent باید فقط فایل‌هایی را که برای مأموریت فعلی لازم‌اند واکشی کند، نه بیشتر.
- ابتدا باید با جست‌وجو (grep/GitHub API) فایل‌های درگیر را شناسایی کند، سپس فقط همان‌ها را بیاورد.
- برای واکشی سبک، در صورت نیاز به قابلیت commit/push، باید از حالت سبک Git استفاده شود:

  ```bash
  git clone --depth 1 --filter=blob:none --sparse <url>
  git sparse-checkout set <only/needed/paths>
  ```

- برای صرفاً خواندن یک فایل، واکشی تک‌فایل از طریق API مجاز و ترجیح داده می‌شود.

### 13.3 پاک‌سازی اجباری پس از اتمام

- پس از پایان مأموریت و اعمال/تأیید تغییرات (commit/push)، Agent باید فایل‌ها و پوشه‌های موقتِ واکشی‌شده را از Workspace حذف کند.
- در پایان هر جلسه، Workspace باید تا حد ممکن سبک بماند.

### 13.4 گردش کار استاندارد تغییر

ترتیب استاندارد برای هر درخواست تغییر:

1. مالک تغییر موردنظر را مشخص می‌کند.
2. Agent فایل‌های مرتبط را شناسایی و فقط همان‌ها را به‌صورت سبک واکشی می‌کند.
3. تغییر اعمال و گزارش مختصر ارائه می‌شود (بدون بازنشر محتوای کامل).
4. commit/push فقط پس از تأیید صریح مالک.
5. پس از اتمام، فایل‌های موقت پاک می‌شوند.

### 13.5 سقف حجم و شفافیت

- اگر واکشی لازم از یک آستانه معقول (مثلاً چند مگابایت) عبور کند، Agent باید پیش از اقدام حجم تقریبی را کوتاه گزارش و تأیید بگیرد.
- ترجیح همیشه بر کمترین ردپای ممکن در Workspace است.
