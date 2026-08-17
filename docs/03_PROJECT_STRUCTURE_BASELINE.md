# VELORA — PROJECT STRUCTURE MAP & PATH AUDIT

**Verified English Source-of-Truth Baseline**

> Source: `Structure.pdf` (SHA-256: `9109848b7a8c2260ec39ecdfa97564e951a2e9fc6e1611dba3132520f9e185ec`) — transcribed without rewriting; all 48 tables converted to Markdown. Line-wrap artifacts of PDF extraction (mid-word breaks inside table cells) were rejoined; no content was altered. Nothing from the baseline was removed.
>
> Tables that the PDF splits across page boundaries (e.g., a finding card continuing on the next page, or the route summary continuing from page 5 to page 6) are merged into single Markdown tables; the 48-table count of the source is preserved logically.

AS-IS VERIFIED BASELINE • READ-ONLY • APPROVAL REQUIRED BEFORE CHANGE

| | |
|---|---|
| Audit date | 2026-08-14 |
| Audited root | /home/user/404_extracted/ |
| Scope | 630 files • 159 directories • approximately 29 MB |
| Actual architecture | Static/generated HTML + Vanilla JS/CSS + PHP locale router + custom PHP/PDO API |
| Change status | No application file was moved, renamed, deleted, refactored, or remediated. |
| Document status | Structural source of truth for the audited baseline; all findings remain open. |

CONFIDENTIALITY: .env values, database records, logs, credentials, and personal data are not disclosed. Sensitive evidence is limited to paths, permissions, hashes, and masked counts.

## Document Control and Audit Method

This document records the verified state of the repository. Review it before any feature, refactor, file move, routing/API/localization/build change, or deployment. Every structural change requires approval, the smallest safe patch, staging validation, and an updated structure map.

| | |
|---|---|
| Original archive SHA-256 | d1a680274cf0087ae1b6a1b78c164395fa5df4c4553296d501336cc47d844d5a |
| Previous Production SHA-256 | 4dc741aac7fde68d3124d6d6b7b1e0b8bfefaf2d7885b679874e6ea532e1c615 |
| Current Production SHA-256 | 2550236584a55523ebefd02465c1750c8da721a2fda9eb741f94f303614fee7a |
| Methods | Filesystem walk; SHA-256; route/import/reference parsing; JSON validation; localization contract checks; ZIP diff; case-fold and pixel comparison. |
| Execution limit | PHP, MySQL, MariaDB, SQLite CLI, and Docker were unavailable in the sandbox; live backend/database tests must run in staging. |
| Safety rule | Non-destructive tests; masked evidence; no deletion; preserve Production .env and runtime data. |

### Quantitative Summary

| Metric | Verified value |
|---|---|
| Files | 630 |
| Directories below root | 159 |
| Files in current Production ZIP | 451 |
| Working-tree exclusions | 179 |
| Source routes | 28 |
| Generated locale outputs | 59 / 59 present |
| API endpoints | 35 registered + GET /health |
| Exact duplicate groups | 36 groups / 91 files |
| Near-duplicate triage | 117 text pairs / 13 perceptual image pairs |
| Findings | 0 CRITICAL • 4 HIGH • 10 MEDIUM • 6 LOW |
| Version comparison | 36 changed • 9 added • 0 removed • 0 moved • 406 unchanged |

## 1. Executive Result

The active frontend is generated/static HTML with browser-native JavaScript and CSS. There is no active Next.js, React, Vue, Composer, npm, or bundler application. The backend is a custom PHP API with lightweight PSR-4-style autoloading, an internal Router, PDO repositories, cron workers, and optional OCR system dependencies.

### Verified Healthy Controls

- 26 static PHP include/require targets checked: 0 missing.
- 96 HTML files and 2,645 direct asset references checked: 0 direct targets missing.
- 21 browser JavaScript/related source files and 13 relative dependencies checked: 0 missing.
- 54 PHP source files and 53 namespaced symbols: 0 namespace/path mismatch and no use-graph cycle found.
- All 35 registered API routes resolve to existing handler classes and methods; /health is handled before dispatch.
- 28 templates and all 59 expected generated outputs exist; 40 locale JSON files are valid and 34 feature chunk hashes/sizes match.
- The 451-file Production ZIP excludes .env, runtime storage, dumps, tools, source templates, legacy Next artifacts, and backup assets.
- Screenshot extraction uses permission-restricted temporary files and finally cleanup; no persistent upload directory exists.

### Risk Posture

No CRITICAL issue was verified. Four HIGH findings concern source-tree data/runtime hygiene, checkout localization, and Persian copy in English outputs. All 20 findings remain OPEN; no remediation was applied.

## 2. Architecture and Path Map

