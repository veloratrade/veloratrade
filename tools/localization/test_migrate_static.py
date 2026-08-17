#!/usr/bin/env python3
"""Safety and scope tests for canonical-only static localization migration."""
from __future__ import annotations

import contextlib
import hashlib
import io
import json
import subprocess
import tempfile
import unittest
from pathlib import Path

from tools.localization.migrate_static import (
    MigrationSafetyError,
    _parse_args,
    _validate_targets,
    canonical_template_files,
    main,
)
from tools.localization.route_contract import load_route_contract


class MigrateStaticCanonicalScopeTestCase(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.repository_root = Path(__file__).resolve().parents[2]

    def repository_contract(self):
        return load_route_contract(self.repository_root)

    @staticmethod
    def file_hash(path: Path) -> str:
        return hashlib.sha256(path.read_bytes()).hexdigest()

    def protected_hashes(self) -> dict[str, str]:
        contract = self.repository_contract()
        paths = list(contract.canonical_paths) + [
            self.repository_root / "public/locales/fa.json",
            self.repository_root / "public/locales/en.json",
        ]
        return {
            path.relative_to(self.repository_root).as_posix(): self.file_hash(path)
            for path in paths
        }

    def run_dry(self) -> tuple[int, str, str]:
        stdout = io.StringIO()
        stderr = io.StringIO()
        with contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
            exit_code = main(["--root", str(self.repository_root)])
        return exit_code, stdout.getvalue(), stderr.getvalue()

    def test_scope_is_exactly_29_canonical_templates(self) -> None:
        contract = self.repository_contract()
        targets = canonical_template_files(contract)

        self.assertEqual(len(contract.canonical_templates), 29)
        self.assertEqual(len(targets), 29)
        self.assertEqual(
            {path.relative_to(self.repository_root).as_posix() for path in targets},
            set(contract.canonical_templates),
        )

    def test_scope_excludes_legacy_english_tree(self) -> None:
        targets = canonical_template_files(self.repository_contract())

        self.assertFalse(
            any(path.relative_to(self.repository_root).parts[0] == "en" for path in targets)
        )

    def test_scope_excludes_localized_outputs(self) -> None:
        targets = canonical_template_files(self.repository_contract())

        self.assertFalse(
            any(
                path.relative_to(self.repository_root).parts[0] == "localized"
                for path in targets
            )
        )

    def test_target_outside_contract_fails(self) -> None:
        contract = self.repository_contract()
        outside = self.repository_root / "test-localization.html"

        with self.assertRaisesRegex(MigrationSafetyError, "outside canonical route contract"):
            _validate_targets([*contract.canonical_paths, outside], contract)

    def test_default_mode_is_dry_run_and_apply_requires_explicit_flag(self) -> None:
        self.assertFalse(_parse_args([]).apply)
        self.assertTrue(_parse_args(["--apply"]).apply)

        exit_code, stdout, stderr = self.run_dry()
        self.assertEqual(exit_code, 0)
        self.assertEqual(stderr, "")
        self.assertIn('"mode": "dry-run"', stdout)
        self.assertIn('"readOnly": true', stdout)
        self.assertNotIn('"mode": "apply"', stdout)

    def test_default_dry_run_reports_scope_and_changes_nothing(self) -> None:
        before = self.protected_hashes()

        exit_code, stdout, stderr = self.run_dry()

        after = self.protected_hashes()
        self.assertEqual(exit_code, 0)
        self.assertEqual(stderr, "")
        self.assertIn("MIGRATION_SCOPE_OK canonical_templates=29", stdout)
        self.assertIn('"canonicalTemplates": 29', stdout)
        self.assertIn('"outsideContract": 0', stdout)
        self.assertIn('"collisions": 0', stdout)
        self.assertIn('"enTargets": 0', stdout)
        self.assertIn('"localizedTargets": 0', stdout)
        self.assertEqual(after, before)

    def test_dry_run_output_is_deterministic(self) -> None:
        before = self.protected_hashes()

        first = self.run_dry()
        middle = self.protected_hashes()
        second = self.run_dry()
        after = self.protected_hashes()

        self.assertEqual(first, second)
        self.assertEqual(middle, before)
        self.assertEqual(after, before)

    def test_apply_is_idempotent_in_isolated_clean_repository(self) -> None:
        with tempfile.TemporaryDirectory() as temporary_directory:
            root = Path(temporary_directory)
            (root / "tools/localization").mkdir(parents=True)
            (root / "public/locales").mkdir(parents=True)
            (root / "index.html").write_text(
                "<!doctype html><html><head></head><body>داشبورد</body></html>\n",
                encoding="utf-8",
            )
            (root / "tools/localization/routes.json").write_text(
                json.dumps(
                    {
                        "version": "test",
                        "routes": [
                            {"template": "index.html", "outputs": ["index.html"]}
                        ],
                    }
                ),
                encoding="utf-8",
            )
            (root / "public/locales/manifest.json").write_text(
                json.dumps(
                    {
                        "version": "test",
                        "defaultLocale": "fa",
                        "fallbackLocale": "en",
                        "locales": {
                            "fa": {"enabled": True},
                            "en": {"enabled": True},
                        },
                    }
                ),
                encoding="utf-8",
            )
            for locale in ("fa", "en"):
                (root / f"public/locales/{locale}.json").write_text(
                    json.dumps(
                        {
                            "_meta": {"locale": locale, "version": "test"},
                            "messages": {},
                        }
                    ),
                    encoding="utf-8",
                )
            subprocess.run(["git", "init", "-q", str(root)], check=True)
            subprocess.run(
                ["git", "-C", str(root), "config", "user.email", "test@velora.invalid"],
                check=True,
            )
            subprocess.run(
                ["git", "-C", str(root), "config", "user.name", "Velora Test"],
                check=True,
            )
            subprocess.run(["git", "-C", str(root), "add", "."], check=True)
            subprocess.run(
                ["git", "-C", str(root), "commit", "-qm", "fixture"],
                check=True,
            )

            first_stdout = io.StringIO()
            with contextlib.redirect_stdout(first_stdout):
                first_exit = main(["--root", str(root), "--apply"])
            first_hashes = {
                relative: self.file_hash(root / relative)
                for relative in (
                    "index.html",
                    "public/locales/fa.json",
                    "public/locales/en.json",
                )
            }
            subprocess.run(["git", "-C", str(root), "add", "."], check=True)
            subprocess.run(
                ["git", "-C", str(root), "commit", "-qm", "first migration"],
                check=True,
            )

            second_stdout = io.StringIO()
            with contextlib.redirect_stdout(second_stdout):
                second_exit = main(["--root", str(root), "--apply"])
            second_hashes = {
                relative: self.file_hash(root / relative)
                for relative in first_hashes
            }

            self.assertEqual(first_exit, 0)
            self.assertEqual(second_exit, 0)
            self.assertIn("MIGRATION_SCOPE_OK canonical_templates=1", first_stdout.getvalue())
            self.assertIn("MIGRATION_SCOPE_OK canonical_templates=1", second_stdout.getvalue())
            self.assertEqual(second_hashes, first_hashes)

    def test_apply_is_blocked_by_dirty_working_tree(self) -> None:
        with tempfile.TemporaryDirectory() as temporary_directory:
            root = Path(temporary_directory)
            (root / "tools/localization").mkdir(parents=True)
            (root / "public/locales").mkdir(parents=True)
            (root / "index.html").write_text(
                "<!doctype html><html><body></body></html>\n",
                encoding="utf-8",
            )
            (root / "tools/localization/routes.json").write_text(
                json.dumps(
                    {
                        "version": "test",
                        "routes": [
                            {"template": "index.html", "outputs": ["index.html"]}
                        ],
                    }
                ),
                encoding="utf-8",
            )
            (root / "public/locales/manifest.json").write_text(
                json.dumps(
                    {
                        "version": "test",
                        "defaultLocale": "fa",
                        "fallbackLocale": "en",
                        "locales": {
                            "fa": {"enabled": True},
                            "en": {"enabled": True},
                        },
                    }
                ),
                encoding="utf-8",
            )
            subprocess.run(
                ["git", "init", "-q", str(root)],
                check=True,
                capture_output=True,
                text=True,
            )
            before = {
                path.relative_to(root).as_posix(): self.file_hash(path)
                for path in root.rglob("*")
                if path.is_file() and ".git" not in path.parts
            }
            stdout = io.StringIO()
            stderr = io.StringIO()

            with contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
                exit_code = main(["--root", str(root), "--apply"])

            after = {
                path.relative_to(root).as_posix(): self.file_hash(path)
                for path in root.rglob("*")
                if path.is_file() and ".git" not in path.parts
            }
            self.assertEqual(exit_code, 2)
            self.assertIn("MIGRATION_SCOPE_OK canonical_templates=1", stdout.getvalue())
            self.assertIn("working tree is dirty", stderr.getvalue())
            self.assertEqual(after, before)


if __name__ == "__main__":
    unittest.main()
