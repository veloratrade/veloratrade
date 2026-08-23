#!/usr/bin/env python3
"""Unit tests for the Localization Feature Gate (composition-only wrapper
around validate_localization.py and check_hardcoded_ui.py)."""
from __future__ import annotations

import json
import unittest
from pathlib import Path

from tools.localization.localization_gate import run_gate


class LocalizationGateTestCase(unittest.TestCase):
    """The gate must reuse the two existing validators, not reimplement
    detection logic. These tests only need to prove the composition wires
    both checks through correctly against the real repository tree — the
    underlying detection behavior itself is already covered by
    test_validate_localization.py and test_pr01_freeze.py."""

    def test_gate_passes_on_current_repository_tree(self) -> None:
        root = Path(__file__).resolve().parents[2]
        ok, messages = run_gate(root)
        self.assertTrue(ok, msg="\n".join(messages))
        joined = "\n".join(messages)
        self.assertIn("Catalog validation OK", joined)
        self.assertIn("Hardcoded-UI freeze OK", joined)

    def test_gate_fails_when_catalog_parity_breaks(self) -> None:
        root = Path(__file__).resolve().parents[2]
        en_path = root / "public" / "locales" / "en.json"
        original = en_path.read_text(encoding="utf-8")
        try:
            data = json.loads(original)
            data["messages"]["test.gate.parity.temp"] = "temporary"
            en_path.write_text(
                json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
            )
            ok, messages = run_gate(root)
            self.assertFalse(ok)
            self.assertTrue(
                any("CATALOG VALIDATION FAILED" in m for m in messages),
                msg="\n".join(messages),
            )
        finally:
            en_path.write_text(original, encoding="utf-8")

    def test_gate_fails_when_new_hardcoded_persian_literal_is_introduced(self) -> None:
        root = Path(__file__).resolve().parents[2]
        asset_path = root / "public" / "assets" / "velora-i18n.js"
        original = asset_path.read_text(encoding="utf-8")
        try:
            asset_path.write_text(
                original + "\nvar __gateTestLiteral = '\u06cc\u06a9 \u0645\u062a\u0646 \u062c\u062f\u06cc\u062f \u0641\u0627\u0631\u0633\u06cc';\n",
                encoding="utf-8",
            )
            ok, messages = run_gate(root)
            self.assertFalse(ok)
            self.assertTrue(
                any("HARDCODED-UI CHECK FAILED" in m for m in messages),
                msg="\n".join(messages),
            )
        finally:
            asset_path.write_text(original, encoding="utf-8")


if __name__ == "__main__":
    unittest.main()
