#!/usr/bin/env python3
"""Deterministically build or verify VELORA's localized CSP artifacts.

The default CLI mode is read-only ``--check``. Writing requires both the
explicit ``--write`` flag and an explicit release identifier. The builder never
mutates localized HTML and never derives release metadata from the clock.
"""
from __future__ import annotations

import argparse
import base64
import hashlib
import json
import os
import re
import tempfile
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Iterable, Mapping, Sequence

from bs4 import BeautifulSoup

try:
    from .route_contract import RouteContractError, load_route_contract
except ImportError:  # Direct script execution.
    from route_contract import RouteContractError, load_route_contract


ROOT = Path(__file__).resolve().parents[2]
ROUTES_RELATIVE = Path("tools/localization/routes.json")
LOCALE_MANIFEST_RELATIVE = Path("public/locales/manifest.json")
CSP_MANIFEST_RELATIVE = Path("public/locales/csp-manifest.json")
CSP_RELEASE_RELATIVE = Path("localized/.csp-release.json")
LOCALIZED_RELATIVE = Path("localized")
CSP_ALGORITHM = "sha256"
CSP_POLICY_VERSION = 2
_RELEASE_ID = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$")


class CspArtifactError(ValueError):
    """Raised when CSP inputs or existing artifacts violate the contract."""


@dataclass(frozen=True)
class CspArtifacts:
    """The complete in-memory CSP artifact pair."""

    manifest: Mapping[str, Any]
    manifest_bytes: bytes
    release: Mapping[str, Any]
    release_bytes: bytes


@dataclass(frozen=True)
class CspCheckResult:
    """Read-only comparison of expected and on-disk CSP artifacts."""

    artifacts: CspArtifacts
    mismatches: tuple[str, ...]

    @property
    def ok(self) -> bool:
        return not self.mismatches


