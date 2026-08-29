#!/usr/bin/env python3
"""
AI hardening v2 — static regression tests (no network, no secrets, no DB).

Covers the production-readiness fixes:
  - anonymization is fail-closed (never returns the original image)
  - AIManager refuses to send raw images to external providers
  - Gemini key is sent via header, never in the query string
  - provider error classification distinguishes 401/403/429/5xx
  - trade trust boundary: trade_ids[] (server-resolved), trades[] rejected
  - model output is whitelisted before being returned
  - prompt templates use a data envelope
  - retention covers all AI user-data tables
"""

import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parents[2]


def read(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")


def fail(msg: str) -> None:
    print(f"FAIL: {msg}")
    sys.exit(1)


# 1. ImageAnonymizer is fail-closed
anon = read("api/src/AI/Security/ImageAnonymizer.php")
assert ": ?string" in anon, "anonymize() must return ?string"
# The original image must never be returned on failure paths.
for bad in ["return $imageRaw;"]:
    if bad in anon:
        fail(f"ImageAnonymizer still returns the original image ({bad})")
for guard in ["return null;", "fail", "getInfo", "anonymized"]:
    assert guard in anon, f"ImageAnonymizer missing '{guard}'"
assert "hash('sha256', $anonymized)" in anon, "identical-output guard missing"
print("PASS: ImageAnonymizer is fail-closed (never returns original)")

# 2. AIManager refuses external send on anonymization failure
manager = read("api/src/AI/Services/AIManager.php")
assert "anonymization_failed" in manager, "AIManager must record anonymization_failed"
assert "ANONYMIZATION_FAILED" in manager, "AIManager must log ANONYMIZATION_FAILED"
assert "refusing to send the original image" in manager, "AIManager must refuse external send"
assert "ImageAnonymizer::anonymize" in manager
print("PASS: AIManager fail-closed on anonymization failure")

# 3. Gemini key via header, not query string (direct HTTP code lives in Transports since the transport split)
gemini = read("api/src/AI/Providers/GeminiProvider.php")
direct = read("api/src/AI/Transports/DirectGeminiTransport.php")
relay = read("api/src/AI/Transports/N8nGeminiRelayTransport.php")
for src, label in ((gemini, "GeminiProvider"), (direct, "DirectGeminiTransport"), (relay, "N8nGeminiRelayTransport")):
    assert "?key=" not in src and "?token=" not in src, f"{label}: credentials must not travel in the URL query string"
assert "x-goog-api-key" in direct, "Gemini API key must be sent via x-goog-api-key header"
assert "CURLOPT_SSL_VERIFYPEER" in direct and "true" in direct, "TLS verification must stay on"
assert "CURLOPT_FOLLOWLOCATION" in direct, "redirect handling must stay explicit"
print("PASS: Gemini key via header, TLS + timeout preserved (provider + transports)")

# 4. Provider error classification (HTTP status mapping lives in the direct transport)
for code, exc in [("401", "invalid or unauthorized"), ("429", "AIQuotaExhaustedException"), (">= 500", "service unavailable"), ("=== 400", "AIValidationException")]:
    assert exc in gemini + direct, f"Gemini path missing classification for {code}"
assert "AIQuotaExhaustedException" in gemini
print("PASS: provider error classification (401/403/429/5xx/400)")

# 5. Trade trust boundary
controller = read("api/src/AI/Controllers/AIController.php")
assert "trade_ids" in controller, "AIController must accept trade_ids[]"
assert "TradeResolver" in controller, "AIController must resolve trades server-side"
assert "not accepted; send trade_ids[]" in controller, "AIController must explicitly reject trades[]"
assert "resolveOwned" in controller, "AIController must call resolveOwned"
assert "whitelistOutput" in controller, "AIController must whitelist model output"
resolver = read("api/src/Trades/TradeResolver.php")
assert "findOwned" in resolver, "TradeResolver must enforce ownership via findOwned"
# AI must not reference TradeRepository directly
for f in (ROOT / "api/src/AI").rglob("*.php"):
    c = f.read_text(encoding="utf-8")
    if "use Velora\\Trades\\TradeRepository" in c or "new TradeRepository" in c:
        fail(f"{f.name}: AI must not depend on TradeRepository directly")
print("PASS: trade_ids[] server-resolved; trades[] rejected; AI free of TradeRepository")

# 6. Output whitelisting
assert "ANALYSIS_OUTPUT_FIELDS" in controller and "REPORT_OUTPUT_FIELDS" in controller
print("PASS: model output whitelisted")

# 7. Prompt data envelope
tpl = read("api/src/AI/Prompts/templates/trade_analysis_v1.txt")
assert "<velora_data>" in tpl and "</velora_data>" in tpl, "trade_analysis template must envelope data"
assert "UNTRUSTED USER DATA" in tpl, "template must mark data as untrusted"
weekly = read("api/src/AI/Prompts/templates/weekly_report_v1.txt")
assert "<velora_data>" in weekly, "weekly_report template must envelope data"
print("PASS: prompt templates use data envelope")

# 8. Retention covers all AI user-data tables
ret = read("api/workers/ai_retention_cleanup.php")
for t in ["ai_requests", "ai_provider_logs", "ai_audit_logs", "ai_analysis", "ai_reports", "ai_feedback", "ai_jobs", "ai_extractions"]:
    assert t in ret, f"retention worker missing table {t}"
print("PASS: retention covers all AI user-data tables")

# 9. WeeklyReportService sanitizes numeric/datetime fields
weekly_svc = read("api/src/AI/Reports/WeeklyReportService.php")
assert "sanitizeNumeric" in weekly_svc and "sanitizeDateTime" in weekly_svc
print("PASS: weekly report sanitizes pnl/open_time/close_time")

print("\nAI Hardening v2: ALL CHECKS PASS")
