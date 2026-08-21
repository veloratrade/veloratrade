#!/usr/bin/env python3
"""Validate Velora localization using the shared route contract (shadow mode)."""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from collections import Counter
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Iterable, Mapping, Sequence

from bs4 import BeautifulSoup, Comment
from jsonschema import Draft202012Validator

try:
    from tools.localization.brand_policy import (
        default_token,
        display_tokens,
        localized_message_keys,
        localized_token,
        message_token,
    )
    from tools.localization.route_contract import (
        ROOT,
        RouteContract,
        RouteContractError,
        load_route_contract,
    )
except ModuleNotFoundError:  # Direct execution from tools/localization/.
    from brand_policy import (  # type: ignore[no-redef]
        default_token,
        display_tokens,
        localized_message_keys,
        localized_token,
        message_token,
    )
    from route_contract import (  # type: ignore[no-redef]
        ROOT,
        RouteContract,
        RouteContractError,
        load_route_contract,
    )

KEY_PATTERN = re.compile(r"^[a-zA-Z0-9_.-]+$")
PLACEHOLDER_PATTERN = re.compile(r"\{([a-zA-Z0-9_]+)\}")
CALL_PATTERN = re.compile(
    r"\b(?:VeloraLocale\.)?(?:t|tr|errorMessage)\(\s*"
    r"(?:[^,()'\"]+\s*,\s*)?(['\"])([a-zA-Z0-9_.-]+)\1"
)
QUOTED_KEY_PATTERN = re.compile(
    r"(['\"])([a-zA-Z][a-zA-Z0-9_-]*(?:\.[a-zA-Z0-9_-]+)+)\1"
)
PROTECTED_PUBLIC_TOKEN = re.compile(
    r"https?://[^\s<>'\"]+|\b[^\s@]+@[^\s@]+\.[^\s@]+\b|"
    r"\b(?:[A-Za-z0-9-]+\.)*(?:velora(?:trade)?)(?:\.[A-Za-z]{2,})(?:/[^\s<>'\"]*)?",
    re.IGNORECASE,
)
LATIN_BRAND = re.compile(r"velora", re.IGNORECASE)
I18N_KEY_ATTRS = {
    "data-i18n",
    "data-i18n-title",
    "data-i18n-placeholder",
    "data-i18n-aria-label",
    "data-i18n-alt",
    "data-i18n-value",
    "data-i18n-content",
}
CUSTOM_DICTIONARY_PATHS = (
    Path("tools/localization/manual-translations.json"),
    Path("tools/localization/manual-english-to-persian.json"),
)


@dataclass(frozen=True)
class OutputSetDifference:
    missing: tuple[Path, ...]
    extra: tuple[Path, ...]


@dataclass(frozen=True)
class ValidationScope:
    contract: RouteContract
    canonical_paths: tuple[Path, ...]
    expected_localized_paths: tuple[Path, ...]
    actual_localized_paths: tuple[Path, ...]
    missing_localized_paths: tuple[Path, ...]
    extra_localized_paths: tuple[Path, ...]

    @property
    def total_html(self) -> int:
        return len(self.canonical_paths) + len(self.actual_localized_paths)


@dataclass(frozen=True)
class ValidationResult:
    errors: tuple[str, ...]
    routes: int
    canonical: int
    localized: int
    locales: int
    scope_html: int
    custom_dictionary_ready: bool
    custom_dictionary_entries: int

    @property
    def ok(self) -> bool:
        return not self.errors


