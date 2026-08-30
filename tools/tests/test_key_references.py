#!/usr/bin/env python3
"""Regression tests for tools/localization/check_key_references.py.

Pins the fix for the 2026-08-30 incident class: a template may reference a
catalog key that exists in NEITHER locale (raw-key leak to UI), and the
actual deployed HTML may reference keys missing from the deployed catalog.
"""

import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CHECKER = ROOT / "tools" / "localization" / "check_key_references.py"


def run_checker(args):
    return subprocess.run(
        [sys.executable, str(CHECKER), *args],
        capture_output=True, text=True, cwd=ROOT,
    )


class KeyReferenceGateTest(unittest.TestCase):
    def test_repo_mode_passes_on_current_tree(self):
        """The repository must be self-consistent: every referenced key exists
        in both fa and en catalogs. This fails CI the moment a template or JS
        asset references a key that is not in both catalogs."""
        r = run_checker([])
        self.assertEqual(r.returncode, 0, r.stdout[-2000:] + r.stderr[-500:])
        self.assertIn("REFERENCE-COMPLETENESS: PASS", r.stdout)

    def test_incident_class_detected_in_pair_mode(self):
        """The exact incident pair (new HTML + old catalog) must FAIL with the
        aiConsent keys — proving the gate catches the class."""
        with tempfile.TemporaryDirectory() as td:
            td = Path(td)
            html = td / "profile.html"
            html.write_text(
                '<div data-i18n="profile.aiConsent.title">x</div>'
                '<div data-i18n="profile.aiConsent.pillOff">y</div>', encoding="utf-8")
            fa = td / "fa.json"
            en = td / "en.json"
            fa.write_text(json.dumps({"messages": {"common.ok": "ok-fa", "profile.dummy": "x"}}), encoding="utf-8")
            en.write_text(json.dumps({"messages": {"common.ok": "ok", "profile.dummy": "x"}}), encoding="utf-8")
            r = run_checker(["--page", str(html),
                             "--catalog-fa", str(fa), "--catalog-en", str(en)])
            self.assertEqual(r.returncode, 1)
            self.assertIn("profile.aiConsent.title", r.stdout)
            self.assertIn("profile.aiConsent.pillOff", r.stdout)

    def test_complete_pair_passes(self):
        with tempfile.TemporaryDirectory() as td:
            td = Path(td)
            html = td / "profile.html"
            html.write_text('<div data-i18n="common.ok">x</div>', encoding="utf-8")
            fa = td / "fa.json"
            en = td / "en.json"
            fa.write_text(json.dumps({"messages": {"common.ok": "ok-fa", "profile.dummy": "x"}}), encoding="utf-8")
            en.write_text(json.dumps({"messages": {"common.ok": "ok", "profile.dummy": "x"}}), encoding="utf-8")
            r = run_checker(["--page", str(html),
                             "--catalog-fa", str(fa), "--catalog-en", str(en)])
            self.assertEqual(r.returncode, 0, r.stdout)

    def test_key_in_one_locale_only_fails(self):
        with tempfile.TemporaryDirectory() as td:
            td = Path(td)
            html = td / "profile.html"
            html.write_text('<div data-i18n="common.ok">x</div>', encoding="utf-8")
            fa = td / "fa.json"
            en = td / "en.json"
            fa.write_text(json.dumps({"messages": {"common.ok": "ok-fa", "profile.dummy": "x"}}), encoding="utf-8")
            en.write_text(json.dumps({"messages": {}}), encoding="utf-8")
            r = run_checker(["--page", str(html),
                             "--catalog-fa", str(fa), "--catalog-en", str(en)])
            self.assertEqual(r.returncode, 1)
            self.assertIn("missing in en", r.stdout)


if __name__ == "__main__":
    unittest.main(verbosity=2)
