# Velora AI Retention Cron Setup (cPanel)

## Purpose

Worker `api/workers/ai_retention_cleanup.php` implements retention policy for AI tables:
- Deletes old `ai_requests`, `ai_provider_logs`, `ai_audit_logs` older than retention period
- Cleans `original_result` in `ai_extractions` after retention, keeps `final_result` and stats
- Deletes `ai_extractions` after 2x retention (preserves aggregated stats longer)

## Config

In `velora.env` (private root, 0600):
```
AI_RETENTION_DAYS=30
AI_AUDIT_ENABLED=true
AI_REQUEST_TRACKING_ENABLED=true
```

In `api/config/config.php`:
```php
'ai' => [
  'retention_days' => max(7, min(365, (int) Config::env('AI_RETENTION_DAYS','30'))),
]
```

Valid range 7-365 days, default 30.

## cPanel Cron Setup

**cPanel → Cron Jobs → Add New Cron Job**

Common Settings: Once Per Day (0 2 * * *)

Command:
```
php /home/piknet/velora_private_staging/../public_html/staging.veloratrade.ir/api/workers/ai_retention_cleanup.php --execute >> /home/piknet/velora_private_staging/logs/ai_retention.log 2>&1
```

For production:
```
php /home/piknet/velora_private/../public_html/api/workers/ai_retention_cleanup.php --execute >> /home/piknet/velora_private/logs/ai_retention.log 2>&1
```

**Important:**
- Use full absolute path to PHP binary if needed: `/usr/bin/php` or `/usr/local/bin/php` or `/opt/alt/php81/usr/bin/php`
- Check PHP version: must be 8.1+
- Log file must be in private logs dir (0700), not docroot
- Test with dry-run first via SSH or via temporary GitHub Actions workflow

## Manual Testing (via GitHub Actions runner, has DB access)

```bash
# Dry-run: shows rows to delete, no changes
php api/workers/ai_retention_cleanup.php --dry-run

# Execute: deletes old rows
php api/workers/ai_retention_cleanup.php --execute
```

Expected output:
```
[VELORA AI RETENTION] Driver: mysql, Retention: 30 days, Mode: DRY-RUN
Cutoff: 2024-11-25 00:00:00
  ai_requests: 123 rows to delete
  ai_provider_logs: 456 rows to delete
  ai_audit_logs: 78 rows to delete
  ai_extractions: 45 rows to delete
  ai_extractions.original_result to clean: 45 rows
DRY-RUN: ... no changes made.
```

## Safety

* Never deletes before retention period
* Preserves aggregated statistics (counts, avg latency) via separate queries before delete
* `ai_extractions` kept 2x retention for stats, only `original_result` cleaned after 1x retention
* Uses prepared statements, no user input
* Idempotent, safe to run multiple times
* Logs to private logs, never exposes secrets

## Rollback

If retention too aggressive, restore from backup taken before cron (cPanel backup). No destructive ALTER, only DELETE/UPDATE.

## Monitoring

* Check `ai_retention.log` for deleted counts
* Monitor `ai_requests` growth: `SELECT COUNT(*), MIN(created_at) FROM ai_requests;`
* If growth >10k/day, reduce retention to 14 days or add archiving to S3
