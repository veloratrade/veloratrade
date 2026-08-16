# VELORA Trade localization refactor — implementation report

Date: 2026-08-13  
Release: `2026.08.13.7`  
Project: `/home/user/404-8-extracted`

## Result

The localization foundation now uses centralized canonical templates, build-time localized HTML, server-side locale negotiation, usage-scoped browser catalogs and an asynchronous dynamic-content translation boundary. First-page translation is no longer a JavaScript responsibility.

Current release inventory:

- enabled locales: `fa`, `en`;
- catalog keys per locale: 1,196;
- canonical templates: 28;
- generated localized HTML: 59;
- generated feature chunks: 34 (17 per locale);
- validated static/runtime references: 1,050;
- all HTML included in validation: 88;
- generated locale registry: 893 bytes, zero embedded messages.

## Acceptance result

| Requirement | Implemented result |
|---|---|
| Correct-language HTML before first paint | `build_localized_static.py` renders `localized/{locale}` and `locale-router.php` serves the resolved file directly. HTML carries correct `lang`, `dir`, prelocalized marker and final translated copy before browser JavaScript runs. |
| First visit and persisted choice | Unprefixed requests use valid manual-choice cookie → primary `Accept-Language` → default only when browser language is absent. Persisted manual choice wins on later unprefixed visits. |
| Unsupported language → English | `de-DE`, `ar-SA` and other unsupported primary languages resolve to manifest fallback `en`/LTR, not Persian. |
| Stable locale URLs and SEO | `/fa/...` and `/en/...` are explicit authoritative routes. Every generated output has locale-prefixed canonical, matching `og:url`, all enabled `hreflang` alternates and English `x-default`. Three historical English blog slugs are locale-specific build aliases, not source copies. |
| One source page per feature | The duplicate maintained `/en` source tree is removed. `routes.json` maps 28 canonical templates to locale outputs. Generated `localized/` is excluded from migration. |
| Central catalogs and feature/page loading | Catalogs are centralized and parity-checked. Build-time usage analysis creates `common`, `errors`, `auth`, `dashboard`, `markets`, `trades`, `blog`, `landing`, etc. A page fetches only its declared active-locale chunks. |
| No full-catalog preload | The 893-byte registry contains metadata only. Feature regression confirms zero full-catalog requests and no inactive-locale fetches. |
| UI language independent from data | `VeloraData` transports/normalizes raw resources without attaching UI locale. HTTP regressions confirm language-neutral API behavior. |
| Presentation-only formatting | Number, currency, percent, date/time and relative formatting use centralized `Intl` helpers. Static English output also receives build-time Latin numbering/punctuation normalization. |
| Original-first dynamic content | Source fields return synchronously; cache lookup is asynchronous; misses keep the source; locale and identity fences reject stale responses. |
| Translation outside critical path | The public content route is cache-only. Enqueue occurs during ingestion and provider execution is restricted to the CLI worker. |
| Real-time pipeline protected | Live data does not wait for catalog loading, provider/AI execution or translation queue work. |
| Future-language/page ready | Locale metadata, route outputs, aliases, shared thresholds and runtime namespaces are data-driven. A new locale/page does not require new locale conditionals in the runtime core. |
| RTL/LTR | Direction comes from manifest metadata; shared CSS handles logical placement and financial-value isolation. |
| Policy-scoped public brand | `brand-policy.json` keeps `VELORA` as the default and allowlists only two Persian landing-footer messages for `ولورا`: copyright and legal disclaimer. Catalog/generated-HTML validation resolves the expected token by locale + message key; every other occurrence, including the footer brand heading and `app.VELORA.io/dashboard`, remains `VELORA`. |
| Navigation-first locale control | The selector mounts inline in an explicit slot, app action bar, landing header or public header. Minimal pages use a bottom logical-corner fallback. The landing selector remains visible outside the collapsed menu; narrow headers use an accessible compact globe control. Locale options remain registry-driven. |
| cPanel/LiteSpeed | Root `.htaccess` keeps API/static paths outside page negotiation and sends page requests to `locale-router.php`; direct generated/source/tool exposure is denied. |
| Cache correctness | Negotiated HTML is private/revalidated and varies on cookie/language. Strong ETag returns 304. Static assets/catalog chunks bypass HTML negotiation and receive public cache rules. |

## Architecture artifacts

