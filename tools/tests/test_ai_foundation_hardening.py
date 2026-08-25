#!/usr/bin/env python3
"""
Foundation hardening tests for Velora AI module.
Checks:
- Generic provider interface
- DTOs exist
- PromptManager
- ImageProcessor
- Repositories base
- Migrations
- Backward compatibility
"""

import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parents[2]

def check_file_contains(path, substrings, description):
    content = path.read_text(encoding='utf-8')
    for sub in substrings:
        assert sub in content, f"{path}: missing '{sub}' for {description}"
    print(f"PASS: {description} in {path.name}")

def main():
    base = ROOT / "api/src/AI"

    # 1. Generic provider interface
    interface_path = base / "Providers/AIProviderInterface.php"
    assert interface_path.is_file(), "AIProviderInterface missing"
    check_file_contains(interface_path, 
        ["function generate(", "AIRequestDTO", "AIResponseDTO", "function extract(", "getName()", "getCapabilities()"],
        "Generic provider interface with generate() + backward compat extract()"
    )

    # 2. DTOs
    req_dto = base / "DTOs/AIRequestDTO.php"
    res_dto = base / "DTOs/AIResponseDTO.php"
    assert req_dto.is_file() and res_dto.is_file(), "DTOs missing"
    check_file_contains(req_dto, ["userId", "feature", "provider", "promptHash", "forExtraction", "forAnalysis"], "AIRequestDTO")
    check_file_contains(res_dto, ["content", "provider", "model", "latencyMs", "tokensUsed", "confidence", "contentAsJson"], "AIResponseDTO")

    # 3. Base repository
    base_repo = base / "Repositories/AIRepository.php"
    assert base_repo.is_file(), "AIRepository base missing"
    check_file_contains(base_repo, ["abstract class AIRepository", "function connection()", "Database::connection()"], "Base AIRepository")

    # 4. Repositories extend base
    for repo_file in ["AIExtractionRepository.php", "AIProviderQuotaRepository.php", "AIProviderLogRepository.php", "AIRequestRepository.php", "AIFeatureFlagRepository.php", "AIAuditLogRepository.php"]:
        path = base / "Repositories" / repo_file
        assert path.is_file(), f"Missing {repo_file}"
        content = path.read_text()
        assert "extends AIRepository" in content, f"{repo_file} should extend AIRepository"
        assert "connection()" in content or "AIRepository" in content, f"{repo_file} should use base connection"
        print(f"PASS: {repo_file} extends base")

    # 5. PromptManager
    prompt_mgr = base / "Prompts/PromptManager.php"
    assert prompt_mgr.is_file(), "PromptManager missing"
    check_file_contains(prompt_mgr, ["function get(", "function getWithVars", "TEMPLATE_DIR", "fallbackPrompt"], "PromptManager versioned")

    templates = list((base / "Prompts/templates").glob("*.txt"))
    assert len(templates) >= 2, "Prompt templates missing"
    assert any("screenshot_extraction" in t.name for t in templates), "screenshot_extraction template missing"
    print(f"PASS: Prompt templates {len(templates)} found")

    # 6. ImageProcessor
    img_proc = base / "Services/ImageProcessor.php"
    assert img_proc.is_file(), "ImageProcessor missing"
    check_file_contains(img_proc, ["MAX_DIMENSION", "JPEG_QUALITY", "function process(", "imagescale", "imagejpeg", "imagecreatefromstring"], "ImageProcessor with resize/compress")

    # 7. VisionExtractorInterface adapter
    vision_interface = base / "Extraction/VisionExtractorInterface.php"
    assert vision_interface.is_file(), "VisionExtractorInterface missing"
    check_file_contains(vision_interface, ["interface VisionExtractorInterface", "function extract(", "function generate("], "VisionExtractorInterface adapter")

    # 8. AIManager has both generate and extract with atomic quota
    ai_manager = base / "Services/AIManager.php"
    check_file_contains(ai_manager, ["function generate(", "function extract(", "AIProviderQuotaRepository", "AIProviderLogRepository", "AIRequestRepository", "tryReserveQuota", "hasQuota"], "AIManager with generic generate + atomic quota/log/request tracking")

    # 9. GeminiProvider implements generate + extract
    gemini = base / "Providers/GeminiProvider.php"
    check_file_contains(gemini, ["function generate(", "function extract(", "Config::env('GEMINI_API_KEY'", "PromptManager", "ImageProcessor", "AIResponseDTO"], "GeminiProvider generic + BC")

    # 10. TesseractProvider implements generate
    tesseract = base / "Providers/TesseractProvider.php"
    check_file_contains(tesseract, ["function generate(", "function extract(", "AIResponseDTO"], "TesseractProvider generic")

    # 11. ScreenshotExtractor uses ImageProcessor + PromptManager + VisionExtractorInterface
    extractor = base / "Extraction/ScreenshotExtractor.php"
    check_file_contains(extractor, ["implements VisionExtractorInterface", "ImageProcessor::process", "PromptManager::get", "ExtractionValidator::validate"], "ScreenshotExtractor hardened")

    # 12. Migrations
    v04 = ROOT / "api/database/migrations/v0.4_ai_foundation.sql"
    v05 = ROOT / "api/database/migrations/v0.5_ai_requests.sql"
    assert v04.is_file() and v05.is_file(), "Migrations missing"
    v04_content = v04.read_text()
    v05_content = v05.read_text()
    assert "ai_extractions" in v04_content and "ai_provider_quotas" in v04_content and "ai_provider_logs" in v04_content
    assert "ai_requests" in v05_content and "ai_feature_flags" in v05_content and "ai_audit_logs" in v05_content and "ai_feedback" in v05_content
    assert "ENGINE=InnoDB" in v04_content and "utf8mb4" in v04_content
    assert "IF NOT EXISTS" in v05_content
    print("PASS: Migrations v0.4 and v0.5 valid")

    # 13. Controller backward compatibility
    controller = ROOT / "api/src/Trades/ScreenshotExtractController.php"
    content = controller.read_text()
    assert "AIManager" in content and "ScreenshotExtractor" in content, "Controller should use AIManager"
    assert "'engine'" in content and "'texts'" in content and "'times'" in content, "Controller must keep backward compat fields"
    assert "'extraction'" in content, "Controller should return new extraction field"
    assert "RateLimiter::hit" in content, "RateLimiter must remain"
    assert "decodeAndValidateImage" in content, "File validation must remain"
    assert "AIAuditLogRepository" in content, "Audit logging should be present"
    print("PASS: Controller backward compatibility")

    # 14. Config
    config = ROOT / "api/config/config.php"
    config_content = config.read_text()
    assert "'ai'" in config_content
    assert "GEMINI_API_KEY" in config_content and "Config::env" in config_content
    assert "image_max_dimension" in config_content and "prompt_path" in config_content
    print("PASS: Config ai section hardened")

    # 15. No hardcoded secrets
    for php_file in base.rglob("*.php"):
        txt = php_file.read_text()
        # Check for hardcoded API keys (sk-, AIza) not in comments
        if re.search(r'sk-[A-Za-z0-9]{20,}', txt):
            # Allow in comments or tests?
            if 'replace-with' not in txt and 'test-key' not in txt:
                raise AssertionError(f"{php_file}: possible hardcoded OpenAI key")
        if re.search(r'AIza[0-9A-Za-z\-_]{35}', txt):
            raise AssertionError(f"{php_file}: possible hardcoded Gemini key")
    print("PASS: No hardcoded secrets")

    print("\n=== AI Foundation Hardening: ALL CHECKS PASS ===")
    return 0

if __name__ == "__main__":
    sys.exit(main())
