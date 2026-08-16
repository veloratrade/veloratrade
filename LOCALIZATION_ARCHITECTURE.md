# VELORA Trade localization architecture

Version: `2026.08.13.7`  
Updated: 2026-08-13

## 1. Non-negotiable invariants

VELORA has separate pipelines for application data and presentation language:

```text
Live API → normalization → raw application data → presentation formatting
Central UI keys → build-time localized HTML → first paint
Dynamic source content → immediate original-language paint → async cache lookup
Content ingestion → translation queue → CLI worker/provider → reusable cache
```

1. Prices, decimal values, currencies, percentages, volume, symbols, timestamps, account IDs, trades and statistics remain raw and locale-neutral in APIs and repositories.
2. Localization is a presentation concern. UI copy uses catalog keys; application features do not branch on `fa` or `en`.
3. Financial formatting occurs at the presentation boundary through the central `Intl` helpers.
4. Static HTML arrives in the resolved locale. JavaScript is not responsible for translating the first page.
5. Live-data requests never wait for a catalog, translation provider or AI service.
6. New dynamic content appears immediately in its original language. A cached translation may replace it asynchronously; a cache miss leaves the original visible.
7. HTTP rendering can only read translation cache. Translation providers run only in CLI workers.
8. Direction, script and numbering system are locale metadata, not feature-level conditionals.
9. Display-brand spelling is policy-driven. `VELORA` remains the default everywhere; only allowlisted locale/message pairs may use a localized token. In release `2026.08.13.7`, exactly the Persian landing-page copyright and legal disclaimer use `ولورا`. Validators reject all unapproved spellings and contexts.
10. The manifest, route map, feature policy and brand policy are data-driven so a future language/page does not require runtime-core changes.

## 2. Sources of truth

### Locale and message artifacts

```text
public/locales/
├── manifest.json
├── manifest.schema.json
├── catalog.schema.json
├── fa.json
├── en.json
├── feature-manifest.json                 # generated
└── chunks/{locale}/{feature}.json        # generated
```

`manifest.json` is authoritative for:

- enabled locale codes;
- default and unsupported-language fallback;
- `intlLocale`, direction, script and numbering system;
- the persisted-choice cookie key;
- feature-catalog base URL.

Current metadata:

| Locale | Intl locale | Direction | Script | Numbering |
|---|---|---:|---|---|
| `fa` | `fa-IR` | RTL | `Arab` | `arabext` |
| `en` | `en-GB` | LTR | `Latn` | `latn` |

Both catalogs have 1,196 parity-checked keys. Catalog/manifest versions and interpolation placeholders must match.

### Canonical templates and build policy

```text
tools/localization/routes.json             # canonical templates, outputs and locale aliases
tools/localization/feature-map.json        # always/runtime/shared/page-group policy
tools/localization/brand-policy.json       # default token + locale/message allowlist
tools/localization/brand_policy.py         # shared policy loader/helpers
tools/localization/build_localized_static.py
localized/{locale}/...                     # generated; never edit
```

There is one canonical source template per page, not a separately maintained English tree. The current route map contains 28 templates. Build output contains 59 HTML files: the normal Persian/English routes plus three English legacy blog-slug aliases.

Run:

```bash
python3 tools/localization/migrate_static.py
python3 tools/localization/normalize_brand.py
python3 tools/localization/sync_registry.py
python3 tools/localization/build_localized_static.py
python3 tools/localization/validate_localization.py
```

`migrate_static.py` excludes `localized/`; generated HTML can never become migration source. Repeating migration must produce an identical source tree.

## 3. Build-time first paint

The generator:

1. reads the manifest, route map, feature policy and parity-checked catalogs;
2. renders all `data-i18n*` markers into locale-specific HTML;
3. writes correct `lang`, `dir`, locale markers and feature declarations onto `<html>`;
4. normalizes visible digits and Persian/Arabic separators/punctuation when a locale declares `numberingSystem=latn`;
5. emits locale-specific canonical, `og:url`, `hreflang` and `x-default` metadata;
6. writes usage-scoped feature chunks and their SHA-256/byte/message metadata;
7. removes/recreates generated output atomically at build scope.

The English-output validator rejects any visible Arabic-script code point. This prevents Persian digits or punctuation from leaking into LTR English first paint.

