# VELORA v0.2 — Host Test Root Extract Package

Extract this ZIP **directly into the existing Velora project root on your host**. There is no `application/`, `required-host-files/`, or `migration/` wrapper directory.

The paths in this archive are already the real project paths, for example:

- `.htaccess`
- `api/.htaccess`
- `api/.env.example`
- `api/workers/preflight_v0_2.php`
- `api/database/migrations/v0.2_metaapi_bridge.sql`
- `public/locales/...`
- `localized/fa/...` and `localized/en/...`

## Safe extraction

1. Back up the current host project and database outside the web root.
2. Extract this ZIP into the existing project root, preserving directory paths and allowing only the listed patch files to overwrite their counterparts.
3. Do **not** extract an `.env`; this archive has none.
4. Configure private host-test runtime values separately using the `api/.env.example` template. It contains placeholders, not a configured host URL.
5. Run the migration preflight, then the migration once, then run it once more to verify idempotency.

## Migration

Path: `api/database/migrations/v0.2_metaapi_bridge.sql`

SHA-256: `dd6531bdce14bb5640e50273cd8a5666cff3f9948d691a219c7e81250d303c9b`

```bash
php api/workers/preflight_v0_2.php \
  --migration-gate \
  --migration-sha256=dd6531bdce14bb5640e50273cd8a5666cff3f9948d691a219c7e81250d303c9b \
  --backup=/protected/path/host-test-backup.sql.gz \
  --backup-sha256=YOUR_BACKUP_SHA256 \
  --workers-stopped

mysql -u HOST_TEST_USER -p HOST_TEST_DATABASE \
  < api/database/migrations/v0.2_metaapi_bridge.sql
```

Do not upload a database dump, backup, log, Production `.env`, or credentials.

## Approval-gated legacy actions

After checking host references, move outside the document root or remove only after approval:

- `public/assets/velora-i18n.js`
- `test-localization.html`

## Existing URLs

Canonical, `og:url`, and `hreflang` values in changed HTML are left unchanged from the validated project output. This package does not configure a Production or Staging environment URL.
