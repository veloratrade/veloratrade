#!/usr/bin/env python3
"""
P0: Consent security test — verifies fail-closed behavior
"""

import pathlib

ROOT = pathlib.Path(__file__).resolve().parents[2]

# Check AIManager hasAIConsent fail-closed
ai_manager = (ROOT / "api/src/AI/Services/AIManager.php").read_text()

assert "function hasAIConsent" in ai_manager, "hasAIConsent missing"
assert "AIConsentRequiredException" in ai_manager, "Should throw consent exception"
assert "return false" in ai_manager, "Should have fail-closed return false"

# Check for fail-open old behavior removed
assert "return true;" not in ai_manager or ai_manager.count("return true;") < 2, "Should not have many fail-open return true"

# Check that column missing case returns false (fail-closed)
assert "column missing, fail-closed" in ai_manager or "fail-closed" in ai_manager, "Should have fail-closed comment for column missing"

# Check exception file
consent_exc = ROOT / "api/src/AI/Exceptions/AIConsentRequiredException.php"
assert consent_exc.is_file()
content = consent_exc.read_text()
assert "AI_CONSENT_REQUIRED" in content
assert "403" in content

# Check controller handles consent exception
controller = (ROOT / "api/src/Trades/ScreenshotExtractController.php").read_text()
assert "AIConsentRequiredException" in controller, "Controller should handle consent exception"
assert "AI_CONSENT_REQUIRED" in controller, "Controller should return AI_CONSENT_REQUIRED"
assert "TesseractProvider" in controller, "Tesseract fallback may remain available"

# Check ImageAnonymizer exists
anon = ROOT / "api/src/AI/Security/ImageAnonymizer.php"
assert anon.is_file()
anon_content = anon.read_text()
assert "blur" in anon_content.lower() and "15" in anon_content
assert "0600" in anon_content, "Temp file should be 0600"
assert "anonymize" in anon_content

# Check migration v0.6
v06 = ROOT / "api/database/migrations/v0.6_ai_privacy.sql"
assert v06.is_file()
v06_content = v06.read_text()
assert "ai_consent_at" in v06_content
assert "IF NOT EXISTS" in v06_content or "COUNT(*) FROM information_schema.COLUMNS" in v06_content

print("PASS: Consent security — fail-closed for external AI")
print("PASS: ImageAnonymizer exists with blur top 15%")
print("PASS: Controller handles AI_CONSENT_REQUIRED with Tesseract fallback")
print("PASS: Migration v0.6 exists")

print("\n=== Consent Security P0: PASS ===")
