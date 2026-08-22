#!/usr/bin/env python3
"""Tests for the route-contract scoped localization validator."""
from __future__ import annotations

import contextlib
import hashlib
import io
import json
import tempfile
import unittest
from pathlib import Path

from tools.localization.validate_localization import (
    build_validation_scope,
    compare_output_sets,
    csp_linkage_errors,
    generated_metadata_errors,
    main,
    validate_localization,
)


class ScopedLocalizationValidatorTestCase(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.repository_root = Path(__file__).resolve().parents[2]

    def test_real_repository_scope_is_exactly_90_html_files(self) -> None:
        scope = build_validation_scope(self.repository_root)

        self.assertEqual(len(scope.canonical_paths), 29)
        self.assertEqual(len(scope.expected_localized_paths), 61)
        self.assertEqual(len(scope.actual_localized_paths), 61)
        self.assertEqual(scope.total_html, 90)
        self.assertEqual(scope.missing_localized_paths, ())
        self.assertEqual(scope.extra_localized_paths, ())

    def test_non_route_html_is_ignored(self) -> None:
        scope = build_validation_scope(self.repository_root)
        scoped = {
            path.relative_to(self.repository_root).as_posix()
            for path in (*scope.canonical_paths, *scope.actual_localized_paths)
        }

        self.assertNotIn("test-localization.html", scoped)
        self.assertNotIn("googleacbef8d6416f1474.html", scoped)
        self.assertNotIn("public/logout-diagnostic-v2.html", scoped)
        self.assertFalse(any(path.startswith("en/") for path in scoped))

    def test_validator_does_not_use_repository_wide_html_rglob(self) -> None:
        source = (
            self.repository_root / "tools/localization/validate_localization.py"
        ).read_text(encoding="utf-8")

        self.assertNotIn('ROOT.rglob("*.html")', source)
        self.assertNotIn("ROOT.rglob('*.html')", source)

    def test_missing_output_detection(self) -> None:
        expected = [
            Path("/contract/localized/fa/index.html"),
            Path("/contract/localized/en/index.html"),
        ]
        actual = [Path("/contract/localized/fa/index.html")]

        difference = compare_output_sets(expected, actual)

        self.assertEqual(
            difference.missing,
            (Path("/contract/localized/en/index.html"),),
        )
        self.assertEqual(difference.extra, ())

    def test_extra_output_detection(self) -> None:
        expected = [Path("/contract/localized/fa/index.html")]
        actual = [
            Path("/contract/localized/fa/index.html"),
            Path("/contract/localized/fa/unregistered/index.html"),
        ]

        difference = compare_output_sets(expected, actual)

        self.assertEqual(difference.missing, ())
        self.assertEqual(
            difference.extra,
            (Path("/contract/localized/fa/unregistered/index.html"),),
        )

    def test_seo_metadata_checks(self) -> None:
        valid = """<!doctype html>
<html lang="en" dir="ltr" data-velora-prelocalized="en">
<head>
<link rel="canonical" href="https://veloratrade.ir/en/dashboard/">
<meta property="og:url" content="https://veloratrade.ir/en/dashboard/">
<link rel="alternate" hreflang="fa" href="https://veloratrade.ir/fa/dashboard/">
<link rel="alternate" hreflang="en" href="https://veloratrade.ir/en/dashboard/">
<link rel="alternate" hreflang="x-default" href="https://veloratrade.ir/en/dashboard/">
</head><body></body></html>"""
        options = {
            "relative": "localized/en/dashboard/index.html",
            "locale": "en",
            "expected_direction": "ltr",
            "canonical_url": "/en/dashboard/",
            "alternate_urls": {
                "fa": "/fa/dashboard/",
                "en": "/en/dashboard/",
            },
            "fallback_locale": "en",
        }

        self.assertEqual(generated_metadata_errors(valid, **options), [])

        invalid = valid.replace('dir="ltr"', 'dir="rtl"').replace(
            "https://veloratrade.ir/en/dashboard/",
            "https://veloratrade.ir/en/wrong/",
            1,
        )
        errors = generated_metadata_errors(invalid, **options)
        self.assertIn("localized dir mismatch: localized/en/dashboard/index.html", errors)
        self.assertIn(
            "localized canonical mismatch: localized/en/dashboard/index.html",
            errors,
        )

    def test_seo_json_ld_main_entity_of_page(self) -> None:
        options = {
            "relative": "localized/en/blog/example/index.html",
            "locale": "en",
            "expected_direction": "ltr",
            "canonical_url": "/en/blog/example/",
            "alternate_urls": {
                "fa": "/fa/blog/example/",
                "en": "/en/blog/example/",
            },
            "fallback_locale": "en",
        }
        base = """<!doctype html>
<html lang="en" dir="ltr" data-velora-prelocalized="en">
<head>
<link rel="canonical" href="https://veloratrade.ir/en/blog/example/">
<meta property="og:url" content="https://veloratrade.ir/en/blog/example/">
<link rel="alternate" hreflang="fa" href="https://veloratrade.ir/fa/blog/example/">
<link rel="alternate" hreflang="en" href="https://veloratrade.ir/en/blog/example/">
<link rel="alternate" hreflang="x-default" href="https://veloratrade.ir/en/blog/example/">
{json_ld}
</head><body></body></html>"""

        # Correct mainEntityOfPage matching the locale's canonical URL: no error.
        valid = base.format(
            json_ld=(
                '<script type="application/ld+json">'
                '{"@context":"https://schema.org","@type":"Article",'
                '"headline":"Example","mainEntityOfPage":'
                '"https://veloratrade.ir/en/blog/example/"}</script>'
            )
        )
        self.assertEqual(generated_metadata_errors(valid, **options), [])

        # Wrong mainEntityOfPage (e.g. unlocalized or wrong-locale URL): flagged.
        invalid = base.format(
            json_ld=(
                '<script type="application/ld+json">'
                '{"@context":"https://schema.org","@type":"Article",'
                '"headline":"Example","mainEntityOfPage":'
                '"https://veloratrade.ir/blog/example/"}</script>'
            )
        )
        errors = generated_metadata_errors(invalid, **options)
        self.assertIn(
            "localized JSON-LD mainEntityOfPage mismatch: "
            "localized/en/blog/example/index.html",
            errors,
        )

        # JSON-LD without a mainEntityOfPage key must never be flagged.
        no_key = base.format(
            json_ld=(
                '<script type="application/ld+json">'
                '{"@context":"https://schema.org","@type":"Organization",'
                '"name":"VELORA"}</script>'
            )
        )
        self.assertEqual(generated_metadata_errors(no_key, **options), [])

        # A page with no JSON-LD at all must never be flagged.
        no_script = base.format(json_ld="")
        self.assertEqual(generated_metadata_errors(no_script, **options), [])

    def test_csp_linkage_checks(self) -> None:
        with tempfile.TemporaryDirectory() as temporary_directory:
            root = Path(temporary_directory)
            localized_root = root / "localized"
            page = localized_root / "fa/index.html"
            page.parent.mkdir(parents=True)
            page.write_text("<!doctype html><html></html>\n", encoding="utf-8")
            page_hash = hashlib.sha256(page.read_bytes()).hexdigest()
            manifest = {
                "policyVersion": 2,
                "releaseId": "test-release",
                "releaseHtmlSha256": "aggregate",
                "routeCount": 1,
                "routes": {
                    "fa/index.html": {
                        "file": "fa/index.html",
                        "htmlSha256": page_hash,
                    }
                },
            }
            raw = json.dumps(manifest, sort_keys=True).encode("utf-8")
            release = {
                "cspManifestSha256": hashlib.sha256(raw).hexdigest(),
                "policyVersion": 2,
                "releaseId": "test-release",
                "releaseHtmlSha256": "aggregate",
                "routeCount": 1,
            }

            self.assertEqual(
                csp_linkage_errors(
                    [page],
                    localized_root=localized_root,
                    csp_manifest=manifest,
                    csp_release=release,
                    csp_raw=raw,
                ),
                [],
            )

            manifest["routes"]["fa/index.html"]["htmlSha256"] = "wrong"
            errors = csp_linkage_errors(
                [page],
                localized_root=localized_root,
                csp_manifest=manifest,
                csp_release=release,
                csp_raw=raw,
            )
            self.assertIn("CSP HTML hash mismatch: fa/index.html", errors)

    def test_zero_false_positive_repository_baseline(self) -> None:
        result = validate_localization(self.repository_root)

        self.assertTrue(result.ok)
        self.assertEqual(result.errors, ())
        self.assertEqual(result.routes, 29)
        self.assertEqual(result.canonical, 29)
        self.assertEqual(result.localized, 61)
        self.assertEqual(result.locales, 2)
        self.assertEqual(result.scope_html, 90)

    def test_report_is_deterministic(self) -> None:
        outputs: list[tuple[int, str, str]] = []
        for _ in range(2):
            stdout = io.StringIO()
            stderr = io.StringIO()
            with contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
                exit_code = main(["--root", str(self.repository_root)])
            outputs.append((exit_code, stdout.getvalue(), stderr.getvalue()))

        self.assertEqual(outputs[0], outputs[1])
        self.assertEqual(outputs[0][0], 0)
        self.assertEqual(outputs[0][2], "")
        self.assertEqual(
            outputs[0][1],
            "LOCALIZATION_VALIDATION_OK\n"
            "routes=29\n"
            "canonical=29\n"
            "localized=61\n"
            "locales=2\n"
            "issues=0\n",
        )


if __name__ == "__main__":
    unittest.main()
