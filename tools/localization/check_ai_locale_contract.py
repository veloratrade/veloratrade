#!/usr/bin/env python3
"""VELORA — AI Locale Contract gate (G8, permanent).

Contract (PERMANENT BILINGUAL GOVERNANCE, §4 / §14 / §16):
  * AI prompt templates with user-facing PROSE output (insights, summaries,
    recommendations, review messages) MUST declare an explicit output-language
    rule that binds the prose to {locale}: fa -> Persian (RTL), en -> English.
  * AI prompt templates with MACHINE-DATA output (structured extraction) must
    be language-neutral data and MUST declare an explicit input-language
    policy (Persian / English / mixed screenshots and Persian / Arabic-Indic /
    Latin / mixed digits are VALID INPUT) and a numeric-output policy that
    states the model's digit representation is NOT authoritative and the
    backend normalizes before validation/calculation.
  * The API controller that feeds user-facing AI prose MUST resolve the
    locale through the canonical chain: validated client locale -> persisted
    canonical user locale (users.locale) -> 'en'. A bare "default to en" on
    the request body is the anti-pattern this gate rejects.
  * Every prompt NAME used through PromptManager must be declared in the
    manifest here, classified as prose or machine-data. A new AI prompt that
    is not declared fails the gate (fail-closed, no silent skip).

False-positive policy: checks are content markers that every conforming
template is REQUIRED to carry; the manifest is the single source of truth and
entries must be removed together with the fix (same policy as
allowlist-hardcoded.json). Changing a requirement marker requires updating
this file AND the negative fixture tests in tools/tests/test_ai_locale_contract.py.

Exit codes: 0 = PASS, 1 = FAIL, 2 = usage error.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

REPO_HINT = Path(__file__).resolve().parents[2]
PROMPT_DIR_REL = "api/src/AI/Prompts/templates"
CONTROLLER_REL = "api/src/AI/Controllers/AIController.php"

# ---------------------------------------------------------------------------
# Manifest — single source of truth for AI prompt classification.
# kind: 'prose' (user-facing prose must follow {locale})
#       'machine-data' (language-neutral structured data; policy is about
#                       input language + numeric representation authority)
# ---------------------------------------------------------------------------
MANIFEST: dict[str, dict] = {
    "weekly_report_v1.txt": {
        "kind": "prose",
        "name": "weekly_report",
        "note": "Weekly report summaries/suggestions are user-facing prose.",
    },
    "trade_analysis_v1.txt": {
        "kind": "prose",
        "name": "trade_analysis",
        "note": "Trade analysis insights/recommendations are user-facing prose.",
    },
    "screenshot_extraction_v1.txt": {
        "kind": "machine-data",
        "name": "screenshot_extraction",
        "note": "Extraction returns structured machine-readable numeric fields.",
    },
}

# Required markers per kind — each requirement is a LIST of case-insensitive
# marker groups; a plain string must be present, a nested list means ANY-ONE-of.
# ALL top-level entries must be satisfied (order-independent). See docstring.
PROSE_REQUIRED = [
    ("output-language-rule", [["output language", "support locale"], "{locale}", "fa", "en"]),
]
MACHINE_REQUIRED = [
    ("input-language-policy", ["input language policy", "persian", "english", "mixed", "digit"]),
    ("digit-forms-valid", ["arabic-indic", "persian", "latin", "digit"]),
    ("numeric-policy", ["numeric output policy", "latin", "ascii"]),
    ("backend-authoritative", ["not authoritative", "backend"]),
]


def _has_all(text_lower: str, markers: list) -> bool:
    for m in markers:
        if isinstance(m, list):
            if not any(x in text_lower for x in m):
                return False
        elif m not in text_lower:
            return False
    return True
# Anti-patterns (must NOT appear in the AI controller).
CONTROLLER_ANTIPATTERNS = [
    ("body-locale-defaults-en", re.compile(r"\[\s*'locale'\s*\]\s*\?\?\s*'en'")),
]

# ---------------------------------------------------------------------------
def evaluate_prompt(fname: str, text: str) -> list[str]:
    entry = MANIFEST.get(fname)
    if entry is None:
        return [f"G8.unknown-prompt: template {fname} is not declared in the manifest"]
    problems = []
    lowered = text.lower()
    if entry["kind"] == "prose":
        for label, markers in PROSE_REQUIRED:
            if not _has_all(lowered, markers):
                problems.append(f"G8.prose.{label}: {fname} must declare an output-language rule "
                                f"binding prose to {{locale}} (fa -> Persian, en -> English)")
    elif entry["kind"] == "machine-data":
        for label, markers in MACHINE_REQUIRED:
            if not _has_all(lowered, markers):
                problems.append(f"G8.machine.{label}: {fname} must declare the input-language/numeric policy")
    return problems


def evaluate_controller(text: str) -> list[str]:
    problems = []
    if "UserRepository" not in text:
        problems.append("G8.controller.user-repo: AIController must resolve locale from the "
                        "persisted canonical user locale (UserRepository)")
    if "resolveAiLocale" not in text or "function resolveAiLocale" not in text:
        problems.append("G8.controller.resolve-helper: AIController must contain resolveAiLocale()")
    if text.count("this->resolveAiLocale($userId") < 2:
        problems.append("G8.controller.both-endpoints: resolveAiLocale must be used by analyzeTrades "
                        "AND weeklyReport")
    if "findById" not in text:
        problems.append("G8.controller.user-lookup: resolveAiLocale must lookup the persisted user locale")
    for label, rx in CONTROLLER_ANTIPATTERNS:
        if rx.search(text):
            problems.append(f"G8.controller.{label}: request-body locale must not default to 'en' "
                            f"before consulting the persisted user locale")
    return problems


def evaluate_service(rel: str, text: str) -> list[str]:
    problems = []
    if "PromptManager::getWithVars" not in text or "'locale' => $locale" not in text:
        problems.append(f"G8.service.locale-plumbed: {rel} must pass the resolved $locale "
                        f"into PromptManager::getWithVars")
    return problems


def evaluate(files: dict[str, str]) -> list[str]:
    """files: {repo-relative-path: content}. Returns list of problems ([] = pass)."""
    problems: list[str] = []
    # 1. Templates: every file in the dir must be declared; every declared file must exist.
    declared = set(MANIFEST)
    present = {rel.rsplit("/", 1)[-1] for rel in files if rel.startswith(PROMPT_DIR_REL + "/")}
    for f in sorted(present - declared):
        problems.append(f"G8.undeclared-template: {f} has no manifest entry (classify it prose/machine-data)")
    for f in sorted(declared - present):
        problems.append(f"G8.missing-template: {f} declared in manifest but missing from the tree")
    # 2. Prompt content.
    for f in sorted(declared & present):
        problems.extend(evaluate_prompt(f, files[PROMPT_DIR_REL + "/" + f]))
    # 3. Prompt NAMES used via PromptManager must be classified.
    for rel, text in files.items():
        if not rel.startswith("api/src/"):
            continue
        for name in re.findall(r"PromptManager::get(?:WithVars)?\(\s*'([a-z_]+)'", text):
            if not any(e["name"] == name for e in MANIFEST.values()):
                problems.append(f"G8.unclassified-prompt-name: PromptManager name '{name}' used in "
                                f"{rel} is not classified in the manifest")
    # 4. Controller + service plumbing.
    if CONTROLLER_REL in files:
        problems.extend(evaluate_controller(files[CONTROLLER_REL]))
    for rel, text in files.items():
        if rel.startswith("api/src/AI/") and rel.endswith(".php") and "PromptManager::getWithVars" in text:
            problems.extend(evaluate_service(rel, text))
    return problems


def check_tree(root: Path) -> list[str]:
    files: dict[str, str] = {}
    candidates = [root / PROMPT_DIR_REL, root / "api/src", root / CONTROLLER_REL]
    seen: set[Path] = set()
    for c in candidates:
        if not c.exists():
            continue
        files.update(_read_recursive(c, root, seen))
    return evaluate(files)


def _read_recursive(path: Path, root: Path, seen: set[Path]) -> dict[str, str]:
    out: dict[str, str] = {}
    if path.is_file():
        rel = path.relative_to(root).as_posix()
        if rel not in seen:
            seen.add(rel)
            out[rel] = path.read_text(encoding="utf-8", errors="replace")
        return out
    for p in sorted(path.rglob("*")):
        if not p.is_file() or ".git" in p.parts:
            continue
        rel = p.relative_to(root).as_posix()
        if rel in seen:
            continue
        seen.add(rel)
        out[rel] = p.read_text(encoding="utf-8", errors="replace")
    return out


def main(argv: list[str]) -> int:
    root = Path(argv[1]) if len(argv) > 1 else REPO_HINT
    if not (root / PROMPT_DIR_REL).is_dir():
        print(f"usage: {argv[0]} [repo-root]", file=sys.stderr)
        return 2
    problems = check_tree(root)
    if problems:
        for p in sorted(problems):
            print(f"FAIL: {p}", file=sys.stderr)
        print(f"G8 FAIL — {len(problems)} AI-locale contract violation(s).", file=sys.stderr)
        return 1
    print("G8 PASS — AI prompt language policies and canonical locale resolution verified.")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
