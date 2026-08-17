#!/usr/bin/env python3
"""Contract tests for the canonical Dashboard logout ownership."""
from __future__ import annotations

import re
import unittest
from pathlib import Path

from bs4 import BeautifulSoup


class DashboardLogoutContractTestCase(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.root = Path(__file__).resolve().parents[2]
        cls.dashboard_path = cls.root / "dashboard/index.html"
        cls.dialog_path = cls.root / "public/assets/velora-dialog.js"
        cls.dashboard = cls.dashboard_path.read_text(encoding="utf-8")
        cls.dialog = cls.dialog_path.read_text(encoding="utf-8")
        cls.soup = BeautifulSoup(cls.dashboard, "html.parser")

    def test_logout_button_is_the_only_official_trigger(self) -> None:
        self.assertEqual(len(self.soup.select("#logoutBtn")), 1)
        self.assertEqual(len(self.soup.select("#userAv")), 1)
        self.assertEqual(len(self.soup.select("#mUserAv")), 0)
        self.assertNotRegex(
            self.dashboard,
            r"(?:userAv|mUserAv)[^\n]{0,120}addEventListener\s*\(\s*['\"]click",
        )

    def test_page_local_logout_overlay_and_handlers_are_absent(self) -> None:
        for identifier in ("logoutOverlay", "confirmLogout", "cancelLogout"):
            self.assertNotIn(identifier, self.dashboard)
        for function_name in ("showLogout", "hideLogout"):
            self.assertNotIn(function_name, self.dashboard)
        self.assertNotRegex(
            self.dashboard,
            r"logoutBtn[^\n]{0,120}addEventListener\s*\(\s*['\"]click",
        )

    def test_logout_overlay_css_is_absent(self) -> None:
        style_source = "\n".join(
            tag.get_text("\n") for tag in self.soup.find_all("style")
        )
        for pattern in (
            r"\.overlay(?:\s|\.|\{|,)",
            r"\.o-card(?:\s|\.|\{|,)",
            r"\.o-btns(?:\s|\.|\{|,)",
            r"\.o-btn(?:\s|\.|\{|,)",
            r"@keyframes\s+popIn\b",
        ):
            self.assertIsNone(re.search(pattern, style_source))

    def test_shared_dialog_exclusively_owns_confirmed_logout(self) -> None:
        dialog_scripts = [
            tag.get("src")
            for tag in self.soup.find_all("script", src=True)
            if "velora-dialog.js" in str(tag.get("src"))
        ]
        self.assertEqual(len(dialog_scripts), 1)
        self.assertIn("closest('#logoutBtn')", self.dialog)
        self.assertIn("stopImmediatePropagation()", self.dialog)
        self.assertIn(
            "window.VeloraData?window.VeloraData.logout():Promise.resolve()",
            self.dialog,
        )
        self.assertRegex(
            self.dialog,
            r"then\(function\(yes\)\{if\(yes\)\{.*?logout\(\).*?location\.replace\('/login'\)",
        )


if __name__ == "__main__":
    unittest.main()
