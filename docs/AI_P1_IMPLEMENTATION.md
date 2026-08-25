# Velora AI P1 Implementation — Bounded Context

## Architecture Diagram (Text)

```
Current MVP (v0.4):
Client -> ScreenshotExtractController (RateLimiter, validation, 0600 temp)
  -> ScreenshotExtractor (ImageProcessor 1024px JPEG80, PromptManager)
    -> AIManager [Registry: gemini10, tesseract100] -> tryReserveQuota atomic UPDATE WHERE daily_used<quota_limit
      -> GeminiProvider generate(prompt, context{imageRaw}, options{deadline}) -> AIResponseDTO
      -> fallback TesseractProvider generate() -> AIResponseDTO
    -> ExtractionValidator -> ExtractedTradeData
  -> AIExtractionRepository (extends AIRepository base) -> ai_extractions
  -> AIAuditLogRepository (hash only) -> ai_audit_logs
  -> AIRequestRepository -> ai_requests (prompt_hash, tokens, latency, cost)
  Response: engine, texts, times (BC) + extraction/data/confidence/provider

P1 Hardening (v0.5-v0.6):
- AIProviderInterface: generate(prompt, context, options): AIResponseDTO + extract() BC adapter
- VisionExtractorInterface: extract() + generate(AIRequestDTO)
- DTOs: AIRequestDTO (userId, feature, provider, model, promptHash, context, options), AIResponseDTO (content, provider, model, latencyMs, tokensUsed, confidence, status, errorCode, rawResponse)
- AIRepository base: connection(): PDO { return Database::connection(); } // future aiConnection()
- PromptManager: templates/screenshot_extraction_v1.txt, trade_analysis_v1.txt, weekly_report_v1.txt — versioned, locale fa/en, fallbackPrompt()
- ImageProcessor: resize <=1024px, JPEG 80, GD imagescale, temp 0600, cleanup
- Privacy: ImageAnonymizer blur top 15% Gaussian+Pixelate, users.ai_consent_at DATETIME NULL, hasAIConsent() fail-closed for external, AIConsentRequiredException 403
- Feature Flags: ai_feature_flags table, AIFeatureFlagRepository deterministic crc32(feature:userId)%100, AIFeatureGuard::requireEnabled() 403 AI_FEATURE_DISABLED
- Quota: AIProviderQuotaRepository tryReserveQuota() atomic UPDATE WHERE daily_used<quota_limit + rowCount check, hasQuota(costTier) fail-closed for paid
- Logging: AIProviderLogRepository log(provider,status,latency,errorCode), AIRequestRepository logRequest(), AIAuditLogRepository log(hash only)
- Retention: ai_retention_cleanup.php --dry-run/--execute, AI_RETENTION_DAYS=30, cleans original_result after retention, deletes after 2x retention

P1 Features (v0.7-v0.8):
Client -> AIController (auth, RateLimiter, FeatureGuard, Audit)
  -> POST /api/v1/ai/analyze-trades {trades[], locale, timeframe}
     -> AIFeatureGuard requireEnabled ai_trade_analysis
     -> TradeAnalyzerService (MUST NOT query trades table, receives TradeDataDTO[] sanitized, max 100 trades, max JSON 200KB)
       -> PromptManager get('trade_analysis','v1',locale) with {trades, locale}
       -> AIManager generate(capability=analysis, feature=analysis) -> AIResponseDTO
       -> AnalysisResultDTO (mistakes[], strengths[], patterns[], recommendations[], riskScore, confidence, summary)
     -> TradeAnalysisRepository -> ai_analysis (user_id, provider, model, result_json, confidence) [future table, best-effort now]
     -> Audit log

  -> POST /api/v1/ai/weekly-report {trades[], period_start, period_end, locale}
     -> FeatureGuard ai_weekly_report
     -> WeeklyReportService uses Analysis module (TradeAnalyzerService), does NOT query trades directly, receives DTOs
       -> PromptManager weekly_report_v1 with {week_start, week_end, locale, analysis, trades_count}
       -> AIManager generate(capability=reports)
       -> ReportDTO (userId, periodStart, periodEnd, locale, content{summary, strengths, mistakes, risk_behavior, suggestions}, provider, confidence)
     -> ReportRepository -> ai_reports (UNIQUE user/period/locale, ownership via user_id) duplicate prevention via findForPeriod()
     -> Audit log

  -> POST /api/v1/ai/feedback {extraction_id, original, corrected}
     -> FeatureGuard ai_screenshot_extraction
     -> AIFeedbackService (whitelist: entry,exit,symbol,side,lot,sl,tp,pnl,openTime,closeTime + variants, ownership via AIExtractionRepository::findOwned(), never store screenshots/data:image/base64>10k/rawResponse candidates/usage/inline_data)
       -> AIFeedbackRepository -> ai_feedback (user_id FK, extraction_id FK SET NULL, original_result JSON, corrected_result JSON, changed_fields JSON)
     -> Audit log

Async Jobs (cPanel compatible, no Redis):
Client -> AIJobService createJob(userId, jobType, payload, delay)
  -> AIJobRepository createJob() -> ai_jobs (id, user_id FK, job_type, payload JSON, status ENUM pending/processing/completed/failed, attempts, available_at, created_at/updated_at, indexes status+available_at)
Worker: api/workers/ai_job_worker.php --max=10 --type=analysis
  -> claimJob(workerId) SELECT ... WHERE status=pending AND available_at<=NOW() AND attempts<3 ORDER BY created_at ASC LIMIT 1 FOR UPDATE (lease pattern like metaapi_sync_worker)
  -> UPDATE status=processing, attempts+1
  -> handle(): match job_type -> TradeAnalyzerService / WeeklyReportService / generic
  -> completeJob() / failJob() with retry delay 60*attempts, max 3 attempts
  Cron: */5 * * * * php .../ai_job_worker.php --max=10 >> logs/ai_jobs.log 2>&1
```

