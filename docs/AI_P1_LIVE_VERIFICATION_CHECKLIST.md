# Velora AI P1 Hardening — Live Verification Checklist

**DO NOT EXECUTE WITHOUT OWNER APPROVAL**
**Staging-first, Production only with explicit CONFIRM-PRODUCTION-DEPLOY**

## Prerequisites

- HEAD: ed91224, branch main, clean working tree
- Secrets in GitHub: STAGING_FTP_SERVER, STAGING_FTP_USERNAME, STAGING_FTP_PASSWORD, STAGING_VELORA_ENV, RESEND_API_KEY, GEMINI_API_KEY (add to STAGING_VELORA_ENV)
- Tools: `tools/velora-status.sh` must show VELORA-RUN-*

## 1. Apply Migrations on Staging

**Current migrations:**
- v0.4_ai_foundation.sql: ai_extractions, ai_provider_quotas, ai_provider_logs
- v0.5_ai_requests.sql: ai_requests, ai_feature_flags, ai_audit_logs, ai_feedback
- v0.6_ai_privacy.sql: users.ai_consent_at

**Commands (via GitHub Actions runner, not sandbox — OC-1 firewall):**

```bash
# Deploy staging first to get worker files
# GitHub → Actions → Deploy Staging → Run workflow (ref: main)

# Then via temporary workflow or SSH (if available):
php api/workers/apply_ai_migration.php --check
php api/workers/apply_ai_migration.php --apply
php api/workers/apply_ai_migration.php --check --v05
php api/workers/apply_ai_migration.php --apply --v05

# For v0.6 privacy (adds column to users)
mysql -h $DB_HOST -u $DB_USER -p$DB_PASS $DB_NAME < api/database/migrations/v0.6_ai_privacy.sql

# Verify
mysql -h $DB_HOST -u $DB_USER -p$DB_PASS $DB_NAME -e "SHOW TABLES LIKE 'ai_%';"
mysql -h $DB_HOST -u $DB_USER -p$DB_PASS $DB_NAME -e "DESCRIBE ai_extractions; DESCRIBE ai_requests; DESCRIBE ai_feature_flags; DESCRIBE ai_audit_logs; DESCRIBE ai_feedback; SHOW COLUMNS FROM users LIKE 'ai_consent_at';"
```

**Expected:** 6 tables exist, `users.ai_consent_at` DATETIME NULL.

**Safety:** All migrations use `IF NOT EXISTS`, no destructive ALTER except additive column, FK CASCADE intentional.

## 2. Verify Repository Persistence

```bash
# Run P0 tests on staging runner (has DB)
php tools/tests/test_ai_repository_p0.php
# Should PASS: create extraction, findByHash deduplication, ownership isolation, quota increment, logging
```

**Manual MySQL checks:**
```sql
SELECT COUNT(*) FROM ai_extractions;
SELECT COUNT(*) FROM ai_provider_quotas WHERE provider='gemini';
SELECT COUNT(*) FROM ai_provider_logs;
-- Test dedup: same image_hash for same user should return cached
-- Test ownership: user 1 hash should not be visible to user 2
```

## 3. Real Gemini Integration Test

**Prerequisite:** `GEMINI_API_KEY` set in `STAGING_VELORA_ENV` (real key, not placeholder), `GEMINI_MODEL=gemini-1.5-flash`, `GEMINI_TIMEOUT=15`

**Sample image:** `public/samples/mt5-closed-trade.png` (943K) or real MT4/MT5 screenshot from user.

**Test via API (staging):**
```bash
curl -X POST https://staging.veloratrade.ir/api/v1/trades/extract-screenshot \
  -H "Authorization: Bearer $STAGING_TEST_JWT" \
  -H "Content-Type: application/json" \
  -d '{
    "images": ["data:image/png;base64,'$(base64 -w 0 /tmp/mt5.png)'"]
  }' | jq

# Validate:
# - provider=gemini
# - engine=gemini-vision
# - extraction.symbol e.g. XAUUSD
# - extraction.side buy/sell
# - extraction.entry numeric string
# - extraction.exit numeric
# - extraction.lot
# - extraction.sl, tp
# - extraction.pnl
# - confidence >0.7
# - texts[] array (backward compat)
# - times.openTime/closeTime
```

**If quota exhausted or key invalid:** Should fallback to `provider=tesseract`, `engine=tesseract-system`, `confidence=0.4`, with warning.

## 4. Test Fallback

```bash
# Set invalid key in staging env temporarily:
# GEMINI_API_KEY=invalid
# Deploy staging, then POST screenshot → expect tesseract fallback

# Restore valid key after test
```

