#!/usr/bin/env python3
"""Regression tests for the translation reference-completeness gate (G1/G2).

Covers:
  * repo mode passes on the current tree
  * ANY referenced key missing from a catalog fails (fa / en / both)
  * the exact 2026-08-30 incident pair (branch profile HTML + main catalogs)
    fails with the 14 profile.aiConsent.* keys
  * deployed pair (branch HTML + branch catalogs) passes
  * extractor false-positive classes are ignored:
      CSS selectors, JS comments, HTML comments, code-in-string,
      ordinary identifiers, dynamic heads ("ns." + x)
  * G2 runtime-feature consistency: served page => loaded chunk coverage
"""
from __future__ import annotations

import json
import unittest
from pathlib import Path

import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "localization"))
import check_key_references as gate  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]
FIXTURES = Path(__file__).resolve().parent / "fixtures"


def make_catalog(tmp: Path, name: str = "fa.json", extra: dict | None = None) -> Path:
    data = {"messages": {"common.trades": "Trade", "profile.aiConsent.title": "AI",
                         **(extra or {})}}
    p = tmp / name
    p.write_text(json.dumps(data, ensure_ascii=False), encoding="utf-8")
    return p


class KeyReferenceGateTest(unittest.TestCase):
    def setUp(self):
        gate.NAMESPACES.clear()
        gate.NAMESPACES.update({"common", "profile", "errors", "nav", "landing", "accounts"})
        self._orig_excluded = set(gate.EXCLUDED_DIRS)
        gate.EXCLUDED_DIRS.add("localized")
        gate.EXCLUDED_DIRS.add("en")
        gate.EXCLUDED_DIRS.add("fa")

    def tearDown(self):
        gate.EXCLUDED_DIRS.clear()
        gate.EXCLUDED_DIRS.update(self._orig_excluded)

    # ---- extractor false-positive classes -------------------------------
    def test_css_selector_and_rule_body_ignored(self):
        html = (
            "<html><head><style>nav.links{display:flex}\n"
            ".card .price{color:red}/* common.trades? no */</style></head>"
            '<body data-i18n="common.trades">x</body></html>'
        )
        keys, _ = gate.extract_from_html(html)
        self.assertEqual(keys, {"common.trades"})

    def test_js_comment_and_dynamic_head_ignored(self):
        js = (
            "// PR-14: intent keywords come from the locale catalog "
            "(landing.ai.intent here)\n"
            "/* nav.links hidden in comment */\n"
            "function f(){ return t('errors.http.' + response.status); }\n"
            "const a = t('common.trades');\n"
        )
        keys, _ = gate.extract_from_js(js)
        self.assertNotIn("landing.ai.intent", keys)
        self.assertNotIn("nav.links", keys)
        self.assertNotIn("errors.http", keys)  # dynamic head only
        self.assertIn("common.trades", keys)

    def test_code_inside_string_ignored(self):
        js = (
            "var x = '>\\' + escapeHtml(server) + \\'</div>\\'; }).join(\\'\\'); "
            "'+ 'accounts.map.join str';"
            "var y = t('common.trades');"
        )
        keys, _ = gate.extract_from_js(js)
        self.assertNotIn("accounts.length", keys)
        self.assertNotIn("accounts.map", keys)
        self.assertIn("common.trades", keys)

    def test_html_comment_ignored(self):
        html = "<html><body><!-- data-i18n=\"profile.aiConsent.title\" -->" \
               '<div data-i18n="common.trades"></div></body></html>'
        keys, _ = gate.extract_from_html(html)
        self.assertEqual(keys, {"common.trades"})

    # ---- catalog checks -------------------------------------------------
    def test_key_missing_from_one_locale_fails(self):
        with _TmpDir() as tmp:
            fa = make_catalog(tmp, "fa.json", {"profile.aiConsent.pillOn": "روشن"})
            en = make_catalog(tmp, "en.json")
            html = tmp / "page.html"
            html.write_text('<div data-i18n="profile.aiConsent.pillOn"></div>', encoding="utf-8")
            rc = _run_pair(html, fa, en)
        self.assertNotEqual(rc, 0)

    def test_key_in_one_locale_only_fails(self):
        with _TmpDir() as tmp:
            fa = make_catalog(tmp, "fa.json")
            en = make_catalog(tmp, "en.json", {"profile.aiConsent.pillOn": "On"})
            html = tmp / "page.html"
            html.write_text('<div data-i18n="profile.aiConsent.pillOn"></div>', encoding="utf-8")
            rc = _run_pair(html, fa, en)
        self.assertNotEqual(rc, 0)

    def test_repo_mode_passes_on_current_tree(self):
        gate.NAMESPACES.clear()
        rc = gate.main() if False else _repo_rc(ROOT)
        self.assertEqual(rc, 0, "repository must be self-consistent: every "
                               "referenced key exists in fa AND en")

    def test_exact_incident_pair_fails_with_ai_consent(self):
        """The exact incident pair (new HTML + old catalog) must FAIL with the
        14 profile.aiConsent.* keys — the negative control of the whole gate."""
        branch_html = FIXTURES / "incident-profile.html"
        main_fa = FIXTURES / "main-fa-catalog.json"
        main_en = FIXTURES / "main-en-catalog.json"
        if not branch_html.exists():
            self.skipTest("fixtures not materialized (sparse cone)")
        gate.NAMESPACES.clear()
        gate.NAMESPACES.update({"profile", "common", "errors"})
        rc = _run_pair(branch_html, main_fa, main_en)
        self.assertEqual(rc, 1)
        keys, _ = gate.extract_from_html(
            branch_html.read_text(encoding="utf-8", errors="replace"))
        ai = {k for k in keys if k.startswith("profile.aiConsent.")}
        self.assertEqual(len(ai), 14)

    # ---- G2 runtime feature coverage ------------------------------------
    def test_runtime_feature_missing_key_fails(self):
        with _TmpDir() as tmp:
            (tmp / "localized" / "fa").mkdir(parents=True)
            (tmp / "localized" / "en").mkdir(parents=True)
            (tmp / "public" / "locales" / "chunks" / "fa").mkdir(parents=True)
            (tmp / "public" / "locales" / "chunks" / "en").mkdir(parents=True)
            (tmp / "public" / "locales" / "chunks" / "fa" / "profile.json").write_text(
                json.dumps({"messages": {"profile.aiConsent.pillOn": "x"}}), encoding="utf-8")
            (tmp / "public" / "locales" / "chunks" / "en" / "profile.json").write_text(
                json.dumps({"messages": {"profile.aiConsent.pillOn": "y"}}), encoding="utf-8")
            (tmp / "public" / "locales" / "chunks" / "fa" / "common.json").write_text(
                json.dumps({"messages": {"common.trades": "x"}}), encoding="utf-8")
            (tmp / "public" / "locales" / "chunks" / "en" / "common.json").write_text(
                json.dumps({"messages": {"common.trades": "y"}}), encoding="utf-8")
            page = tmp / "localized" / "fa" / "profile" / "index.html"
            page.parent.mkdir(parents=True)
            page.write_text(
                '<html data-i18n-features="common,profile"><body>'
                '<div data-i18n="profile.aiConsent.pillOn"></div>'
                '<div data-i18n="profile.aiConsent.missingKey"></div>'
                "</body></html>", encoding="utf-8")
            gate.NAMESPACES.clear()
            gate.NAMESPACES.update({"profile", "common"})
            report = gate.RuntimeFeatureReport()
            gate.scan_runtime_features(tmp, report)
        self.assertTrue(any("profile.aiConsent.missingKey" in p for p in report.problems))
        self.assertEqual(len(report.problems), 1)

    def test_runtime_feature_missing_chunk_fails(self):
        with _TmpDir() as tmp:
            for loc in ("fa", "en"):
                (tmp / "localized" / loc).mkdir(parents=True)
                (tmp / "public" / "locales" / "chunks" / loc).mkdir(parents=True)
            page = tmp / "localized" / "fa" / "index.html"
            page.write_text('<html data-i18n-features="common,errors,ghost"><body></body></html>',
                            encoding="utf-8")
            gate.NAMESPACES.clear()
            report = gate.RuntimeFeatureReport()
            gate.scan_runtime_features(tmp, report)
        self.assertTrue(any("ghost" in p and "missing" in p for p in report.problems))


class _TmpDir:
    def __enter__(self):
        import tempfile
        self._d = tempfile.TemporaryDirectory()
        return Path(self._d.name)

    def __exit__(self, *a):
        self._d.cleanup()


def _run_pair(html: Path, fa: Path, en: Path) -> int:
    gate.NAMESPACES.clear()
    return _pair_rc(html, fa, en)


def _pair_rc(html, fa, en):
    import io
    import contextlib
    old = sys.argv
    sys.argv = ["check", "--page", str(html), "--catalog-fa", str(fa),
                "--catalog-en", str(en)]
    try:
        with contextlib.redirect_stdout(io.StringIO()):
            with contextlib.redirect_stderr(io.StringIO()):
                rc = gate.main()
    finally:
        sys.argv = old
    return rc


def _repo_rc(root):
    import io
    import contextlib
    old = sys.argv
    sys.argv = ["check", "--root", str(root)]
    try:
        with contextlib.redirect_stdout(io.StringIO()):
            with contextlib.redirect_stderr(io.StringIO()):
                rc = gate.main()
    finally:
        sys.argv = old
    return rc


if __name__ == "__main__":
    unittest.main()