## Data Flow

**Screenshot Extraction:**
1. User uploads data:image/png;base64 (validated type/size/dimensions)
2. Controller RateLimiter 8/300, FeatureFlag ai_screenshot_extraction check 403 if disabled
3. Decode + validate + hash sha256(image)
4. Dedup cache findByHash(userId, hash) → return cached if exists
5. ImageProcessor process() → resize <=1024px JPEG80, 0600 temp, cleanup
6. PromptManager get('screenshot_extraction','v1',en)
7. AIManager extract(imageRaw, deadline, userId):
   - hasAIConsent(userId) check ai_consent_at NOT NULL, fail-closed → AIConsentRequiredException 403 (Tesseract fallback may remain)
   - tryReserveQuota(provider) atomic UPDATE WHERE daily_used<quota_limit, rowCount check, no overflow
   - ImageAnonymizer anonymize() blur top 15% for external
   - GeminiProvider generate() → AIResponseDTO (content JSON, tokens, latency)
   - On quota/timeout/validation → fallback TesseractProvider
   - Log to ai_provider_logs + increment quota + log to ai_requests
8. ExtractionValidator validate()
9. Save to ai_extractions (original_result JSON, final_result JSON, confidence, latency, hash) + audit log hash only
10. Return backward compat engine/texts/times + new extraction/data

**Trade Analysis:**
1. Client sends trades[] from TradeService (already sanitized DTO, not raw DB rows)
2. Controller RateLimiter ai-analyze 10/3600, FeatureGuard ai_trade_analysis 403 if disabled
3. TradeAnalyzerService: max 100 trades, max JSON 200KB, sanitize symbol/side/numeric, PromptManager trade_analysis_v1 with {trades,locale}
4. AIManager generate(capability=analysis) → AIResponseDTO
5. Save to ai_analysis (best-effort)

**Weekly Report:**
1. Client sends weekly trades + period_start
2. FeatureGuard ai_weekly_report
3. Check duplicate findForPeriod() → return cached if exists (idempotency via UNIQUE)
4. TradeAnalyzerService analyze() → analysisResponse
5. PromptManager weekly_report_v1 with {week_start, week_end, locale, analysis, trades_count}
6. AIManager generate(capability=reports) → AIResponseDTO
7. Save to ai_reports

**Feedback:**
1. Client sends extraction_id + original + corrected
2. FeatureGuard, RateLimiter 20/3600
3. Ownership check findOwned(extractionId, userId) → 422 if not owned
4. Whitelist check ALLOWED_FIELDS entry/exit/symbol/side/lot/sl/tp/pnl + variants → 422 if not allowed
5. assertNoImageData (reject data:image, base64>10k) + assertNoRawResponse (reject candidates/usage/inline_data)
6. Calculate changed_fields, store to ai_feedback

**Jobs:**
1. createJob() with feature flag check
2. claimJob() SELECT FOR UPDATE lease
3. handle() → Analysis/Report services
4. completeJob() / failJob() with retry delay

## Feature Flags

Table `ai_feature_flags`: feature_name PK VARCHAR64, enabled BOOL, rollout_percentage 0-100, created_at/updated_at

Seed:
- ai_screenshot_extraction 1 100% — enabled for all
- ai_trade_analysis 0 0% — disabled until P1 verified
- ai_weekly_report 0 0% — disabled until P1 verified
- ai_assistant 0 0% — future
- ai_recommendations 0 0%
- ai_risk_analysis 0 0%

Enforcement:
- `AIFeatureGuard::requireEnabled(name, userId)` throws ForbiddenException 403 AI_FEATURE_DISABLED
- Deterministic rollout: `crc32(feature:userId)%100 < rollout_percentage` — stable per user
- Fail-open for extraction (backward compat if table missing), fail-closed for new features
- Enforced in: ScreenshotExtractController (extraction), TradeAnalyzerService (analysis), WeeklyReportService (weekly_report), AIJobService (analysis/report/assistant), AIController (all 3 endpoints)

## Database Tables

**v0.4:**
- ai_extractions (id, user_id FK CASCADE, provider, image_hash CHAR64, original_result JSON, final_result JSON, confidence FLOAT, latency_ms, status ENUM success/fallback/failed, error_code, created_at, indexes user/hash/provider/status/created)
- ai_provider_quotas (provider PK, daily_used, quota_limit, reset_at, updated_at) seed gemini 1500, tesseract 100000
- ai_provider_logs (id, provider, status ENUM success/failed/quota_exhausted/timeout, latency_ms, error_code, created_at, indexes)

