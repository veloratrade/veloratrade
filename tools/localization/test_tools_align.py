#!/usr/bin/env python3
"""Tests for the read-only catalog alignment reporter shadow mode."""
from __future__ import annotations

import contextlib
import io
import json
import tempfile
import unittest
from pathlib import Path

from tools.localization.route_contract import load_route_contract
from tools_align import build_alignment_report, main


class ToolsAlignShadowModeTestCase(unittest.TestCase):
    def setUp(self) -> None:
        self._temporary_directory = tempfile.TemporaryDirectory()
        self.root = Path(self._temporary_directory.name)

    def tearDown(self) -> None:
        self._temporary_directory.cleanup()

    def write_json(self, relative: str, payload: object) -> None:
        path = self.root / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(
            json.dumps(payload, ensure_ascii=False, indent=2) + "\n",
            encoding="utf-8",
        )

    def write_template(self, relative: str, body: str) -> None:
        path = self.root / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(
            "<!doctype html><html><body>" + body + "</body></html>\n",
            encoding="utf-8",
        )

    def prepare_contract(
        self,
        *,
        fa_messages: dict[str, str] | None = None,
        en_messages: dict[str, str] | None = None,
    ) -> None:
        fa_messages = fa_messages or {
            "home.title": "سلام {name}",
            "dashboard.save": "ذخیره",
        }
        en_messages = en_messages or {
            "home.title": "Hello {name}",
            "dashboard.save": "Save",
        }
        self.write_json(
            "public/locales/manifest.json",
            {
                "version": "test",
                "defaultLocale": "fa",
                "fallbackLocale": "en",
                "locales": {
                    "fa": {"enabled": True, "direction": "rtl"},
                    "en": {"enabled": True, "direction": "ltr"},
                },
            },
        )
        self.write_json(
            "public/locales/fa.json",
            {"_meta": {"locale": "fa", "version": "test"}, "messages": fa_messages},
        )
        self.write_json(
            "public/locales/en.json",
            {"_meta": {"locale": "en", "version": "test"}, "messages": en_messages},
        )
        self.write_template(
            "index.html",
            '<h1 data-i18n="home.title"></h1>',
        )
        self.write_template(
            "dashboard/index.html",
            '<button data-i18n="dashboard.save"></button>',
        )
        self.write_json(
            "tools/localization/routes.json",
            {
                "version": "test",
                "routes": [
                    {"template": "index.html", "outputs": ["index.html"]},
                    {
                        "template": "dashboard/index.html",
                        "outputs": ["dashboard/index.html"],
                    },
                ],
            },
        )

    def test_no_legacy_english_page_source_references(self) -> None:
        source = (Path(__file__).resolve().parents[2] / "tools_align.py").read_text(
            encoding="utf-8"
        )

        self.assertNotIn("PAIRS", source)
        self.assertNotIn("SequenceMatcher", source)
        self.assertNotIn("en/index.html", source)
        self.assertNotIn("en/blog/", source)
        self.assertNotIn("/tmp/aligned.json", source)

    def test_canonical_route_coverage(self) -> None:
        self.prepare_contract()
        contract = load_route_contract(self.root)

        report = build_alignment_report(self.root)

        self.assertEqual(report["status"], "ok")
        self.assertTrue(report["readOnly"])
        self.assertEqual(report["summary"]["routes"], 2)
        self.assertEqual(report["summary"]["canonicalTemplates"], 2)
        self.assertEqual(report["summary"]["coveredTemplates"], 2)
        self.assertEqual(report["summary"]["records"], 2)
        self.assertEqual(report["summary"]["issues"], 0)
        self.assertEqual(
            {record["template"] for record in report["records"]},
            set(contract.canonical_templates),
        )
        home = next(record for record in report["records"] if record["key"] == "home.title")
        self.assertEqual(home["route"], "/")
        self.assertEqual(home["fa"], "سلام {name}")
        self.assertEqual(home["en"], "Hello {name}")
        self.assertEqual(home["placeholderStatus"], "match")
        self.assertEqual(home["references"][0]["kind"], "data-i18n")

    def test_missing_key_detection(self) -> None:
        self.prepare_contract(en_messages={"dashboard.save": "Save"})

        report = build_alignment_report(self.root)

        self.assertEqual(report["status"], "issues")
        self.assertEqual(report["summary"]["missingKeys"], 1)
        issue = next(issue for issue in report["issues"] if issue["type"] == "missing-key")
        self.assertEqual(issue["key"], "home.title")
        self.assertEqual(issue["missingLocales"], ["en"])
        record = next(record for record in report["records"] if record["key"] == "home.title")
        self.assertIsNone(record["en"])
        self.assertEqual(record["placeholderStatus"], "missing")

    def test_placeholder_mismatch(self) -> None:
        self.prepare_contract(
            fa_messages={
                "home.title": "سلام {name}",
                "dashboard.save": "ذخیره",
            },
            en_messages={
                "home.title": "Hello {user}",
                "dashboard.save": "Save",
            },
        )

        report = build_alignment_report(self.root)

        self.assertEqual(report["status"], "issues")
        self.assertEqual(report["summary"]["placeholderMismatches"], 1)
        issue = next(
            issue for issue in report["issues"]
            if issue["type"] == "placeholder-mismatch"
        )
        self.assertEqual(issue["key"], "home.title")
        self.assertEqual(issue["faPlaceholders"], {"name": 1})
        self.assertEqual(issue["enPlaceholders"], {"user": 1})

    def test_default_output_is_stdout_json_and_creates_no_report_file(self) -> None:
        self.prepare_contract()
        files_before = {
            path.relative_to(self.root).as_posix()
            for path in self.root.rglob("*")
            if path.is_file()
        }
        output = io.StringIO()

        with contextlib.redirect_stdout(output):
            exit_code = main(["--root", str(self.root), "--compact"])

        files_after = {
            path.relative_to(self.root).as_posix()
            for path in self.root.rglob("*")
            if path.is_file()
        }
        payload = json.loads(output.getvalue())
        self.assertEqual(exit_code, 0)
        self.assertEqual(payload["mode"], "catalog-alignment-shadow")
        self.assertIn("records", payload)
        self.assertEqual(files_after, files_before)


if __name__ == "__main__":
    unittest.main()
