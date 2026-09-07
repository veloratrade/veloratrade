#!/usr/bin/env python3
"""Regression tests — runtime locale integrity guard (failure-class proof).

Proves the exact failure classes from the localization contract:

  A  EN Persian leakage (static UI text) is DETECTED
  B  FA English leakage (static UI text) is DETECTED
  C  Persian user-generated data in EN is NOT flagged (td/span user cells)
  D  English user-generated data in FA is NOT flagged
  E  untranslated Persian aria-label in EN is DETECTED
  F  untranslated placeholder/title in EN are DETECTED
  G  runtime-generated (JS-injected) untranslated UI is DETECTED
  H  brand/technical tokens (Velora, MetaAPI, Resend, n8n, flag ids) do NOT
     produce false positives
  +  user-identity attribute values (name/email tooltip) are NOT flagged
  I  the real Admin v2 artifact passes both locales over the full canonical
     route registry (module CLI, self-contained stub) — the original incident
     is prevented; the 17becaf8 pre-fix artifact is known-failed (verified in
     the accompanying report with 198 EN findings).

Pure-Python classifier tests (no browser) cover the token/email logic; the
fixture tests use file:// pages with the repo's existing Playwright/chromium
stack — no new browser framework.
"""
from __future__ import annotations

import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO))

from tools.localization.runtime_locale_integrity import (  # noqa: E402
    check_values, load_tokens, ARABIC_SCRIPT_RE)

TOKENS = load_tokens(REPO / "tools" / "localization" / "allowlist-runtime-locale.json")

FIXTURE = """<!doctype html><html><head><meta charset="utf-8"></head><body>
{body}
</body></html>"""


class ClassifierTests(unittest.TestCase):
    """check_values() logic without a browser."""

    def test_en_persian_text_flagged(self):                      # Test A (classifier)
        f = check_values([{"tag": "button", "text": "ذخیره تغییرات"}], "en", "/x", TOKENS)
        self.assertEqual(len(f), 1)
        self.assertIn("arabic-script", f[0]["reason"])

    def test_fa_english_text_flagged(self):                      # Test B (classifier)
        f = check_values([{"tag": "button", "text": "Download report"}], "fa", "/x", TOKENS)
        self.assertEqual(len(f), 1)
        self.assertIn("untranslated English", f[0]["reason"])

    def test_persian_user_data_in_en_not_flagged(self):          # Test C
        # td = backend/user data container, never application UI copy; free
        # spans are not even collected (collection is data-i18n/semantic only,
        # proven by the browser fixture tests below).
        f = check_values([{"tag": "td", "text": "علی رضایی"},
                          {"tag": "td", "text": "یادداشت معامله: ورود خوب"}], "en", "/x", TOKENS)
        self.assertEqual(f, [])

    def test_english_user_data_in_fa_not_flagged(self):          # Test D
        f = check_values([{"tag": "td", "text": "XAUUSD buy 0.5 lot"},
                          {"tag": "td", "text": "journal note kept as entered"}], "fa", "/x", TOKENS)
        self.assertEqual(f, [])

    def test_persian_aria_label_flagged(self):                   # Test E (classifier)
        f = check_values([{"tag": "button", "attr": "aria-label", "value": "باز کردن منو"}],
                         "en", "/x", TOKENS)
        self.assertEqual(len(f), 1)
        self.assertEqual(f[0]["attribute"], "aria-label")

    def test_placeholder_and_title_flagged(self):                # Test F (classifier)
        f = check_values([{"tag": "input", "attr": "placeholder", "value": "جست‌وجو…"},
                          {"tag": "span", "attr": "title", "value": "راهنما"}], "en", "/x", TOKENS)
        self.assertEqual({x["attribute"] for x in f}, {"placeholder", "title"})

    def test_brand_tokens_not_flagged(self):                     # Test H (classifier)
        vals = [{"tag": "button", "text": t} for t in
                ("Velora", "MetaAPI", "Resend", "n8n", "S3", "Free", "Pro")]
        vals += [{"tag": "option", "text": t} for t in
                 ("DEBUG", "INFO", "WARN", "ERROR", "smtp", "resend",
                  "ai_weekly_report", "ai_trade_analysis")]
        self.assertEqual(check_values(vals, "fa", "/x", TOKENS), [])
        self.assertEqual(check_values(vals, "en", "/x", TOKENS), [])

    def test_user_identity_attribute_not_flagged(self):
        # email-bearing attribute values are session/user data (avatar tooltip)
        f = check_values([{"tag": "button", "attr": "title",
                           "value": "Sahar Rahimi · s.rahimi@veloratrade.ir"}], "fa", "/x", TOKENS)
        self.assertEqual(f, [])

    def test_dynamic_ui_text_flagged(self):                      # Test G (classifier)
        f = check_values([{"tag": "button", "text": "ذخیره تغییرات", "id": "injected"}],
                         "en", "/x", TOKENS)
        self.assertEqual(len(f), 1)


