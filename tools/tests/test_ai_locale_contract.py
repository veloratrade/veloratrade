#!/usr/bin/env python3
"""VELORA — G8 AI Locale Contract gate regression tests.

Negative fixtures prove the gate actually fails when the invariant is
violated (PERMANENT BILINGUAL GOVERNANCE §18): a prompt without an explicit
output-language rule, an extraction prompt without the digit/authority policy,
a controller defaulting the body locale to 'en' before consulting the
persisted user locale, an undeclared template, and an unclassified prompt
name must ALL fail the checker.
"""

import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "localization"))
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from localization import check_ai_locale_contract as g8  # noqa: E402

PROMPT = "api/src/AI/Prompts/templates"
CTRL = "api/src/AI/Controllers/AIController.php"

GOOD_PROSE = """You are an analyst.
Context: {locale}.
Rules:
- Return ONLY JSON.
- Output language contract: write every prose field in {locale}: if fa, Persian (RTL); if en, English. Never mix languages.
- Confidence based on data completeness.
"""

GOOD_MACHINE = """You are a screenshot parser. Return ONLY valid JSON.
Rules:
- Input language policy: screenshots may be Persian, English, or mixed-language; digits may be Persian (۰۱۲۳), Arabic-Indic (٠١٢٣), Latin, or mixed. Extract semantic fields independently of display language/digit forms.
- Digit forms valid: Persian (۰۱۲۳), Arabic-Indic (٠١٢٣), Latin digits are all valid input.
- Numeric output policy: preferably return canonical Latin ASCII digit strings (e.g. 131.40).
- Your digit representation is NOT authoritative: the backend normalizes digit forms before validation and calculation.
- If field not visible, use null.
"""

GOOD_CONTROLLER = """final class AIController {
    private readonly UserRepository $userRepository = new UserRepository();
    private function resolveAiLocale(int $userId, ?string $bodyLocale): string {
        if (is_string($bodyLocale) && in_array(strtolower(trim($bodyLocale)), ['fa','en'], true)) return strtolower(trim($bodyLocale));
        $user = $this->userRepository->findById($userId);
        $locale = is_array($user) ? strtolower(trim((string) ($user['locale'] ?? ''))) : '';
        return in_array($locale, ['fa','en'], true) ? $locale : 'en';
    }
    public function analyzeTrades(Request $request): never {
        $locale = $this->resolveAiLocale($userId, $request->body['locale'] ?? null);
        $this->analyzer->analyze($userId, $trades, ['locale' => $locale]);
    }
    public function weeklyReport(Request $request): never {
        $locale = $this->resolveAiLocale($userId, $request->body['locale'] ?? null);
        $this->reportService->generateWeekly($userId, $start, ['locale' => $locale]);
    }
}
"""

GOOD_SERVICE = """final class TradeAnalyzerService {
    $locale = $options['locale'] ?? 'en';
    $prompt = PromptManager::getWithVars('trade_analysis', ['locale' => $locale], 'v1', $locale);
}
"""


def tree(**extra: str) -> dict[str, str]:
    d = {
        PROMPT + "/weekly_report_v1.txt": GOOD_PROSE,
        PROMPT + "/trade_analysis_v1.txt": GOOD_PROSE.replace("analyst", "trade analyst"),
        PROMPT + "/screenshot_extraction_v1.txt": GOOD_MACHINE,
        PROMPT + "/screenshot_extraction_v2.txt": GOOD_MACHINE,
        CTRL: GOOD_CONTROLLER,
        "api/src/AI/Analysis/TradeAnalyzerService.php": GOOD_SERVICE,
        "api/src/AI/Reports/WeeklyReportService.php": GOOD_SERVICE,
    }
    d.update(extra)
    return d


class G8PositiveTest(unittest.TestCase):
    def test_conforming_tree_passes(self):
        self.assertEqual(g8.evaluate(tree()), [])

    def test_real_tree_passes(self):
        root = Path(__file__).resolve().parents[2]
        self.assertEqual(g8.check_tree(root), [])