The browser registry is generated at:

```text
public/assets/velora-locale-registry.js
```

It is currently 893 bytes and contains locale metadata only—no full messages or preloaded catalogs.

## 4. Server locale negotiation

Production uses `.htaccess` and `locale-router.php`; local development uses `router.php` and the same resolver.

### Unprefixed requests

For an ordinary request such as `/dashboard/`:

1. valid persisted manual-choice cookie (`velora_locale`);
2. primary `Accept-Language` value;
3. manifest default only when no browser language exists.

An unsupported primary browser language resolves to manifest `fallbackLocale=en`. Therefore `de-DE` and `ar-SA` receive English/LTR, while `fa` and `fa-IR` receive Persian/RTL.

Only the primary browser language is considered. A later Persian preference must not make an unsupported German primary preference fall back to Persian.

### Explicit SEO locale routes

A supported prefix such as `/en/dashboard/` or `/fa/dashboard/` is explicit navigation intent and is authoritative for that request. The router strips the prefix for internal generated-file lookup. This provides stable crawlable URLs while preserving cookie → browser behavior on existing unprefixed application links.

The three historical English article slugs are declared as locale-specific route outputs, not duplicated source pages.

### HTTP behavior

Localized pages send:

- `Content-Language` and `X-VELORA-Locale`;
- `Vary: Cookie, Accept-Language`;
- `Cache-Control: private, max-age=0, must-revalidate`;
- strong `ETag` and `Last-Modified`;
- `304 Not Modified` for matching `If-None-Match`;
- `X-Content-Type-Options: nosniff`.

API/health routes and static assets bypass page negotiation. Catalog JSON and versioned static assets use public cache rules in `.htaccess`.

## 5. SEO model

Every generated page has:

- a locale-prefixed canonical URL;
- matching `og:url`;
- one alternate link per enabled locale;
- `x-default` pointing to fallback English;
- route-correct JSON-LD URL when a JSON-LD object has a `url` property.

Example:

```text
canonical:  https://veloratrade.ir/en/dashboard/
alternate:  https://veloratrade.ir/fa/dashboard/  hreflang=fa
alternate:  https://veloratrade.ir/en/dashboard/  hreflang=en
x-default:  https://veloratrade.ir/en/dashboard/
```

Unprefixed URLs remain functional and negotiate the user locale; their served document canonicalizes to the corresponding explicit locale route.

## 6. Usage-scoped catalog loading

`feature-map.json` defines always-loaded features, server-only namespaces, runtime keys/namespaces, shared-key threshold and route aliases.

At build time, keys referenced by multiple route groups become `common`; error descriptors become `errors`; remaining keys are grouped by route feature such as `auth`, `dashboard`, `markets`, `trades`, `blog` or `landing`. Namespace spelling does not force a page to download an unrelated whole namespace.

A dashboard page currently declares only:

```html
data-i18n-features="common,errors,dashboard"
```

For English this is 10,619 bytes / 178 messages instead of the 104,158-byte / 1,196-key full catalog. Runtime tests assert active-locale-only fetches and zero full-catalog requests.

The validator independently proves that every catalog key referenced by a template exists in the chunks declared by each generated output. Chunk locale, feature, version, keyset, byte count and SHA-256 metadata are also verified.

## 7. Browser runtime

Load order:

```text
velora-locale-registry.js
velora-locale-bootstrap.js
velora-localization.css
velora-localization.js
velora-data.js
velora-dynamic-content.js
```

The bootstrap aligns with the server contract: explicit supported URL prefix → cookie/localStorage manual choice → primary browser language → prelocalized route marker → manifest default. Correct server-built HTML is paint-ready; concealment exists only as a bounded fail-open protection for stale/mismatched HTML.

`velora-localization.js` loads only declared feature chunks for the active locale, applies runtime-created key copy and exposes formatting helpers:

```javascript
VeloraLocale.number(rawValue, options);
VeloraLocale.currency(rawAmount, rawCurrencyCode, options);
VeloraLocale.percent(rawRatio, options);
VeloraLocale.date(rawIsoTimestamp, options);
VeloraLocale.dateTime(rawIsoTimestamp, options);
VeloraLocale.time(rawTimeOrTimestamp, options);
VeloraLocale.relative(rawIsoTimestamp, base);
```