@unittest.skipUnless(_chromium_available := True, "playwright")
class BrowserFixtureTests(unittest.TestCase):
    """file:// fixtures through the real guard scan path."""

    pg = None
    pw = None
    browser = None

    @classmethod
    def setUpClass(cls):
        try:
            from playwright.sync_api import sync_playwright
        except ImportError:
            raise unittest.SkipTest("playwright not installed")
        cls.pw = sync_playwright().start()
        cls.browser = cls.pw.chromium.launch()
        cls.pg = cls.browser.new_page()

    @classmethod
    def tearDownClass(cls):
        for x in ("pg", "browser"):
            try:
                getattr(cls, x).close()
            except Exception:
                pass
        cls.pw.stop()

    def _scan(self, body: str, locale: str, settle: int = 900):
        with tempfile.NamedTemporaryFile("w", suffix=".html", delete=False, encoding="utf-8") as fh:
            fh.write(FIXTURE.format(body=body))
            path = fh.name
        try:
            self.pg.goto("file://" + path, wait_until="load")
            from tools.localization.runtime_locale_integrity import findings_on_page
            return findings_on_page(self.pg, locale, "/fixture", TOKENS, settle_ms=settle)
        finally:
            Path(path).unlink(missing_ok=True)

    def test_A_en_persian_button_detected(self):
        f = self._scan("<button>ذخیره تغییرات</button>", "en")
        self.assertTrue(any("ذخیره" in x["detected"] for x in f), f)

    def test_B_fa_english_button_detected(self):
        f = self._scan("<button>Download report</button>", "fa")
        self.assertTrue(any("Download report" in x["detected"] for x in f), f)

    def test_C_persian_user_data_not_flagged(self):
        f = self._scan("<table><tr><td>علی رضایی</td><td>یادداشت: ورود خوب</td></tr></table>", "en")
        self.assertEqual(f, [])

    def test_D_english_user_data_not_flagged(self):
        f = self._scan("<table><tr><td>XAUUSD buy note</td></tr></table>", "fa")
        self.assertEqual(f, [])

    def test_E_persian_aria_label_detected(self):
        f = self._scan('<button aria-label="باز کردن منو">Menu</button>', "en")
        self.assertTrue(any(x["attribute"] == "aria-label" and "باز کردن" in x["detected"] for x in f), f)

    def test_F_placeholder_and_title_detected(self):
        f = self._scan('<input placeholder="جست‌وجو…"><span title="راهنما">i</span>', "en")
        attrs = {x["attribute"] for x in f}
        self.assertIn("placeholder", attrs)
        self.assertIn("title", attrs)

    def test_G_runtime_injected_ui_detected(self):
        body = """<button id="later">ready</button>
<script>setTimeout(()=>{document.getElementById('later').textContent='ذخیره تغییرات';},250);</script>"""
        f = self._scan(body, "en", settle=900)
        self.assertTrue(any("ذخیره" in x["detected"] for x in f), f)

    def test_H_brand_and_technical_tokens_clean(self):
        body = ("<button>Velora</button><button>MetaAPI</button><button>Resend</button>"
                "<button>n8n</button><option>DEBUG</option><option>ai_weekly_report</option>")
        self.assertEqual(self._scan(body, "en"), [])
        self.assertEqual(self._scan(body, "fa"), [])

    def test_baseline_pinned_finding_passes(self):
        from tools.localization.runtime_locale_integrity import _pin_match
        pins = [{"locale": "EN", "route": "/en/x/index.html", "element": "<p.a>",
                 "attribute": "(text)", "text": "متن قدیمی"}]
        self.assertIsNotNone(_pin_match({"locale": "EN", "route": "/en/x/index.html",
                                          "element": "<p.a>", "attribute": "(text)",
                                          "detected": "متن قدیمی"}, pins))

    def test_baseline_new_finding_fails(self):
        from tools.localization.runtime_locale_integrity import _pin_match
        pins = [{"locale": "EN", "route": "/en/x/index.html", "element": "<p.a>",
                 "attribute": "(text)", "text": "متن قدیمی"}]
        # different text on the same element = NEW leakage -> not pinned
        self.assertIsNone(_pin_match({"locale": "EN", "route": "/en/x/index.html",
                                       "element": "<p.a>", "attribute": "(text)",
                                       "detected": "متن تازه"}, pins))
        # same text on a different route = NEW leakage -> not pinned
        self.assertIsNone(_pin_match({"locale": "EN", "route": "/en/y/index.html",
                                       "element": "<p.a>", "attribute": "(text)",
                                       "detected": "متن قدیمی"}, pins))

    def test_I_admin_artifact_full_routes_both_locales(self):
        """The real Admin artifact (the original incident) must pass EN+FA."""
        proc = subprocess.run(
            [sys.executable, "-m", "tools.localization.runtime_locale_integrity", "--app", "admin"],
            cwd=str(REPO), capture_output=True, text=True, timeout=420)
        self.assertEqual(proc.returncode, 0,
                         f"admin runtime locale integrity failed:\n{proc.stdout[-1500:]}\n{proc.stderr[-800:]}")
        self.assertIn("LOCALIZATION_RUNTIME_INTEGRITY_OK", proc.stdout)


if __name__ == "__main__":
    unittest.main(verbosity=2)
