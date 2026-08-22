#!/usr/bin/env python3
"""Unit tests for the orphan catalog-key report (PR-09, report-only)."""
from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path

from tools.localization.report_orphan_catalog_keys import find_orphan_keys, main


class OrphanCatalogKeysTestCase(unittest.TestCase):
    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.root = Path(self._tmp.name)
        (self.root / "public" / "locales" / "chunks" / "fa").mkdir(parents=True)
        (self.root / "public" / "locales" / "chunks" / "en").mkdir(parents=True)
        (self.root / "localized").mkdir(parents=True)
        (self.root / "public" / "assets").mkdir(parents=True)

    def tearDown(self) -> None:
        self._tmp.cleanup()

    def write_catalog(self, locale: str, messages: dict[str, str]) -> None:
        path = self.root / "public" / "locales" / f"{locale}.json"
        path.write_text(json.dumps({"messages": messages}), encoding="utf-8")

    def write_html(self, relative: str, content: str) -> None:
        path = self.root / "localized" / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content, encoding="utf-8")

    def write_asset(self, name: str, content: str) -> None:
        (self.root / "public" / "assets" / name).write_text(content, encoding="utf-8")

    def test_referenced_key_via_data_i18n_attribute_is_not_orphan(self) -> None:
        self.write_catalog("en", {"common.cancel": "Cancel"})
        self.write_catalog("fa", {"common.cancel": "\u0627\u0646\u0635\u0631\u0627\u0641"})
        self.write_html("en/index.html", '<button data-i18n="common.cancel">Cancel</button>')
        self.write_html("fa/index.html", '<button data-i18n="common.cancel">\u0627\u0646\u0635\u0631\u0627\u0641</button>')

        orphans = find_orphan_keys(self.root)

        self.assertNotIn("common.cancel", orphans["en"])
        self.assertNotIn("common.cancel", orphans["fa"])

    def test_referenced_key_via_t_call_is_not_orphan(self) -> None:
        self.write_catalog("en", {"auth.loginFailed": "Login failed"})
        self.write_catalog("fa", {"auth.loginFailed": "\u062e\u0637\u0627"})
        self.write_asset("app.js", "showError(t('auth.loginFailed'));")

        orphans = find_orphan_keys(self.root)

        self.assertNotIn("auth.loginFailed", orphans["en"])
        self.assertNotIn("auth.loginFailed", orphans["fa"])

    def test_referenced_key_via_php_ternary_assignment_is_not_orphan(self) -> None:
        # Mirrors the real repository pattern found in TradeService.php /
        # dashboard admin script: a dotted key assigned as a plain string
        # literal, not passed straight into t()/tr().
        self.write_catalog("en", {"admin.role.admin": "Admin", "admin.role.user": "User"})
        self.write_catalog("fa", {"admin.role.admin": "\u0645\u062f\u06cc\u0631", "admin.role.user": "\u06a9\u0627\u0631\u0628\u0631"})
        (self.root / "api" / "src").mkdir(parents=True)
        (self.root / "api" / "src" / "Users.php").write_text(
            "$roleKey = $isAdmin ? 'admin.role.admin' : 'admin.role.user';",
            encoding="utf-8",
        )

        orphans = find_orphan_keys(self.root)

        self.assertNotIn("admin.role.admin", orphans["en"])
        self.assertNotIn("admin.role.user", orphans["en"])

    def test_unreferenced_key_is_reported_as_orphan(self) -> None:
        self.write_catalog("en", {"common.trulyUnused": "Unused"})
        self.write_catalog("fa", {"common.trulyUnused": "\u0628\u062f\u0648\u0646 \u0627\u0633\u062a\u0641\u0627\u062f\u0647"})

        orphans = find_orphan_keys(self.root)

        self.assertIn("common.trulyUnused", orphans["en"])
        self.assertIn("common.trulyUnused", orphans["fa"])

    def test_config_get_call_is_not_mistaken_for_catalog_key(self) -> None:
        # Matches validate_localization.py's own exclusion: PHP
        # Config::get('foo.bar') calls must never be treated as catalog
        # key references, even if 'foo' happens to be a known prefix.
        self.write_catalog("en", {"common.someSetting": "Value"})
        self.write_catalog("fa", {"common.someSetting": "\u0645\u0642\u062f\u0627\u0631"})
        (self.root / "api" / "src").mkdir(parents=True)
        (self.root / "api" / "src" / "Config.php").write_text(
            "$x = Config::get('common.someSetting');",
            encoding="utf-8",
        )

        orphans = find_orphan_keys(self.root)

        # Excluded from the prefix-filtered scan (Config::get line), so it
        # remains correctly reported as orphan from a *catalog-usage*
        # perspective, exactly mirroring validate_localization.py.
        self.assertIn("common.someSetting", orphans["en"])

    def test_key_used_in_only_one_locale_is_not_orphan_in_either(self) -> None:
        self.write_catalog("en", {"common.enOnlyCopy": "English variant"})
        self.write_catalog("fa", {"common.enOnlyCopy": "\u0641\u0627\u0631\u0633\u06cc"})
        self.write_html("en/index.html", '<span data-i18n="common.enOnlyCopy">x</span>')

        orphans = find_orphan_keys(self.root)

        self.assertNotIn("common.enOnlyCopy", orphans["en"])
        # fa catalog also defines the key; since it's referenced at all
        # (in the en page), it's not flagged as orphan for fa either —
        # orphan detection is about "never referenced anywhere", not
        # per-locale usage parity (that's validate_localization.py's job).
        self.assertNotIn("common.enOnlyCopy", orphans["fa"])

    def test_main_report_only_exits_zero_even_with_orphans(self) -> None:
        self.write_catalog("en", {"common.trulyUnused": "Unused"})
        self.write_catalog("fa", {"common.trulyUnused": "x"})

        exit_code = main(["--root", str(self.root)])

        self.assertEqual(exit_code, 0)

    def test_main_fail_flag_exits_nonzero_with_orphans(self) -> None:
        self.write_catalog("en", {"common.trulyUnused": "Unused"})
        self.write_catalog("fa", {"common.trulyUnused": "x"})

        exit_code = main(["--root", str(self.root), "--fail"])

        self.assertEqual(exit_code, 1)

    def test_main_fail_flag_exits_zero_with_no_orphans(self) -> None:
        self.write_catalog("en", {"common.cancel": "Cancel"})
        self.write_catalog("fa", {"common.cancel": "x"})
        self.write_html("en/index.html", '<button data-i18n="common.cancel">Cancel</button>')
        self.write_html("fa/index.html", '<button data-i18n="common.cancel">x</button>')

        exit_code = main(["--root", str(self.root), "--fail"])

        self.assertEqual(exit_code, 0)

    def test_chunk_keys_are_included_in_scan(self) -> None:
        chunk = self.root / "public" / "locales" / "chunks" / "en" / "dashboard.json"
        chunk.write_text(
            json.dumps({"messages": {"dashboard.chunkOnlyKey": "Value"}}),
            encoding="utf-8",
        )
        fa_chunk = self.root / "public" / "locales" / "chunks" / "fa" / "dashboard.json"
        fa_chunk.write_text(
            json.dumps({"messages": {"dashboard.chunkOnlyKey": "\u0645\u0642\u062f\u0627\u0631"}}),
            encoding="utf-8",
        )
        self.write_catalog("en", {})
        self.write_catalog("fa", {})

        orphans = find_orphan_keys(self.root)

        self.assertIn("dashboard.chunkOnlyKey", orphans["en"])
        self.assertIn("dashboard.chunkOnlyKey", orphans["fa"])

    # -- real repository smoke test (does not assert a specific count,
    # only that the scan runs cleanly end-to-end against real data) -----
    def test_real_repository_scan_runs_without_error(self) -> None:
        repo_root = Path(__file__).resolve().parents[2]

        orphans = find_orphan_keys(repo_root)

        self.assertIn("en", orphans)
        self.assertIn("fa", orphans)
        self.assertIsInstance(orphans["en"], list)
        self.assertIsInstance(orphans["fa"], list)


if __name__ == "__main__":
    unittest.main()