**Expected:** No 500, returns 200 with tesseract result, logs `quota_exhausted` or `provider_error` in `ai_provider_logs`.

## 5. Test Consent Check

```sql
-- User without consent should get AI_CONSENT_REQUIRED for external provider
UPDATE users SET ai_consent_at = NULL WHERE id = $TEST_USER_ID;
-- POST screenshot with external provider (gemini) → expect 403 AI_CONSENT_REQUIRED
-- Then set consent:
UPDATE users SET ai_consent_at = NOW() WHERE id = $TEST_USER_ID;
-- POST again → should succeed
```

**Note:** Consent check fail-open if column missing (backward compat until v0.6 applied) — intentional.

## 6. Test Image Optimization

* Upload 3000x2000 PNG → check `ImageProcessor::process()` resizes to <=1024px, JPEG 80, processed_size < original_size
* Verify provider receives optimized image (check `ai_requests` or logs, not raw image)
* Check temp file 0600 and cleanup via `ls /tmp/velora_*` after request → should be 0

## 7. Test Prompt Management

```bash
php -r "
require 'api/src/AI/Prompts/PromptManager.php';
echo \Velora\AI\Prompts\PromptManager::get('screenshot_extraction','v1','en');
"
# Should load from templates/screenshot_extraction_v1.txt, not hardcoded
# Check list: PromptManager::list() should return 2 files
```

## 8. Test Feature Flags

```sql
SELECT * FROM ai_feature_flags;
-- ai_screenshot_extraction should be enabled=1, rollout=100
-- ai_trade_analysis should be 0,0

-- Test deterministic rollout:
-- For user 1, isEnabled('ai_screenshot_extraction',1) should be true
-- For disabled feature, should be false
-- 0% rollout should always false, 100% always true
```

**Enforcement check:** Currently enforced in `ScreenshotExtractController` for `ai_screenshot_extraction` — verify 403 when disabled.

## 9. Test Retention Cleanup

```bash
php api/workers/ai_retention_cleanup.php --dry-run
# Should show rows to delete, no changes
php api/workers/ai_retention_cleanup.php --execute
# Should delete old rows, clean original_result
```

**Config:** `AI_RETENTION_DAYS=30` in env.

## 10. Architecture & Security Checks (must pass before P1)

```bash
bash tools/velora-status.sh
# Must show VELORA-RUN-*, no FULL-CLONE-VIOLATION, no CREDENTIAL-IN-CONFIG

python3 tools/tests/test_ai_foundation_hardening.py
# Must PASS all 14 checks

python3 tools/tests/test_velora_status.py
python3 tools/tests/test_structure_source_locator.py

# Security grep
grep -RIn "AIza\|sk-\|Authorization\|Bearer\|password\|secret\|api_key" api/src/AI/ --include="*.php" | grep -v "replace-with" | grep -v "Config::env" | grep -v "test-key"
# Should be 0 or only legitimate curl/Config usage

# Dependency direction
grep -RIn "Velora\\\\Trades\\\\\|TradeRepository\|TradeService" api/src/AI/ --include="*.php"
# Should be 0 (only DTO context trades array allowed)

# Backward compat
grep -n "'engine'\|'texts'\|'times'" api/src/Trades/ScreenshotExtractController.php
# Must exist

# No changes in core
git diff HEAD -- api/src/Core/ api/src/Trades/TradeService.php api/src/Trades/TradeRepository.php api/src/Auth/ | wc -l
# Should be 0 except ScreenshotExtractController
```

## 11. Production Readiness (only after staging PASS)

* Staging healthcheck: `Actions → Health Check (Staging) → Run workflow` → 15/15 PASS
* CSP Guard: `csp-guard.yml` PASS
* Then production dry-run: `Actions → Deploy Production → confirm_production_deploy=CONFIRM-PRODUCTION-DEPLOY dry_run=true` → must show allow-list 470+ files, backup 650, zero writes, no ai_* SQL in bundle? Actually SQL files excluded from bundle per deploy-staging guard, but migrations are in api/database/migrations which is excluded from deploy — correct, migrations applied separately via worker, not via FTP mirror.

**Production migration:** Only with explicit owner approval, backup, and `ai_consent_at` column additive.

## Final Gate

If all P0 live verification PASS on staging, then:

**APPROVE P1** for Analysis, Reports, Feedback, Jobs modules.

If only static verified but live staging blocked, **APPROVE P1 WITH CONDITIONS** (conditions listed in audit).

If any architectural defect found (circular dependency, secrets in logs, raw image persisted), **BLOCK P1**.
