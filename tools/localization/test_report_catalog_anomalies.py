#!/usr/bin/env python3
from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path

from tools.localization.report_catalog_anomalies import main


class CatalogAnomalyReportTestCase(unittest.TestCase):
    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.root = Path(self._tmp.name)
        (self.root / "public" / "locales").mkdir(parents=True)

    def tearDown(self) -> None:
        self._tmp.cleanup()

    def write_catalog(self, locale: str, messages: dict[str, str]) -> None:
        path = self.root / "public" / "locales" / f"{locale}.json"
        path.write_text(json.dumps({"messages": messages}), encoding="utf-8")

    def write_allowlist(self, payload: dict) -> Path:
        path = self.root / "allowlist.json"
        path.write_text(json.dumps(payload), encoding="utf-8")
        return path

    def test_allowlisted_empty_translation_passes_blocking_group(self) -> None:
        self.write_catalog("en", {"pages.title.suffix": ""})
        self.write_catalog("fa", {"pages.title.suffix": "پسوند"})
        allowlist = self.write_allowlist(
            {"catalogAnomalies": {"en.empty": ["pages.title.suffix"]}}
        )

        exit_code = main(
            [
                "--root",
                str(self.root),
                "--allowlist",
                str(allowlist),
                "--fail",
                "--fail-group",
                "en.empty",
            ]
        )

        self.assertEqual(exit_code, 0)

    def test_unallowlisted_empty_translation_fails_blocking_group(self) -> None:
        self.write_catalog("en", {"pages.title.suffix": ""})
        self.write_catalog("fa", {"pages.title.suffix": "پسوند"})

        exit_code = main(
            [
                "--root",
                str(self.root),
                "--fail",
                "--fail-group",
                "en.empty",
            ]
        )

        self.assertEqual(exit_code, 1)

    def test_allowlisted_identical_value_passes_blocking_group(self) -> None:
        self.write_catalog("en", {"common.metaapi": "MetaApi"})
        self.write_catalog("fa", {"common.metaapi": "MetaApi"})
        allowlist = self.write_allowlist(
            {"catalogAnomalies": {"fa.en.identical": ["common.metaapi"]}}
        )

        exit_code = main(
            [
                "--root",
                str(self.root),
                "--allowlist",
                str(allowlist),
                "--fail",
                "--fail-group",
                "fa.en.identical",
            ]
        )

        self.assertEqual(exit_code, 0)

    def test_stale_allowlist_fails(self) -> None:
        self.write_catalog("en", {"common.metaapi": "MetaApi"})
        self.write_catalog("fa", {"common.metaapi": "متاآپی"})
        allowlist = self.write_allowlist(
            {"catalogAnomalies": {"fa.en.identical": ["common.metaapi"]}}
        )

        exit_code = main(
            [
                "--root",
                str(self.root),
                "--allowlist",
                str(allowlist),
                "--fail",
                "--fail-group",
                "fa.en.identical",
            ]
        )

        self.assertEqual(exit_code, 1)


if __name__ == "__main__":
    unittest.main()
