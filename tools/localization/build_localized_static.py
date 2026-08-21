#!/usr/bin/env python3
"""Build first-paint localized HTML and feature-scoped browser catalogs."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import shutil
import stat
import tempfile
from pathlib import Path
from typing import Any

from bs4 import BeautifulSoup, Comment, NavigableString

try:
    from .build_csp_artifacts import (
        CspArtifactError,
        build_csp_artifacts,
        check_csp_artifacts,
        compute_source_digest,
        validate_commit_sha,
        validate_release_id,
        write_csp_artifacts,
    )
except ImportError:  # Direct script execution.
    from build_csp_artifacts import (
        CspArtifactError,
        build_csp_artifacts,
        check_csp_artifacts,
        compute_source_digest,
        validate_commit_sha,
        validate_release_id,
        write_csp_artifacts,
    )

ROOT = Path(__file__).resolve().parents[2]
LOCALES_DIR = ROOT / "public/locales"
CHUNKS_DIR = LOCALES_DIR / "chunks"
OUTPUT_DIR = ROOT / "localized"
ATTRS = {
    "data-i18n-title": "title",
    "data-i18n-placeholder": "placeholder",
    "data-i18n-aria-label": "aria-label",
    "data-i18n-content": "content",
    "data-i18n-alt": "alt",
    "data-i18n-value": "value",
}
PARAM_RE = re.compile(r"\{([A-Za-z_][A-Za-z0-9_]*)\}")
LATIN_DIGITS = str.maketrans({
    **dict(zip("۰۱۲۳۴۵۶۷۸۹", "0123456789")),
    **dict(zip("٠١٢٣٤٥٦٧٨٩", "0123456789")),
    "٫": ".", "٬": ",", "٪": "%", "؟": "?", "،": ",", "؛": ";",
})
VISIBLE_NUMBER_ATTRS = ("alt", "title", "placeholder", "aria-label", "content", "value")


def load_json(path: Path) -> dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def route_feature(template: str, config: dict[str, Any]) -> str:
    parts = Path(template).parts
    if template == "index.html":
        feature = "landing"
    elif parts and parts[0].startswith("404"):
        feature = "common"
    else:
        feature = (parts[0] if parts else "common").replace("-", "_")
    return str(config.get("aliases", {}).get(feature, feature))


def referenced_keys(source: str, all_keys: set[str]) -> set[str]:
    # Catalog keys are stable identifiers, so exact literal presence also captures
    # lookup tables used by dynamic UI handlers without requiring a JavaScript parser.
    return {key for key in all_keys if key in source}


def format_message(message: str, params: dict[str, Any]) -> str:
    return PARAM_RE.sub(lambda m: str(params.get(m.group(1), m.group(0))), message)


def parse_params(tag) -> dict[str, Any]:
    raw = tag.get("data-i18n-params")
    if not raw:
        return {}
    try:
        parsed = json.loads(raw)
        return parsed if isinstance(parsed, dict) else {}
    except json.JSONDecodeError:
        return {}


def public_url_for_output(relative: str) -> str:
    if relative == "index.html":
        return "/"
    if relative.endswith("/index.html"):
        return "/" + relative[: -len("index.html")]
    return "/" + relative


def localized_public_url(locale: str, output_relative: str) -> str:
    route = public_url_for_output(output_relative)
    return f"/{locale}/" if route == "/" else f"/{locale}{route}"


def update_route_seo(
    soup: BeautifulSoup,
    canonical_url: str,
    alternate_urls: dict[str, str],
    fallback_locale: str,
) -> None:
    absolute = "https://veloratrade.ir" + canonical_url
    canonical = soup.find("link", rel=lambda value: value and "canonical" in value)
    if not canonical and soup.head:
        canonical = soup.new_tag("link")
        canonical["rel"] = "canonical"
        soup.head.append(canonical)
    if canonical:
        canonical["href"] = absolute
    og_url = soup.find("meta", attrs={"property": "og:url"})
    if not og_url and soup.head:
        og_url = soup.new_tag("meta")
        og_url["property"] = "og:url"
        soup.head.append(og_url)
    if og_url:
        og_url["content"] = absolute

    for link in list(soup.find_all("link", rel=lambda value: value and "alternate" in value)):
        if link.get("hreflang"):
            link.decompose()
    if soup.head:
        for locale, url in alternate_urls.items():
            alternate = soup.new_tag("link")
            alternate["rel"] = "alternate"
            alternate["hreflang"] = locale
            alternate["href"] = "https://veloratrade.ir" + url
            soup.head.append(alternate)
        default_alternate = soup.new_tag("link")
        default_alternate["rel"] = "alternate"
        default_alternate["hreflang"] = "x-default"
        default_alternate["href"] = "https://veloratrade.ir" + alternate_urls[fallback_locale]
        soup.head.append(default_alternate)

    for script in soup.find_all("script", attrs={"type": "application/ld+json"}):
        if not script.string:
            continue
        try:
            data = json.loads(script.string)
        except json.JSONDecodeError:
            continue
        if isinstance(data, dict) and "url" in data:
            data["url"] = absolute
            script.string = json.dumps(data, ensure_ascii=False, separators=(",", ":"))


def collect_page_features(template: str, feature_plan: dict[str, set[str]], config: dict[str, Any]) -> list[str]:
    always = [feature for feature in config.get("always", []) if feature in feature_plan]
    primary = route_feature(template, config)
    if primary in feature_plan and primary not in always:
        always.append(primary)
    return always


def normalize_static_numbering(soup: BeautifulSoup, numbering_system: str) -> None:
    if numbering_system != "latn":
        return
    for node in list(soup.find_all(string=True)):
        parent = node.parent.name.lower() if node.parent and node.parent.name else ""
        if isinstance(node, Comment) or parent in {"script", "style", "code", "pre"}:
            continue
        updated = str(node).translate(LATIN_DIGITS)
        if updated != str(node):
            node.replace_with(NavigableString(updated))
    for tag in soup.find_all(True):
        for attr in VISIBLE_NUMBER_ATTRS:
            value = tag.get(attr)
            if isinstance(value, str):
                tag[attr] = value.translate(LATIN_DIGITS)


def render_html(
    source: str,
    locale: str,
    direction: str,
    numbering_system: str,
    messages: dict[str, str],
    features: list[str],
    canonical_url: str,
    alternate_urls: dict[str, str],
    fallback_locale: str,
) -> str:
    soup = BeautifulSoup(source, "html.parser")
    root = soup.find("html")
    if not root:
        raise ValueError("template has no html root")
    root["lang"] = locale
    root["dir"] = direction
    root["data-velora-prelocalized"] = locale
    root["data-route-locale"] = locale
    root["data-i18n-features"] = ",".join(features)

    for tag in soup.find_all(attrs={"data-i18n": True}):
        key = tag.get("data-i18n")
        if key not in messages:
            raise KeyError(f"missing catalog key: {key}")
        tag.string = format_message(str(messages[key]), parse_params(tag))
    for marker, target in ATTRS.items():
        for tag in soup.find_all(attrs={marker: True}):
            key = tag.get(marker)
            if key not in messages:
                raise KeyError(f"missing catalog key: {key}")
            tag[target] = format_message(str(messages[key]), parse_params(tag))

    meta = soup.find("meta", attrs={"name": "content-language"})
    if meta:
        meta["content"] = locale
    elif soup.head:
        created = soup.new_tag("meta")
        created["name"] = "content-language"
        created["content"] = locale
        soup.head.append(created)
    normalize_static_numbering(soup, numbering_system)
    update_route_seo(soup, canonical_url, alternate_urls, fallback_locale)
    return str(soup)


def plan_feature_keys(
    routes: list[dict[str, Any]], all_keys: set[str], config: dict[str, Any]
) -> dict[str, set[str]]:
    usage: dict[str, set[str]] = {}
    for route in routes:
        template = ROOT / route["template"]
        if not template.is_file():
            raise FileNotFoundError(f"missing template: {route['template']}")
        feature = route_feature(route["template"], config)
        usage.setdefault(feature, set()).update(referenced_keys(template.read_text(encoding="utf-8"), all_keys))

    consumers: dict[str, set[str]] = {}
    for feature, keys in usage.items():
        for key in keys:
            consumers.setdefault(key, set()).add(feature)
    threshold = int(config.get("sharedFeatureThreshold", 2))
    shared = {key for key, features in consumers.items() if len(features) >= threshold}
    shared.update(key for key in config.get("runtimeKeys", []) if key in all_keys)
    for namespace in config.get("runtimeNamespaces", []):
        shared.update(key for key in all_keys if key == namespace or key.startswith(namespace + "."))

    error_keys = {key for key in all_keys if key == "errors" or key.startswith("errors.")}
    server_only = set(config.get("serverOnly", []))
    browser_allowed = {
        key for key in all_keys if key.split(".", 1)[0] not in server_only
    }
    shared &= browser_allowed
    error_keys &= browser_allowed

    plan: dict[str, set[str]] = {
        "common": ((shared | usage.get("common", set())) - error_keys) & browser_allowed,
        "errors": error_keys,
    }
    for feature, keys in usage.items():
        if feature == "common":
            continue
        scoped = (keys - shared - error_keys) & browser_allowed
        if scoped:
            plan[feature] = scoped
    return plan


def build_chunks(
    manifest: dict[str, Any],
    config: dict[str, Any],
    routes: list[dict[str, Any]],
    all_keys: set[str],
    catalogs: dict[str, dict[str, str]],
    *,
    chunks_dir: Path,
    feature_manifest_path: Path,
) -> tuple[dict[str, Any], dict[str, set[str]]]:
    if chunks_dir.exists():
        shutil.rmtree(chunks_dir)
    feature_plan = plan_feature_keys(routes, all_keys, config)
    feature_manifest: dict[str, Any] = {
        "version": manifest["version"],
        "basePath": manifest["featureCatalogBase"],
        "strategy": "usage-scoped",
        "locales": {},
    }
    for locale, messages in catalogs.items():
        locale_meta: dict[str, Any] = {}
        for feature, keys in sorted(feature_plan.items()):
            feature_messages = {key: messages[key] for key in keys}
            path = chunks_dir / locale / f"{feature}.json"
            path.parent.mkdir(parents=True, exist_ok=True)
            payload = {
                "locale": locale,
                "version": manifest["version"],
                "feature": feature,
                "messages": dict(sorted(feature_messages.items())),
            }
            rendered = json.dumps(payload, ensure_ascii=False, separators=(",", ":")) + "\n"
            path.write_text(rendered, encoding="utf-8")
            locale_meta[feature] = {
                "path": f"{locale}/{feature}.json",
                "messages": len(feature_messages),
                "bytes": len(rendered.encode("utf-8")),
                "sha256": hashlib.sha256(rendered.encode("utf-8")).hexdigest(),
            }
        feature_manifest["locales"][locale] = locale_meta
    feature_manifest_path.parent.mkdir(parents=True, exist_ok=True)
    feature_manifest_path.write_text(
        json.dumps(feature_manifest, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    return feature_manifest, feature_plan


_GENERATED_TARGETS = (
    Path("public/locales/chunks"),
    Path("public/locales/feature-manifest.json"),
    Path("public/locales/csp-manifest.json"),
    Path("localized"),
)


class AtomicPromotionError(RuntimeError):
    """Raised when a controlled promotion failure cannot be rolled back exactly."""


def _replace_path(source: Path, destination: Path) -> None:
    os.replace(source, destination)


def _remove_path(path: Path) -> None:
    if path.is_symlink() or path.is_file():
        path.unlink(missing_ok=True)
    elif path.is_dir():
        shutil.rmtree(path)


def _path_inventory_digest(path: Path) -> str:
    digest = hashlib.sha256()
    if not path.exists() and not path.is_symlink():
        digest.update(b"missing\0")
        return digest.hexdigest()

    entries = [path]
    if path.is_dir() and not path.is_symlink():
        entries.extend(sorted(path.rglob("*"), key=lambda item: item.as_posix()))
    for entry in entries:
        relative = "." if entry == path else entry.relative_to(path).as_posix()
        metadata = entry.lstat()
        digest.update(relative.encode("utf-8"))
        digest.update(b"\0")
        digest.update(str(stat.S_IMODE(metadata.st_mode)).encode("ascii"))
        digest.update(b"\0")
        if entry.is_symlink():
            digest.update(b"symlink\0")
            digest.update(os.readlink(entry).encode("utf-8"))
        elif entry.is_dir():
            digest.update(b"directory\0")
        elif entry.is_file():
            digest.update(b"file\0")
            digest.update(hashlib.sha256(entry.read_bytes()).digest())
        else:
            digest.update(b"other\0")
        digest.update(b"\0")
    return digest.hexdigest()


def generated_state_digest(repository_root: Path) -> str:
    digest = hashlib.sha256()
    for relative in _GENERATED_TARGETS:
        digest.update(relative.as_posix().encode("utf-8"))
        digest.update(b"\0")
        digest.update(_path_inventory_digest(repository_root / relative).encode("ascii"))
        digest.update(b"\0")
    return digest.hexdigest()


def _require_csp_check(result, label: str) -> None:
    if result.ok:
        return
    raise CspArtifactError(f"{label} failed: {'; '.join(result.mismatches)}")


def promote_staged_release(
    repository_root: Path,
    stage_root: Path,
    *,
    release_id: str,
    commit_sha: str,
) -> None:
    """Promote a complete staged release with same-process backup and rollback."""

    for relative in _GENERATED_TARGETS:
        staged = stage_root / relative
        if not staged.exists() and not staged.is_symlink():
            raise AtomicPromotionError(f"missing staged target: {relative}")

    original_digest = generated_state_digest(repository_root)
    backup_root = stage_root.parent / "backup"
    backed_up: list[Path] = []
    promoted: list[Path] = []
    try:
        for relative in _GENERATED_TARGETS:
            live = repository_root / relative
            if not live.exists() and not live.is_symlink():
                continue
            backup = backup_root / relative
            backup.parent.mkdir(parents=True, exist_ok=True)
            _replace_path(live, backup)
            backed_up.append(relative)

        for relative in _GENERATED_TARGETS:
            staged = stage_root / relative
            live = repository_root / relative
            live.parent.mkdir(parents=True, exist_ok=True)
            _replace_path(staged, live)
            promoted.append(relative)

        _require_csp_check(
            check_csp_artifacts(
                repository_root, release_id=release_id, commit_sha=commit_sha
            ),
            "post-promotion CSP check",
        )
    except BaseException as exc:
        rollback_errors: list[str] = []
        for relative in reversed(promoted):
            try:
                _remove_path(repository_root / relative)
            except OSError as rollback_exc:
                rollback_errors.append(f"remove {relative}: {rollback_exc}")
        for relative in reversed(backed_up):
            live = repository_root / relative
            backup = backup_root / relative
            try:
                _remove_path(live)
                live.parent.mkdir(parents=True, exist_ok=True)
                _replace_path(backup, live)
            except OSError as rollback_exc:
                rollback_errors.append(f"restore {relative}: {rollback_exc}")

        restored_digest = generated_state_digest(repository_root)
        if restored_digest != original_digest:
            rollback_errors.append(
                f"generated state digest mismatch: {restored_digest} != {original_digest}"
            )
        if rollback_errors:
            raise AtomicPromotionError(
                f"promotion failed ({exc}); rollback failed: "
                + "; ".join(rollback_errors)
            ) from exc
        raise


def build_staged(
    release_id: str,
    commit_sha: str,
    stage_root: Path,
) -> tuple[int, int, int, int]:
    """Render every generated target into ``stage_root`` without promoting.

    Read-only with respect to the repository: all outputs land under
    ``stage_root``, which the caller owns (a temp dir or the build transaction).
    """
    release_identifier = validate_release_id(release_id)
    commit_identifier = validate_commit_sha(commit_sha)
    manifest = load_json(LOCALES_DIR / "manifest.json")
    config = load_json(ROOT / "tools/localization/feature-map.json")
    routes = load_json(ROOT / "tools/localization/routes.json")["routes"]
    for route in routes:
        template = ROOT / route["template"]
        if not template.is_file():
            raise FileNotFoundError(f"missing template: {route['template']}")

    catalogs: dict[str, dict[str, str]] = {}
    locale_meta: dict[str, dict[str, Any]] = {}
    for locale, entry in manifest["locales"].items():
        if not entry.get("enabled", True):
            continue
        catalogs[locale] = load_json(LOCALES_DIR / f"{locale}.json")["messages"]
        locale_meta[locale] = entry
    keysets = {locale: set(messages) for locale, messages in catalogs.items()}
    if len({frozenset(keys) for keys in keysets.values()}) != 1:
        raise RuntimeError("enabled locale catalogs must have identical keys")
    all_keys = next(iter(keysets.values()))

    stage_localized = stage_root / "localized"
    stage_locales = stage_root / "public/locales"
    stage_chunks = stage_locales / "chunks"
    stage_feature_manifest = stage_locales / "feature-manifest.json"

    feature_manifest, feature_plan = build_chunks(
        manifest,
        config,
        routes,
        all_keys,
        catalogs,
        chunks_dir=stage_chunks,
        feature_manifest_path=stage_feature_manifest,
    )

    rendered_count = 0
    template_count = 0
    for route in routes:
        template = ROOT / route["template"]
        source = template.read_text(encoding="utf-8")
        features = collect_page_features(route["template"], feature_plan, config)
        missing_features = [
            feature
            for feature in features
            if any(
                feature not in feature_manifest["locales"][locale]
                for locale in catalogs
            )
        ]
        if missing_features:
            raise RuntimeError(
                f"template {route['template']} requires missing chunks: "
                f"{missing_features}"
            )
        template_count += 1
        preferred_outputs = {
            locale: route.get("localeOutputs", {}).get(
                locale, route["outputs"]
            )[0]
            for locale in catalogs
        }
        alternate_urls = {
            locale: localized_public_url(locale, preferred_output)
            for locale, preferred_output in preferred_outputs.items()
        }
        for locale, messages in catalogs.items():
            locale_outputs = list(route["outputs"]) + list(
                route.get("localeOutputs", {}).get(locale, [])
            )
            for output_relative in dict.fromkeys(locale_outputs):
                output = stage_localized / locale / output_relative
                output.parent.mkdir(parents=True, exist_ok=True)
                output.write_text(
                    render_html(
                        source,
                        locale,
                        locale_meta[locale]["direction"],
                        locale_meta[locale].get("numberingSystem", "latn"),
                        messages,
                        features,
                        alternate_urls[locale],
                        alternate_urls,
                        manifest["fallbackLocale"],
                    ),
                    encoding="utf-8",
                )
                rendered_count += 1

    csp_artifacts = build_csp_artifacts(
        ROOT,
        release_id=release_identifier,
        commit_sha=commit_identifier,
        localized_root=stage_localized,
    )
    write_csp_artifacts(csp_artifacts, stage_root)
    _require_csp_check(
        check_csp_artifacts(
            ROOT,
            release_id=release_identifier,
            commit_sha=commit_identifier,
            localized_root=stage_localized,
            artifact_root=stage_root,
        ),
        "staged CSP check",
    )

    return (
        template_count,
        rendered_count,
        sum(len(x) for x in feature_manifest["locales"].values()),
        int(csp_artifacts.manifest["routeCount"]),
    )


def build(release_id: str, commit_sha: str) -> tuple[int, int, int, int]:
    # Validate release metadata before build_chunks() or OUTPUT_DIR can mutate.
    release_identifier = validate_release_id(release_id)
    commit_identifier = validate_commit_sha(commit_sha)

    with tempfile.TemporaryDirectory(
        prefix=".velora-localization-transaction-", dir=ROOT
    ) as transaction_directory:
        stage_root = Path(transaction_directory) / "stage"
        result = build_staged(release_identifier, commit_identifier, stage_root)
        promote_staged_release(
            ROOT,
            stage_root,
            release_id=release_identifier,
            commit_sha=commit_identifier,
        )

    return result


def compare_generated_targets(repository_root: Path, stage_root: Path) -> list[str]:
    """Byte-compare every generated target between repo and a staged build."""

    mismatches: list[str] = []
    for relative in _GENERATED_TARGETS:
        live = repository_root / relative
        staged = stage_root / relative
        if not staged.exists() and not staged.is_symlink():
            mismatches.append(
                f"missing generated target in build: {relative.as_posix()}"
            )
            continue
        if _path_inventory_digest(live) != _path_inventory_digest(staged):
            mismatches.append(f"generated artifact drift: {relative.as_posix()}")
    return mismatches


def check_artifact_freshness(
    repository_root: Path = ROOT,
) -> tuple[bool, list[str]]:
    """Regenerate artifacts in a temp dir and prove they match the repo (TEST-26).

    Read-only with respect to tracked files: never writes to ``repository_root``.
    Returns ``(ok, errors)``.
    """
    errors: list[str] = []
    manifest_path = repository_root / "public/locales/csp-manifest.json"
    try:
        manifest = load_json(manifest_path)
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        return False, [f"cannot read csp-manifest.json: {exc}"]

    release_id = manifest.get("releaseId")
    recorded_sha = manifest.get("commitSha")
    recorded_digest = manifest.get("sourceDigest")

    # ── §0 provenance presence ───────────────────────────────────────────
    if not recorded_sha:
        errors.append(
            "committed csp-manifest.json is missing commitSha (provenance not recorded)"
        )
    else:
        try:
            validate_commit_sha(recorded_sha)
        except CspArtifactError as exc:
            errors.append(str(exc))
    if not recorded_digest:
        errors.append(
            "committed csp-manifest.json is missing sourceDigest (provenance not recorded)"
        )
    if release_id is None:
        errors.append("committed csp-manifest.json is missing releaseId")

    # ── §1 source freshness (fast path) ──────────────────────────────────
    if recorded_digest:
        try:
            if compute_source_digest(repository_root) != recorded_digest:
                errors.append(
                    "source changed but generated artifact is stale "
                    "(sourceDigest mismatch)"
                )
        except CspArtifactError as exc:
            return False, errors + [f"cannot compute source digest: {exc}"]

    # ── full regeneration byte-compare ───────────────────────────────────
    if release_id is not None and recorded_sha:
        try:
            with tempfile.TemporaryDirectory(
                prefix=".velora-freshness-", dir=repository_root
            ) as transaction_directory:
                stage_root = Path(transaction_directory) / "stage"
                build_staged(str(release_id), str(recorded_sha), stage_root)
                errors.extend(compare_generated_targets(repository_root, stage_root))
        except CspArtifactError as exc:
            errors.append(f"regeneration failed: {exc}")
        except (OSError, RuntimeError, ValueError, UnicodeError) as exc:
            errors.append(f"regeneration failed: {exc}")

    return (not errors), errors


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--release-id",
        required=True,
        help="Explicit CSP release identifier; never generated from the clock.",
    )
    parser.add_argument(
        "--commit-sha",
        help="Explicit git commit SHA provenance; required unless --check is used.",
    )
    parser.add_argument(
        "--check",
        action="store_true",
        help="Read-only freshness verification: rebuild in temp and compare (TEST-26).",
    )
    args = parser.parse_args()
    if not args.check and not args.commit_sha:
        parser.error("--commit-sha is required unless --check is used")
    try:
        release_id = validate_release_id(args.release_id)
    except CspArtifactError as exc:
        parser.error(str(exc))
    commit_sha = None
    if args.commit_sha:
        try:
            commit_sha = validate_commit_sha(args.commit_sha)
        except CspArtifactError as exc:
            parser.error(str(exc))

    if args.check:
        ok, errors = check_artifact_freshness(ROOT)
        if ok:
            print("ARTIFACT_FRESHNESS_OK")
            return
        print("ARTIFACT_FRESHNESS_FAILED")
        for error in errors:
            print(f"- {error}")
        raise SystemExit(1)

    templates, html_files, chunks, csp_routes = build(release_id, commit_sha)
    print(
        f"LOCALIZED_BUILD_OK templates={templates} html={html_files} "
        f"feature_chunks={chunks} csp_routes={csp_routes} "
        f"releaseId={release_id} commitSha={commit_sha}"
    )


if __name__ == "__main__":
    main()
