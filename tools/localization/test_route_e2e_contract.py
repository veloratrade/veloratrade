#!/usr/bin/env python3
"""Unit tests for the route/E2E coverage contract (PR-09)."""
from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path

from tools.localization.route_e2e_contract import (
    RouteE2EContractError,
    known_gap_report,
    slug_for_template,
    validate_route_e2e_contract,
)


class RouteE2EContractTestCase(unittest.TestCase):
    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.root = Path(self._tmp.name)
        self.routes_path = self.root / "routes.json"
        self.e2e_dir = self.root / "e2e"

    def tearDown(self) -> None:
        self._tmp.cleanup()

    def write_routes(self, routes: list[dict]) -> None:
        self.routes_path.write_text(
            json.dumps({"version": "test", "routes": routes}, indent=2),
            encoding="utf-8",
        )

    def write_spec(self, slug: str, *, page_load: bool = True, interaction: bool = True) -> None:
        self.e2e_dir.mkdir(parents=True, exist_ok=True)
        body = []
        if page_load:
            body.append("await page.goto(BASE + '/some-route/');")
        if interaction:
            body.append("await page.click('#main-cta');")
        (self.e2e_dir / f"{slug}.spec.js").write_text(
            "\n".join(body) or "// empty spec\n", encoding="utf-8"
        )

    def validate(self) -> list[str]:
        return validate_route_e2e_contract(routes_path=self.routes_path, e2e_dir=self.e2e_dir)

    # -- slug derivation -----------------------------------------------
    def test_slug_for_template(self) -> None:
        self.assertEqual(slug_for_template("login/index.html"), "login")
        self.assertEqual(slug_for_template("trades/new/index.html"), "trades-new")
        self.assertEqual(slug_for_template("404.html"), "404")
        self.assertEqual(slug_for_template("index.html"), "index")

    # -- missing/invalid declarations -----------------------------------
    def test_missing_e2e_category_is_reported(self) -> None:
        self.write_routes([{"template": "blog/index.html", "outputs": ["blog/index.html"]}])

        errors = self.validate()

        self.assertTrue(any("missing required field 'e2eCategory'" in e for e in errors))

    def test_invalid_e2e_category_is_reported(self) -> None:
        self.write_routes(
            [
                {
                    "template": "blog/index.html",
                    "outputs": ["blog/index.html"],
                    "e2eCategory": "urgent",
                }
            ]
        )

        errors = self.validate()

        self.assertTrue(any("invalid e2eCategory" in e for e in errors))

    def test_informational_route_needs_no_spec(self) -> None:
        self.write_routes(
            [
                {
                    "template": "blog/index.html",
                    "outputs": ["blog/index.html"],
                    "e2eCategory": "informational",
                }
            ]
        )

        errors = self.validate()

        self.assertEqual(errors, [])

    # -- critical route coverage -----------------------------------------
    def test_new_critical_route_without_spec_fails(self) -> None:
        self.write_routes(
            [
                {
                    "template": "new-critical-flow/index.html",
                    "outputs": ["new-critical-flow/index.html"],
                    "e2eCategory": "critical",
                }
            ]
        )

        errors = self.validate()

        self.assertTrue(any("has no E2E spec" in e for e in errors))

    def test_documented_baseline_gap_does_not_fail(self) -> None:
        # login/index.html is in KNOWN_MISSING_CRITICAL_SPECS and has no
        # spec file here — this must NOT produce an error.
        self.write_routes(
            [
                {
                    "template": "login/index.html",
                    "outputs": ["login/index.html"],
                    "e2eCategory": "critical",
                }
            ]
        )

        errors = self.validate()

        self.assertEqual(errors, [])

    def test_documented_baseline_gap_is_reported_via_known_gap_report(self) -> None:
        self.write_routes(
            [
                {
                    "template": "login/index.html",
                    "outputs": ["login/index.html"],
                    "e2eCategory": "critical",
                }
            ]
        )

        gaps = known_gap_report(routes_path=self.routes_path)

        self.assertEqual(gaps, ["login/index.html"])

    def test_critical_route_with_valid_spec_passes(self) -> None:
        self.write_routes(
            [
                {
                    "template": "new-critical-flow/index.html",
                    "outputs": ["new-critical-flow/index.html"],
                    "e2eCategory": "critical",
                }
            ]
        )
        self.write_spec("new-critical-flow", page_load=True, interaction=True)

        errors = self.validate()

        self.assertEqual(errors, [])

    def test_critical_spec_missing_page_load_assertion_fails(self) -> None:
        self.write_routes(
            [
                {
                    "template": "new-critical-flow/index.html",
                    "outputs": ["new-critical-flow/index.html"],
                    "e2eCategory": "critical",
                }
            ]
        )
        self.write_spec("new-critical-flow", page_load=False, interaction=True)

        errors = self.validate()

        self.assertTrue(any("no page-load assertion" in e for e in errors))

    def test_critical_spec_missing_interaction_assertion_fails(self) -> None:
        self.write_routes(
            [
                {
                    "template": "new-critical-flow/index.html",
                    "outputs": ["new-critical-flow/index.html"],
                    "e2eCategory": "critical",
                }
            ]
        )
        self.write_spec("new-critical-flow", page_load=True, interaction=False)

        errors = self.validate()

        self.assertTrue(any("no interactive-element assertion" in e for e in errors))

    # -- input errors ------------------------------------------------------
    def test_missing_routes_file_raises(self) -> None:
        with self.assertRaises(RouteE2EContractError):
            validate_route_e2e_contract(
                routes_path=self.root / "does-not-exist.json", e2e_dir=self.e2e_dir
            )

    # -- real repository contract ------------------------------------------
    def test_real_repository_contract_has_no_undeclared_routes(self) -> None:
        repo_root = Path(__file__).resolve().parents[2]

        errors = validate_route_e2e_contract(
            routes_path=repo_root / "tools" / "localization" / "routes.json",
            e2e_dir=repo_root / "tools" / "e2e",
        )

        self.assertEqual(errors, [])

    def test_real_repository_admin_and_intelligence_categorization(self) -> None:
        repo_root = Path(__file__).resolve().parents[2]
        routes = json.loads(
            (repo_root / "tools" / "localization" / "routes.json").read_text(encoding="utf-8")
        )["routes"]
        by_template = {r["template"]: r for r in routes}

        self.assertEqual(by_template["admin/index.html"]["e2eCategory"], "critical")
        self.assertEqual(by_template["intelligence/index.html"]["e2eCategory"], "informational")


if __name__ == "__main__":
    unittest.main()