| Layer | Verified flow |
|---|---|
| Browser | Generated HTML first paint → shared CSS/JS → locale chunks → API fetch |
| Page routing | Apache/LiteSpeed .htaccess → locale-router.php → localized/{locale}/{route} |
| Localization build | routes.json + catalogs/maps → Python sync/build → 59 HTML outputs + 34 chunks |
| API | /api/* or /health → api/index.php → Router → middleware → controller |
| Data | Controllers → services → repositories → PDO → MySQL/MariaDB or local SQLite |
| Workers | Cron → MetaApi sync / dynamic translation; OCR → Tesseract + Python helper |
| Runtime | api/.env + api/storage; persistent screenshot uploads: none |
| Deployment | Explicit 451-file package → cPanel public_html; host .env/data preserved |

### Page Route Contract

- An explicit /fa/... or /en/... prefix is authoritative.
- For unprefixed URLs, locale-router checks velora_locale, then primary Accept-Language, then default=fa; unsupported languages fall back to en.
- Only generated localized artifacts are needed for Production first paint; source templates are excluded from the current Production ZIP.
- API, health, public assets, and checkout are outside page-language negotiation.
- Generated canonical/hreflang URLs use explicit locale paths, but sitemap/navigation/robots still drift from that contract.

### Import and Path Contract

- Browser code uses global namespaces and script ordering; no @/ alias or bundler resolution exists.
- PHP autoload maps only Velora\ to api/src/{Namespace}.php; Composer is not used.
- Static browser paths are predominantly web-root absolute; source-relative paths serve build tooling.
- Only one case-fold collision exists: MANIFEST.json versus manifest.json.

## 3. Frontend, Localization, and Design-System Map

| Area | Actual location / role |
|---|---|
| Pages | index.html plus 27 route templates; 28 source routes |
| Components | Footer, dialog, product gallery, sidebar icons, smart import, symbol picker, locale switcher |
| Layouts | Navigation/sidebar/shell are mostly embedded in templates; no framework layout component |
| Hooks | No React/Vue hooks; DOM events, IIFEs, and helper functions |
| Utilities | velora-data.js, localization.js, latin-digits.js, dynamic-content.js, symbol-icons.js |
| Styles | Inline page CSS plus 16 shared CSS files; no CSS build/bundle pipeline |
| Assets | public/assets: logos, backgrounds, symbols, flags, OCR data |
| Generated | localized/fa and localized/en; builder-owned, never manually edited |

### Localization Flow

```
public/locales/manifest.json → tools/localization/sync_registry.py → public/assets/velora-locale-registry.js
catalogs + feature maps + routes.json → build_localized_static.py → localized/{fa,en}/**
.htaccess → locale-router.php → generated artifact + Content-Language / Vary / ETag
velora-locale-bootstrap.js resolves lang/dir before paint; velora-localization.js loads chunks and supplies t()/Intl/switching
API dynamic translation uses public cache-only lookup; the provider is invoked only from the CLI worker
```

### Frontend Route Summary

| Source template | FA output | EN preferred output | Alias / note |
|---|---|---|---|
| index.html | localized/fa/index.html | localized/en/index.html | — |
| 404.html | localized/fa/404.html | localized/en/404.html | — |
| 404/index.html | localized/fa/404/index.html | localized/en/404/index.html | — |
| accounts/connect/index.html | localized/fa/accounts/connect/index.html | localized/en/accounts/connect/index.html | — |
| admin/index.html | localized/fa/admin/index.html | localized/en/admin/index.html | — |
| dashboard/index.html | localized/fa/dashboard/index.html | localized/en/dashboard/index.html | — |
| forgot-password/index.html | localized/fa/forgot-password/index.html | localized/en/forgot-password/index.html | — |
| intelligence/index.html | localized/fa/intelligence/index.html | localized/en/intelligence/index.html | — |
| login/index.html | localized/fa/login/index.html | localized/en/login/index.html | — |
| markets/index.html | localized/fa/markets/index.html | localized/en/markets/index.html | — |
| news/index.html | localized/fa/news/index.html | localized/en/news/index.html | — |
| performance/index.html | localized/fa/performance/index.html | localized/en/performance/index.html | — |
| privacy/index.html | localized/fa/privacy/index.html | localized/en/privacy/index.html | — |
| profile/index.html | localized/fa/profile/index.html | localized/en/profile/index.html | — |
| register/index.html | localized/fa/register/index.html | localized/en/register/index.html | — |
| reset-password/index.html | localized/fa/reset-password/index.html | localized/en/reset-password/index.html | — |
| support/index.html | localized/fa/support/index.html | localized/en/support/index.html | — |
| terms/index.html | localized/fa/terms/index.html | localized/en/terms/index.html | — |
| trades/index.html | localized/fa/trades/index.html | localized/en/trades/index.html | — |
| trades/new/index.html | localized/fa/trades/new/index.html | localized/en/trades/new/index.html | — |
| verify-email/index.html | localized/fa/verify-email/index.html | localized/en/verify-email/index.html | — |
| wallet/index.html | localized/fa/wallet/index.html | localized/en/wallet/index.html | — |
| blog/index.html | localized/fa/blog/index.html | localized/en/blog/index.html | — |
| blog/what-is-trading-journal/index.html | localized/fa/blog/what-is-trading-journal/index.html | localized/en/blog/what-is-a-trading-journal/index.html | English legacy alias: localized/en/blog/what-is-trading-journal/index.html |
| blog/forex-trading-journal/index.html | localized/fa/blog/forex-trading-journal/index.html | localized/en/blog/forex-trading-journal/index.html | — |
| blog/why-traders-need-journal/index.html | localized/fa/blog/why-traders-need-journal/index.html | localized/en/blog/why-traders-need-a-journal/index.html | English legacy alias: localized/en/blog/why-traders-need-journal/index.html |
| blog/risk-management-trading/index.html | localized/fa/blog/risk-management-trading/index.html | localized/en/blog/risk-management-in-trading/index.html | English legacy alias: localized/en/blog/risk-management-trading/index.html |
| blog/mt4-mt5-trading-journal/index.html | localized/fa/blog/mt4-mt5-trading-journal/index.html | localized/en/blog/mt4-mt5-trading-journal/index.html | — |

## 4. Backend, API, and Middleware Map

The backend uses no external application framework. api/src/bootstrap.php provides custom autoloading and exception handling; Core/Router.php and Core/Request/Response.php define the HTTP contract. PHP 8.1+ is required by syntax and runtime functions.

### Response Envelope Contract

> **Amendment 2026-08-17 (B-8) — added after the baseline audit.** This contract
> was implicit in the code and recorded in no document. A health probe asserted
> against the wrong field and reported a false failure while staging was healthy.

All API responses are wrapped in a fixed envelope:

```json
{ "status": "success" | "error",
  "data":   { ... } | null,
  "error":  null | { ... },
  "timestamp": "ISO-8601" }
```

| Rule | Detail |
|---|---|
| Payload location | Always under `data` — never at the top level |
| Top-level `status` | Transport-level outcome (`success` / `error`), **not** domain state |
| Health example | `GET /health` → `{"status":"success","data":{"status":"ok","time":"…"}}` — liveness is `data.status`, not `status` |
| Consumer rule | Any probe, client, or test must assert `status == "success"` **and** the specific field inside `data` |

Verified live on staging 2026-08-17. Automated assertion lives in
`.github/workflows/healthcheck-suite.yml` and follows this contract.

### Registered Routes, Handlers, and Access

Every handler below was verified for class-file and method existence. Protected routes use AuthMiddleware; admin uses adminOnly; the public MetaApi webhook performs HMAC verification inside the service.

| Method + path | Handler | Access / permission | Status / limit |
|---|---|---|---|
| GET /health | Inline response | Public | Handled before Router dispatch |
| POST /api/v1/auth/register | Velora\Auth\AuthController::register | Public | Handler verified; rate 5 / 3600 s |
| POST /api/v1/auth/verify-email | Velora\Auth\AuthController::verifyEmail | Public | Handler verified; rate 20 / 900 s |
| POST /api/v1/auth/resend-verification | Velora\Auth\AuthController::resendVerification | Public | Handler verified; rate 4 / 3600 s |
| POST /api/v1/auth/resend-verification-email | Velora\Auth\AuthController::resendVerification | Public | Handler verified; rate 4 / 3600 s |
| POST /api/v1/auth/login | Velora\Auth\AuthController::login | Public | Handler verified; rate 8 / 300 s |
| POST /api/v1/auth/refresh | Velora\Auth\AuthController::refresh | Public | Handler verified; rate 30 / 300 s |
| POST /api/v1/auth/logout | Velora\Auth\AuthController::logout | Public | Handler verified |
| POST /api/v1/auth/forgot-password | Velora\Auth\AuthController::forgotPassword | Public | Handler verified; rate 4 / 3600 s |
| POST /api/v1/auth/reset-password | Velora\Auth\AuthController::resetPassword | Public | Handler verified; rate 6 / 3600 s |
| POST /api/v1/content-translations/lookup | Velora\Core\Locale\ContentTranslationController::lookup | Public | Handler verified |
| GET /api/v1/auth/me | Velora\Auth\AuthController::me | JWT authenticated | Handler verified |
| POST /api/v1/auth/change-password | Velora\Auth\AuthController::changePassword | JWT authenticated | Handler verified; rate 8 / 900 s |
| GET /api/v1/trades | Velora\Trades\TradeController::index | JWT authenticated | Handler verified |
| POST /api/v1/trades | Velora\Trades\TradeController::store | JWT authenticated | Handler verified |
| GET /api/v1/trades/symbols | Velora\Trades\TradeController::symbols | JWT authenticated | Handler verified |
| POST /api/v1/trades/extract-screenshot | Velora\Trades\ScreenshotExtractController::extract | JWT authenticated | Handler verified; rate 8 / user / 300 s |
| GET /api/v1/trades/{id}/exits | Velora\Trades\TradeExitController::index | JWT authenticated | Handler verified |
| POST /api/v1/trades/{id}/exits | Velora\Trades\TradeExitController::store | JWT authenticated | Handler verified |
| DELETE /api/v1/trades/exits/{exitId} | Velora\Trades\TradeExitController::destroy | JWT authenticated | Handler verified |
| GET /api/v1/trades/{id} | Velora\Trades\TradeController::show | JWT authenticated | Handler verified |
| PUT /api/v1/trades/{id} | Velora\Trades\TradeController::update | JWT authenticated | Handler verified |
| DELETE /api/v1/trades/{id} | Velora\Trades\TradeController::destroy | JWT authenticated | Handler verified |
| GET /api/v1/accounts | Velora\Accounts\AccountController::index | JWT authenticated | Handler verified |
| POST /api/v1/accounts | Velora\Accounts\AccountController::store | JWT authenticated | Handler verified |
| POST /api/v1/accounts/detect-server | Velora\Accounts\AccountController::detectServer | JWT authenticated | Handler verified |
| POST /api/v1/accounts/connect-metaapi | Velora\Accounts\AccountController::connectMetaApi | JWT authenticated | Handler verified |
| POST /api/v1/accounts/{id}/sync | Velora\Accounts\AccountController::sync | JWT authenticated | Handler verified |
| GET /api/v1/accounts/{id}/sync-status | Velora\Accounts\AccountController::syncStatus | JWT authenticated | Handler verified |
| DELETE /api/v1/accounts/{id} | Velora\Accounts\AccountController::destroy | JWT authenticated | Handler verified |
| POST /api/v1/webhooks/metaapi | Velora\Webhooks\MetaApiWebhookController::handle | Public transport; HMAC verified in service | Handler verified |
| GET /api/v1/webhooks/metaapi/test | Velora\Webhooks\MetaApiWebhookController::test | Development only; Production 404 | Handler verified |
| GET /api/v1/dashboard/summary | Velora\Dashboard\DashboardController::summary | JWT authenticated | Handler verified |
| GET /api/v1/dashboard/equity-curve | Velora\Dashboard\DashboardController::equityCurve | JWT authenticated | Handler verified |
| GET /api/v1/dashboard/strategies | Velora\Dashboard\DashboardController::strategies | JWT authenticated | Handler verified |
| GET /api/v1/admin/users | Velora\Admin\AdminController::users | JWT + admin role | Handler verified |

### Dependency Flow

- AuthController → AuthService → user/session/reset/verification repositories → Database, Mailer, Jwt.
- AccountController → MetaApiService + account/sync/webhook repositories → Crypto, HTTP, PDO.
- Trade/Exit/Screenshot controllers → TradeService/PnlCalculator/repositories/OCR → PDO and optional system tools.
- DashboardController → MetricsService → trades/accounts data.
- AdminController → JWT authentication + admin role → users table.

## 5. Database, Storage, Upload, and OCR Map

### Canonical Schema

| Table | Role | Active reference |
|---|---|---|
| users | Identity and roles | YES |
| user_sessions | Refresh/access sessions | YES |
| password_resets | Password-reset tokens | YES |
| email_verifications | Email verification | YES |
| email_notifications | Email notification queue | YES |
| email_preferences | Email preferences | YES |
| user_achievements | Achievements | YES |
| user_devices | Device/fingerprint records | YES |
| rate_limits | Rate-limit buckets | YES |
| trading_accounts | Trading/MetaApi accounts | YES |
| trades | Trades | YES |
| trade_exits | Partial/full exits | YES |
| sync_jobs | MetaApi sync queue | YES |
| webhook_events | Webhook idempotency/log | YES |
| content_translation_cache | Dynamic translation cache | YES |
| content_translation_jobs | Dynamic translation queue | YES |

All 16 canonical schema tables have at least one active PHP reference. Historical tables such as trade_screenshots, tags, and trade_features occur only in old dumps and are not used by the current screenshot controller or canonical schema.

### Migrations and Installer

- api/install.php imports only api/database/schema.sql; Production .htaccess denies it and the current release excludes it.
- add_language_support.sql, v0.2_metaapi_bridge.sql, and v0.3_trade_financial_consistency.sql are manual operational migrations; no automatic migration runner exists.
- init-sqlite.php creates a smaller local/test schema and demo seed; it is denied and release-excluded.
- v0.2 requires a dialect decision and staged MySQL/MariaDB validation (F-13).

### Screenshot Flow

```
Browser data URLs (max 4 images; 8 MiB each; 16 MiB total) → authenticated extraction endpoint with a per-user rate limit
Controller validates MIME/content/dimensions → temp file → chmod 0600 → OCR tools → finally unlink
First temp image → python3 read_mt5_times.py → Pillow/NumPy parsing → finally unlink
Server OCR unavailable → HTTP 501 → browser Tesseract.js 5.1.1 with SRI loader and local eng/fas traineddata
Persistent screenshot upload directory: NONE; trade_screenshots appears only in historical SQL dumps
```

## 6. Configuration, Build, Deployment, and cPanel

| Component | Verified assumption / path |
|---|---|
| Document root | /home/[cpanel-user]/public_html/ — account-specific examples must not be authoritative |
| Web server | Apache-compatible LiteSpeed, rewrite, AllowOverride, headers, directory indexing disabled |
| Page runtime | PHP 8.1+, locale-router.php, read access to localized and public/locales |
| API runtime | PHP 8.1+, PDO MySQL, OpenSSL, cURL/mail, JSON; GD/proc_open/Tesseract for server OCR |
| Workers | Cron PHP workers; Python 3 + Pillow + NumPy for timestamp OCR |
| Writable state | api/storage at runtime; preferably outside the document root; 0700 directory and 0600 files |
| Secrets | api/.env or host environment; a release must never overwrite Production values |
| Build host | Python localization tools; source templates need not ship to Production |
| Remote runtime | Google Fonts; pinned jsDelivr resources for browser OCR fallback |
| Deployment unit | Current 451-file ZIP; incremental by default; server state preserved |

### Working Tree vs Production Boundary

| Status | Count | Meaning |
|---|---|---|
| IN TREE + PACKAGE | 451 | Required Production runtime files |
| IN TREE, NOT PACKAGE | 179 | Sources, tools, docs, secrets/runtime, dumps, backups, and legacy artifacts |
| PACKAGE, NOT TREE | 0 | No fabricated or package-only path |

### Approved Release Gate Sequence

```
python3 tools/localization/normalize_brand.py
python3 tools/localization/sync_registry.py
python3 tools/localization/build_localized_static.py
python3 tools/localization/validate_localization.py
Create an incremental allowlisted package; preserve server api/.env and runtime data; report CHANGED / ADDED / DELETED / UNCHANGED / SERVER ACTION.
```

The current validator exits with code 1. An older LOCALIZATION_VALIDATION_OK statement in deployment documentation must not be treated as current release evidence.

## 7. File Inventory (Path / Role / Status)

| Path / group | Type | Role | Used by / dependency | Status |
|---|---|---|---|---|
| .htaccess | Web-server config | Production rewrites, locale routing, security, cache, CSP | Apache/LiteSpeed | ACTIVE; checkout exception |
| locale-router.php | PHP front controller | Locale negotiation and generated HTML serving | .htaccess, locale manifest, localized/ | ACTIVE |
| router.php | Development router | Local no-cache/live reload and API delegation | PHP built-in server | DEV ONLY; blocked in Production |
| tools/localization/routes.json | Route registry | 28 source templates and aliases | Builder, tests, audit | AUTHORITATIVE BUILD ROUTES |
| tools/localization/build_localized_static.py | Build tool | Generates localized/{fa,en} | Routes, catalogs | ACTIVE BUILD |
| tools/localization/validate_localization.py | Validator | Localization/brand/path QA | Catalogs, policy, HTML | ACTIVE; currently fails |
| index.html + 27 templates | HTML source | One shared source per route | Localization builder | ACTIVE SOURCE; not packaged |
| checkout/index.html | Standalone HTML | Checkout/plan selection | Homepage link, static bypass | ACTIVE; outside localization |
| localized/fa\|en/** | Generated HTML | Locale-specific first paint | Locale router | GENERATED; do not edit |
| public/locales/manifest.json | Runtime JSON | Locale/default/fallback/version | Router and browser bootstrap | ACTIVE 2026.08.13.11 |
| public/locales/feature-manifest.json | Chunk index | 34 feature chunks and hashes | Localization runtime | ACTIVE; stale version |
| velora-locale-registry.js | Browser registry | Frozen locale configuration | Bootstrap/localization | ACTIVE |
| velora-locale-bootstrap.js | Browser bootstrap | Resolves locale before paint | Generated pages | ACTIVE |
| velora-localization.js | Browser service | Catalog load, t(), formatting, switcher | Registry/chunks | ACTIVE |
| velora-data.js | Browser utility | API/auth/data helper | Application pages | ACTIVE |
| velora-dialog.js | UI behavior | Shared confirmation/logout dialog | Application pages | ACTIVE; inline locale branch |
| velora-smart-import.js | OCR feature | Screenshot import, API extraction, browser OCR | API, Tesseract resources | ACTIVE; system deps |
| symbol-icons.js + symbols.json | Asset service | Registry and dynamic symbol icons | Trading UI | ACTIVE; one missing target |
| public/assets/*.css | CSS system | Shared design fragments | HTML templates | ACTIVE; no bundler |
| velora-premium-footer.* | Shared component | Footer style and behavior | Site pages | ACTIVE |
| sitemap.xml / robots.txt | SEO configuration | Discovery and crawl policy | Search engines | ACTIVE; route drift |
| api/.htaccess | API server config | Protect source/storage/tools and route API | Apache/LiteSpeed | ACTIVE |
| api/index.php | API front controller | 35 routes plus health | Root/API rewrite | AUTHORITATIVE API MAP |
| api/public/index.php | Compatibility entry | Delegates to canonical API entry | Alternate document root | ACTIVE |
| api/src/bootstrap.php | PHP bootstrap | Autoload and exception handling | api/index.php | ACTIVE; PHP 8.1+ |
| api/config/config.php | Runtime config | Environment, DB, CORS, limits, proxy | Config loader / .env | ACTIVE; template gap |
| api/src/Core/** | Backend core | Router, HTTP, config, DB, JWT, mail, locale | All backend modules | ACTIVE |
| api/src/Auth/** | Domain module | Auth, sessions, reset, email verification | Auth routes | ACTIVE |
| api/src/Accounts/** | Domain module | Accounts, MetaApi, sync, webhooks | Account routes/workers | ACTIVE |
| api/src/Trades/** | Domain module | Trades, exits, PnL, OCR | Trade routes | ACTIVE |
| api/src/Dashboard/** | Domain module | Summary, equity, strategy metrics | Dashboard routes | ACTIVE |
| api/src/Webhooks/** | Domain module | MetaApi HMAC ingestion | Webhook routes | ACTIVE |
| api/src/Admin/AdminController.php | Controller | Admin user list | Admin route | ACTIVE; admin guard |
| api/database/schema.sql | SQL schema | Canonical fresh-install 16 tables | Installer/manual deployment | PRIMARY SCHEMA |
| api/database/migrations/*.sql | SQL migration | Language and v0.2/v0.3 upgrades | Operator | ACTIVE OPS; v0.2 issue |
| database_corrected.sql / db_backup.sql | Historical exports | Data-bearing historical database copies | No active code | SENSITIVE/LEGACY |
| api/workers/metaapi_sync_worker.php | CLI worker | Processes MetaApi sync jobs | Cron | ACTIVE CLI |
| api/workers/content_translation_worker.php | CLI worker | Processes translation jobs | Cron/provider env | ACTIVE CLI |
| api/workers/read_mt5_times.py | Python worker | Extracts MT5 timestamps | Screenshot controller | ACTIVE OPTIONAL OCR |
| api/storage/ | Runtime storage | SQLite and logs | Database/Mailer | SENSITIVE RUNTIME |
| api/.env / api/.env.example | Environment config | Secrets / safe template | Config.php | SECRET / TEMPLATE |
| public/assets/tesseract-data/** | OCR models | Local English/Persian traineddata | Browser OCR fallback | ACTIVE |
| public/assets/symbols/** | Asset library | Registry symbols and fallbacks | Symbol service | ACTIVE + candidates |
| _next/** + */index.txt | Legacy output | Historical Next/RSC artifacts | No active importer | ORPHAN CANDIDATE |
| Deployment manifests/guides | Release history | Old package lists and instructions | Operator only | STALE |