Dynamic outputs call helpers after data updates. Do not attach a fixed declarative value to a live output node.

### Locale control placement

The locale selector is registry-driven and mounts by presentation priority, without locale-name branches:

1. an explicit page-owned `[data-velora-locale-slot]`;
2. application top-navigation actions;
3. the landing/public header near its menu/auth actions;
4. a bottom logical-corner dock only on minimal pages with no navigation target.

The landing control stays visible outside the collapsed menu. Narrow headers reduce it to an accessible globe-sized native-select tap target; desktop exposes the native locale name where space permits. Fallback placement uses logical insets, safe-area offsets and shared RTL/LTR rules, so it does not cover top navigation.

## 8. API/data separation

`public/assets/velora-data.js` owns HTTP transport and normalization. It does not add UI locale to resource requests. Raw API payload semantics remain equal across UI languages.

A locale is sent only for recipient-facing copy, for example `notificationLocale` on registration or password email operations. It affects the notification, never the returned trade/account/statistic data.

Errors use stable descriptors:

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Validation failed.",
    "messageKey": "errors.validation.failed",
    "params": {},
    "details": {}
  }
}
```

UI uses `messageKey`/`params`; `message` remains a language-neutral fallback for logs and non-localized clients.

## 9. Dynamic content and translation workers

`VeloraDynamicContent.localize()` returns source fields synchronously, performs a cache-only lookup outside the critical path and updates only when a matching cached translation exists.

Cache identity:

```text
content_type + content_id + source_hash + target_locale
```

Locale and content-identity fences prevent stale async responses from repainting a newer selection/revision.

The public route is cache-only:

```text
POST /api/v1/content-translations/lookup
```

Ingestion/import code enqueues genuinely new content. Only this CLI worker can reach a provider:

```bash
php api/workers/content_translation_worker.php
```

Claims are conditional, stale leases recoverable, ownership fenced, retries bounded, and cache-upsert/job-completion transactional.

## 10. RTL/LTR

`public/assets/velora-localization.css` is the shared direction layer. New UI must:

- use CSS logical properties and `text-align: start/end`;
- isolate symbols, prices, account IDs and chart values;
- use direction metadata/selectors, never locale-name branches;
- share one component implementation between directions;
- test desktop and responsive layouts for every enabled direction.

## 11. Adding a locale or page

### Locale

1. Add metadata to `manifest.json`, including direction, Intl locale, script and numbering system.
2. Add a parity-complete `{locale}.json` with unchanged keys/placeholders.
3. If localized brand copy is approved, add its token/message allowlist only in `brand-policy.json`; otherwise the default token applies automatically.
4. Bump the shared version in the manifest, catalogs and build policies.
5. Sync, build and validate.
6. Test explicit URL, cookie/browser negotiation, formatting, notifications and responsive direction.

### Page/feature

1. Add one canonical template using catalog keys.
2. Declare its route and optional locale-specific legacy aliases in `routes.json`.
3. Add a feature alias only if pages should share a chunk.
4. Build and validate. The usage planner creates/scopes chunks without runtime-core edits.

## 12. Release checks

```bash
python3 tools/localization/normalize_brand.py
python3 tools/localization/sync_registry.py
python3 tools/localization/build_localized_static.py
python3 tools/localization/validate_localization.py
python3 tools/localization/test_brand_policy.py
node tools/localization/test_locale_resolution.js
node tools/localization/test_catalog_preload.js
node tools/localization/test_switcher_mount.js
node tools/localization/test_dynamic_content.js
php tools/localization/test_server_locale.php
php tools/localization/test_backend_localization.php
python3 tools/localization/test_http_localization.py --base-url http://127.0.0.1:4174
python3 tools/localization/test_http_routes.py --base-url http://127.0.0.1:4174
```

Also run Python compilation, Node syntax checks and PHP lint. A release fails if the first HTML is in the wrong locale, unsupported browser language falls to Persian, full catalogs are fetched, live data waits for translation, a provider runs in HTTP, raw API values depend on UI locale, brand copy differs from its centralized message policy, the locale control overlays an available navigation target, chunk coverage is incomplete, SEO links are broken, or direction metadata is inconsistent.