class G8NegativeTest(unittest.TestCase):
    def test_prose_without_output_language_rule_fails(self):
        bad = GOOD_PROSE.replace(
            "- Output language contract: write every prose field in {locale}: if fa, Persian (RTL); if en, English. Never mix languages.\n", "")
        problems = g8.evaluate(tree(**{PROMPT + "/trade_analysis_v1.txt": bad}))
        self.assertTrue(any("G8.prose.output-language-rule" in p for p in problems), problems)

    def test_machine_without_input_language_policy_fails(self):
        bad = GOOD_MACHINE.replace("Input language policy:", "Screenshot policy:")
        problems = g8.evaluate(tree(**{PROMPT + "/screenshot_extraction_v1.txt": bad}))
        self.assertTrue(any("G8.machine.input-language-policy" in p for p in problems), problems)

    def test_machine_without_digit_forms_policy_fails(self):
        # Remove the Arabic-Indic marker everywhere: the gate is document-global,
        # so a negative must strip the marker from all lines.
        bad = GOOD_MACHINE.replace("Arabic-Indic", "Other-forms")
        problems = g8.evaluate(tree(**{PROMPT + "/screenshot_extraction_v1.txt": bad}))
        self.assertTrue(any("G8.machine.digit-forms-valid" in p for p in problems), problems)

    def test_machine_without_backend_authoritative_fails(self):
        bad = GOOD_MACHINE.replace("is NOT authoritative", "is ignored")
        problems = g8.evaluate(tree(**{PROMPT + "/screenshot_extraction_v1.txt": bad}))
        self.assertTrue(any("G8.machine.backend-authoritative" in p for p in problems), problems)

    def test_controller_body_locale_defaults_en_fails(self):
        bad = GOOD_CONTROLLER.replace(
            "$locale = $this->resolveAiLocale($userId, $request->body['locale'] ?? null);",
            "$locale = strtolower(trim((string) ($request->body['locale'] ?? 'en')));").replace(
            "private readonly UserRepository $userRepository = new UserRepository();", "").replace(
            "$user = $this->userRepository->findById($userId);", "").replace(
            "return in_array($locale, ['fa','en'], true) ? $locale : 'en';", "return 'en';").replace(
            "private function resolveAiLocale", "private function legacyLocale")
        problems = g8.evaluate(tree(**{CTRL: bad}))
        self.assertTrue(any("G8.controller.body-locale-defaults-en" in p or "G8.controller.resolve-helper" in p
                            or "G8.controller.user-repo" in p for p in problems), problems)

    def test_undeclared_template_fails(self):
        problems = g8.evaluate(tree(**{PROMPT + "/new_insights_v1.txt": GOOD_PROSE}))
        self.assertTrue(any("G8.undeclared-template" in p for p in problems), problems)

    def test_missing_declared_template_fails(self):
        d = tree()
        del d[PROMPT + "/trade_analysis_v1.txt"]
        problems = g8.evaluate(d)
        self.assertTrue(any("G8.missing-template" in p for p in problems), problems)

    def test_unclassified_prompt_name_fails(self):
        problems = g8.evaluate(tree(**{
            "api/src/AI/Analysis/NewInsightsService.php":
                GOOD_SERVICE.replace("trade_analysis", "new_insights")}))
        self.assertTrue(any("G8.unclassified-prompt-name" in p for p in problems), problems)

    def test_service_not_plumbing_locale_fails(self):
        bad = GOOD_SERVICE.replace("'locale' => $locale", "'locale' => 'en'")
        problems = g8.evaluate(tree(**{"api/src/AI/Analysis/TradeAnalyzerService.php": bad}))
        self.assertTrue(any("G8.service.locale-plumbed" in p for p in problems), problems)


if __name__ == "__main__":
    unittest.main(verbosity=2)