def _duplicate_safe_object(pairs: Sequence[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            raise ValueError(f"duplicate JSON key: {key}")
        result[key] = value
    return result


def load(path: Path) -> dict[str, Any]:
    payload = json.loads(
        path.read_text(encoding="utf-8"),
        object_pairs_hook=_duplicate_safe_object,
    )
    if not isinstance(payload, dict):
        raise ValueError(f"JSON document must be an object: {path}")
    return payload


def compare_output_sets(
    expected: Iterable[Path],
    actual: Iterable[Path],
) -> OutputSetDifference:
    expected_set = {path.resolve() for path in expected}
    actual_set = {path.resolve() for path in actual}
    return OutputSetDifference(
        missing=tuple(sorted(expected_set - actual_set, key=lambda path: path.as_posix())),
        extra=tuple(sorted(actual_set - expected_set, key=lambda path: path.as_posix())),
    )


def build_validation_scope(root: str | Path = ROOT) -> ValidationScope:
    repository_root = Path(root).resolve()
    contract = load_route_contract(repository_root)
    canonical_paths = tuple(contract.canonical_paths)
    expected_paths = tuple(contract.expected_output_paths)
    localized_root = repository_root / "localized"
    actual_paths = tuple(
        sorted(
            localized_root.rglob("*.html") if localized_root.is_dir() else (),
            key=lambda path: path.relative_to(repository_root).as_posix(),
        )
    )
    difference = compare_output_sets(expected_paths, actual_paths)
    return ValidationScope(
        contract=contract,
        canonical_paths=canonical_paths,
        expected_localized_paths=expected_paths,
        actual_localized_paths=actual_paths,
        missing_localized_paths=difference.missing,
        extra_localized_paths=difference.extra,
    )


def invalid_display_brand(
    value: str,
    policy: Mapping[str, Any],
    expected_token: str | None = None,
) -> bool:
    policy_dict = dict(policy)
    expected = expected_token or default_token(policy_dict)
    tokens = display_tokens(policy_dict)
    for token in tokens - {default_token(policy_dict)}:
        if token in value and token != expected:
            return True
    return any(match.group(0) != expected for match in LATIN_BRAND.finditer(value))


def invalid_public_brand(
    value: str,
    policy: Mapping[str, Any],
    expected_token: str | None = None,
) -> bool:
    public_copy = PROTECTED_PUBLIC_TOKEN.sub("", value)
    return invalid_display_brand(public_copy, policy, expected_token)


def _custom_dictionary_readiness(
    root: Path,
    errors: list[str],
) -> tuple[bool, int]:
    entries = 0
    for relative in CUSTOM_DICTIONARY_PATHS:
        path = root / relative
        if not path.is_file():
            errors.append(f"custom dictionary readiness: missing {relative.as_posix()}")
            continue
        try:
            payload = load(path)
        except (OSError, json.JSONDecodeError, ValueError) as exc:
            errors.append(f"custom dictionary readiness: invalid {relative.as_posix()}: {exc}")
            continue
        if any(not isinstance(key, str) or not isinstance(value, str) for key, value in payload.items()):
            errors.append(
                f"custom dictionary readiness: non-string entry in {relative.as_posix()}"
            )
            continue
        entries += len(payload)
    return (
        not any(error.startswith("custom dictionary readiness:") for error in errors),
        entries,
    )


def _localized_url(locale: str, output: str) -> str:
    if output == "index.html":
        return f"/{locale}/"
    route_url = "/" + (
        output[: -len("index.html")] if output.endswith("index.html") else output
    )
    return f"/{locale}{route_url}"


def _output_seo_contract(
    contract: RouteContract,
) -> dict[str, tuple[str, dict[str, str]]]:
    result: dict[str, tuple[str, dict[str, str]]] = {}
    for route in contract.routes:
        preferred: dict[str, str] = {}
        for locale in contract.locales:
            localized = route.locale_outputs.get(locale, ())
            preferred[locale] = (localized or route.outputs)[0]
        alternates = {
            locale: _localized_url(locale, output)
            for locale, output in preferred.items()
        }
        for locale in contract.locales:
            for output in route.outputs_for(locale):
                result[f"localized/{locale}/{output}"] = (
                    alternates[locale],
                    alternates,
                )
    return result


def generated_metadata_errors(
    source: str,
    *,
    relative: str,
    locale: str,
    expected_direction: str,
    canonical_url: str,
    alternate_urls: Mapping[str, str],
    fallback_locale: str,
) -> list[str]:
    """Validate generated lang/dir and SEO metadata without mutating HTML."""
    errors: list[str] = []
    soup = BeautifulSoup(source, "html.parser")
    root_tag = soup.find("html")
    if not root_tag or root_tag.get("lang") != locale:
        errors.append(f"localized lang mismatch: {relative}")
    if not root_tag or root_tag.get("dir") != expected_direction:
        errors.append(f"localized dir mismatch: {relative}")
    if not root_tag or root_tag.get("data-velora-prelocalized") != locale:
        errors.append(f"localized HTML marker mismatch: {relative}")

    expected_absolute = "https://veloratrade.ir" + canonical_url
    canonical = soup.find("link", rel=lambda value: value and "canonical" in value)
    og_url = soup.find("meta", attrs={"property": "og:url"})
    if not canonical or canonical.get("href") != expected_absolute:
        errors.append(f"localized canonical mismatch: {relative}")
    if not og_url or og_url.get("content") != expected_absolute:
        errors.append(f"localized og:url mismatch: {relative}")

    hreflangs = {
        link.get("hreflang"): link.get("href")
        for link in soup.find_all(
            "link", rel=lambda value: value and "alternate" in value
        )
        if link.get("hreflang")
    }
    expected_hreflangs = {
        code: "https://veloratrade.ir" + url
        for code, url in alternate_urls.items()
    }
    expected_hreflangs["x-default"] = (
        "https://veloratrade.ir" + alternate_urls.get(fallback_locale, "")
    )
    if hreflangs != expected_hreflangs:
        errors.append(f"localized hreflang mismatch: {relative}")
    return errors


def csp_linkage_errors(
    expected_paths: Iterable[Path],
    *,
    localized_root: Path,
    csp_manifest: Mapping[str, Any],
    csp_release: Mapping[str, Any],
    csp_raw: bytes,
) -> list[str]:
    """Validate CSP manifest/release linkage for expected generated outputs."""
    errors: list[str] = []
    csp_routes = csp_manifest.get("routes", {})
    if not isinstance(csp_routes, dict):
        return ["CSP route map is missing or invalid"]

    expected = {
        path.resolve().relative_to(localized_root.resolve()).as_posix(): path.resolve()
        for path in expected_paths
    }
    expected_keys = set(expected)
    actual_keys = set(csp_routes)
    for key in sorted(expected_keys - actual_keys):
        errors.append(f"missing CSP linkage: {key}")
    for key in sorted(actual_keys - expected_keys):
        errors.append(f"extra CSP linkage: {key}")

    if csp_manifest.get("routeCount") != len(expected_keys):
        errors.append("CSP route count does not match route contract")
    if csp_release.get("routeCount") != len(expected_keys):
        errors.append("CSP release route count does not match route contract")
    if not csp_raw:
        errors.append("CSP manifest bytes are missing")
    elif csp_release.get("cspManifestSha256") != hashlib.sha256(csp_raw).hexdigest():
        errors.append("CSP release manifest hash mismatch")
    for key in ("policyVersion", "releaseId", "releaseHtmlSha256", "routeCount"):
        if csp_manifest.get(key) != csp_release.get(key):
            errors.append(f"CSP manifest/release field mismatch: {key}")

    for key, path in sorted(expected.items()):
        entry = csp_routes.get(key)
        if not isinstance(entry, dict):
            continue
        if entry.get("file") != key:
            errors.append(f"CSP file linkage mismatch: {key}")
        if not path.is_file():
            errors.append(f"missing localized output for CSP linkage: {key}")
        elif entry.get("htmlSha256") != hashlib.sha256(path.read_bytes()).hexdigest():
            errors.append(f"CSP HTML hash mismatch: {key}")
    return errors


def validate_localization(root: str | Path = ROOT) -> ValidationResult:
    repository_root = Path(root).resolve()
    scope = build_validation_scope(repository_root)
    contract = scope.contract
    locales_dir = repository_root / "public/locales"
    assets_dir = repository_root / "public/assets"
    errors: list[str] = []

    manifest = load(locales_dir / "manifest.json")
    manifest_schema = load(locales_dir / "manifest.schema.json")
    catalog_schema = load(locales_dir / "catalog.schema.json")
    brand_policy = load(repository_root / "tools/localization/brand-policy.json")
    default_brand_token = default_token(brand_policy)

    for error in Draft202012Validator(manifest_schema).iter_errors(manifest):
        errors.append(f"manifest schema: {error.message}")

    enabled = list(contract.locales)
    if manifest.get("defaultLocale") not in enabled:
        errors.append("defaultLocale is not enabled")
    if manifest.get("fallbackLocale") not in enabled:
        errors.append("fallbackLocale is not enabled")
    if brand_policy.get("version") != manifest.get("version"):
        errors.append("brand policy version does not match manifest")
    if not default_brand_token:
        errors.append("brand policy defaultToken is missing")
    for policy_locale in brand_policy.get("localizedMessages", {}):
        if policy_locale not in enabled:
            errors.append(
                f"brand policy references disabled/unknown locale: {policy_locale}"
            )
        if not localized_token(brand_policy, policy_locale):
            errors.append(f"brand policy locale has no localized token: {policy_locale}")

    catalogs: dict[str, dict[str, str]] = {}
    for locale in enabled:
        path = locales_dir / f"{locale}.json"
        if not path.is_file():
            errors.append(f"missing catalog: {path.relative_to(repository_root)}")
            continue
        payload = load(path)
        for error in Draft202012Validator(catalog_schema).iter_errors(payload):
            errors.append(f"{locale} catalog schema: {error.message}")
        if payload.get("_meta", {}).get("locale") != locale:
            errors.append(f"{locale} catalog metadata locale mismatch")
        if payload.get("_meta", {}).get("version") != manifest.get("version"):
            errors.append(f"{locale} catalog version does not match manifest")
        messages = payload.get("messages", {})
        catalogs[locale] = messages if isinstance(messages, dict) else {}

    fallback_locale = str(manifest.get("fallbackLocale", ""))
    if fallback_locale not in catalogs:
        errors.append("fallback catalog could not be loaded")
        fallback_keys: set[str] = set()
    else:
        fallback_keys = set(catalogs[fallback_locale])

    for locale, messages in catalogs.items():
        keys = set(messages)
        if keys != fallback_keys:
            errors.append(
                f"{locale} keyset differs: missing={sorted(fallback_keys - keys)[:10]} "
                f"extra={sorted(keys - fallback_keys)[:10]}"
            )
        for key, message in messages.items():
            if not isinstance(message, str):
                errors.append(f"catalog message is not a string: {locale}/{key}")
                continue
            if not KEY_PATTERN.fullmatch(key):
                errors.append(f"invalid key syntax in {locale}: {key}")
            fallback_message = catalogs.get(fallback_locale, {}).get(key, "")
            if Counter(PLACEHOLDER_PATTERN.findall(message)) != Counter(
                PLACEHOLDER_PATTERN.findall(str(fallback_message))
            ):
                errors.append(f"placeholder mismatch for {key}: {locale} vs fallback")
            expected_brand = message_token(brand_policy, locale, key)
            if expected_brand != default_brand_token and expected_brand not in message:
                errors.append(
                    f"localized brand token missing in {locale} catalog: {key}"
                )
            if invalid_display_brand(message, brand_policy, expected_brand):
                errors.append(
                    f"brand policy violation in {locale} catalog: {key} "
                    f"(expected token {expected_brand!r})"
                )

    for policy_locale in brand_policy.get("localizedMessages", {}):
        for key in sorted(localized_message_keys(brand_policy, policy_locale)):
            if key not in fallback_keys:
                errors.append(
                    f"brand policy references missing catalog key: {policy_locale}/{key}"
                )

    custom_ready, custom_entries = _custom_dictionary_readiness(
        repository_root, errors
    )

    references: dict[str, set[str]] = {}

    def record(key: str, source: Path) -> None:
        if key.endswith("."):
            return
        references.setdefault(key, set()).add(
            source.relative_to(repository_root).as_posix()
        )

    shared_assets = {
        "/public/assets/velora-locale-registry.js",
        "/public/assets/velora-locale-bootstrap.js",
        "/public/assets/velora-localization.css",
        "/public/assets/velora-localization.js",
        "/public/assets/velora-data.js",
        "/public/assets/velora-dynamic-content.js",
    }
    expected_asset_suffix = f"?v={manifest['version']}"

    def scan_html(path: Path, *, locale: str | None, record_keys: bool) -> None:
        source = path.read_text(encoding="utf-8", errors="ignore")
        soup = BeautifulSoup(source, "html.parser")
        for tag in soup.find_all(True):
            for attribute, value in tag.attrs.items():
                if (
                    record_keys
                    and attribute in I18N_KEY_ATTRS
                    and isinstance(value, str)
                    and value
                ):
                    if not KEY_PATTERN.fullmatch(value):
                        errors.append(
                            f"invalid i18n key in {path.relative_to(repository_root)}: {value}"
                        )
                    record(value, path)
                if attribute in {"src", "href"} and isinstance(value, str):
                    asset_path = value.split("?", 1)[0]
                    if (
                        asset_path in shared_assets
                        and value != asset_path + expected_asset_suffix
                    ):
                        errors.append(
                            "stale or missing asset version in "
                            f"{path.relative_to(repository_root)}: {value}"
                        )
                if (
                    attribute
                    in {"alt", "title", "placeholder", "aria-label", "content", "value"}
                    and isinstance(value, str)
                ):
                    attr_key = tag.get("data-i18n-" + attribute)
                    expected_brand = message_token(
                        brand_policy,
                        locale,
                        attr_key if isinstance(attr_key, str) else None,
                    )
                    if invalid_public_brand(value, brand_policy, expected_brand):
                        errors.append(
                            "brand policy violation in "
                            f"{path.relative_to(repository_root)} attribute {attribute}"
                        )
        for node in soup.find_all(string=True):
            parent = node.parent.name.lower() if node.parent and node.parent.name else ""
            if isinstance(node, Comment) or parent in {"script", "style", "code", "pre"}:
                continue
            marker = node.parent
            text_key = None
            while marker and getattr(marker, "name", None):
                candidate = marker.get("data-i18n")
                if isinstance(candidate, str) and candidate:
                    text_key = candidate
                    break
                marker = marker.parent
            expected_brand = message_token(brand_policy, locale, text_key)
            if invalid_public_brand(str(node), brand_policy, expected_brand):
                errors.append(
                    f"brand policy violation in visible text: "
                    f"{path.relative_to(repository_root)}"
                )
                break
        for script in soup.find_all("script", attrs={"type": "application/ld+json"}):
            if script.string and invalid_public_brand(
                script.string, brand_policy, default_brand_token
            ):
                errors.append(
                    f"brand policy violation in JSON-LD: "
                    f"{path.relative_to(repository_root)}"
                )
        if record_keys:
            for match in CALL_PATTERN.finditer(source):
                record(match.group(2), path)
        if soup.select_one("script[data-velora-i18n]") is not None:
            errors.append(
                f"legacy source-text translation map remains in "
                f"{path.relative_to(repository_root)}"
            )

    # Canonical HTML is the only source-template scope.
    for path in scope.canonical_paths:
        scan_html(path, locale=None, record_keys=True)

    source_paths = (
        [
            path
            for path in sorted(assets_dir.glob("*.js"))
            if path.name != "velora-locale-registry.js"
        ]
        + sorted((repository_root / "api/src").rglob("*.php"))
        + sorted((repository_root / "api/workers").rglob("*.php"))
        + [repository_root / "api/index.php"]
    )
    known_prefixes = {key.split(".", 1)[0] for key in fallback_keys}
    for path in source_paths:
        if not path.is_file():
            continue
        source = path.read_text(encoding="utf-8", errors="ignore")
        for match in CALL_PATTERN.finditer(source):
            record(match.group(2), path)
        for match in QUOTED_KEY_PATTERN.finditer(source):
            key = match.group(2)
            line_start = source.rfind("\n", 0, match.start()) + 1
            line_end = source.find("\n", match.end())
            line = source[line_start: None if line_end == -1 else line_end]
            if "Config::get" in line:
                continue
            if key.split(".", 1)[0] in known_prefixes:
                record(key, path)

    for key in sorted(key for key in references if key not in fallback_keys):
        errors.append(
            f"missing key {key}, referenced by "
            f"{', '.join(sorted(references[key]))}"
        )

    registry_path = assets_dir / "velora-locale-registry.js"
    registry = registry_path.read_text(encoding="utf-8")
    if json.dumps(manifest["version"]) not in registry:
        errors.append("generated locale registry version does not match manifest")
    if (
        "__VELORA_PRELOADED_CATALOGS__" in registry
        or registry_path.stat().st_size > 10_000
    ):
        errors.append("locale registry must not embed full catalogs")

    # F-05: every repository asset must be referenced with exactly one
    # cache-busting version across all canonical templates. Individual assets
    # may keep their own intentional version; only cross-page drift of the
    # same asset (stale cached copies for some routes) is an error.
    asset_versions: dict[str, dict[str, set[str]]] = {}
    asset_reference = re.compile(
        r"/public/assets/([A-Za-z0-9._-]+\.(?:js|css))\?v=([0-9A-Za-z._-]+)"
    )
    for path in scope.canonical_paths:
        source = path.read_text(encoding="utf-8", errors="ignore")
        for match in asset_reference.finditer(source):
            asset_versions.setdefault(match.group(1), {}).setdefault(
                match.group(2), set()
            ).add(path.relative_to(repository_root).as_posix())
    for asset, versions in sorted(asset_versions.items()):
        if len(versions) > 1:
            spread = ", ".join(
                f"{version} ({len(pages)} pages)"
                for version, pages in sorted(versions.items())
            )
            errors.append(
                f"inconsistent cache-busting version for {asset}: {spread}"
            )

    feature_manifest_path = locales_dir / "feature-manifest.json"
    chunk_keys: dict[str, dict[str, set[str]]] = {
        locale: {} for locale in enabled
    }
    if not feature_manifest_path.is_file():
        errors.append("feature catalog manifest is missing")
    else:
        feature_manifest = load(feature_manifest_path)
        if feature_manifest.get("version") != manifest["version"]:
            errors.append("feature catalog manifest version mismatch")
        if feature_manifest.get("strategy") != "usage-scoped":
            errors.append("feature catalog strategy must be usage-scoped")
        for locale in enabled:
            locale_features = feature_manifest.get("locales", {}).get(locale, {})
            for feature, metadata in locale_features.items():
                chunk = locales_dir / "chunks" / metadata.get("path", "")
                if not chunk.is_file():
                    errors.append(f"missing feature chunk: {locale}/{feature}")
                    continue
                rendered = chunk.read_bytes()
                payload = json.loads(rendered)
                messages = payload.get("messages", {})
                chunk_keys[locale][feature] = set(messages)
                if payload.get("locale") != locale or payload.get("feature") != feature:
                    errors.append(f"feature chunk identity mismatch: {locale}/{feature}")
                if payload.get("version") != manifest["version"]:
                    errors.append(f"feature chunk version mismatch: {locale}/{feature}")
                if (
                    metadata.get("messages") != len(messages)
                    or metadata.get("bytes") != len(rendered)
                ):
                    errors.append(f"feature chunk metadata mismatch: {locale}/{feature}")
                if metadata.get("sha256") != hashlib.sha256(rendered).hexdigest():
                    errors.append(f"feature chunk checksum mismatch: {locale}/{feature}")
                for key, value in messages.items():
                    if catalogs[locale].get(key) != value:
                        errors.append(
                            f"feature chunk catalog mismatch: {locale}/{feature}/{key}"
                        )
            if locale != enabled[0]:
                expected_features = set(chunk_keys[enabled[0]])
                if set(chunk_keys[locale]) != expected_features:
                    errors.append(
                        f"feature chunk set differs across locales: {locale}"
                    )
                for feature in expected_features & set(chunk_keys[locale]):
                    if (
                        chunk_keys[locale][feature]
                        != chunk_keys[enabled[0]][feature]
                    ):
                        errors.append(
                            f"feature keyset differs across locales: {locale}/{feature}"
                        )

    for path in scope.missing_localized_paths:
        errors.append(
            f"missing localized output: {path.relative_to(repository_root).as_posix()}"
        )
    for path in scope.extra_localized_paths:
        errors.append(
            f"extra localized output: {path.relative_to(repository_root).as_posix()}"
        )

    output_seo = _output_seo_contract(contract)
    feature_config = load(repository_root / "tools/localization/feature-map.json")
    server_only_namespaces = set(feature_config.get("serverOnly", []))
    localized_root = repository_root / "localized"

    csp_path = locales_dir / "csp-manifest.json"
    csp_release_path = localized_root / ".csp-release.json"
    csp_raw = csp_path.read_bytes() if csp_path.is_file() else b""
    csp_manifest = load(csp_path) if csp_raw else {}
    csp_release = load(csp_release_path) if csp_release_path.is_file() else {}
    errors.extend(
        csp_linkage_errors(
            scope.expected_localized_paths,
            localized_root=localized_root,
            csp_manifest=csp_manifest,
            csp_release=csp_release,
            csp_raw=csp_raw,
        )
    )

    for path in scope.actual_localized_paths:
        relative = path.relative_to(repository_root).as_posix()
        if relative not in contract.output_to_template:
            continue
        relative_parts = path.relative_to(localized_root).parts
        locale = relative_parts[0]
        source = path.read_text(encoding="utf-8", errors="ignore")
        localized_soup = BeautifulSoup(source, "html.parser")
        root_tag = localized_soup.find("html")
        expected_direction = str(
            manifest.get("locales", {}).get(locale, {}).get("direction", "")
        )
        canonical_url, alternate_urls = output_seo.get(relative, ("", {}))
        errors.extend(
            generated_metadata_errors(
                source,
                relative=relative,
                locale=locale,
                expected_direction=expected_direction,
                canonical_url=canonical_url,
                alternate_urls=alternate_urls,
                fallback_locale=fallback_locale,
            )
        )

        scan_html(path, locale=locale, record_keys=False)
        declared_features = [
            feature
            for feature in str(
                root_tag.get("data-i18n-features", "") if root_tag else ""
            ).split(",")
            if feature
        ]
        if not declared_features:
            errors.append(f"localized HTML feature declaration missing: {relative}")
        available_keys: set[str] = set()
        for feature in declared_features:
            if feature not in chunk_keys.get(locale, {}):
                errors.append(
                    f"localized HTML declares missing chunk: {relative}:{feature}"
                )
            available_keys.update(chunk_keys.get(locale, {}).get(feature, set()))

        template_relative = contract.output_to_template[relative]
        template_path = repository_root / template_relative
        template_source = template_path.read_text(encoding="utf-8", errors="ignore")
        required_keys = {
            key
            for key in fallback_keys
            if key in template_source
            and key.split(".", 1)[0] not in server_only_namespaces
        }
        unavailable = sorted(required_keys - available_keys)
        if unavailable:
            errors.append(
                f"localized HTML catalog coverage missing: {relative}:{unavailable[:10]}"
            )

        if manifest.get("locales", {}).get(locale, {}).get("script") == "Latn":
            for node in localized_soup.find_all(string=True):
                parent = (
                    node.parent.name.lower()
                    if node.parent and node.parent.name
                    else ""
                )
                if isinstance(node, Comment) or parent in {
                    "script",
                    "style",
                    "code",
                    "pre",
                }:
                    continue
                if re.search(r"[\u0600-\u06ff]", str(node)):
                    errors.append(f"non-Latin static copy in Latin locale: {relative}")
                    break

    # Locale branches are checked only in canonical HTML source templates.
    locale_codes = "|".join(re.escape(code) for code in enabled)
    branch_pattern = re.compile(
        rf"\b(?:locale|language)\b[^\n;]{{0,80}}(?:===|!==|==|!=)"
        rf"[^\n;]{{0,20}}['\"](?:{locale_codes})['\"]",
        re.IGNORECASE,
    )
    for path in scope.canonical_paths:
        for line_number, line in enumerate(
            path.read_text(encoding="utf-8", errors="ignore").splitlines(), 1
        ):
            if branch_pattern.search(line):
                errors.append(
                    f"hard-coded locale branch: "
                    f"{path.relative_to(repository_root)}:{line_number}"
                )

    # Shared JS assets must not compare the runtime locale against bare
    # locale codes: <html lang> carries regional tags (fa-IR, en-GB), so an
    # exact comparison such as documentElement.lang === 'en' silently breaks
    # on regional variants. Only comparison operators are matched, so files
    # that intentionally SET the locale (locale bootstrap/localization
    # runtime assignments like root.lang = ...) can never trigger this rule.
    asset_branch_pattern = re.compile(
        rf"(?:\b\w*(?:locale|language)\b|\blang\b)[^\n;]{{0,80}}(?:===|!==|==|!=)"
        rf"[^\n;]{{0,20}}['\"](?:{locale_codes})['\"]",
        re.IGNORECASE,
    )
    assets_root = repository_root / "public" / "assets"
    for path in sorted(assets_root.glob("*.js")):
        for line_number, line in enumerate(
            path.read_text(encoding="utf-8", errors="ignore").splitlines(), 1
        ):
            if asset_branch_pattern.search(line):
                errors.append(
                    f"hard-coded locale branch in shared asset: "
                    f"{path.relative_to(repository_root)}:{line_number}"
                )

    return ValidationResult(
        errors=tuple(errors),
        routes=len(contract.routes),
        canonical=len(scope.canonical_paths),
        localized=len(scope.actual_localized_paths),
        locales=len(contract.locales),
        scope_html=scope.total_html,
        custom_dictionary_ready=custom_ready,
        custom_dictionary_entries=custom_entries,
    )


def _parse_args(argv: Iterable[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Validate canonical routes and generated localization outputs."
    )
    parser.add_argument(
        "--root",
        default=str(ROOT),
        help="Repository root (default: inferred from route_contract.py).",
    )
    return parser.parse_args(argv)


def main(argv: Iterable[str] | None = None) -> int:
    args = _parse_args(argv)
    try:
        result = validate_localization(args.root)
    except (RouteContractError, OSError, UnicodeError, ValueError) as exc:
        print("LOCALIZATION_VALIDATION_FAILED")
        print(f"- route contract or validation setup: {exc}")
        return 2

    if result.errors:
        print("LOCALIZATION_VALIDATION_FAILED")
        for error in result.errors:
            print(f"- {error}")
        return 1

    print("LOCALIZATION_VALIDATION_OK")
    print(f"routes={result.routes}")
    print(f"canonical={result.canonical}")
    print(f"localized={result.localized}")
    print(f"locales={result.locales}")
    print("issues=0")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
