#!/usr/bin/env python3
"""Unit tests for the shared localization route contract loader."""
from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path

from tools.localization.route_contract import (
    RouteContractError,
    load_route_contract,
)


class RouteContractTestCase(unittest.TestCase):
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

    def write_template(self, relative: str) -> None:
        path = self.root / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text("<!doctype html><html><body></body></html>\n", encoding="utf-8")

    def write_manifest(self) -> None:
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

    def write_routes(self, routes: list[dict[str, object]]) -> None:
        self.write_json(
            "tools/localization/routes.json",
            {"version": "test", "routes": routes},
        )

    def prepare_valid_contract(self) -> None:
        self.write_manifest()
        self.write_template("index.html")
        self.write_template("blog/index.html")
        self.write_routes(
            [
                {"template": "index.html", "outputs": ["index.html"]},
                {
                    "template": "blog/index.html",
                    "outputs": ["blog/index.html"],
                    "localeOutputs": {
                        "en": ["blog/articles/index.html"],
                    },
                },
            ]
        )

    def test_valid_contract(self) -> None:
        self.prepare_valid_contract()

        contract = load_route_contract(self.root)

        self.assertEqual(contract.locales, ("fa", "en"))
        self.assertEqual(
            contract.canonical_templates,
            ("blog/index.html", "index.html"),
        )
        self.assertEqual(
            contract.expected_outputs["fa"],
            ("localized/fa/blog/index.html", "localized/fa/index.html"),
        )
        self.assertEqual(
            contract.expected_outputs["en"],
            (
                "localized/en/blog/articles/index.html",
                "localized/en/blog/index.html",
                "localized/en/index.html",
            ),
        )
        self.assertEqual(
            contract.output_to_template["localized/en/blog/articles/index.html"],
            "blog/index.html",
        )
        self.assertEqual(len(contract.routes), 2)
        self.assertEqual(len(contract.expected_output_paths), 5)
        self.assertTrue(all(path.is_absolute() for path in contract.canonical_paths))

    def test_missing_template(self) -> None:
        self.write_manifest()
        self.write_routes(
            [{"template": "missing/index.html", "outputs": ["missing/index.html"]}]
        )

        with self.assertRaisesRegex(RouteContractError, "missing canonical template"):
            load_route_contract(self.root)

    def test_duplicate_route_template(self) -> None:
        self.write_manifest()
        self.write_template("index.html")
        self.write_routes(
            [
                {"template": "index.html", "outputs": ["index.html"]},
                {"template": "index.html", "outputs": ["other/index.html"]},
            ]
        )

        with self.assertRaisesRegex(RouteContractError, "duplicate canonical template"):
            load_route_contract(self.root)

    def test_invalid_path_traversal(self) -> None:
        self.write_manifest()
        self.write_routes(
            [{"template": "../outside.html", "outputs": ["index.html"]}]
        )

        with self.assertRaisesRegex(RouteContractError, "path traversal"):
            load_route_contract(self.root)

    def test_output_collision(self) -> None:
        self.write_manifest()
        self.write_template("first/index.html")
        self.write_template("second/index.html")
        self.write_routes(
            [
                {
                    "template": "first/index.html",
                    "outputs": ["shared/index.html"],
                },
                {
                    "template": "second/index.html",
                    "outputs": ["shared/index.html"],
                },
            ]
        )

        with self.assertRaisesRegex(RouteContractError, "output collision"):
            load_route_contract(self.root)

    def test_invalid_locale(self) -> None:
        self.write_manifest()
        self.write_template("index.html")
        self.write_routes(
            [
                {
                    "template": "index.html",
                    "outputs": ["index.html"],
                    "localeOutputs": {"de": ["start/index.html"]},
                }
            ]
        )

        with self.assertRaisesRegex(RouteContractError, "unknown locale"):
            load_route_contract(self.root)

    def test_absolute_template_path_is_forbidden(self) -> None:
        self.write_manifest()
        self.write_routes(
            [{"template": "/index.html", "outputs": ["index.html"]}]
        )

        with self.assertRaisesRegex(RouteContractError, "absolute path"):
            load_route_contract(self.root)

    def test_template_inside_en_tree_is_forbidden(self) -> None:
        self.write_manifest()
        self.write_template("en/index.html")
        self.write_routes(
            [{"template": "en/index.html", "outputs": ["index.html"]}]
        )

        with self.assertRaisesRegex(RouteContractError, r"inside en/\*\*"):
            load_route_contract(self.root)

    def test_template_inside_localized_tree_is_forbidden(self) -> None:
        self.write_manifest()
        self.write_template("localized/en/index.html")
        self.write_routes(
            [
                {
                    "template": "localized/en/index.html",
                    "outputs": ["index.html"],
                }
            ]
        )

        with self.assertRaisesRegex(RouteContractError, r"inside localized/\*\*"):
            load_route_contract(self.root)

    def test_output_cannot_escape_localized_locale_root(self) -> None:
        self.write_manifest()
        self.write_template("index.html")
        self.write_routes(
            [{"template": "index.html", "outputs": ["../index.html"]}]
        )

        with self.assertRaisesRegex(RouteContractError, "path traversal"):
            load_route_contract(self.root)

    def test_repository_contract_loads_without_generated_output_dependency(self) -> None:
        repository_root = Path(__file__).resolve().parents[2]

        contract = load_route_contract(repository_root)

        self.assertEqual(len(contract.routes), 29)
        self.assertEqual(len(contract.canonical_templates), 29)
        self.assertEqual(len(contract.output_to_template), 61)
        self.assertIn("index.html", contract.canonical_templates)
        self.assertIn("fa", contract.locales)
        self.assertIn("en", contract.locales)
        self.assertEqual(
            contract.output_to_template["localized/en/index.html"],
            "index.html",
        )


if __name__ == "__main__":
    unittest.main()
