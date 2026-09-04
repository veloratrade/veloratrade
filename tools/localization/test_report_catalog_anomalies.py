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

    # ── Targeted false-positive fix (§7–§10): masked-secret placeholder + protected
    #    technical terms must NOT block, while genuine English leakage MUST remain
    #    detectable. These tests enforce the accuracy improvements without weakening
    #    the fa.en.identical check. ──────────────────────────────────────────────

    def test_masked_secret_placeholder_does_not_block(self) -> None:
        # •••••••• is a redaction sentinel (no translatable text) and must not be
        # reported as an untranslated-English fa.en.identical value.
        mask = "\u2022" * 8
        self.write_catalog("en", {"admin.relay.maskedToken": mask})
        self.write_catalog("fa", {"admin.relay.maskedToken": mask})
        exit_code = main(
            ["--root", str(self.root), "--fail", "--fail-group", "fa.en.identical"]
        )
        self.assertEqual(exit_code, 0)

    def test_protected_technical_term_still_allowlisted(self) -> None:
        # Approved technical term remains in the FA catalog untranslated; it must be
        # allowed only via the exact-key allowlist, matching existing convention.
        self.write_catalog("en", {"admin.system.redis": "Redis"})
        self.write_catalog("fa", {"admin.system.redis": "Redis"})
        allowlist = self.write_allowlist(
            {"catalogAnomalies": {"fa.en.identical": ["admin.system.redis"]}}
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

    def test_protected_technical_term_without_allowlist_still_blocks(self) -> None:
        # Same technical term is only tolerated because it is allowlisted. Without
        # the allowlist it must STILL block — proving we never broadly exempt Latin.
        self.write_catalog("en", {"admin.system.redis": "Redis"})
        self.write_catalog("fa", {"admin.system.redis": "Redis"})
        exit_code = main(
            ["--root", str(self.root), "--fail", "--fail-group", "fa.en.identical"]
        )
        self.assertEqual(exit_code, 1)

    def test_genuine_english_leakage_save_still_blocks(self) -> None:
        # FA catalog accidentally contains untranslated English "Save" (should be
        # Persian). This is genuine English leakage and MUST remain blocking.
        self.write_catalog("en", {"common.save": "Save"})
        self.write_catalog("fa", {"common.save": "Save"})
        exit_code = main(
            ["--root", str(self.root), "--fail", "--fail-group", "fa.en.identical"]
        )
        self.assertEqual(exit_code, 1)

    def test_genuine_english_leakage_dashboard_still_blocks(self) -> None:
        # Untranslated "Dashboard" is genuine English leakage and MUST remain blocking.
        self.write_catalog("en", {"nav.dashboard": "Dashboard"})
        self.write_catalog("fa", {"nav.dashboard": "Dashboard"})
        exit_code = main(
            ["--root", str(self.root), "--fail", "--fail-group", "fa.en.identical"]
        )
        self.assertEqual(exit_code, 1)

    def test_genuine_english_leakage_settings_still_blocks(self) -> None:
        # Untranslated "Settings" is genuine English leakage and MUST remain blocking.
        self.write_catalog("en", {"nav.settings": "Settings"})
        self.write_catalog("fa", {"nav.settings": "Settings"})
        exit_code = main(
            ["--root", str(self.root), "--fail", "--fail-group", "fa.en.identical"]
        )
        self.assertEqual(exit_code, 1)

    def test_translated_persian_not_identical(self) -> None:
        # FA="ذخیره" / EN="Save" are correctly different; this must NOT be flagged as
        # fa.en.identical. Proves the fix does not over-fire.
        self.write_catalog("en", {"common.save": "Save"})
        self.write_catalog("fa", {"common.save": "ذخیره"})
        exit_code = main(
            ["--root", str(self.root), "--fail", "--fail-group", "fa.en.identical"]
        )
        self.assertEqual(exit_code, 0)

    def test_arbitrary_repeated_punctuation_not_exempted(self) -> None:
        # A string that is NOT the exact masked-secret representation (e.g. dashes)
        # must still be treated as a value and flagged when FA==EN. The placeholder
        # rule must not exempt arbitrary punctuation.
        self.write_catalog("en", {"dash.separator": "----------"})
        self.write_catalog("fa", {"dash.separator": "----------"})
        exit_code = main(
            ["--root", str(self.root), "--fail", "--fail-group", "fa.en.identical"]
        )
        self.assertEqual(exit_code, 1)


if __name__ == "__main__":
    unittest.main()
