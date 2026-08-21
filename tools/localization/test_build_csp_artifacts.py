#!/usr/bin/env python3
"""Tests for deterministic, tool-only CSP artifact generation."""
from __future__ import annotations

import base64
import contextlib
import hashlib
import io
import json
import os
import stat
import tempfile
import unittest
from pathlib import Path
from unittest import mock

from tools.localization import build_localized_static
from tools.localization.build_csp_artifacts import (
    CSP_MANIFEST_RELATIVE,
    CSP_POLICY_VERSION,
    CSP_RELEASE_RELATIVE,
    CspArtifactError,
    build_csp_artifacts,
    check_csp_artifacts,
    main,
    validate_commit_sha,
    validate_release_id,
    write_csp_artifacts,
)


class CspArtifactBuilderTestCase(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.repository_root = Path(__file__).resolve().parents[2]
        cls.manifest_path = cls.repository_root / CSP_MANIFEST_RELATIVE
        cls.release_path = cls.repository_root / CSP_RELEASE_RELATIVE

    @staticmethod
    def _fixture_html() -> str:
        return """<!doctype html>
<html><head>
<style>body{color:red}</style><style>body{color:red}</style>
<script>run()</script><script>run()</script><script src="/external.js"></script>
</head><body>
<button onclick="go()" style="color:red">One</button>
<button onclick="go()" style="color:red">Two</button>
</body></html>"""

    @classmethod
    def _write_fixture(
        cls,
        root: Path,
        *,
        html_by_locale: dict[str, str] | None = None,
        routes_trailing_space: bool = False,
    ) -> None:
        (root / "tools/localization").mkdir(parents=True)
        (root / "public/locales").mkdir(parents=True)
        (root / "localized").mkdir(parents=True)
        (root / "index.html").write_text(
            "<!doctype html><html><body>canonical</body></html>",
            encoding="utf-8",
        )
        routes = {
            "version": 1,
            "routes": [{"template": "index.html", "outputs": ["index.html"]}],
        }
        routes_text = json.dumps(routes, ensure_ascii=False, indent=2)
        if routes_trailing_space:
            routes_text += " "
        (root / "tools/localization/routes.json").write_text(
            routes_text, encoding="utf-8"
        )
        locale_manifest = {
            "version": "fixture-version",
            "defaultLocale": "fa",
            "fallbackLocale": "en",
            "featureCatalogBase": "/public/locales/chunks/",
            "locales": {
                "fa": {
                    "enabled": True,
                    "direction": "rtl",
                    "numberingSystem": "arabext",
                },
                "en": {
                    "enabled": True,
                    "direction": "ltr",
                    "numberingSystem": "latn",
                },
            },
        }
        (root / "public/locales/manifest.json").write_text(
            json.dumps(locale_manifest, ensure_ascii=False, indent=2),
            encoding="utf-8",
        )
        for locale in ("fa", "en"):
            (root / f"public/locales/{locale}.json").write_text(
                json.dumps({"messages": {}}, sort_keys=True), encoding="utf-8"
            )
        feature_map = {
            "aliases": {},
            "always": [],
            "runtimeKeys": [],
            "runtimeNamespaces": [],
            "serverOnly": [],
            "sharedFeatureThreshold": 2,
        }
        (root / "tools/localization/feature-map.json").write_text(
            json.dumps(feature_map, sort_keys=True), encoding="utf-8"
        )
        localized = html_by_locale or {
            "fa": cls._fixture_html(),
            "en": cls._fixture_html(),
        }
        for locale, source in localized.items():
            output = root / "localized" / locale / "index.html"
            output.parent.mkdir(parents=True, exist_ok=True)
            output.write_text(source, encoding="utf-8")

    @staticmethod
    def _patch_localized_builder(root: Path):
        return mock.patch.multiple(
            build_localized_static,
            ROOT=root,
            LOCALES_DIR=root / "public/locales",
            CHUNKS_DIR=root / "public/locales/chunks",
            OUTPUT_DIR=root / "localized",
        )

    @classmethod
    def _prepare_live_generated_state(cls, root: Path) -> str:
        chunks = root / "public/locales/chunks"
        chunks.mkdir(parents=True, exist_ok=True)
        (chunks / "old.json").write_text("old chunk\n", encoding="utf-8")
        (root / "public/locales/feature-manifest.json").write_text(
            '{"state":"old"}', encoding="utf-8"
        )
        artifacts = build_csp_artifacts(root, release_id="old-release")
        write_csp_artifacts(artifacts, root)
        return build_localized_static.generated_state_digest(root)

    @staticmethod
    def _transaction_directories(root: Path) -> list[Path]:
        return sorted(root.glob(".velora-localization-transaction-*"))

    @staticmethod
    def _csp_hash(source: str) -> str:
        digest = hashlib.sha256(source.encode("utf-8")).digest()
        return "sha256-" + base64.b64encode(digest).decode("ascii")

    def test_repository_artifacts_reproduce_byte_for_byte(self) -> None:
        current_manifest = json.loads(self.manifest_path.read_text(encoding="utf-8"))
        artifacts = build_csp_artifacts(
            self.repository_root,
            release_id=current_manifest["releaseId"],
            commit_sha=current_manifest["commitSha"],
        )

        self.assertEqual(artifacts.manifest_bytes, self.manifest_path.read_bytes())
        self.assertEqual(artifacts.release_bytes, self.release_path.read_bytes())
        self.assertEqual(artifacts.manifest["routeCount"], 61)
        self.assertEqual(
            artifacts.manifest["commitSha"], current_manifest["commitSha"]
        )
        self.assertEqual(
            artifacts.manifest["sourceDigest"], current_manifest["sourceDigest"]
        )

    def test_repository_check_mode_is_read_only(self) -> None:
        paths = (self.manifest_path, self.release_path)
        before = {
            path: (path.read_bytes(), path.stat().st_mtime_ns, path.stat().st_mode)
            for path in paths
        }

        result = check_csp_artifacts(self.repository_root)

        self.assertTrue(result.ok)
        self.assertEqual(result.mismatches, ())
        after = {
            path: (path.read_bytes(), path.stat().st_mtime_ns, path.stat().st_mode)
            for path in paths
        }
        self.assertEqual(after, before)

    def test_manifest_schema_and_serialization_match_current_contract(self) -> None:
        current = json.loads(self.manifest_path.read_text(encoding="utf-8"))
        artifacts = build_csp_artifacts(
            self.repository_root,
            release_id=current["releaseId"],
            commit_sha=current["commitSha"],
        )

        self.assertEqual(
            sorted(artifacts.manifest),
            [
                "algorithm",
                "commitSha",
                "localizationVersion",
                "policyVersion",
                "releaseHtmlSha256",
                "releaseId",
                "routeCount",
                "routeManifestSha256",
                "routes",
                "sourceDigest",
            ],
        )
        self.assertEqual(
            artifacts.manifest_bytes,
            json.dumps(
                artifacts.manifest,
                ensure_ascii=False,
                sort_keys=True,
                indent=2,
            ).encode("utf-8"),
        )
        self.assertFalse(artifacts.manifest_bytes.endswith(b"\n"))
        self.assertEqual(artifacts.manifest["policyVersion"], CSP_POLICY_VERSION)
        self.assertEqual(artifacts.manifest["algorithm"], "sha256")

    def test_release_schema_and_serialization_match_current_contract(self) -> None:
        current = json.loads(self.manifest_path.read_text(encoding="utf-8"))
        artifacts = build_csp_artifacts(
            self.repository_root,
            release_id=current["releaseId"],
            commit_sha=current["commitSha"],
        )

        self.assertEqual(
            sorted(artifacts.release),
            [
                "commitSha",
                "cspManifestSha256",
                "policyVersion",
                "releaseHtmlSha256",
                "releaseId",
                "routeCount",
                "sourceDigest",
            ],
        )
        self.assertEqual(
            artifacts.release_bytes,
            json.dumps(
                artifacts.release,
                ensure_ascii=False,
                sort_keys=True,
            ).encode("utf-8"),
        )
        self.assertFalse(artifacts.release_bytes.endswith(b"\n"))

    def test_route_scope_rejects_missing_html(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self._write_fixture(root)
            (root / "localized/fa/index.html").unlink()

            with self.assertRaisesRegex(CspArtifactError, "missing localized HTML"):
                build_csp_artifacts(root, release_id="fixture-release")

    def test_route_scope_rejects_extra_html(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self._write_fixture(root)
            extra = root / "localized/en/extra.html"
            extra.write_text("<html></html>", encoding="utf-8")

            with self.assertRaisesRegex(CspArtifactError, "extra localized HTML"):
                build_csp_artifacts(root, release_id="fixture-release")

    def test_inline_scripts_and_styles_are_hashed_exactly(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self._write_fixture(root)

            entry = build_csp_artifacts(
                root, release_id="fixture-release"
            ).manifest["routes"]["en/index.html"]

            self.assertEqual(entry["inlineScriptCount"], 2)
            self.assertEqual(entry["inlineScriptHashes"], [self._csp_hash("run()")])
            self.assertEqual(entry["inlineStyleCount"], 2)
            self.assertEqual(
                entry["inlineStyleHashes"], [self._csp_hash("body{color:red}")]
            )

    def test_external_scripts_are_excluded(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self._write_fixture(root)

            entry = build_csp_artifacts(
                root, release_id="fixture-release"
            ).manifest["routes"]["fa/index.html"]

            self.assertEqual(entry["inlineScriptCount"], 2)
            self.assertNotIn(self._csp_hash(""), entry["inlineScriptHashes"])

    def test_event_handlers_and_style_attributes_are_hashed_exactly(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self._write_fixture(root)

            entry = build_csp_artifacts(
                root, release_id="fixture-release"
            ).manifest["routes"]["fa/index.html"]

            self.assertEqual(entry["eventHandlerCount"], 2)
            self.assertEqual(entry["eventHandlerHashes"], [self._csp_hash("go()")])
            self.assertEqual(entry["styleAttributeCount"], 2)
            self.assertEqual(
                entry["styleAttributeHashes"], [self._csp_hash("color:red")]
            )

    def test_duplicate_occurrences_keep_counts_and_deduplicate_hashes(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self._write_fixture(root)

            entry = build_csp_artifacts(
                root, release_id="fixture-release"
            ).manifest["routes"]["en/index.html"]

            self.assertGreater(entry["inlineScriptCount"], len(entry["inlineScriptHashes"]))
            self.assertGreater(entry["inlineStyleCount"], len(entry["inlineStyleHashes"]))
            self.assertGreater(entry["eventHandlerCount"], len(entry["eventHandlerHashes"]))
            self.assertGreater(
                entry["styleAttributeCount"], len(entry["styleAttributeHashes"])
            )

    def test_hash_arrays_are_sorted_deterministically(self) -> None:
        source = """<html><head><script>z()</script><script>a()</script></head>
<body><button onclick="z()"></button><button onclick="a()"></button></body></html>"""
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self._write_fixture(root, html_by_locale={"fa": source, "en": source})

            artifacts = build_csp_artifacts(root, release_id="fixture-release")
            entry = artifacts.manifest["routes"]["en/index.html"]

            self.assertEqual(
                entry["inlineScriptHashes"], sorted(entry["inlineScriptHashes"])
            )
            self.assertEqual(
                entry["eventHandlerHashes"], sorted(entry["eventHandlerHashes"])
            )
            self.assertEqual(
                len(entry["inlineScriptHashes"]),
                len(set(entry["inlineScriptHashes"])),
            )

    def test_release_html_aggregate_uses_sorted_path_hash_records(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self._write_fixture(root)
            artifacts = build_csp_artifacts(root, release_id="fixture-release")
            routes = artifacts.manifest["routes"]
            payload = "".join(
                f"{path}:{routes[path]['htmlSha256']}\n" for path in sorted(routes)
            ).encode("utf-8")

            self.assertEqual(
                artifacts.manifest["releaseHtmlSha256"],
                hashlib.sha256(payload).hexdigest(),
            )
            self.assertEqual(
                artifacts.release["releaseHtmlSha256"],
                artifacts.manifest["releaseHtmlSha256"],
            )

    def test_route_manifest_hash_uses_raw_routes_bytes(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self._write_fixture(root, routes_trailing_space=True)
            artifacts = build_csp_artifacts(root, release_id="fixture-release")
            raw = (root / "tools/localization/routes.json").read_bytes()

            self.assertEqual(
                artifacts.manifest["routeManifestSha256"],
                hashlib.sha256(raw).hexdigest(),
            )

    def test_manifest_hash_links_release_to_exact_manifest_bytes(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self._write_fixture(root)
            artifacts = build_csp_artifacts(root, release_id="fixture-release")

            self.assertEqual(
                artifacts.release["cspManifestSha256"],
                hashlib.sha256(artifacts.manifest_bytes).hexdigest(),
            )
            self.assertEqual(
                artifacts.release["routeCount"], artifacts.manifest["routeCount"]
            )
            self.assertEqual(
                artifacts.release["policyVersion"],
                artifacts.manifest["policyVersion"],
            )

    def test_write_requires_explicit_release_id(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self._write_fixture(root)
            stdout = io.StringIO()

            with contextlib.redirect_stdout(stdout):
                exit_code = main(["--root", str(root), "--write"])

            self.assertEqual(exit_code, 2)
            self.assertIn("--write requires an explicit --release-id", stdout.getvalue())
            self.assertFalse((root / CSP_MANIFEST_RELATIVE).exists())
            self.assertFalse((root / CSP_RELEASE_RELATIVE).exists())

    def test_write_requires_explicit_commit_sha(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self._write_fixture(root)
            stdout = io.StringIO()

            with contextlib.redirect_stdout(stdout):
                exit_code = main(
                    ["--root", str(root), "--write", "--release-id", "fixture-release"]
                )

            self.assertEqual(exit_code, 2)
            self.assertIn("--write requires an explicit --commit-sha", stdout.getvalue())
            self.assertFalse((root / CSP_MANIFEST_RELATIVE).exists())
            self.assertFalse((root / CSP_RELEASE_RELATIVE).exists())

    def test_commit_sha_is_validated_and_normalized(self) -> None:
        for invalid in ("", "zzzz", "not-a-sha", "g" * 40, "abc" * 2, "a" * 65):
            with self.subTest(invalid=invalid):
                with self.assertRaises(CspArtifactError):
                    validate_commit_sha(invalid)
        self.assertEqual(validate_commit_sha("ABC" * 3 + "abc1234"), "abcabcabcabc1234")

    def test_release_id_is_never_generated_from_time(self) -> None:
        for invalid in ("", " leading", "trailing ", "bad/id", "bad:id", "\n"):
            with self.subTest(invalid=invalid):
                with self.assertRaises(CspArtifactError):
                    validate_release_id(invalid)
        self.assertEqual(validate_release_id("2026.08.16.1"), "2026.08.16.1")
        self.assertEqual(validate_release_id("test-release"), "test-release")

    def test_temp_fixture_write_and_check_round_trip(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self._write_fixture(root)
            artifacts = build_csp_artifacts(root, release_id="fixture-release")

            write_csp_artifacts(artifacts, root)
            result = check_csp_artifacts(root)

            self.assertTrue(result.ok)
            self.assertEqual(
                (root / CSP_MANIFEST_RELATIVE).read_bytes(), artifacts.manifest_bytes
            )
            self.assertEqual(
                (root / CSP_RELEASE_RELATIVE).read_bytes(), artifacts.release_bytes
            )
            self.assertEqual(
                stat.S_IMODE((root / CSP_MANIFEST_RELATIVE).stat().st_mode), 0o644
            )
            self.assertEqual(
                stat.S_IMODE((root / CSP_RELEASE_RELATIVE).stat().st_mode), 0o644
            )

    def test_check_detects_manifest_drift_without_writing(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self._write_fixture(root)
            artifacts = build_csp_artifacts(root, release_id="fixture-release")
            write_csp_artifacts(artifacts, root)
            manifest_path = root / CSP_MANIFEST_RELATIVE
            manifest_path.write_bytes(manifest_path.read_bytes() + b"\n")
            before = manifest_path.read_bytes()

            result = check_csp_artifacts(root)

            self.assertEqual(
                result.mismatches,
                ("artifact byte mismatch: public/locales/csp-manifest.json",),
            )
            self.assertEqual(manifest_path.read_bytes(), before)

    def test_check_detects_release_drift_without_writing(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self._write_fixture(root)
            artifacts = build_csp_artifacts(root, release_id="fixture-release")
            write_csp_artifacts(artifacts, root)
            release_path = root / CSP_RELEASE_RELATIVE
            release = json.loads(release_path.read_text(encoding="utf-8"))
            release["routeCount"] = 999
            release_path.write_text(json.dumps(release, sort_keys=True), encoding="utf-8")
            before = release_path.read_bytes()

            result = check_csp_artifacts(root)

            self.assertEqual(
                result.mismatches,
                ("artifact byte mismatch: localized/.csp-release.json",),
            )
            self.assertEqual(release_path.read_bytes(), before)

    def test_output_is_deterministic(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self._write_fixture(root)

            first = build_csp_artifacts(root, release_id="fixture-release")
            second = build_csp_artifacts(root, release_id="fixture-release")

            self.assertEqual(first.manifest_bytes, second.manifest_bytes)
            self.assertEqual(first.release_bytes, second.release_bytes)

    def test_generation_failure_preserves_existing_outputs(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self._write_fixture(root)
            artifacts = build_csp_artifacts(root, release_id="fixture-release")
            write_csp_artifacts(artifacts, root)
            paths = (root / CSP_MANIFEST_RELATIVE, root / CSP_RELEASE_RELATIVE)
            before = {path: path.read_bytes() for path in paths}
            (root / "localized/en/unexpected.html").write_text(
                "<html></html>", encoding="utf-8"
            )

            with self.assertRaises(CspArtifactError):
                build_csp_artifacts(root, release_id="next-release")

            self.assertEqual({path: path.read_bytes() for path in paths}, before)

    def test_localized_build_validates_release_id_before_mutation(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self._write_fixture(root)
            sentinel = root / "public/locales/chunks/sentinel.txt"
            sentinel.parent.mkdir(parents=True)
            sentinel.write_text("keep", encoding="utf-8")
            before = {
                path.relative_to(root).as_posix(): path.read_bytes()
                for path in root.rglob("*")
                if path.is_file()
            }

            with self._patch_localized_builder(root):
                with self.assertRaises(CspArtifactError):
                    build_localized_static.build("invalid/release", "f" * 40)

            after = {
                path.relative_to(root).as_posix(): path.read_bytes()
                for path in root.rglob("*")
                if path.is_file()
            }
            self.assertEqual(after, before)

    def test_localized_build_writes_csp_pair_after_all_html_outputs(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self._write_fixture(root)
            real_build_csp = build_localized_static.build_csp_artifacts
            real_write_csp = build_localized_static.write_csp_artifacts
            events: list[tuple[str, bool]] = []

            def observed_build_csp(
                repository_root: Path,
                *,
                release_id: str,
                commit_sha: str | None = None,
                localized_root: Path | None = None,
            ):
                self.assertIsNotNone(localized_root)
                staged_localized = Path(localized_root)
                outputs = (
                    staged_localized / "fa/index.html",
                    staged_localized / "en/index.html",
                )
                completed = all(
                    path.is_file()
                    and "data-velora-prelocalized" in path.read_text(encoding="utf-8")
                    for path in outputs
                )
                events.append(("build-csp", completed))
                return real_build_csp(
                    repository_root,
                    release_id=release_id,
                    commit_sha=commit_sha,
                    localized_root=staged_localized,
                )

            def observed_write_csp(artifacts, repository_root: Path) -> None:
                events.append(("write-csp", True))
                real_write_csp(artifacts, repository_root)

            with self._patch_localized_builder(root), mock.patch.object(
                build_localized_static,
                "build_csp_artifacts",
                side_effect=observed_build_csp,
            ), mock.patch.object(
                build_localized_static,
                "write_csp_artifacts",
                side_effect=observed_write_csp,
            ):
                result = build_localized_static.build("integration-release", "f" * 40)

            self.assertEqual(events, [("build-csp", True), ("write-csp", True)])
            self.assertEqual(result, (1, 2, 4, 2))
            self.assertTrue(check_csp_artifacts(root).ok)
            self.assertEqual(self._transaction_directories(root), [])

    def test_localized_build_csp_failure_cannot_report_success_pair(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self._write_fixture(root)

            with self._patch_localized_builder(root), mock.patch.object(
                build_localized_static,
                "build_csp_artifacts",
                side_effect=CspArtifactError("fixture CSP failure"),
            ):
                with self.assertRaisesRegex(CspArtifactError, "fixture CSP failure"):
                    build_localized_static.build("integration-release", "f" * 40)

            self.assertFalse((root / CSP_MANIFEST_RELATIVE).exists())
            self.assertFalse((root / CSP_RELEASE_RELATIVE).exists())
            self.assertTrue((root / "localized/fa/index.html").is_file())
            self.assertTrue((root / "localized/en/index.html").is_file())
            self.assertEqual(self._transaction_directories(root), [])

    def test_staged_csp_check_uses_staged_html_and_artifacts(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self._write_fixture(root)
            stage_root = root / ".stage"
            stage_localized = stage_root / "localized"
            for locale in ("fa", "en"):
                output = stage_localized / locale / "index.html"
                output.parent.mkdir(parents=True, exist_ok=True)
                output.write_text(
                    f"<html><body>staged {locale}</body></html>", encoding="utf-8"
                )

            artifacts = build_csp_artifacts(
                root,
                release_id="staged-release",
                localized_root=stage_localized,
            )
            write_csp_artifacts(artifacts, stage_root)
            result = check_csp_artifacts(
                root,
                release_id="staged-release",
                localized_root=stage_localized,
                artifact_root=stage_root,
            )

            self.assertTrue(result.ok)
            self.assertFalse((root / CSP_MANIFEST_RELATIVE).exists())
            self.assertFalse((root / CSP_RELEASE_RELATIVE).exists())

    def test_staged_csp_write_failure_preserves_complete_live_release(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self._write_fixture(root)
            before = self._prepare_live_generated_state(root)

            with self._patch_localized_builder(root), mock.patch.object(
                build_localized_static,
                "write_csp_artifacts",
                side_effect=OSError("staged CSP write failure"),
            ):
                with self.assertRaisesRegex(OSError, "staged CSP write failure"):
                    build_localized_static.build("next-release", "f" * 40)

            self.assertEqual(
                build_localized_static.generated_state_digest(root), before
            )
            self.assertTrue(check_csp_artifacts(root, release_id="old-release").ok)
            self.assertEqual(self._transaction_directories(root), [])

    def test_each_controlled_promotion_failure_rolls_back_exactly(self) -> None:
        # Four complete live targets are backed up, then four staged targets are
        # promoted. Inject one failure at every replace position in that sequence.
        for fail_at in range(1, 9):
            with self.subTest(fail_at=fail_at):
                with tempfile.TemporaryDirectory() as directory:
                    root = Path(directory)
                    self._write_fixture(root)
                    before = self._prepare_live_generated_state(root)
                    calls = 0

                    def flaky_replace(source: Path, destination: Path) -> None:
                        nonlocal calls
                        calls += 1
                        if calls == fail_at:
                            raise OSError(f"promotion failure {fail_at}")
                        os.replace(source, destination)

                    with self._patch_localized_builder(root), mock.patch.object(
                        build_localized_static,
                        "_replace_path",
                        side_effect=flaky_replace,
                    ):
                        with self.assertRaisesRegex(
                            OSError, f"promotion failure {fail_at}"
                        ):
                            build_localized_static.build("next-release", "f" * 40)

                    self.assertEqual(
                        build_localized_static.generated_state_digest(root), before
                    )
                    self.assertTrue(
                        check_csp_artifacts(root, release_id="old-release").ok
                    )
                    self.assertEqual(self._transaction_directories(root), [])

    def test_post_promotion_check_failure_rolls_back_exactly(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self._write_fixture(root)
            before = self._prepare_live_generated_state(root)
            real_check = build_localized_static.check_csp_artifacts

            def fail_live_check(repository_root: Path, **kwargs):
                if kwargs.get("artifact_root") is not None:
                    return real_check(repository_root, **kwargs)
                raise CspArtifactError("post-promotion check failure")

            with self._patch_localized_builder(root), mock.patch.object(
                build_localized_static,
                "check_csp_artifacts",
                side_effect=fail_live_check,
            ):
                with self.assertRaisesRegex(
                    CspArtifactError, "post-promotion check failure"
                ):
                    build_localized_static.build("next-release", "f" * 40)

            self.assertEqual(
                build_localized_static.generated_state_digest(root), before
            )
            self.assertTrue(check_csp_artifacts(root, release_id="old-release").ok)
            self.assertEqual(self._transaction_directories(root), [])

    def test_csp_guard_uses_generator_check_only(self) -> None:
        workflow = (
            self.repository_root / ".github/workflows/csp-guard.yml"
        ).read_text(encoding="utf-8")

        self.assertIn("actions/setup-python@v5", workflow)
        self.assertIn("beautifulsoup4==4.15.0", workflow)
        self.assertIn(
            "python3 tools/localization/build_csp_artifacts.py --check", workflow
        )
        self.assertNotIn(
            "python3 tools/localization/build_csp_artifacts.py --write", workflow
        )
        self.assertIn("secret-scan:", workflow)

    def test_staging_deployment_depends_on_reusable_csp_guard(self) -> None:
        workflow = (
            self.repository_root / ".github/workflows/deploy-staging.yml"
        ).read_text(encoding="utf-8")

        self.assertRegex(
            workflow,
            r"(?m)^  guard:\n(?:    .*\n)+?    uses: \./\.github/workflows/csp-guard\.yml$",
        )
        self.assertRegex(
            workflow,
            r"(?m)^  deploy-staging:\n(?:    .*\n)+?    needs: guard$",
        )

    def test_production_deployment_retains_reusable_csp_guard(self) -> None:
        workflow = (self.repository_root / ".github/workflows/deploy.yml").read_text(
            encoding="utf-8"
        )

        self.assertIn("uses: ./.github/workflows/csp-guard.yml", workflow)
        self.assertRegex(workflow, r"(?m)^    needs: guard$")


if __name__ == "__main__":
    unittest.main()
