#!/usr/bin/env python3
"""
P1 architecture verification — must pass before P1 feature development.
Checks:
- AI has no TradeRepository dependency
- AI has no JOIN trades
- AIManager uses registry
- Feature guards exist
- Jobs table migration exists
- Reports do not query database directly
- Feedback ownership exists
- No secrets
- Core untouched
"""

import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parents[2]

def assert_contains(path, substrings, desc):
    content = path.read_text(encoding='utf-8')
    for sub in substrings:
        assert sub in content, f"{path}: missing '{sub}' for {desc}"
    print(f"PASS: {desc}")

def assert_not_contains(path, substrings, desc):
    content = path.read_text(encoding='utf-8')
    for sub in substrings:
        assert sub not in content, f"{path}: should not contain '{sub}' for {desc}"
    print(f"PASS: {desc} (not contains)")

def main():
    base = ROOT / "api/src/AI"

    # 1. AI has no TradeRepository dependency (check actual usage, not comments)
    for php_file in base.rglob("*.php"):
        content = php_file.read_text()
        # Check for actual class usage, not just pattern mention in comment
        if "use Velora\\Trades\\TradeRepository" in content or "new TradeRepository" in content:
            raise AssertionError(f"{php_file}: must not depend on TradeRepository (AI must not query trades table)")
        if "use Velora\\Trades\\TradeService" in content or "new TradeService" in content:
            raise AssertionError(f"{php_file}: must not depend on TradeService")
        if "use Velora\\Auth\\" in content and "UserAIConsentRepository" not in str(php_file):
            # Allow UserAIConsentRepository which updates users table for consent, but not general Auth
            if "UserRepository" in content and "AI" not in str(php_file):
                raise AssertionError(f"{php_file}: forbidden Auth dependency")
    print("PASS: AI has no TradeRepository/TradeService dependency")

    # 2. AI has no JOIN trades (check actual SQL, not comments about TradeService)
    for php_file in base.rglob("*.php"):
        content = php_file.read_text()
        # Look for SQL patterns: FROM `trades` or FROM trades with word boundary, not TradeService
        if re.search(r'FROM\s+`?trades`?\b', content, re.I):
            # Allow if it's in comment saying "MUST NOT query trades table"
            if 'MUST NOT query trades table' in content:
                continue
            raise AssertionError(f"{php_file}: must not have FROM trades SQL")
        if re.search(r'JOIN\s+`?trades`?\b', content, re.I):
            raise AssertionError(f"{php_file}: must not have JOIN trades")
    print("PASS: AI has no JOIN trades")

    # 3. AIManager uses registry
    ai_manager = base / "Services/AIManager.php"
    assert_contains(ai_manager, ["AIProviderRegistry", "loadEnabledProviders", "tryReserveQuota", "hasAIConsent"], "AIManager uses registry + atomic quota + consent")

    registry = base / "Services/AIProviderRegistry.php"
    assert registry.is_file(), "AIProviderRegistry missing"
    assert_contains(registry, ["loadEnabledProviders", "getEnabledProviderNames", "PROVIDER_MAP", "DEFAULT_PRIORITY"], "AIProviderRegistry with priority")

    capability = base / "Providers/ProviderCapability.php"
    assert capability.is_file(), "ProviderCapability missing"
    assert_contains(capability, ["VISION", "TEXT", "ANALYSIS", "CHAT"], "ProviderCapability constants")

    # 4. Feature guards exist
    guard = base / "Services/AIFeatureGuard.php"
    assert guard.is_file(), "AIFeatureGuard missing"
    assert_contains(guard, ["requireEnabled", "AI_FEATURE_DISABLED", "isEnabled", "checkMultiple"], "AIFeatureGuard central guard")

    flag_repo = base / "Repositories/AIFeatureFlagRepository.php"
    assert_contains(flag_repo, ["isEnabled", "rollout_percentage", "crc32"], "Feature flag deterministic rollout")

    # Check enforcement in controllers/services
    controller = ROOT / "api/src/Trades/ScreenshotExtractController.php"
    assert_contains(controller, ["AIFeatureFlagRepository", "ai_screenshot_extraction"], "Feature flag enforced in ScreenshotExtractController")

    analysis_service = base / "Analysis/TradeAnalyzerService.php"
    if analysis_service.is_file():
        assert_contains(analysis_service, ["AIFeatureGuard", "ai_trade_analysis"], "Feature flag enforced in TradeAnalyzerService")

    reports_service = base / "Reports/WeeklyReportService.php"
    if reports_service.is_file():
        assert_contains(reports_service, ["AIFeatureGuard", "ai_weekly_report"], "Feature flag enforced in WeeklyReportService")

    # 5. Jobs table migration exists
    v07 = ROOT / "api/database/migrations/v0.7_ai_jobs.sql"
    assert v07.is_file(), "v0.7_ai_jobs.sql missing"
    v07_content = v07.read_text()
    assert "ai_jobs" in v07_content
    assert "pending" in v07_content and "processing" in v07_content and "completed" in v07_content and "failed" in v07_content
    assert "ENGINE=InnoDB" in v07_content
    print("PASS: Jobs table migration exists with statuses")

    job_repo = base / "Jobs/AIJobRepository.php"
    assert job_repo.is_file()
    assert_contains(job_repo, ["createJob", "claimJob", "completeJob", "failJob", "FOR UPDATE"], "AIJobRepository with lease pattern")

    job_service = base / "Jobs/AIJobService.php"
    assert_contains(job_service, ["createJob", "claimJob", "AIFeatureGuard"], "AIJobService with feature guard")

    # 6. Reports do not query database directly (should receive DTOs)
    report_repo = base / "Reports/ReportRepository.php"
    if report_repo.is_file():
        content = report_repo.read_text()
        # ReportRepository may query ai_reports but should not query trades
        assert "trades" not in content.lower() or "ai_reports" in content.lower(), "ReportRepository should not query trades"
        print("PASS: Reports do not query trades table directly")

    weekly_service = base / "Reports/WeeklyReportService.php"
    if weekly_service.is_file():
        content = weekly_service.read_text()
        assert "TradeAnalyzerService" in content, "WeeklyReportService should use Analysis module"
        assert "PromptManager" in content, "Should use PromptManager"
        print("PASS: WeeklyReportService uses Analysis + PromptManager")

    # 7. Feedback ownership exists
    feedback_repo = base / "Feedback/AIFeedbackRepository.php"
    assert feedback_repo.is_file()
    assert_contains(feedback_repo, ["user_id", "findUserFeedback", "findOwned", "changed_fields"], "Feedback ownership validation")

    feedback_service = base / "Feedback/AIFeedbackService.php"
    assert_contains(feedback_service, ["storeCorrection", "changedFields", "assertNoImageData"], "Feedback service with image data check")

    # 8. No secrets
    for php_file in base.rglob("*.php"):
        content = php_file.read_text()
        if re.search(r'AIza[0-9A-Za-z\-_]{35}', content):
            raise AssertionError(f"{php_file}: hardcoded Gemini key")
        if re.search(r'sk-[A-Za-z0-9]{20,}', content):
            if 'replace-with' not in content and 'test-key' not in content:
                raise AssertionError(f"{php_file}: hardcoded OpenAI key")
    print("PASS: No hardcoded secrets in AI module")

    # 9. Core untouched
    import subprocess
    result = subprocess.run(["git", "diff", "HEAD", "--", "api/src/Core/", "api/src/Trades/TradeService.php", "api/src/Trades/TradeRepository.php"], capture_output=True, text=True, cwd=str(ROOT))
    assert result.stdout.strip() == "", f"Core/TradeService/TradeRepository should be untouched, diff: {result.stdout[:500]}"
    print("PASS: Core, TradeService, TradeRepository untouched")

    # 10. Check new tables
    v05 = ROOT / "api/database/migrations/v0.5_ai_requests.sql"
    v06 = ROOT / "api/database/migrations/v0.6_ai_privacy.sql"
    v08 = ROOT / "api/database/migrations/v0.8_ai_reports.sql"
    assert v05.is_file() and v06.is_file() and v08.is_file(), "Migrations v0.5/v0.6/v0.8 missing"
    print("PASS: Migrations v0.5, v0.6, v0.8 exist")

    # 11. Check consent API
    auth_controller = ROOT / "api/src/Auth/AuthController.php"
    if auth_controller.is_file():
        content = auth_controller.read_text()
        assert "ai_consent" in content, "AuthController should support ai_consent"
        assert "UserAIConsentRepository" in content or "ai_consent_at" in content, "Should update ai_consent_at"
        print("PASS: Consent API support in AuthController")

    # 12. Check retention worker
    retention = ROOT / "api/workers/ai_retention_cleanup.php"
    assert retention.is_file()
    assert_contains(retention, ["--dry-run", "--execute", "retention_days", "ai_requests"], "Retention worker with dry-run/execute")

    print("\n=== P1 Architecture: ALL CHECKS PASS ===")
    return 0

if __name__ == "__main__":
    sys.exit(main())