**v0.5:**
- ai_requests (id, user_id FK, feature, provider, model, prompt_hash CHAR64, tokens_used, latency_ms, status ENUM, cost DECIMAL 10,6, created_at, indexes user/feature/provider/status/created/hash)
- ai_feature_flags (feature_name PK, enabled, rollout_percentage, created_at/updated_at)
- ai_audit_logs (id, user_id FK, feature, provider, image_hash CHAR64 only, action, created_at, indexes)
- ai_feedback (id, user_id FK, extraction_id FK ai_extractions SET NULL, original_result JSON, corrected_result JSON, changed_fields JSON, created_at)

**v0.6:**
- users.ai_consent_at DATETIME NULL (information_schema check, additive)

**v0.7:**
- ai_jobs (id, user_id FK, job_type VARCHAR32, payload JSON, status ENUM pending/processing/completed/failed, attempts TINYINT, available_at DATETIME, created_at/updated_at, indexes user/type/status/available/created/status+available)

**v0.8:**
- ai_reports (id, user_id FK, period_start DATE, period_end DATE, locale VARCHAR10, content JSON, created_at, UNIQUE user/period/locale, indexes)
- ai_analysis (id, user_id FK, provider, model, result_json JSON, confidence FLOAT, created_at)

All InnoDB, utf8mb4_unicode_ci, IF NOT EXISTS, FK CASCADE intentional.

## Worker Lifecycle

**ai_job_worker.php:**
```
Cron */5 * * * * (cPanel)
  -> claimJob(workerId) BEGIN; SELECT ... WHERE status=pending AND available_at<=NOW() AND attempts<3 ORDER BY created_at ASC LIMIT 1 FOR UPDATE; UPDATE status=processing, attempts+1 WHERE id=? AND status=pending; COMMIT;
  -> handle() match job_type: analysis -> TradeAnalyzerService, report -> WeeklyReportService
  -> completeJob(id, result) UPDATE status=completed
  -> on failure: failJob(id, errorCode, delay=60*attempts) -> status=pending (retry) if attempts<3 else failed
  -> log to ai_provider_logs, ai_requests, ai_audit_logs
```

Same lease pattern as `metaapi_sync_worker.php` (fenced queue, atomic claim, no Redis, cPanel compatible).

**ai_retention_cleanup.php:**
```
--dry-run: SELECT COUNT(*) FROM ai_requests WHERE created_at < cutoff (retention_days)
--execute: DELETE FROM ai_requests, ai_provider_logs, ai_audit_logs WHERE created_at < cutoff; UPDATE ai_extractions SET original_result=NULL WHERE created_at < cutoff; DELETE FROM ai_extractions WHERE created_at < 2*cutoff
Cron: 0 2 * * * daily
```

## Future Scaling Triggers

* **Users >10k or AI req/day >10k:** ai_requests >10GB → move to separate AI MySQL (change AIRepository::connection() to aiConnection()), add Redis for quota atomicity if needed (but keep DB fallback for cPanel)
* **Need vector search for chat with trading data:** Add Qdrant/Pinecone for Memory module, cannot run on cPanel shared hosting → triggers separate AI service `veloratrade/velora-ai` (Python FastAPI or PHP), AIManager becomes HTTP client
* **Need streaming chat:** Current Response::json() calls exit, blocks streaming — need Response::stream() with SSE, requires FPM change
* **Need GPU self-hosted model:** LocalModelProvider wrapping Ollama/Qwen2-VL 7B on RunPod/Vast.ai, cost monitoring via ai_requests.cost
* **FPM blocking:** At 10 concurrent AI requests, FPM pool pressure → mandatory async via ai_jobs queue (P1 structure ready, just need to switch controller to createJob() instead of sync extract() for reports/analysis)

## Security & Privacy

* Secrets only via Config::env() from private root 0600, never in code/logs/DB, HTTPS enforced, SSL_VERIFYPEER true
* File handling: tempnam 0600, chmod 0600, unlink in finally, bounded output, MAX_PIXELS 12M, MAX_BYTES 8MB, decompression bomb protection
* Privacy: ImageAnonymizer blur top 15%, consent check ai_consent_at fail-closed for external, audit logs only hash, never raw image/base64, retention 30 days, feedback whitelist + no image/raw response check
* Rate limiting: screenshot-ocr 8/300, ai-analyze 10/3600, ai-report 5/3600, ai-feedback 20/3600 — fail-closed (503 if DB unavailable)
* Feature flags: deterministic rollout, prepared statements, no arbitrary SQL, disabled returns 403

## Backward Compatibility

* `POST /api/v1/trades/extract-screenshot` still returns engine, texts[], times{} — old frontend works
* New fields extraction/data/confidence/provider added, ignored by old clients
* No changes in Core/*, TradeService, TradeRepository, AuthService, Dashboard, Router (except new routes added), Frontend assets
* AuthController updatePreferences now handles both locale and ai_consent — backward compat (if only locale sent, works as before)