## 8. Findings and Approval-Gated Remediation Plan

| ID | Severity | Finding | Primary file/component |
|---|---|---|---|
| F-01 | HIGH | Secret and runtime artifacts have mode 0644 | api/.env; api/storage/velora.sqlite; api/storage/mail.log; api/error_log |
| F-02 | HIGH | Data-bearing historical SQL exports remain under the web-root snapshot | _database/database_corrected.sql; api/database/database_corrected.sql; api/database/db_backup.sql |
| F-03 | HIGH | Checkout bypasses locale routing and has no independent English route | index.html:1824; .htaccess; checkout/index.html; tools/localization/routes.json |
| F-04 | HIGH | Five English outputs contain 161 visible Persian nodes | localized/en/accounts/connect (17); privacy (85); profile (3); terms (52); trades/new (4) |
| F-05 | MEDIUM | Localization release and cache-busting versions are inconsistent | public/locales/manifest.json; locale registry; catalogs; feature manifest/chunks; 87 HTML files |
| F-06 | MEDIUM | Active locale branches remain outside the central catalog and escape validator detection | velora-dialog.js:7,14; velora-smart-import-dash.js:9; velora-smart-import.js:11–12; index.html:2395,2537 |
| F-07 | MEDIUM | Active locale key errors.validation.format is missing | AccountController.php; TradeService.php; public/locales/* |
| F-08 | MEDIUM | Localization validation fails with 622 mixed real and false-positive reports | tools/localization/validate_localization.py; brand_policy.py; test-localization.html; /tmp/velora_validate.out |
| F-09 | MEDIUM | GER40 and DAX40 reference a missing flag target | public/assets/symbols/symbols.json:214,239 → assets/flags/de.png |
| F-10 | MEDIUM | Sitemap, robots, locale-aware links, and canonical route contracts drift | sitemap.xml; robots.txt; localized/en/*.html; routes.json |
| F-11 | MEDIUM | Historical manifests and deployment guides are stale and multi-authoritative | DEPLOYMENT_MANIFEST.json; MANIFEST.json; PATCH_MANIFEST.json; DEPLOYMENT_GUIDE_FA.md |
| F-12 | MEDIUM | Dependency contract and host preflight are incomplete | Repository root; api/src; read_mt5_times.py; ScreenshotExtractController.php; velora-smart-import.js |
| F-13 | MEDIUM | Migration v0.2 is not compatible with MySQL 8 grammar | api/database/migrations/v0.2_metaapi_bridge.sql; deployment guide |
| F-14 | MEDIUM | TRUSTED_PROXY_CIDRS is used but absent from the environment template | api/config/config.php:82; api/.env.example |
| F-15 | LOW | Nine PHP imports are unused | Six PDO repository imports; AuthMiddleware UnauthorizedException; Router ApiException; TranslationController Response |
| F-16 | LOW | Legacy TranslationController depends on missing LocalizedResponse | TranslationController.php; LocaleMiddleware.php; absent LocalizedResponse.php |
| F-17 | LOW | Exact and near-duplicate ownership is unresolved | Appendix C: generated aliases, logos/icons, SQL/readme copies, symbol aliases, backgrounds, email assets |
| F-18 | LOW | Legacy Next.js/RSC artifacts are inactive and incomplete | _next/ (40 files); nine route-level index.txt files |
| F-19 | LOW | Large asset sets are likely unused but dynamic paths prevent automatic deletion | asset-icons2: 54/60 candidates; symbols library: 93/147 candidates; nine backup-named files; bg-b variants |
| F-20 | LOW | Case-fold collision exists between MANIFEST.json and manifest.json | MANIFEST.json; manifest.json |

### F-01 — HIGH — Secret and runtime artifacts have mode 0644

| | |
|---|---|
| File / component | api/.env; api/storage/velora.sqlite; api/storage/mail.log; api/error_log |
| Verified evidence | Metadata confirms mode 0644. Secret contents were neither copied nor disclosed. HTTP rules deny access and the Production ZIP excludes the files. |
| Root cause | Runtime data is mixed with the source snapshot and relies on web-server denial as the final control. |
| Risk | Accidental export, copying outside Apache/LiteSpeed, backup errors, or another local user could expose credentials, logs, or runtime data. |
| Recommendation | After approval and backup, keep runtime data outside source/public_html; use 0600 files and a 0700 storage directory; preserve Production .env and data. |
| Verification test | Check stat output, archive listing, controlled HTTP denial, and API smoke tests. |
| Status | OPEN — no remediation applied; approval required. |

### F-02 — HIGH — Data-bearing historical SQL exports remain under the web-root snapshot

| | |
|---|---|
| File / component | _database/database_corrected.sql; api/database/database_corrected.sql; api/database/db_backup.sql |
| Verified evidence | The two database_corrected.sql files are exact duplicates. The dumps contain masked indicators for 64–65 email literals and bcrypt hashes. No values are disclosed. |
| Root cause | Historical exports were retained beside clean schema and migrations. |
| Risk | A different server configuration or package mistake could expose personal data or password hashes and make schema ownership ambiguous. |
| Recommendation | Move approved dumps to encrypted storage outside the web root; retain only clean schema/migrations in source and release packages. |
| Verification test | Scan package listings and HTTP denial; verify clean schema/migrations contain no real records. |
| Status | OPEN — no remediation applied; approval required. |

### F-03 — HIGH — Checkout bypasses locale routing and has no independent English route

| | |
|---|---|
| File / component | index.html:1824; .htaccess; checkout/index.html; tools/localization/routes.json |
| Verified evidence | The homepage links to /checkout/index.html?plan=professional. Checkout is rewrite-bypassed, absent from routes.json, and has no generated /fa/ or /en/ output. |
| Root cause | A standalone page remained outside the shared localization contract. |
| Risk | English users can enter a Persian or mixed-language conversion flow; /en/checkout/ cannot resolve to a verified localized output. |
| Recommendation | Keep one shared source, make checkout language-aware, and register an approved route/build contract without creating separately authored locale sources. |
| Verification test | Test /fa/checkout/ and /en/checkout/, query preservation, canonical/hreflang, payment flow, and responsive behavior. |
| Status | OPEN — no remediation applied; approval required. |

### F-04 — HIGH — Five English outputs contain 161 visible Persian nodes

| | |
|---|---|
| File / component | localized/en/accounts/connect (17); privacy (85); profile (3); terms (52); trades/new (4) |
| Verified evidence | A visible DOM-text scan found Persian copy in generated English output. Generated files were not edited. |
| Root cause | Source/catalog coverage is incomplete and the build preserves Persian copy. |
| Risk | English usability, legal content, language parity, and user trust are affected. |
| Recommendation | Correct shared sources and catalogs, then rebuild generated outputs; never edit localized/{locale} manually. |
| Verification test | Require zero visible Persian nodes, validator success, human FA/EN review, and unchanged layout/design. |
| Status | OPEN — no remediation applied; approval required. |

### F-05 — MEDIUM — Localization release and cache-busting versions are inconsistent

| | |
|---|---|
| File / component | public/locales/manifest.json; locale registry; catalogs; feature manifest/chunks; 87 HTML files |
| Verified evidence | Main manifest/registry use 2026.08.13.11 while catalogs, brand, features, and routes use 2026.08.13.7. The validator reports 3 primary, 35 feature, and 522 stale query-version findings. |
| Root cause | Version changes and rebuild metadata are coordinated manually. |
| Risk | Stale caches and incompatible runtime/catalog combinations can occur. |
| Recommendation | Use one release-version source, then run registry sync, localized build, and validation. |
| Verification test | All manifests, catalogs, chunks, and query versions must match; all 34 chunk hashes must validate. |
| Status | OPEN — no remediation applied; approval required. |

### F-06 — MEDIUM — Active locale branches remain outside the central catalog and escape validator detection

| | |
|---|---|
| File / component | velora-dialog.js:7,14; velora-smart-import-dash.js:9; velora-smart-import.js:11–12; index.html:2395,2537 |
| Verified evidence | Active checks use document.documentElement.lang or local t(fa,en) helpers. The validator regex does not recognize all forms. |
| Root cause | Bilingual copy is embedded inline and validator parsing is limited. |
| Risk | Text drift and incomplete QA coverage continue while the validator reports misleading results. |
| Recommendation | Move active copy into the catalog/VeloraLocale with the smallest approved change and extend validation to real syntax. |
| Verification test | Test both locales, DOM snapshots, branch scans, and false-positive/false-negative fixtures. |
| Status | OPEN — no remediation applied; approval required. |

### F-07 — MEDIUM — Active locale key errors.validation.format is missing

| | |
|---|---|
| File / component | AccountController.php; TradeService.php; public/locales/* |
| Verified evidence | Eight active emissions use the same messageKey, but it is absent from both FA and EN catalogs. |
| Root cause | The validation contract changed without a synchronized catalog update. |
| Risk | Raw fallback messages or incomplete localized API responses can reach users. |
| Recommendation | Add approved FA and EN values to the correct catalog/feature chunk and rebuild. |
| Verification test | Exercise all eight validation paths in both locales and assert messageKey plus localized message. |
| Status | OPEN — no remediation applied; approval required. |

### F-08 — MEDIUM — Localization validation fails with 622 mixed real and false-positive reports

| | |
|---|---|
| File / component | tools/localization/validate_localization.py; brand_policy.py; test-localization.html; /tmp/velora_validate.out |
| Verified evidence | Exit code 1 includes version drift, 522 stale versions, brand false positives for the approved support email, parser misses, diagnostic-only keys, active missing keys, Persian copy, and missed branches. |
| Root cause | Production, diagnostic, brand-email, and JavaScript-helper scopes are not modeled precisely. |
| Risk | Noise can hide real defects and prevents a reliable release gate. |
| Recommendation | Fix root causes, separate diagnostic scope, preserve the approved mailbox, and improve parser fixtures without suppressing real findings. |
| Verification test | Production validation must report only real defects and reach zero after remediation. |
| Status | OPEN — no remediation applied; approval required. |

### F-09 — MEDIUM — GER40 and DAX40 reference a missing flag target

| | |
|---|---|
| File / component | public/assets/symbols/symbols.json:214,239 → assets/flags/de.png |
| Verified evidence | public/assets/flags/de.png is absent while public/assets/symbols/flags/de.png exists. The reference is data-driven and escaped direct HTML scans. |
| Root cause | The registry was not updated after asset organization changed. |
| Risk | Broken images or fallback rendering occur for two symbols. |
| Recommendation | After design approval, point the registry to the existing approved asset or place the approved asset at the contractual location. |
| Verification test | Verify registry target existence, HTTP 200, Linux case, and both symbol renders. |
| Status | OPEN — no remediation applied; approval required. |

### F-10 — MEDIUM — Sitemap, robots, locale-aware links, and canonical route contracts drift

| | |
|---|---|
| File / component | sitemap.xml; robots.txt; localized/en/*.html; routes.json |
| Verified evidence | Thirteen unprefixed sitemap URLs differ from /fa/... canonicals. English pages contain negotiated internal paths. Robots rules cover only unprefixed private routes and inspected app pages lack noindex. |
| Root cause | SEO/navigation files are maintained separately from the route registry. |
| Risk | Crawlers can discover duplicate/noncanonical pages or private application shells; English crawling can depend on cookies or headers. |
| Recommendation | Generate sitemap, robots, and locale URLs from route registry plus public/private policy; use explicit crawlable English destinations. |
| Verification test | Crawl without cookies under different Accept-Language values and verify canonical, hreflang, sitemap, robots, and noindex. |
| Status | OPEN — no remediation applied; approval required. |

### F-11 — MEDIUM — Historical manifests and deployment guides are stale and multi-authoritative

| | |
|---|---|
| File / component | DEPLOYMENT_MANIFEST.json; MANIFEST.json; PATCH_MANIFEST.json; DEPLOYMENT_GUIDE_FA.md |
| Verified evidence | The manifests contain 497/128/111 entries with 2/1/1 missing targets. The guide records an older validator result/version and hardcodes a cPanel account path. |
| Root cause | Old package manifests remain in the root and are not generated from the current archive. |
| Risk | Operators can upload incomplete sets, overwrite the wrong path, or trust obsolete QA results. |
| Recommendation | Generate a versioned manifest from each final ZIP; clearly archive historical documents; replace account-specific paths with variables. |
| Verification test | Manifest path/count/hash must exactly match the current 451-file package with zero missing targets. |
| Status | OPEN — no remediation applied; approval required. |

### F-12 — MEDIUM — Dependency contract and host preflight are incomplete

| | |
|---|---|
| File / component | Repository root; api/src; read_mt5_times.py; ScreenshotExtractController.php; velora-smart-import.js |
| Verified evidence | No Composer, npm, or Python dependency manifest exists. Code requires PHP 8.1+, PDO, OpenSSL, cURL/mail, GD, proc_open, Tesseract, Python 3, Pillow, NumPy, and pinned CDN fallback resources. |
| Root cause | A cPanel-oriented dependency-light architecture relies on undocumented system packages. |
| Risk | API, OCR, or workers may fail on another host and dependency auditing is not reproducible. |
| Recommendation | Document runtime requirements and add a non-destructive preflight without introducing a framework. |
| Verification test | Check PHP version/extensions, executable versions, Python imports, OCR smoke tests, and local traineddata in staging. |
| Status | OPEN — no remediation applied; approval required. |

### F-13 — MEDIUM — Migration v0.2 is not compatible with MySQL 8 grammar

| | |
|---|---|
| File / component | api/database/migrations/v0.2_metaapi_bridge.sql; deployment guide |
| Verified evidence | ADD COLUMN IF NOT EXISTS and ADD INDEX IF NOT EXISTS in the current ALTER form are supported by MariaDB but not by MySQL 8 grammar. |
| Root cause | One migration was written for MariaDB while documentation claims both engines. |
| Risk | MySQL 8 deployment can fail or stop mid-migration. |
| Recommendation | Create a dialect-aware/idempotent migration or information_schema prechecks; never run on Production without backup and staging. |
| Verification test | Run in disposable MySQL 8 and MariaDB, compare schemas, and prove rerun idempotency. |
| Status | OPEN — no remediation applied; approval required. |

### F-14 — MEDIUM — TRUSTED_PROXY_CIDRS is used but absent from the environment template

| | |
|---|---|
| File / component | api/config/config.php:82; api/.env.example |
| Verified evidence | It is the only PHP-consumed environment key missing from the example file. |
| Root cause | Proxy hardening was added without synchronizing the safe template and documentation. |
| Risk | Client-IP and rate-limit behavior behind a reverse proxy may be incorrect. |
| Recommendation | Add a nonsecret documented example and describe the actual topology without disclosing Production values. |
| Verification test | Test direct, trusted-proxy, and untrusted-forwarded requests and verify client-IP buckets. |
| Status | OPEN — no remediation applied; approval required. |

### F-15 — LOW — Nine PHP imports are unused

| | |
|---|---|
| File / component | Six PDO repository imports; AuthMiddleware UnauthorizedException; Router ApiException; TranslationController Response |
| Verified evidence | Each imported short name is unused after its declaration and was spot-checked. |
| Root cause | Earlier refactors left import noise. |
| Risk | No direct runtime impact, but maintenance and dependency interpretation are noisier. |
| Recommendation | Remove only in an approved cleanup patch with no adjacent refactor. |
| Verification test | Run PHP lint/static analysis and route smoke tests in staging. |
| Status | OPEN — no remediation applied; approval required. |

### F-16 — LOW — Legacy TranslationController depends on missing LocalizedResponse

| | |
|---|---|
| File / component | TranslationController.php; LocaleMiddleware.php; absent LocalizedResponse.php |
| Verified evidence | The class is absent. The controller is not registered among the 35 active routes; LocaleMiddleware is only tied to that legacy path. |
| Root cause | Old translation architecture remained after replacement by content translation/cache lookup. |
| Risk | Registering the legacy controller would cause an autoload failure; active API routes are unaffected. |
| Recommendation | Do not register it blindly; approve archive/removal or restore a complete tested contract. |
| Verification test | Keep all active route handlers valid; require integration tests if the legacy route is revived. |
| Status | OPEN — no remediation applied; approval required. |

### F-17 — LOW — Exact and near-duplicate ownership is unresolved

| | |
|---|---|
| File / component | Appendix C: generated aliases, logos/icons, SQL/readme copies, symbol aliases, backgrounds, email assets |
| Verified evidence | SHA-256 found 36 groups/91 files. Triage also found 117 normalized-text pairs and 13 perceptual-image pairs. Two byte-different pairs are pixel-identical. |
| Root cause | Compatibility aliases, generated outputs, backups, and libraries coexist. |
| Risk | Files can be edited inconsistently, but many duplicates are intentional. |
| Recommendation | Classify each as intentional, generated, compatibility, or redundant; prove routing/runtime ownership before deletion. |
| Verification test | Use hash, route/reference trace, runtime access, and visual regression; perform no automatic deletion. |
| Status | OPEN — no remediation applied; approval required. |

### F-18 — LOW — Legacy Next.js/RSC artifacts are inactive and incomplete

| | |
|---|---|
| File / component | _next/ (40 files); nine route-level index.txt files |
| Verified evidence | Four build IDs, two CSS files, repeated chunks, and nine missing fonts were found. No active HTML references exist; all 49 files are excluded from the current Production ZIP. |
| Root cause | Several historical Next exports were copied beside the current static frontend. |
| Risk | They create clutter, false framework identification, and accidental packaging risk. |
| Recommendation | After approval and an access-log observation period, archive/remove from source while retaining release exclusion. |
| Verification test | Require zero references, page smoke tests, package checks, and expected 404 behavior. |
| Status | OPEN — no remediation applied; approval required. |

### F-19 — LOW — Large asset sets are likely unused but dynamic paths prevent automatic deletion

| | |
|---|---|
| File / component | asset-icons2: 54/60 candidates; symbols library: 93/147 candidates; nine backup-named files; bg-b variants |
| Verified evidence | symbols.json directly uses icon-20 through icon-25 and 54 symbol-library targets. Dynamic Forex paths and fallbacks mean text-reference absence is not proof. |
| Root cause | Historical libraries and runtime path construction coexist. |
| Risk | Storage and maintenance overhead exist, but premature deletion can break dynamic symbols. |
| Recommendation | Use access logs, a complete symbol-picker fixture, and an authoritative asset manifest before any deletion. |
| Verification test | Test all 75 registry entries, dynamic inputs, fallbacks, and visual snapshots; then report incremental deletions. |
| Status | OPEN — no remediation applied; approval required. |

### F-20 — LOW — Case-fold collision exists between MANIFEST.json and manifest.json

| | |
|---|---|
| File / component | MANIFEST.json; manifest.json |
| Verified evidence | This is the only case-insensitive collision among 630 files. Linux distinguishes them; Windows/macOS and some ZIP workflows may not. |
| Root cause | Two unrelated roles use names differing only by case. |
| Risk | Extraction or development on a case-insensitive filesystem can overwrite or select the wrong file. |
| Recommendation | After approval, rename/version the release manifest explicitly and decide whether the unreferenced PWA-like manifest is retained, registered, or removed. |
| Verification test | Test case-insensitive extraction, HTML links, and package manifest resolution. |
| Status | OPEN — no remediation applied; approval required. |

### Recommended Order After Approval

| Priority | Findings | Scope | Gate |
|---|---|---|---|
| P0 | F-01, F-02 | Protect/quarantine runtime and data-bearing dumps | Backup, owner approval, staging |
| P0 | F-03, F-04 | Checkout locale path and English content | UX/content approval and rebuild |
| P1 | F-05…F-08 | Version, key, inline branches, validator | One coordinated localization release |
| P1 | F-09, F-10 | Asset path and SEO/navigation/robots | Design and SEO approval |
| P1 | F-11…F-14 | Release manifests, dependencies, DB dialect, proxy docs | Operations/DB staging |
| P2 | F-15…F-20 | Imports, legacy code, duplicates, Next, assets, case collision | Runtime trace and explicit cleanup approval |

No P0, P1, or P2 change was performed. No new full export was produced because the exact full-export trigger was not given.

## 9. Previous vs Current Production Version

| | |
|---|---|
| Previous | VELORA-homepage-parity-production.zip • 442 files • SHA-256 4dc741aac7fde68d3124d6d6b7b1e0b8bfefaf2d7885b679874e6ea532e1c615 |
| Current | VELORATRADE-security-hardened-production-2026-08-14.zip • 451 files • SHA-256 2550236584a55523ebefd02465c1750c8da721a2fda9eb741f94f303614fee7a |
| Result | ADDED 9 • REMOVED 0 • MOVED 0 • CHANGED 36 • UNCHANGED 406 |
| Move interpretation | No old-only path exists, so no move/rename can be proven in this comparison. |

### Added Files

| Status | Path | Risk / server action |
|---|---|---|
| ADDED | api/database/migrations/add_language_support.sql | Database migration requires a staged DB decision |
| ADDED | api/database/migrations/v0.2_metaapi_bridge.sql | Database migration requires a staged DB decision |
| ADDED | api/database/migrations/v0.3_trade_financial_consistency.sql | Database migration requires a staged DB decision |
| ADDED | api/database/password_resets.sql | Database migration requires a staged DB decision |
| ADDED | api/database/schema.sql | Database migration requires a staged DB decision |
| ADDED | api/database/schema_extensions.sql | Database migration requires a staged DB decision |
| ADDED | public/assets/tesseract-data/NOTICE.txt | OCR model data; verify integrity and runtime path |
| ADDED | public/assets/tesseract-data/eng.traineddata.gz | OCR model data; verify integrity and runtime path |
| ADDED | public/assets/tesseract-data/fas.traineddata.gz | OCR model data; verify integrity and runtime path |

### Changed Files

| Status | Path | Risk / verification |
|---|---|---|
| CHANGED | .htaccess | Routing/security — Apache/LiteSpeed regression test |
| CHANGED | api/.htaccess | API/security/data path — staging smoke test |
| CHANGED | api/config/config.php | API/security/data path — staging smoke test |
| CHANGED | api/index.php | API/security/data path — staging smoke test |
| CHANGED | api/public/index.php | API/security/data path — staging smoke test |
| CHANGED | api/src/Accounts/AccountController.php | API/security/data path — staging smoke test |
| CHANGED | api/src/Accounts/MetaApiService.php | API/security/data path — staging smoke test |
| CHANGED | api/src/Auth/AuthController.php | API/security/data path — staging smoke test |
| CHANGED | api/src/Auth/AuthService.php | API/security/data path — staging smoke test |
| CHANGED | api/src/Auth/EmailVerificationRepository.php | API/security/data path — staging smoke test |
| CHANGED | api/src/Auth/PasswordResetRepository.php | API/security/data path — staging smoke test |
| CHANGED | api/src/Auth/PasswordService.php | API/security/data path — staging smoke test |
| CHANGED | api/src/Auth/SessionRepository.php | API/security/data path — staging smoke test |
| CHANGED | api/src/Core/Config.php | API/security/data path — staging smoke test |
| CHANGED | api/src/Core/Database.php | API/security/data path — staging smoke test |
| CHANGED | api/src/Core/Mailer.php | API/security/data path — staging smoke test |
| CHANGED | api/src/Core/RateLimiter.php | API/security/data path — staging smoke test |
| CHANGED | api/src/Core/Request.php | API/security/data path — staging smoke test |
| CHANGED | api/src/Core/Response.php | API/security/data path — staging smoke test |
| CHANGED | api/src/Core/Validation.php | API/security/data path — staging smoke test |
| CHANGED | api/src/Trades/PnlCalculator.php | API/security/data path — staging smoke test |
| CHANGED | api/src/Trades/ScreenshotExtractController.php | API/security/data path — staging smoke test |
| CHANGED | api/src/Trades/TradeExitController.php | API/security/data path — staging smoke test |
| CHANGED | api/src/Trades/TradeExitRepository.php | API/security/data path — staging smoke test |
| CHANGED | api/src/Trades/TradeRepository.php | API/security/data path — staging smoke test |
| CHANGED | api/src/Trades/TradeService.php | API/security/data path — staging smoke test |
| CHANGED | api/src/Webhooks/MetaApiWebhookController.php | API/security/data path — staging smoke test |
| CHANGED | localized/en/dashboard/index.html | Generated output — rebuild provenance and locale visual test |
| CHANGED | localized/en/reset-password/index.html | Generated output — rebuild provenance and locale visual test |
| CHANGED | localized/en/verify-email/index.html | Generated output — rebuild provenance and locale visual test |
| CHANGED | localized/fa/dashboard/index.html | Generated output — rebuild provenance and locale visual test |
| CHANGED | localized/fa/reset-password/index.html | Generated output — rebuild provenance and locale visual test |
| CHANGED | localized/fa/verify-email/index.html | Generated output — rebuild provenance and locale visual test |
| CHANGED | public/assets/symbol-icons.js | Frontend behavior/asset — browser regression test |
| CHANGED | public/assets/velora-data.js | Frontend behavior/asset — browser regression test |
| CHANGED | public/assets/velora-smart-import.js | Frontend behavior/asset — browser regression test |

- REMOVED: 0.
- MOVED/RENAMED: 0.
- UNCHANGED: 406; paths are not repeated individually because the checksum comparison was complete.
- Database migrations are SERVER ACTIONS, not mere uploads; Production .env and runtime data must be preserved.

## Appendix A — Complete Verified Repository Tree

This tree was generated from the final audited filesystem after removal of audit-generated bytecode. It contains all 630 files and 159 subdirectories. Secret filenames are shown, but secret contents are not.

```
404_extracted/
├── 404/
│ └── index.html
├── _database/
│ └── database_corrected.sql
├── _next/
│ └── static/
│ ├── 218lBAl1Pgco_ZAYgrXcP/
│ │ ├── _buildManifest.js
│ │ └── _ssgManifest.js
│ ├── chunks/
│ │ ├── app/
│ │ │ ├── _not-found/
│ │ │ │ └── page-402ff515b2c14f9f.js
│ │ │ ├── admin/
│ │ │ │ ├── page-1e9989bfd2a6baaa.js
│ │ │ │ └── page-5c38b2cabbf4d752.js
│ │ │ ├── dashboard/
│ │ │ │ ├── page-54e0b502fc4aa20c.js
│ │ │ │ └── page-d9f448fc68bf4336.js
│ │ │ ├── forgot-password/
│ │ │ │ ├── page-0a5f23bad3bd659c.js
│ │ │ │ └── page-4f1549709939907d.js
│ │ │ ├── login/
│ │ │ │ ├── page-1982df9f616796cd.js
│ │ │ │ └── page-63ee9d52b18ccfc9.js
│ │ │ ├── profile/
│ │ │ │ ├── page-83f492d16914ea09.js
│ │ │ │ └── page-f089a1aebc69e352.js
│ │ │ ├── register/
│ │ │ │ ├── page-1a356346f9466047.js
│ │ │ │ └── page-e59c08b61ecfbf40.js
│ │ │ ├── reset-password/
│ │ │ │ ├── page-448e660679ca5054.js
│ │ │ │ └── page-ff7ccd03538232f4.js
│ │ │ ├── trades/
│ │ │ │ ├── new/
│ │ │ │ │ ├── page-27f7896a3c59ff4a.js
│ │ │ │ │ └── page-390d1101e58d9e5d.js
│ │ │ │ ├── page-38e15ee67f5302c6.js
│ │ │ │ └── page-e8fb3e9bd06893c7.js
│ │ │ ├── verify-email/
│ │ │ │ ├── page-1c83a6cba9633ded.js
│ │ │ │ └── page-aa0aa21a43021492.js
│ │ │ ├── error-cdd6999f3f915eb9.js
│ │ │ ├── global-error-820a8860d14fee4f.js
│ │ │ ├── layout-4b81b4155c2d976a.js
│ │ │ ├── layout-dcf3934a7520ada2.js
│ │ │ ├── loading-03a2f68b8da55ce2.js
│ │ │ ├── page-27520d1ae1f72e75.js
│ │ │ └── page-5f5bcd2f87251aac.js
│ │ └── pages/
│ │ ├── _app-44e5d7e52411c6a5.js
│ │ └── _error-4a2fb0735435b993.js
│ ├── css/
│ │ ├── 35c900d50ac706ed.css
│ │ └── fdce29635a02623d.css
│ ├── FM4dgr6qVkuvEFTtyilqO/
│ │ ├── _buildManifest.js
│ │ └── _ssgManifest.js
│ ├── X3dim2gBYlGAf45rCn4KJ/
│ │ ├── _buildManifest.js
│ │ └── _ssgManifest.js
│ └── z7aWr4SvvOLxF09Kc8Wha/
│ ├── _buildManifest.js
│ └── _ssgManifest.js
├── accounts/
│ └── connect/
│ └── index.html
├── admin/
│ ├── index.html
│ └── index.txt
├── api/
│ ├── assets/
│ │ └── email/
│ │ ├── reset-lock.jpg
│ │ ├── velora-lock.jpg
│ │ ├── velora-logo-backup.png
│ │ ├── velora-logo.jpg
│ │ └── velora-logo.png
│ ├── config/
│ │ ├── .htaccess
│ │ └── config.php
│ ├── database/
│ │ ├── migrations/
│ │ │ ├── add_language_support.sql
│ │ │ ├── v0.2_metaapi_bridge.sql
│ │ │ └── v0.3_trade_financial_consistency.sql
│ │ ├── database.sql
│ │ ├── database_corrected.sql
│ │ ├── database_fixed.sql
│ │ ├── db_backup.sql
│ │ ├── password_resets.sql
│ │ ├── schema.sql
│ │ └── schema_extensions.sql
│ ├── locales/
│ │ ├── en/
│ │ │ └── ui.php
│ │ └── fa/
│ │ └── ui.php
│ ├── public/
│ │ └── index.php
│ ├── src/
│ │ ├── Accounts/
│ │ │ ├── AccountController.php
│ │ │ ├── AccountRepository.php
│ │ │ ├── MetaApiService.php
│ │ │ ├── SyncJobRepository.php
│ │ │ └── WebhookEventRepository.php
│ │ ├── Admin/
│ │ │ └── AdminController.php
│ │ ├── Auth/
│ │ │ ├── AuthController.php
│ │ │ ├── AuthMiddleware.php
│ │ │ ├── AuthService.php
│ │ │ ├── EmailVerificationRepository.php
│ │ │ ├── PasswordResetRepository.php
│ │ │ ├── PasswordService.php
│ │ │ ├── README-#U0622#U067e#U0644#U0648#U062f.txt
│ │ │ ├── README-persian-5.txt
│ │ │ ├── README-persian-6.txt
│ │ │ ├── SessionRepository.php
│ │ │ ├── UserDeviceRepository.php
│ │ │ └── UserRepository.php
│ │ ├── Core/
│ │ │ ├── Exceptions/
│ │ │ │ ├── ApiException.php
│ │ │ │ ├── ConflictException.php
│ │ │ │ ├── ForbiddenException.php
│ │ │ │ ├── MethodNotAllowedException.php
│ │ │ │ ├── NotFoundException.php
│ │ │ │ ├── UnauthorizedException.php
│ │ │ │ └── ValidationException.php
│ │ │ ├── Locale/
│ │ │ │ ├── ContentTranslationController.php
│ │ │ │ ├── ContentTranslationRepository.php
│ │ │ │ ├── LocaleManager.php
│ │ │ │ ├── LocaleMiddleware.php
│ │ │ │ ├── TranslationController.php
│ │ │ │ └── TranslationProviderClient.php
│ │ │ ├── Config.php
│ │ │ ├── Crypto.php
│ │ │ ├── Database.php
│ │ │ ├── EmailNotificationRepository.php
│ │ │ ├── EmailPreferenceRepository.php
│ │ │ ├── EmailTemplate.php
│ │ │ ├── Jwt.php
│ │ │ ├── Mailer.php
│ │ │ ├── NotificationService.php
│ │ │ ├── RateLimiter.php
│ │ │ ├── README-#U0622#U067e#U0644#U0648#U062f.txt
│ │ │ ├── README-persian-3.txt
│ │ │ ├── README-persian-4.txt
│ │ │ ├── Request.php
│ │ │ ├── Response.php
│ │ │ ├── Router.php
│ │ │ ├── UserAchievementRepository.php
│ │ │ └── Validation.php
│ │ ├── Dashboard/
│ │ │ ├── DashboardController.php
│ │ │ └── MetricsService.php
│ │ ├── Trades/
│ │ │ ├── PnlCalculator.php
│ │ │ ├── ScreenshotExtractController.php
│ │ │ ├── TradeController.php
│ │ │ ├── TradeExitController.php
│ │ │ ├── TradeExitRepository.php
│ │ │ ├── TradeRepository.php
│ │ │ └── TradeService.php
│ │ ├── Webhooks/
│ │ │ └── MetaApiWebhookController.php
│ │ ├── .htaccess
│ │ └── bootstrap.php
│ ├── storage/
│ │ ├── .gitkeep
│ │ ├── mail.log
│ │ └── velora.sqlite
│ ├── workers/
│ │ ├── content_translation_worker.php
│ │ ├── metaapi_sync_worker.php
│ │ ├── preflight_v0_2.php
│ │ ├── preflight_write_file.php
│ │ └── read_mt5_times.py
│ ├── .env
│ ├── .env.example
│ ├── .htaccess
│ ├── diag.php
│ ├── env-check.php
│ ├── error_log
│ ├── index.php
│ ├── init-sqlite.php
│ ├── install.php
│ ├── mail-setup.php
│ ├── mail-test-simple.php
│ ├── mail-test.php
│ ├── README-#U0622#U067e#U0644#U0648#U062f.txt
│ ├── README-persian-1.txt
│ ├── README-persian-2.txt
│ ├── test-resend-verification.php
│ └── test-verify-email.php
├── blog/
│ ├── forex-trading-journal/
│ │ └── index.html
│ ├── mt4-mt5-trading-journal/
│ │ └── index.html
│ ├── risk-management-trading/
│ │ └── index.html
│ ├── what-is-trading-journal/
│ │ └── index.html
│ ├── why-traders-need-journal/
│ │ └── index.html
│ └── index.html
├── checkout/
│ └── index.html
├── dashboard/
│ ├── index.html
│ └── index.txt
├── en/
│ └── blog/
│ ├── forex-trading-journal/
│ │ └── index.html
│ ├── mt4-mt5-trading-journal/
│ │ └── index.html
│ ├── risk-management-in-trading/
│ │ └── index.html
│ ├── what-is-a-trading-journal/
│ │ └── index.html
│ ├── why-traders-need-a-journal/
│ │ └── index.html
│ └── index.html
├── forgot-password/
│ └── index.html
├── intelligence/
│ └── index.html
├── localized/
│ ├── en/
│ │ ├── 404/
│ │ │ └── index.html
│ │ ├── accounts/
│ │ │ └── connect/
│ │ │ └── index.html
│ │ ├── admin/
│ │ │ └── index.html
│ │ ├── blog/
│ │ │ ├── forex-trading-journal/
│ │ │ │ └── index.html
│ │ │ ├── mt4-mt5-trading-journal/
│ │ │ │ └── index.html
│ │ │ ├── risk-management-in-trading/
│ │ │ │ └── index.html
│ │ │ ├── risk-management-trading/
│ │ │ │ └── index.html
│ │ │ ├── what-is-a-trading-journal/
│ │ │ │ └── index.html
│ │ │ ├── what-is-trading-journal/
│ │ │ │ └── index.html
│ │ │ ├── why-traders-need-a-journal/
│ │ │ │ └── index.html
│ │ │ ├── why-traders-need-journal/
│ │ │ │ └── index.html
│ │ │ └── index.html
│ │ ├── dashboard/
│ │ │ └── index.html
│ │ ├── forgot-password/
│ │ │ └── index.html
│ │ ├── intelligence/
│ │ │ └── index.html
│ │ ├── login/
│ │ │ └── index.html
│ │ ├── markets/
│ │ │ └── index.html
│ │ ├── news/
│ │ │ └── index.html
│ │ ├── performance/
│ │ │ └── index.html
│ │ ├── privacy/
│ │ │ └── index.html
│ │ ├── profile/
│ │ │ └── index.html
│ │ ├── register/
│ │ │ └── index.html
│ │ ├── reset-password/
│ │ │ └── index.html
│ │ ├── support/
│ │ │ └── index.html
│ │ ├── terms/
│ │ │ └── index.html
│ │ ├── trades/
│ │ │ ├── new/
│ │ │ │ └── index.html
│ │ │ └── index.html
│ │ ├── verify-email/
│ │ │ └── index.html
│ │ ├── wallet/
│ │ │ └── index.html
│ │ ├── 404.html
│ │ └── index.html
│ └── fa/
│ ├── 404/
│ │ └── index.html
│ ├── accounts/
│ │ └── connect/
│ │ └── index.html
│ ├── admin/
│ │ └── index.html
│ ├── blog/
│ │ ├── forex-trading-journal/
│ │ │ └── index.html
│ │ ├── mt4-mt5-trading-journal/
│ │ │ └── index.html
│ │ ├── risk-management-trading/
│ │ │ └── index.html
│ │ ├── what-is-trading-journal/
│ │ │ └── index.html
│ │ ├── why-traders-need-journal/
│ │ │ └── index.html
│ │ └── index.html
│ ├── dashboard/
│ │ └── index.html
│ ├── forgot-password/
│ │ └── index.html
│ ├── intelligence/
│ │ └── index.html
│ ├── login/
│ │ └── index.html
│ ├── markets/
│ │ └── index.html
│ ├── news/
│ │ └── index.html
│ ├── performance/
│ │ └── index.html
│ ├── privacy/
│ │ └── index.html
│ ├── profile/
│ │ └── index.html
│ ├── register/
│ │ └── index.html
│ ├── reset-password/
│ │ └── index.html
│ ├── support/
│ │ └── index.html
│ ├── terms/
│ │ └── index.html
│ ├── trades/
│ │ ├── new/
│ │ │ └── index.html
│ │ └── index.html
│ ├── verify-email/
│ │ └── index.html
│ ├── wallet/
│ │ └── index.html
│ ├── 404.html
│ └── index.html
├── login/
│ ├── index.html
│ └── index.txt
├── markets/
│ └── index.html
├── news/
│ └── index.html
├── performance/
│ └── index.html
├── privacy/
│ └── index.html
├── profile/
│ ├── index.html
│ └── index.txt
├── public/
│ ├── assets/
│ │ ├── asset-icons2/
│ │ │ ├── icon-00.png
│ │ │ ├── icon-01.png
│ │ │ ├── icon-02.png
│ │ │ ├── icon-03.png
│ │ │ ├── icon-04.png
│ │ │ ├── icon-05.png
│ │ │ ├── icon-06.png
│ │ │ ├── icon-07.png
│ │ │ ├── icon-08.png
│ │ │ ├── icon-09.png
│ │ │ ├── icon-10.png
│ │ │ ├── icon-11.png
│ │ │ ├── icon-12.png
│ │ │ ├── icon-13.png
│ │ │ ├── icon-14.png
│ │ │ ├── icon-15.png
│ │ │ ├── icon-16.png
│ │ │ ├── icon-17.png
│ │ │ ├── icon-18.png
│ │ │ ├── icon-19.png
│ │ │ ├── icon-20.png
│ │ │ ├── icon-21.png
│ │ │ ├── icon-22.png
│ │ │ ├── icon-23.png
│ │ │ ├── icon-24.png
│ │ │ ├── icon-25.png
│ │ │ ├── icon-26.png
│ │ │ ├── icon-27.png
│ │ │ ├── icon-28.png
│ │ │ ├── icon-29.png
│ │ │ ├── icon-30.png
│ │ │ ├── icon-31.png
│ │ │ ├── icon-32.png
│ │ │ ├── icon-33.png
│ │ │ ├── icon-34.png
│ │ │ ├── icon-35.png
│ │ │ ├── icon-36.png
│ │ │ ├── icon-37.png
│ │ │ ├── icon-38.png
│ │ │ ├── icon-39.png
│ │ │ ├── icon-40.png
│ │ │ ├── icon-41.png
│ │ │ ├── icon-42.png
│ │ │ ├── icon-43.png
│ │ │ ├── icon-44.png
│ │ │ ├── icon-45.png
│ │ │ ├── icon-46.png
│ │ │ ├── icon-47.png
│ │ │ ├── icon-48.png
│ │ │ ├── icon-49.png
│ │ │ ├── icon-50.png
│ │ │ ├── icon-51.png
│ │ │ ├── icon-52.png
│ │ │ ├── icon-53.png
│ │ │ ├── icon-54.png
│ │ │ ├── icon-55.png
│ │ │ ├── icon-56.png
│ │ │ ├── icon-57.png
│ │ │ ├── icon-58.png
│ │ │ └── icon-59.png
│ │ ├── flags/
│ │ │ ├── au.png
│ │ │ ├── ca.png
│ │ │ ├── ch.png
│ │ │ ├── cn.png
│ │ │ ├── cz.png
│ │ │ ├── eu.png
│ │ │ ├── gb.png
│ │ │ ├── hk.png
│ │ │ ├── hu.png
│ │ │ ├── il.png
│ │ │ ├── jp.png
│ │ │ ├── mx.png
│ │ │ ├── no.png
│ │ │ ├── nz.png
│ │ │ ├── pl.png
│ │ │ ├── se.png
│ │ │ ├── sg.png
│ │ │ ├── tr.png
│ │ │ ├── us.png
│ │ │ └── za.png
│ │ ├── symbols/
│ │ │ ├── commodity/
│ │ │ │ ├── BRENT.svg
│ │ │ │ ├── COPPER.svg
│ │ │ │ ├── GAS.svg
│ │ │ │ ├── NG.svg
│ │ │ │ ├── OIL.svg
│ │ │ │ └── WTI.svg
│ │ │ ├── crypto/
│ │ │ │ ├── AAVE.svg
│ │ │ │ ├── ADA.svg
│ │ │ │ ├── ATOM.svg
│ │ │ │ ├── AVAX.svg
│ │ │ │ ├── BCH.svg
│ │ │ │ ├── BNB.svg
│ │ │ │ ├── BTC.svg
│ │ │ │ ├── DOGE.svg
│ │ │ │ ├── DOT.svg
│ │ │ │ ├── ETH.svg
│ │ │ │ ├── ICP.svg
│ │ │ │ ├── LINK.svg
│ │ │ │ ├── LTC.svg
│ │ │ │ ├── MATIC.svg
│ │ │ │ ├── NEAR.png
│ │ │ │ ├── PEPE.png
│ │ │ │ ├── SOL.svg
│ │ │ │ ├── TRX.svg
│ │ │ │ ├── UNI.svg
│ │ │ │ ├── USDT.svg
│ │ │ │ ├── XLM.svg
│ │ │ │ └── XRP.svg
│ │ │ ├── fallback/
│ │ │ │ ├── AAPL.svg
│ │ │ │ ├── AAVE.svg
│ │ │ │ ├── ADA.svg
│ │ │ │ ├── ATOM.svg
│ │ │ │ ├── AUDCAD.svg
│ │ │ │ ├── AUDJPY.svg
│ │ │ │ ├── AUDNZD.svg
│ │ │ │ ├── AUDUSD.svg
│ │ │ │ ├── AVAX.svg
│ │ │ │ ├── BCH.svg
│ │ │ │ ├── BNB.svg
│ │ │ │ ├── BRENT.svg
│ │ │ │ ├── BTC.svg
│ │ │ │ ├── COPPER.svg
│ │ │ │ ├── DAX40.svg
│ │ │ │ ├── DOGE.svg
│ │ │ │ ├── DOT.svg
│ │ │ │ ├── ETH.svg
│ │ │ │ ├── EURAUD.svg
│ │ │ │ ├── EURCAD.svg
│ │ │ │ ├── EURCHF.svg
│ │ │ │ ├── EURGBP.svg
│ │ │ │ ├── EURJPY.svg
│ │ │ │ ├── EURNZD.svg
│ │ │ │ ├── EURUSD.svg
│ │ │ │ ├── FTSE100.svg
│ │ │ │ ├── GAS.svg
│ │ │ │ ├── GBPCHF.svg
│ │ │ │ ├── GBPJPY.svg
│ │ │ │ ├── GBPUSD.svg
│ │ │ │ ├── GER40.svg
│ │ │ │ ├── HSI.svg
│ │ │ │ ├── ICP.svg
│ │ │ │ ├── JP225.svg
│ │ │ │ ├── LINK.svg
│ │ │ │ ├── LTC.svg
│ │ │ │ ├── MATIC.svg
│ │ │ │ ├── N225.svg
│ │ │ │ ├── NAS100.svg
│ │ │ │ ├── NEAR.svg
│ │ │ │ ├── NG.svg
│ │ │ │ ├── NZDUSD.svg
│ │ │ │ ├── OIL.svg
│ │ │ │ ├── SOL.svg
│ │ │ │ ├── SP500.svg
│ │ │ │ ├── SPX500.svg
│ │ │ │ ├── TRX.svg
│ │ │ │ ├── UK100.svg
│ │ │ │ ├── UNI.svg
│ │ │ │ ├── US30.svg
│ │ │ │ ├── USDCAD.svg
│ │ │ │ ├── USDCHF.svg
│ │ │ │ ├── USDJPY.svg
│ │ │ │ ├── USDT.svg
│ │ │ │ ├── VIX.svg
│ │ │ │ ├── WTI.svg
│ │ │ │ ├── XAGUSD.svg
│ │ │ │ ├── XAUUSD.svg
│ │ │ │ ├── XLM.svg
│ │ │ │ └── XRP.svg
│ │ │ ├── flags/
│ │ │ │ ├── au.svg
│ │ │ │ ├── ca.svg
│ │ │ │ ├── ch.svg
│ │ │ │ ├── de.png
│ │ │ │ ├── de.svg
│ │ │ │ ├── eu.svg
│ │ │ │ ├── gb.svg
│ │ │ │ ├── hk.svg
│ │ │ │ ├── jp.svg
│ │ │ │ ├── nz.svg
│ │ │ │ └── us.svg
│ │ │ ├── forex/
│ │ │ │ ├── AUDCAD.png
│ │ │ │ ├── AUDCHF.png
│ │ │ │ ├── AUDJPY.png
│ │ │ │ ├── AUDNZD.png
│ │ │ │ ├── AUDUSD.png
│ │ │ │ ├── CADCHF.png
│ │ │ │ ├── CADJPY.png
│ │ │ │ ├── CHFJPY.png
│ │ │ │ ├── EURAUD.png
│ │ │ │ ├── EURCAD.png
│ │ │ │ ├── EURCHF.png
│ │ │ │ ├── EURGBP.png
│ │ │ │ ├── EURJPY.png
│ │ │ │ ├── EURNZD.png
│ │ │ │ ├── EURUSD.png
│ │ │ │ ├── GBPAUD.png
│ │ │ │ ├── GBPCAD.png
│ │ │ │ ├── GBPCHF.png
│ │ │ │ ├── GBPJPY.png
│ │ │ │ ├── GBPNZD.png
│ │ │ │ ├── GBPUSD.png
│ │ │ │ ├── NZDCAD.png
│ │ │ │ ├── NZDCHF.png
│ │ │ │ ├── NZDJPY.png
│ │ │ │ ├── NZDUSD.png
│ │ │ │ ├── USDCAD.png
│ │ │ │ ├── USDCHF.png
│ │ │ │ └── USDJPY.png
│ │ │ ├── index/
│ │ │ │ ├── DAX40.svg
│ │ │ │ ├── FTSE100.svg
│ │ │ │ ├── GER40.svg
│ │ │ │ ├── HSI.svg
│ │ │ │ ├── JP225.svg
│ │ │ │ ├── N225.svg
│ │ │ │ ├── NAS100.svg
│ │ │ │ ├── SP500.svg
│ │ │ │ ├── SPX500.svg
│ │ │ │ ├── UK100.svg
│ │ │ │ ├── US30.svg
│ │ │ │ └── VIX.svg
│ │ │ ├── metal/
│ │ │ │ ├── XAG.png
│ │ │ │ ├── XAGUSD.png
│ │ │ │ ├── XAU.png
│ │ │ │ ├── XAUUSD.png
│ │ │ │ ├── XPD.png
│ │ │ │ ├── XPDUSD.png
│ │ │ │ ├── XPT.png
│ │ │ │ └── XPTUSD.png
│ │ │ └── symbols.json
│ │ ├── tesseract-data/
│ │ │ ├── eng.traineddata.gz
│ │ │ ├── fas.traineddata.gz
│ │ │ └── NOTICE.txt
│ │ ├── apple-icon-backup.png
│ │ ├── apple-icon.png
│ │ ├── bg-b-clean.png
│ │ ├── bg-b-exact.png
│ │ ├── bg-b.png
│ │ ├── email-reset-lock.png
│ │ ├── emotion-icons.js
│ │ ├── favicon-16-backup.png
│ │ ├── favicon-16.png
│ │ ├── favicon-32-backup.png
│ │ ├── favicon-32.png
│ │ ├── gold-lock-transparent.png
│ │ ├── gold-lock.png
│ │ ├── icon-192-backup.png
│ │ ├── icon-192.png
│ │ ├── icon-512-backup.png
│ │ ├── icon-512.png
│ │ ├── logo-icon-512-purple-backup2.png
│ │ ├── logo-icon-512.png
│ │ ├── logo-original-1024-purple-backup.png
│ │ ├── logo-original-1024.png
│ │ ├── symbol-icons.js
│ │ ├── velora-ai-briefing.css
│ │ ├── velora-ai-free-ask.css
│ │ ├── velora-data.js
│ │ ├── velora-desk-pro.css
│ │ ├── velora-dialog.js
│ │ ├── velora-dynamic-content.js
│ │ ├── velora-feature-icons.css
│ │ ├── velora-how-modals.js
│ │ ├── velora-latin-digits.css
│ │ ├── velora-latin-digits.js
│ │ ├── velora-locale-bootstrap.js
│ │ ├── velora-locale-registry.js
│ │ ├── velora-localization.css
│ │ ├── velora-localization.js
│ │ ├── velora-logo.svg
│ │ ├── velora-mobile-app-icon.css
│ │ ├── velora-monthly-consistency.css
│ │ ├── velora-nav-cta.css
│ │ ├── velora-premium-footer.css
│ │ ├── velora-premium-footer.js
│ │ ├── velora-pricing-pro.css
│ │ ├── velora-product-gallery.css
│ │ ├── velora-product-gallery.js
│ │ ├── velora-sidebar-icons.css
│ │ ├── velora-sidebar-icons.js
│ │ ├── velora-smart-import-dash.js
│ │ ├── velora-smart-import.css
│ │ ├── velora-smart-import.js
│ │ ├── velora-step-nodes.css
│ │ ├── velora-steps.css
│ │ ├── velora-sym-picker.js
│ │ └── velora-ui-sound.js
│ ├── locales/
│ │ ├── chunks/
│ │ │ ├── en/
│ │ │ │ ├── admin.json
│ │ │ │ ├── auth.json
│ │ │ │ ├── blog.json
│ │ │ │ ├── common.json
│ │ │ │ ├── dashboard.json
│ │ │ │ ├── errors.json
│ │ │ │ ├── intelligence.json
│ │ │ │ ├── landing.json
│ │ │ │ ├── markets.json
│ │ │ │ ├── news.json
│ │ │ │ ├── performance.json
│ │ │ │ ├── privacy.json
│ │ │ │ ├── profile.json
│ │ │ │ ├── support.json
│ │ │ │ ├── terms.json
│ │ │ │ ├── trades.json
│ │ │ │ └── wallet.json
│ │ │ └── fa/
│ │ │ ├── admin.json
│ │ │ ├── auth.json
│ │ │ ├── blog.json
│ │ │ ├── common.json
│ │ │ ├── dashboard.json
│ │ │ ├── errors.json
│ │ │ ├── intelligence.json
│ │ │ ├── landing.json
│ │ │ ├── markets.json
│ │ │ ├── news.json
│ │ │ ├── performance.json
│ │ │ ├── privacy.json
│ │ │ ├── profile.json
│ │ │ ├── support.json
│ │ │ ├── terms.json
│ │ │ ├── trades.json
│ │ │ └── wallet.json
│ │ ├── catalog.schema.json
│ │ ├── en.json
│ │ ├── fa.json
│ │ ├── feature-manifest.json
│ │ ├── manifest.json
│ │ └── manifest.schema.json
│ ├── samples/
│ │ ├── mt5-closed-trade.png
│ │ └── mt5-fa-card.jpg
│ └── velora-logo.svg
├── register/
│ ├── index.html
│ └── index.txt
├── reset-password/
│ ├── index.html
│ └── index.txt
├── support/
│ └── index.html
├── terms/
│ └── index.html
├── tools/
│ └── localization/
│ ├── brand-policy.json
│ ├── brand_policy.py
│ ├── build_localized_static.py
│ ├── feature-map.json
│ ├── legacy-phrase-pairs.json
│ ├── manual-english-to-persian.json
│ ├── manual-translations.json
│ ├── migrate_static.py
│ ├── normalize_brand.py
│ ├── routes.json
│ ├── sync_registry.py
│ ├── test_backend_localization.php
│ ├── test_brand_policy.py
│ ├── test_catalog_preload.js
│ ├── test_dynamic_content.js
│ ├── test_http_localization.py
│ ├── test_http_routes.py
│ ├── test_locale_resolution.js
│ ├── test_server_locale.php
│ ├── test_switcher_mount.js
│ └── validate_localization.py
├── trades/
│ ├── new/
│ │ ├── index.html
│ │ └── index.txt
│ ├── index.html
│ └── index.txt
├── verify-email/
│ ├── index.html
│ └── index.txt
├── wallet/
│ └── index.html
├── .gitignore
├── .htaccess
├── 00-INSTALL-FA.txt
├── 404.html
├── ALL_INCLUDED_CHANGES_FA.txt
├── apple-icon.png
├── CHANGELOG_FA.txt
├── DEPLOYMENT_GUIDE_FA.md
├── DEPLOYMENT_MANIFEST.json
├── DEPLOYMENT_README.txt
├── extract-persian.py
├── favicon-16.png
├── favicon-32.png
├── favicon-48.png
├── favicon.ico
├── googleacbef8d6416f1474.html
├── icon-192.png
├── icon-512.png
├── index.html
├── locale-router.php
├── LOCALIZATION_ARCHITECTURE.md
├── LOCALIZATION_IMPLEMENTATION_REPORT.md
├── MANIFEST.json
├── manifest.json
├── PATCH_INSTRUCTIONS_FA.txt
├── PATCH_MANIFEST.json
├── README.txt
├── robots.txt
├── router.php
├── sitemap.xml
├── test-localization.html
├── tools_align.py
└── velora-logo.svg
```

## Appendix B — Complete Frontend Route Registry

All 28 templates and all 59 expected generated outputs exist. Three additional English outputs are legacy slug aliases and are byte-identical to their preferred English-slug outputs.

| Source template | FA generated output | EN preferred output | Legacy alias |
|---|---|---|---|
| index.html | localized/fa/index.html | localized/en/index.html | — |
| 404.html | localized/fa/404.html | localized/en/404.html | — |
| 404/index.html | localized/fa/404/index.html | localized/en/404/index.html | — |
| accounts/connect/index.html | localized/fa/accounts/connect/index.html | localized/en/accounts/connect/index.html | — |
| admin/index.html | localized/fa/admin/index.html | localized/en/admin/index.html | — |
| dashboard/index.html | localized/fa/dashboard/index.html | localized/en/dashboard/index.html | — |
| forgot-password/index.html | localized/fa/forgot-password/index.html | localized/en/forgot-password/index.html | — |
| intelligence/index.html | localized/fa/intelligence/index.html | localized/en/intelligence/index.html | — |
| login/index.html | localized/fa/login/index.html | localized/en/login/index.html | — |
| markets/index.html | localized/fa/markets/index.html | localized/en/markets/index.html | — |
| news/index.html | localized/fa/news/index.html | localized/en/news/index.html | — |
| performance/index.html | localized/fa/performance/index.html | localized/en/performance/index.html | — |
| privacy/index.html | localized/fa/privacy/index.html | localized/en/privacy/index.html | — |
| profile/index.html | localized/fa/profile/index.html | localized/en/profile/index.html | — |
| register/index.html | localized/fa/register/index.html | localized/en/register/index.html | — |
| reset-password/index.html | localized/fa/reset-password/index.html | localized/en/reset-password/index.html | — |
| support/index.html | localized/fa/support/index.html | localized/en/support/index.html | — |
| terms/index.html | localized/fa/terms/index.html | localized/en/terms/index.html | — |
| trades/index.html | localized/fa/trades/index.html | localized/en/trades/index.html | — |
| trades/new/index.html | localized/fa/trades/new/index.html | localized/en/trades/new/index.html | — |
| verify-email/index.html | localized/fa/verify-email/index.html | localized/en/verify-email/index.html | — |
| wallet/index.html | localized/fa/wallet/index.html | localized/en/wallet/index.html | — |
| blog/index.html | localized/fa/blog/index.html | localized/en/blog/index.html | — |
| blog/what-is-trading-journal/index.html | localized/fa/blog/what-is-trading-journal/index.html | localized/en/blog/what-is-a-trading-journal/index.html | English legacy alias: localized/en/blog/what-is-trading-journal/index.html |
| blog/forex-trading-journal/index.html | localized/fa/blog/forex-trading-journal/index.html | localized/en/blog/forex-trading-journal/index.html | — |
| blog/why-traders-need-journal/index.html | localized/fa/blog/why-traders-need-journal/index.html | localized/en/blog/why-traders-need-a-journal/index.html | English legacy alias: localized/en/blog/why-traders-need-journal/index.html |
| blog/risk-management-trading/index.html | localized/fa/blog/risk-management-trading/index.html | localized/en/blog/risk-management-in-trading/index.html | English legacy alias: localized/en/blog/risk-management-trading/index.html |
| blog/mt4-mt5-trading-journal/index.html | localized/fa/blog/mt4-mt5-trading-journal/index.html | localized/en/blog/mt4-mt5-trading-journal/index.html | — |

## Appendix C — Exact and Near-Duplicate Inventory

### C.1 All Exact SHA-256 Duplicate Groups

| Group | Files | Paths | Disposition |
|---|---|---|---|
| 1 | 7 | public/assets/symbols/fallback/EURAUD.svg; public/assets/symbols/fallback/EURCAD.svg; public/assets/symbols/fallback/EURCHF.svg; public/assets/symbols/fallback/EURGBP.svg; public/assets/symbols/fallback/EURJPY.svg; public/assets/symbols/fallback/EURNZD.svg; public/assets/symbols/fallback/EURUSD.svg | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 2 | 5 | public/assets/symbols/fallback/US30.svg; public/assets/symbols/fallback/USDCAD.svg; public/assets/symbols/fallback/USDCHF.svg; public/assets/symbols/fallback/USDJPY.svg; public/assets/symbols/fallback/USDT.svg | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 3 | 4 | _next/static/218lBAl1Pgco_ZAYgrXcP/_ssgManifest.js; _next/static/FM4dgr6qVkuvEFTtyilqO/_ssgManifest.js; _next/static/X3dim2gBYlGAf45rCn4KJ/_ssgManifest.js; _next/static/z7aWr4SvvOLxF09Kc8Wha/_ssgManifest.js | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 4 | 4 | public/assets/symbols/fallback/AUDCAD.svg; public/assets/symbols/fallback/AUDJPY.svg; public/assets/symbols/fallback/AUDNZD.svg; public/assets/symbols/fallback/AUDUSD.svg | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 5 | 3 | _next/static/218lBAl1Pgco_ZAYgrXcP/_buildManifest.js; _next/static/X3dim2gBYlGAf45rCn4KJ/_buildManifest.js; _next/static/z7aWr4SvvOLxF09Kc8Wha/_buildManifest.js | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 6 | 3 | api/README-#U0622#U067e#U0644#U0648#U062f.txt; api/README-persian-1.txt; api/README-persian-2.txt | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 7 | 3 | api/src/Auth/README-#U0622#U067e#U0644#U0648#U062f.txt; api/src/Auth/README-persian-5.txt; api/src/Auth/README-persian-6.txt | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 8 | 3 | api/src/Core/README-#U0622#U067e#U0644#U0648#U062f.txt; api/src/Core/README-persian-3.txt; api/src/Core/README-persian-4.txt | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 9 | 3 | public/assets/symbols/commodity/BRENT.svg; public/assets/symbols/commodity/OIL.svg; public/assets/symbols/commodity/WTI.svg | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 10 | 3 | public/assets/symbols/fallback/GBPCHF.svg; public/assets/symbols/fallback/GBPJPY.svg; public/assets/symbols/fallback/GBPUSD.svg | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 11 | 3 | public/assets/velora-logo.svg; public/velora-logo.svg; velora-logo.svg | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 12 | 2 | 00-INSTALL-FA.txt; CHANGELOG_FA.txt | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 13 | 2 | 404.html; 404/index.html | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 14 | 2 | _database/database_corrected.sql; api/database/database_corrected.sql | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 15 | 2 | _next/static/chunks/app/login/page-1982df9f616796cd.js; _next/static/chunks/app/login/page-63ee9d52b18ccfc9.js | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 16 | 2 | apple-icon.png; public/assets/apple-icon.png | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 17 | 2 | favicon-16.png; public/assets/favicon-16.png | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 18 | 2 | favicon-32.png; public/assets/favicon-32.png | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 19 | 2 | icon-192.png; public/assets/icon-192.png | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 20 | 2 | icon-512.png; public/assets/icon-512.png | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 21 | 2 | localized/en/blog/risk-management-in-trading/index.html; localized/en/blog/risk-management-trading/index.html | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 22 | 2 | localized/en/blog/what-is-a-trading-journal/index.html; localized/en/blog/what-is-trading-journal/index.html | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 23 | 2 | localized/en/blog/why-traders-need-a-journal/index.html; localized/en/blog/why-traders-need-journal/index.html | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 24 | 2 | public/assets/asset-icons2/icon-20.png; public/assets/symbols/metal/XPT.png | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 25 | 2 | public/assets/asset-icons2/icon-21.png; public/assets/symbols/metal/XPD.png | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 26 | 2 | public/assets/symbols/commodity/GAS.svg; public/assets/symbols/commodity/NG.svg | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 27 | 2 | public/assets/symbols/fallback/AAPL.svg; public/assets/symbols/fallback/AAVE.svg | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 28 | 2 | public/assets/symbols/fallback/DOGE.svg; public/assets/symbols/fallback/DOT.svg | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 29 | 2 | public/assets/symbols/fallback/SP500.svg; public/assets/symbols/fallback/SPX500.svg | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 30 | 2 | public/assets/symbols/fallback/XAGUSD.svg; public/assets/symbols/fallback/XAUUSD.svg | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 31 | 2 | public/assets/symbols/index/DAX40.svg; public/assets/symbols/index/GER40.svg | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 32 | 2 | public/assets/symbols/index/FTSE100.svg; public/assets/symbols/index/UK100.svg | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 33 | 2 | public/assets/symbols/index/JP225.svg; public/assets/symbols/index/N225.svg | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 34 | 2 | public/assets/symbols/index/SP500.svg; public/assets/symbols/index/SPX500.svg | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 35 | 2 | public/assets/symbols/metal/XAG.png; public/assets/symbols/metal/XAGUSD.png | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |
| 36 | 2 | public/assets/symbols/metal/XAU.png; public/assets/symbols/metal/XAUUSD.png | Classify as generated, alias, compatibility, library, backup, or redundant before deletion |

### C.2 Near-Duplicate Evidence

Triage found 117 normalized-text pairs and 13 perceptual-image pairs. Most text pairs are source↔generated or shared-shell relationships. Perceptual similarity is candidate evidence only, never deletion authority.

| Pair / family | Evidence | Decision |
|---|---|---|
| public/assets/bg-b-clean.png ↔ bg-b.png | Pixel-identical RGB, 1254×1254; byte sizes differ | Candidate only; no active text reference |
| api/assets/email/velora-lock.jpg ↔ velora-logo.jpg | Pixel-identical RGB, 104×104; byte sizes differ | Semantic names differ; both release-excluded |
| Source template ↔ localized/fa output | High normalized-text similarity on many routes | Expected generated relationship; never edit/deduplicate manually |
| _next paired chunks/build manifests | Greater than 0.99 normalized similarity across old build IDs | Legacy artifacts; see F-18 |

Explicit rejection: GBPAUD.png and GBPNZD.png have real pixel differences and different market meanings. They are not duplicates and must not be merged.

## Appendix D — Unused and Legacy Candidates (No Deletion Approval)

### D.1 asset-icons2 Candidates — 54 Files

Only icon-20.png through icon-25.png are referenced by symbols.json. The following are candidates, not deletion instructions:

```
public/assets/asset-icons2/icon-00.png
public/assets/asset-icons2/icon-01.png
public/assets/asset-icons2/icon-02.png
public/assets/asset-icons2/icon-03.png
public/assets/asset-icons2/icon-04.png
public/assets/asset-icons2/icon-05.png
public/assets/asset-icons2/icon-06.png
public/assets/asset-icons2/icon-07.png
public/assets/asset-icons2/icon-08.png
public/assets/asset-icons2/icon-09.png
public/assets/asset-icons2/icon-10.png
public/assets/asset-icons2/icon-11.png
public/assets/asset-icons2/icon-12.png
public/assets/asset-icons2/icon-13.png
public/assets/asset-icons2/icon-14.png
public/assets/asset-icons2/icon-15.png
public/assets/asset-icons2/icon-16.png
public/assets/asset-icons2/icon-17.png
public/assets/asset-icons2/icon-18.png
public/assets/asset-icons2/icon-19.png
public/assets/asset-icons2/icon-26.png
public/assets/asset-icons2/icon-27.png
public/assets/asset-icons2/icon-28.png
public/assets/asset-icons2/icon-29.png
public/assets/asset-icons2/icon-30.png
public/assets/asset-icons2/icon-31.png
public/assets/asset-icons2/icon-32.png
public/assets/asset-icons2/icon-33.png
public/assets/asset-icons2/icon-34.png
public/assets/asset-icons2/icon-35.png
public/assets/asset-icons2/icon-36.png
public/assets/asset-icons2/icon-37.png
public/assets/asset-icons2/icon-38.png
public/assets/asset-icons2/icon-39.png
public/assets/asset-icons2/icon-40.png
public/assets/asset-icons2/icon-41.png
public/assets/asset-icons2/icon-42.png
public/assets/asset-icons2/icon-43.png
public/assets/asset-icons2/icon-44.png
public/assets/asset-icons2/icon-45.png
public/assets/asset-icons2/icon-46.png
public/assets/asset-icons2/icon-47.png
public/assets/asset-icons2/icon-48.png
public/assets/asset-icons2/icon-49.png
public/assets/asset-icons2/icon-50.png
public/assets/asset-icons2/icon-51.png
public/assets/asset-icons2/icon-52.png
public/assets/asset-icons2/icon-53.png
public/assets/asset-icons2/icon-54.png
public/assets/asset-icons2/icon-55.png
public/assets/asset-icons2/icon-56.png
public/assets/asset-icons2/icon-57.png
public/assets/asset-icons2/icon-58.png
public/assets/asset-icons2/icon-59.png
```

### D.2 Symbol-Library Candidates — 93 Files

symbol-icons.js constructs Forex paths dynamically, so this list requires runtime fixtures and access evidence:

```
public/assets/symbols/commodity/BRENT.svg
public/assets/symbols/commodity/GAS.svg
public/assets/symbols/commodity/NG.svg
public/assets/symbols/commodity/OIL.svg
public/assets/symbols/commodity/WTI.svg
public/assets/symbols/crypto/PEPE.png
public/assets/symbols/fallback/AAPL.svg
public/assets/symbols/fallback/AAVE.svg
public/assets/symbols/fallback/ADA.svg
public/assets/symbols/fallback/ATOM.svg
public/assets/symbols/fallback/AUDCAD.svg
public/assets/symbols/fallback/AUDJPY.svg
public/assets/symbols/fallback/AUDNZD.svg
public/assets/symbols/fallback/AUDUSD.svg
public/assets/symbols/fallback/AVAX.svg
public/assets/symbols/fallback/BCH.svg
public/assets/symbols/fallback/BNB.svg
public/assets/symbols/fallback/BRENT.svg
public/assets/symbols/fallback/BTC.svg
public/assets/symbols/fallback/COPPER.svg
public/assets/symbols/fallback/DAX40.svg
public/assets/symbols/fallback/DOGE.svg
public/assets/symbols/fallback/DOT.svg
public/assets/symbols/fallback/ETH.svg
public/assets/symbols/fallback/EURAUD.svg
public/assets/symbols/fallback/EURCAD.svg
public/assets/symbols/fallback/EURCHF.svg
public/assets/symbols/fallback/EURGBP.svg
public/assets/symbols/fallback/EURJPY.svg
public/assets/symbols/fallback/EURNZD.svg
public/assets/symbols/fallback/EURUSD.svg
public/assets/symbols/fallback/FTSE100.svg
public/assets/symbols/fallback/GAS.svg
public/assets/symbols/fallback/GBPCHF.svg
public/assets/symbols/fallback/GBPJPY.svg
public/assets/symbols/fallback/GBPUSD.svg
public/assets/symbols/fallback/GER40.svg
public/assets/symbols/fallback/HSI.svg
public/assets/symbols/fallback/ICP.svg
public/assets/symbols/fallback/JP225.svg
public/assets/symbols/fallback/LINK.svg
public/assets/symbols/fallback/LTC.svg
public/assets/symbols/fallback/MATIC.svg
public/assets/symbols/fallback/N225.svg
public/assets/symbols/fallback/NAS100.svg
public/assets/symbols/fallback/NEAR.svg
public/assets/symbols/fallback/NG.svg
public/assets/symbols/fallback/NZDUSD.svg
public/assets/symbols/fallback/OIL.svg
public/assets/symbols/fallback/SOL.svg
public/assets/symbols/fallback/SP500.svg
public/assets/symbols/fallback/SPX500.svg
public/assets/symbols/fallback/TRX.svg
public/assets/symbols/fallback/UK100.svg
public/assets/symbols/fallback/UNI.svg
public/assets/symbols/fallback/US30.svg
public/assets/symbols/fallback/USDCAD.svg
public/assets/symbols/fallback/USDCHF.svg
public/assets/symbols/fallback/USDJPY.svg
public/assets/symbols/fallback/USDT.svg
public/assets/symbols/fallback/VIX.svg
public/assets/symbols/fallback/WTI.svg
public/assets/symbols/fallback/XAGUSD.svg
public/assets/symbols/fallback/XAUUSD.svg
public/assets/symbols/fallback/XLM.svg
public/assets/symbols/fallback/XRP.svg
public/assets/symbols/flags/au.svg
public/assets/symbols/flags/ca.svg
public/assets/symbols/flags/ch.svg
public/assets/symbols/flags/de.png
public/assets/symbols/flags/de.svg
public/assets/symbols/flags/eu.svg
public/assets/symbols/flags/gb.svg
public/assets/symbols/flags/hk.svg
public/assets/symbols/flags/jp.svg
public/assets/symbols/flags/nz.svg
public/assets/symbols/flags/us.svg
public/assets/symbols/index/DAX40.svg
public/assets/symbols/index/FTSE100.svg
public/assets/symbols/index/GER40.svg
public/assets/symbols/index/HSI.svg
public/assets/symbols/index/JP225.svg
public/assets/symbols/index/N225.svg
public/assets/symbols/index/NAS100.svg
public/assets/symbols/index/SP500.svg
public/assets/symbols/index/SPX500.svg
public/assets/symbols/index/UK100.svg
public/assets/symbols/index/US30.svg
public/assets/symbols/index/VIX.svg
public/assets/symbols/metal/XAGUSD.png
public/assets/symbols/metal/XAUUSD.png
public/assets/symbols/metal/XPDUSD.png
public/assets/symbols/metal/XPTUSD.png
```

### D.3 Legacy Next/RSC Artifacts — 49 Files

```
_next/static/218lBAl1Pgco_ZAYgrXcP/_buildManifest.js
_next/static/218lBAl1Pgco_ZAYgrXcP/_ssgManifest.js
_next/static/FM4dgr6qVkuvEFTtyilqO/_buildManifest.js
_next/static/FM4dgr6qVkuvEFTtyilqO/_ssgManifest.js
_next/static/X3dim2gBYlGAf45rCn4KJ/_buildManifest.js
_next/static/X3dim2gBYlGAf45rCn4KJ/_ssgManifest.js
_next/static/chunks/app/_not-found/page-402ff515b2c14f9f.js
_next/static/chunks/app/admin/page-1e9989bfd2a6baaa.js
_next/static/chunks/app/admin/page-5c38b2cabbf4d752.js
_next/static/chunks/app/dashboard/page-54e0b502fc4aa20c.js
_next/static/chunks/app/dashboard/page-d9f448fc68bf4336.js
_next/static/chunks/app/error-cdd6999f3f915eb9.js
_next/static/chunks/app/forgot-password/page-0a5f23bad3bd659c.js
_next/static/chunks/app/forgot-password/page-4f1549709939907d.js
_next/static/chunks/app/global-error-820a8860d14fee4f.js
_next/static/chunks/app/layout-4b81b4155c2d976a.js
_next/static/chunks/app/layout-dcf3934a7520ada2.js
_next/static/chunks/app/loading-03a2f68b8da55ce2.js
_next/static/chunks/app/login/page-1982df9f616796cd.js
_next/static/chunks/app/login/page-63ee9d52b18ccfc9.js
_next/static/chunks/app/page-27520d1ae1f72e75.js
_next/static/chunks/app/page-5f5bcd2f87251aac.js
_next/static/chunks/app/profile/page-83f492d16914ea09.js
_next/static/chunks/app/profile/page-f089a1aebc69e352.js
_next/static/chunks/app/register/page-1a356346f9466047.js
_next/static/chunks/app/register/page-e59c08b61ecfbf40.js
_next/static/chunks/app/reset-password/page-448e660679ca5054.js
_next/static/chunks/app/reset-password/page-ff7ccd03538232f4.js
_next/static/chunks/app/trades/new/page-27f7896a3c59ff4a.js
_next/static/chunks/app/trades/new/page-390d1101e58d9e5d.js
_next/static/chunks/app/trades/page-38e15ee67f5302c6.js
_next/static/chunks/app/trades/page-e8fb3e9bd06893c7.js
_next/static/chunks/app/verify-email/page-1c83a6cba9633ded.js
_next/static/chunks/app/verify-email/page-aa0aa21a43021492.js
_next/static/chunks/pages/_app-44e5d7e52411c6a5.js
_next/static/chunks/pages/_error-4a2fb0735435b993.js
_next/static/css/35c900d50ac706ed.css
_next/static/css/fdce29635a02623d.css
_next/static/z7aWr4SvvOLxF09Kc8Wha/_buildManifest.js
_next/static/z7aWr4SvvOLxF09Kc8Wha/_ssgManifest.js
admin/index.txt
dashboard/index.txt
login/index.txt
profile/index.txt
register/index.txt
reset-password/index.txt
trades/index.txt
trades/new/index.txt
verify-email/index.txt
```

### D.4 Backup-Named Files Without Active Code References

```
api/assets/email/velora-logo-backup.png
api/database/db_backup.sql
public/assets/apple-icon-backup.png
public/assets/favicon-16-backup.png
public/assets/favicon-32-backup.png
public/assets/icon-192-backup.png
public/assets/icon-512-backup.png
public/assets/logo-icon-512-purple-backup2.png
public/assets/logo-original-1024-purple-backup.png
```

db_backup.sql is also a HIGH data-hygiene finding under F-02; it is not merely a cleanup candidate.

## Appendix E — Verification Matrix and References

| Area | Method | Result | Status |
|---|---|---|---|
| Tree | Filesystem walk | 630 files / 159 directories | PASS |
| PHP includes | Static include resolver | 26 checked / 0 missing | PASS |
| HTML assets | 96 HTML / 2,645 direct references | 0 direct missing | PASS |
| JavaScript paths | Source path/import resolver | 0 relative missing | PASS |
| PHP namespace | PSR-4 symbol/path graph | 53 symbols; 0 mismatch/cycle | PASS |
| API routes | Route → class → method | 35/35 plus health | PASS |
| Generated localization | routes.json output contract | 59/59 present | PASS |
| Localization JSON | JSON parse | 40 valid | PASS |
| Feature chunks | Size + SHA-256 | 34/34 | PASS |
| Localization validator | validate_localization.py | 622 reports; exit 1 | FAIL / OPEN |
| Case sensitivity | Case-fold path scan | 1 collision | WARN |
| Duplicates | SHA-256 + pixel triage | 36 exact groups; 117 text / 13 image candidates | WARN |
| Version comparison | ZIP path + SHA-256 | 36 changed, 9 added, 0 removed | PASS |
| DB/API runtime | Live execution | Required tools unavailable in sandbox | STAGING REQUIRED |
| OCR host | System dependency preflight | Not proven on target host | STAGING REQUIRED |

### External Technical References

- https://dev.mysql.com/doc/refman/8.0/en/alter-table.html
- https://mariadb.com/docs/server/reference/sql-statements/data-definition/alter/alter-table
- https://github.com/naptha/tesseract.js/blob/master/docs/api.md
- https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/package.json

### Change-Control Rule

Before any structural change: review this document → approve the finding/goal and smallest patch → back up and stage → make the incremental change → rerun the verification matrix → report CHANGED / ADDED / DELETED / UNCHANGED / SERVER ACTION → update this document. Never reset, overwrite, delete, or migrate Production .env/data without explicit instruction.

**END OF VERIFIED ENGLISH AS-IS STRUCTURE BASELINE**

---

# BASELINE DELTA / STALE FINDINGS

> This section is separate from the original baseline text above. It records only the approved delta items; it does not modify, reinterpret, or override any part of the transcribed baseline.

- **A-1** — مسیر env تغییر کرده (the `.env` path has changed relative to the audited baseline).
- **A-2** — تعداد خروجی locale تغییر کرده (the count of generated locale outputs has changed relative to the audited baseline).
- **A-3** — تعداد فایل‌ها/پوشه‌ها تغییر کرده (the file/directory counts have changed relative to the audited baseline).
- **A-4** — وضعیت endpointها نیازمند بازشماری است (endpoint status requires re-enumeration).
- **A-5** — لایه CI/CD اضافه شده (a CI/CD layer has been added since the audited baseline).