- `public/locales/manifest.json` and schemas — locale, direction, script, numbering, cookie and feature-base contract.
- `public/locales/{fa,en}.json` — full backend/build catalogs.
- `tools/localization/routes.json` — canonical route and locale-alias map.
- `tools/localization/feature-map.json` — usage-scoped feature policy.
- `tools/localization/brand-policy.json` and `brand_policy.py` — centralized default/localized display-token policy and shared helpers.
- `tools/localization/build_localized_static.py` — HTML/SEO/chunk generator.
- `public/locales/feature-manifest.json` and `chunks/` — generated browser catalog metadata/assets.
- `locale-router.php`, `router.php`, `.htaccess` — production/development first-paint resolver.
- `public/assets/velora-locale-bootstrap.js` — server-aligned early locale metadata.
- `public/assets/velora-localization.js` — feature chunk loading, runtime key copy and `Intl` formatting.
- `public/assets/velora-data.js` — locale-neutral transport/normalization.
- `public/assets/velora-dynamic-content.js` — original-first cache-only dynamic localization.
- `api/src/Core/Locale/*` — backend locale manager and fenced translation cache/queue.
- `api/workers/content_translation_worker.php` — provider execution boundary.
- `LOCALIZATION_ARCHITECTURE.md` — design, extension and release rules.
- `DEPLOYMENT_GUIDE_FA.md` — cPanel/LiteSpeed build, cache and production checks.

## Catalog loading measurement

Dashboard output declares `common,errors,dashboard` only:

| Locale | Full catalog | Dashboard chunks | Messages loaded |
|---|---:|---:|---:|
| `fa` | 133,703 bytes | 13,374 bytes | 178 |
| `en` | 104,158 bytes | 10,619 bytes | 178 |

The validator proves each template reference is covered by its declared chunks and validates chunk identity, version, message count, bytes, SHA-256, catalog values and cross-locale keyset parity.

## Final verification

```text
BRAND_NORMALIZATION_OK changed_files=0 default_brand=VELORA localized_exceptions=2
LOCALE_REGISTRY_SYNC_OK locales=2 version=2026.08.13.7 bytes=893 preloaded_messages=0
LOCALIZED_BUILD_OK templates=28 html=59 feature_chunks=34
LOCALIZATION_VALIDATION_OK locales=2 keys=1196 references=1050 html=88
BRAND_POLICY_TEST_OK localized_locale=fa allowlisted_messages=2 default_brand=VELORA other_occurrences=VELORA
EN_VISIBLE_SCAN_OK arabic_script=0 invalid_brand=0 html=31
LOCALE_RESOLUTION_TEST_OK explicit_locale_url=true cookie_priority=true browser_primary=true unsupported_browser=en prelocalized_first_paint=true
FEATURE_CATALOG_TEST_OK fa_features=3 en_features=3 active_locale_only=true full_catalog_fetches=0 registry_bytes=893
SWITCHER_MOUNT_TEST_OK slot=inline landing=inline app=inline fallback=dock registry_driven=true
RESPONSIVE_VISUAL_CHECK_OK chromium=151 landing=fa-desktop,fa-mobile public=en-blog-desktop fallback=en-login-mobile rtl_ltr=true
DYNAMIC_CONTENT_TEST_OK original-first=true locale-fence=true identity-fence=true cache-reuse=true
SERVER_LOCALE_TEST_OK cases=10 unsupported_to_en=true cookie_priority=true explicit_locale_url=true localized_404=true
BACKEND_LOCALIZATION_TEST_OK locale=true email_rtl_ltr=true queue_lifecycle=true ownership_fence=true race_workers=4 race_jobs=48
HTTP_LOCALIZATION_TEST_OK cache_only=true accept_language_neutral=true auth_contract=true validation_contract=true unsupported_locale=true
HTTP_ROUTE_MATRIX_OK requests=171 templates=28 browser_languages=fa-IR,en-GB,de-DE,ar-SA explicit_locales=fa,en localized_brand_scope=2 etag=304
STATIC_SYNTAX_OK python=true javascript=true php=true php_files=71
ETAG_REVALIDATION_OK status=304
SEO_ROUTE_OK locales=fa,en hreflang=fa,en,x-default
```

Migration was run twice after generated-output exclusion and produced identical second-run hashes:

```text
files=28 textNodes=0 attributes=84 catalogKeys=1196 reusedPairs=137
second_run_idempotent=true
```

## Production-environment checks still required

Local SQLite, resolver, HTTP and concurrency regressions pass. Deployment must still verify integrations not provable in this workspace:

1. database migration and concurrent worker behavior on the production MySQL/LiteSpeed stack;
2. real SMTP delivery and email-client RTL/LTR rendering;
3. external translation provider credentials and CLI-only execution in a sandbox;
4. authenticated end-to-end flows against a disposable production-like database;
5. visual responsive checks of every page in both directions using target browsers/devices;
6. live dashboard/trade latency under production traffic and with catalog/provider services deliberately unavailable;
7. host/CDN cache purge and 304 behavior after replacing the previous release.