def _duplicate_safe_object(pairs: Sequence[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            raise CspArtifactError(f"duplicate JSON key: {key}")
        result[key] = value
    return result


def _read_json_object(path: Path, label: str) -> dict[str, Any]:
    if not path.is_file():
        raise CspArtifactError(f"missing {label}: {path}")
    try:
        payload = json.loads(
            path.read_text(encoding="utf-8"),
            object_pairs_hook=_duplicate_safe_object,
        )
    except CspArtifactError:
        raise
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        raise CspArtifactError(f"invalid {label}: {path}: {exc}") from exc
    if not isinstance(payload, dict):
        raise CspArtifactError(f"{label} must be a JSON object: {path}")
    return payload


def validate_release_id(value: Any) -> str:
    """Return a safe explicit release id; never synthesize one."""

    if not isinstance(value, str) or _RELEASE_ID.fullmatch(value) is None:
        raise CspArtifactError(
            "releaseId must match [A-Za-z0-9][A-Za-z0-9._-]{0,127}"
        )
    return value


_COMMIT_SHA = re.compile(r"^[0-9a-fA-F]{7,64}$")


def validate_commit_sha(value: Any) -> str:
    """Return a valid lowercase git commit SHA; never synthesize one."""

    if not isinstance(value, str) or _COMMIT_SHA.fullmatch(value) is None:
        raise CspArtifactError(
            "commitSha must match [0-9a-f]{7,64} (a valid git commit SHA)"
        )
    return value.lower()


def _sha256_hex(payload: bytes) -> str:
    return hashlib.sha256(payload).hexdigest()


def _csp_sha256(source: str) -> str:
    digest = hashlib.sha256(source.encode("utf-8")).digest()
    return "sha256-" + base64.b64encode(digest).decode("ascii")


def _inline_text(tag: Any) -> str:
    # BeautifulSoup's html.parser preserves script/style text in the same form
    # used to produce the current policyVersion=2 artifacts.
    return "" if tag.string is None else str(tag.string)


def _hash_occurrences(values: Iterable[str]) -> list[str]:
    return sorted({_csp_sha256(value) for value in values})


def build_route_entry(path: Path, localized_root: Path) -> dict[str, Any]:
    """Build one route record from exact HTML bytes and parsed inline sources."""

    try:
        resolved_path = path.resolve(strict=True)
        resolved_localized = localized_root.resolve(strict=True)
        relative = resolved_path.relative_to(resolved_localized).as_posix()
        raw = resolved_path.read_bytes()
        source = raw.decode("utf-8")
    except (OSError, UnicodeError, ValueError) as exc:
        raise CspArtifactError(f"cannot read localized HTML safely: {path}: {exc}") from exc

    soup = BeautifulSoup(source, "html.parser")
    scripts = [
        _inline_text(tag) for tag in soup.find_all("script") if not tag.get("src")
    ]
    styles = [_inline_text(tag) for tag in soup.find_all("style")]
    handlers = [
        str(value)
        for tag in soup.find_all(True)
        for attribute, value in tag.attrs.items()
        if attribute.lower().startswith("on")
    ]
    style_attributes = [
        str(tag.get("style"))
        for tag in soup.find_all(True)
        if tag.has_attr("style")
    ]

    return {
        "eventHandlerCount": len(handlers),
        "eventHandlerHashes": _hash_occurrences(handlers),
        "file": relative,
        "htmlSha256": _sha256_hex(raw),
        "inlineScriptCount": len(scripts),
        "inlineScriptHashes": _hash_occurrences(scripts),
        "inlineStyleCount": len(styles),
        "inlineStyleHashes": _hash_occurrences(styles),
        "styleAttributeCount": len(style_attributes),
        "styleAttributeHashes": _hash_occurrences(style_attributes),
    }


def _expected_localized_paths(repository_root: Path) -> tuple[Path, ...]:
    try:
        contract = load_route_contract(repository_root)
    except RouteContractError as exc:
        raise CspArtifactError(f"invalid route contract: {exc}") from exc
    return tuple(sorted(contract.expected_output_paths, key=lambda path: path.as_posix()))


def _assert_exact_output_scope(
    localized_root: Path, expected_paths: Sequence[Path]
) -> None:
    if not localized_root.is_dir():
        raise CspArtifactError(f"missing localized output directory: {localized_root}")

    expected = {
        path.relative_to(localized_root).as_posix(): path for path in expected_paths
    }
    actual: dict[str, Path] = {}
    for path in localized_root.rglob("*.html"):
        if not path.is_file():
            continue
        try:
            path.resolve(strict=True).relative_to(localized_root.resolve(strict=True))
        except (OSError, ValueError) as exc:
            raise CspArtifactError(
                f"localized HTML escapes output directory: {path}: {exc}"
            ) from exc
        actual[path.relative_to(localized_root).as_posix()] = path

    missing = sorted(set(expected) - set(actual))
    extra = sorted(set(actual) - set(expected))
    if missing or extra:
        details: list[str] = []
        details.extend(f"missing localized HTML: {path}" for path in missing)
        details.extend(f"extra localized HTML: {path}" for path in extra)
        raise CspArtifactError("localized output scope mismatch\n- " + "\n- ".join(details))


def _release_html_sha256(routes: Mapping[str, Mapping[str, Any]]) -> str:
    payload = "".join(
        f"{relative}:{routes[relative]['htmlSha256']}\n"
        for relative in sorted(routes)
    ).encode("utf-8")
    return _sha256_hex(payload)


_SOURCE_INPUTS = (
    "tools/localization/routes.json",
    "tools/localization/feature-map.json",
    "public/locales/manifest.json",
)


def _source_inputs(repository_root: Path) -> list[tuple[str, Path]]:
    """Canonical ordered list of every source file that drives regeneration."""

    inputs: list[tuple[str, Path]] = []
    for relative in _SOURCE_INPUTS:
        inputs.append((relative, repository_root / relative))

    locale_manifest = _read_json_object(
        repository_root / LOCALE_MANIFEST_RELATIVE, "locale manifest"
    )
    for locale, entry in locale_manifest.get("locales", {}).items():
        if entry.get("enabled", True):
            relative = f"public/locales/{locale}.json"
            inputs.append((relative, repository_root / relative))

    routes_path = repository_root / ROUTES_RELATIVE
    routes_raw = routes_path.read_bytes() if routes_path.is_file() else b""
    if routes_raw:
        try:
            routes_payload = json.loads(routes_raw.decode("utf-8"))
        except (UnicodeError, json.JSONDecodeError) as exc:
            raise CspArtifactError(f"invalid routes manifest: {exc}") from exc
        for route in routes_payload.get("routes", []):
            template = route.get("template")
            if not isinstance(template, str) or not template:
                raise CspArtifactError("route template must be a non-empty string")
            inputs.append((Path(template).as_posix(), repository_root / template))

    seen: set[str] = set()
    unique: list[tuple[str, Path]] = []
    for relative, path in inputs:
        if relative in seen:
            continue
        seen.add(relative)
        unique.append((relative, path))
    unique.sort(key=lambda item: item[0])
    return unique


def compute_source_digest(repository_root: Path) -> str:
    """Deterministic digest over every source input of the generated artifacts.

    No timestamp is involved; the digest changes only when a source file
    (template, catalog, locale manifest, route contract or feature map) changes.
    """

    digest = hashlib.sha256()
    for relative, path in _source_inputs(repository_root):
        if not path.is_file():
            raise CspArtifactError(f"missing source input: {relative}")
        digest.update(relative.encode("utf-8"))
        digest.update(b"\0")
        digest.update(hashlib.sha256(path.read_bytes()).digest())
        digest.update(b"\0")
    return digest.hexdigest()


def _serialize_manifest(payload: Mapping[str, Any]) -> bytes:
    return json.dumps(
        payload,
        ensure_ascii=False,
        sort_keys=True,
        indent=2,
    ).encode("utf-8")


def _serialize_release(payload: Mapping[str, Any]) -> bytes:
    return json.dumps(
        payload,
        ensure_ascii=False,
        sort_keys=True,
    ).encode("utf-8")


def build_csp_artifacts(
    root: str | Path = ROOT,
    *,
    release_id: str,
    commit_sha: str | None = None,
    localized_root: str | Path | None = None,
) -> CspArtifacts:
    """Build both CSP artifacts entirely in memory without writing files."""

    repository_root = Path(root).resolve()
    if not repository_root.is_dir():
        raise CspArtifactError(f"repository root does not exist: {repository_root}")
    release_identifier = validate_release_id(release_id)
    provenance_commit_sha = (
        validate_commit_sha(commit_sha) if commit_sha is not None else None
    )
    provenance_source_digest = (
        compute_source_digest(repository_root)
        if provenance_commit_sha is not None
        else None
    )
    source_localized_root = (
        Path(localized_root).resolve()
        if localized_root is not None
        else repository_root / LOCALIZED_RELATIVE
    )

    routes_path = repository_root / ROUTES_RELATIVE
    locale_manifest_path = repository_root / LOCALE_MANIFEST_RELATIVE
    routes_raw = routes_path.read_bytes() if routes_path.is_file() else b""
    if not routes_raw:
        raise CspArtifactError(f"missing or empty routes manifest: {routes_path}")
    locale_manifest = _read_json_object(locale_manifest_path, "locale manifest")
    localization_version = locale_manifest.get("version")
    if not isinstance(localization_version, str) or not localization_version:
        raise CspArtifactError("locale manifest version must be a non-empty string")

    contract_paths = _expected_localized_paths(repository_root)
    repository_localized_root = repository_root / LOCALIZED_RELATIVE
    expected_paths = tuple(
        source_localized_root / path.relative_to(repository_localized_root)
        for path in contract_paths
    )
    _assert_exact_output_scope(source_localized_root, expected_paths)

    routes: dict[str, dict[str, Any]] = {}
    for path in expected_paths:
        entry = build_route_entry(path, source_localized_root)
        relative = str(entry["file"])
        if relative in routes:
            raise CspArtifactError(f"duplicate CSP route: {relative}")
        routes[relative] = entry
    routes = dict(sorted(routes.items()))

    release_html_sha256 = _release_html_sha256(routes)
    manifest: dict[str, Any] = {
        "algorithm": CSP_ALGORITHM,
        "localizationVersion": localization_version,
        "policyVersion": CSP_POLICY_VERSION,
        "releaseHtmlSha256": release_html_sha256,
        "releaseId": release_identifier,
        "routeCount": len(routes),
        "routeManifestSha256": _sha256_hex(routes_raw),
        "routes": routes,
    }
    if provenance_commit_sha is not None:
        manifest["commitSha"] = provenance_commit_sha
        manifest["sourceDigest"] = provenance_source_digest
    manifest_bytes = _serialize_manifest(manifest)
    release: dict[str, Any] = {
        "cspManifestSha256": _sha256_hex(manifest_bytes),
        "policyVersion": CSP_POLICY_VERSION,
        "releaseHtmlSha256": release_html_sha256,
        "releaseId": release_identifier,
        "routeCount": len(routes),
    }
    if provenance_commit_sha is not None:
        release["commitSha"] = provenance_commit_sha
        release["sourceDigest"] = provenance_source_digest
    release_bytes = _serialize_release(release)
    return CspArtifacts(
        manifest=manifest,
        manifest_bytes=manifest_bytes,
        release=release,
        release_bytes=release_bytes,
    )


def _existing_release_id(artifact_root: Path) -> str:
    manifest = _read_json_object(
        artifact_root / CSP_MANIFEST_RELATIVE, "CSP manifest"
    )
    return validate_release_id(manifest.get("releaseId"))


def _existing_commit_sha(artifact_root: Path) -> str | None:
    manifest = _read_json_object(
        artifact_root / CSP_MANIFEST_RELATIVE, "CSP manifest"
    )
    value = manifest.get("commitSha")
    return validate_commit_sha(value) if value is not None else None


def check_csp_artifacts(
    root: str | Path = ROOT,
    *,
    release_id: str | None = None,
    commit_sha: str | None = None,
    localized_root: str | Path | None = None,
    artifact_root: str | Path | None = None,
) -> CspCheckResult:
    """Rebuild expected bytes in memory and compare without touching the filesystem."""

    repository_root = Path(root).resolve()
    output_artifact_root = (
        Path(artifact_root).resolve()
        if artifact_root is not None
        else repository_root
    )
    selected_release_id = (
        validate_release_id(release_id)
        if release_id is not None
        else _existing_release_id(output_artifact_root)
    )
    selected_commit_sha = (
        validate_commit_sha(commit_sha)
        if commit_sha is not None
        else _existing_commit_sha(output_artifact_root)
    )
    artifacts = build_csp_artifacts(
        repository_root,
        release_id=selected_release_id,
        commit_sha=selected_commit_sha,
        localized_root=localized_root,
    )
    expected = (
        (output_artifact_root / CSP_MANIFEST_RELATIVE, artifacts.manifest_bytes),
        (output_artifact_root / CSP_RELEASE_RELATIVE, artifacts.release_bytes),
    )
    mismatches: list[str] = []
    for path, expected_bytes in expected:
        relative = path.relative_to(output_artifact_root)
        if not path.is_file():
            mismatches.append(f"missing artifact: {relative}")
            continue
        try:
            actual_bytes = path.read_bytes()
        except OSError as exc:
            raise CspArtifactError(f"cannot read artifact: {path}: {exc}") from exc
        if actual_bytes != expected_bytes:
            mismatches.append(f"artifact byte mismatch: {relative}")
    return CspCheckResult(artifacts=artifacts, mismatches=tuple(mismatches))


def _stage_bytes(path: Path, payload: bytes) -> Path:
    path.parent.mkdir(parents=True, exist_ok=True)
    descriptor, temporary_name = tempfile.mkstemp(
        prefix=f".{path.name}.", suffix=".tmp", dir=path.parent
    )
    temporary_path = Path(temporary_name)
    try:
        with os.fdopen(descriptor, "wb") as stream:
            os.fchmod(stream.fileno(), 0o644)
            stream.write(payload)
            stream.flush()
            os.fsync(stream.fileno())
    except BaseException:
        temporary_path.unlink(missing_ok=True)
        raise
    return temporary_path


def _restore_target(path: Path, previous: bytes | None) -> None:
    if previous is None:
        path.unlink(missing_ok=True)
        return
    staged = _stage_bytes(path, previous)
    try:
        os.replace(staged, path)
    finally:
        staged.unlink(missing_ok=True)


def write_csp_artifacts(
    artifacts: CspArtifacts, root: str | Path = ROOT
) -> None:
    """Atomically replace the pair after all generation has succeeded."""

    repository_root = Path(root).resolve()
    targets = (
        (repository_root / CSP_MANIFEST_RELATIVE, artifacts.manifest_bytes),
        (repository_root / CSP_RELEASE_RELATIVE, artifacts.release_bytes),
    )
    previous = {
        path: path.read_bytes() if path.is_file() else None for path, _ in targets
    }
    staged: dict[Path, Path] = {}
    try:
        for path, payload in targets:
            staged[path] = _stage_bytes(path, payload)
    except BaseException:
        for temporary_path in staged.values():
            temporary_path.unlink(missing_ok=True)
        raise

    try:
        for path, _ in targets:
            os.replace(staged[path], path)
    except BaseException as exc:
        rollback_errors: list[str] = []
        for path, _ in targets:
            try:
                _restore_target(path, previous[path])
            except OSError as rollback_exc:
                rollback_errors.append(f"{path}: {rollback_exc}")
        suffix = (
            "; rollback errors: " + "; ".join(rollback_errors)
            if rollback_errors
            else ""
        )
        raise CspArtifactError(f"failed to write CSP artifact pair: {exc}{suffix}") from exc
    finally:
        for temporary_path in staged.values():
            temporary_path.unlink(missing_ok=True)


def _parse_args(argv: Iterable[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Deterministically verify or write localized CSP artifacts."
    )
    parser.add_argument(
        "--root",
        default=str(ROOT),
        help="Repository root (default: inferred from this script).",
    )
    modes = parser.add_mutually_exclusive_group()
    modes.add_argument(
        "--check",
        action="store_true",
        help="Read-only verification (default).",
    )
    modes.add_argument(
        "--write",
        action="store_true",
        help="Explicitly replace both artifacts after complete in-memory generation.",
    )
    parser.add_argument(
        "--release-id",
        help="Explicit release metadata; mandatory with --write.",
    )
    parser.add_argument(
        "--commit-sha",
        help="Explicit git commit SHA provenance; mandatory with --write.",
    )
    return parser.parse_args(list(argv) if argv is not None else None)


def main(argv: Iterable[str] | None = None) -> int:
    args = _parse_args(argv)
    try:
        if args.write:
            if args.release_id is None:
                raise CspArtifactError("--write requires an explicit --release-id")
            if args.commit_sha is None:
                raise CspArtifactError("--write requires an explicit --commit-sha")
            artifacts = build_csp_artifacts(
                args.root, release_id=args.release_id, commit_sha=args.commit_sha
            )
            write_csp_artifacts(artifacts, args.root)
            print(
                "CSP_ARTIFACTS_WRITE_OK "
                f"routes={artifacts.manifest['routeCount']} "
                f"policyVersion={CSP_POLICY_VERSION} "
                f"releaseId={artifacts.manifest['releaseId']} "
                f"commitSha={artifacts.manifest['commitSha']}"
            )
            return 0

        result = check_csp_artifacts(args.root, release_id=args.release_id)
        if not result.ok:
            print("CSP_ARTIFACTS_CHECK_FAILED")
            for mismatch in result.mismatches:
                print(f"- {mismatch}")
            return 1
        print(
            "CSP_ARTIFACTS_CHECK_OK "
            f"routes={result.artifacts.manifest['routeCount']} "
            f"policyVersion={CSP_POLICY_VERSION} "
            f"releaseId={result.artifacts.manifest['releaseId']}"
        )
        return 0
    except (CspArtifactError, OSError, UnicodeError, ValueError) as exc:
        print("CSP_ARTIFACTS_FAILED")
        print(f"- {exc}")
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
