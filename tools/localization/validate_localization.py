#!/usr/bin/env python3
"""Validate Velora's manifest, catalogs, references and anti-regression rules."""
from __future__ import annotations

import hashlib
import json
import re
import sys
from pathlib import Path

from bs4 import BeautifulSoup, Comment
from jsonschema import Draft202012Validator

from brand_policy import (
    default_token,
    display_tokens,
    load_brand_policy,
    localized_message_keys,
    localized_token,
    message_token,
)

ROOT = Path(__file__).resolve().parents[2]
LOCALES = ROOT / "public" / "locales"
ASSETS = ROOT / "public" / "assets"
KEY_PATTERN = re.compile(r"^[a-zA-Z0-9_.-]+$")
PLACEHOLDER_PATTERN = re.compile(r"\{([a-zA-Z0-9_]+)\}")
CALL_PATTERN = re.compile(
    # Supports direct global calls, plus wrappers with a non-string locale/context
    # argument. Two-string helper calls such as t('Persian copy', 'English copy')
    # are literal copy pairs, not catalog-key references.
    r"\b(?:VeloraLocale\.)?(?:t|tr|errorMessage)\(\s*(?:[^,()'\"]+\s*,\s*)?(['\"])([a-zA-Z0-9_.-]+)\1"
)
QUOTED_KEY_PATTERN = re.compile(r"(['\"])([a-zA-Z][a-zA-Z0-9_-]*(?:\.[a-zA-Z0-9_-]+)+)\1")
PROTECTED_PUBLIC_TOKEN = re.compile(
    r"https?://[^\s<>'\"]+|\b[^\s@]+@[^\s@]+\.[^\s@]+\b|"
    r"\b(?:[A-Za-z0-9-]+\.)*(?:velora(?:trade)?)(?:\.[A-Za-z]{2,})(?:/[^\s<>'\"]*)?",
    re.IGNORECASE,
)
LATIN_BRAND = re.compile(r"velora", re.IGNORECASE)
BRAND_POLICY = load_brand_policy()
DEFAULT_BRAND_TOKEN = default_token(BRAND_POLICY)
DISPLAY_BRAND_TOKENS = display_tokens(BRAND_POLICY)
I18N_KEY_ATTRS = {
    "data-i18n", "data-i18n-title", "data-i18n-placeholder", "data-i18n-aria-label",
    "data-i18n-alt", "data-i18n-value", "data-i18n-content",
}


def invalid_display_brand(value: str, expected_token: str = DEFAULT_BRAND_TOKEN) -> bool:
    """Reject every recognized display-brand token except the policy token for this message."""
    for token in DISPLAY_BRAND_TOKENS - {DEFAULT_BRAND_TOKEN}:
        if token in value and token != expected_token:
            return True
    return any(match.group(0) != expected_token for match in LATIN_BRAND.finditer(value))


def invalid_public_brand(value: str, expected_token: str = DEFAULT_BRAND_TOKEN) -> bool:
    public_copy = PROTECTED_PUBLIC_TOKEN.sub("", value)
    return invalid_display_brand(public_copy, expected_token)


def load(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def main() -> int:
    errors: list[str] = []
    manifest = load(LOCALES / "manifest.json")
    manifest_schema = load(LOCALES / "manifest.schema.json")
    catalog_schema = load(LOCALES / "catalog.schema.json")

    for error in Draft202012Validator(manifest_schema).iter_errors(manifest):
        errors.append(f"manifest schema: {error.message}")

    enabled = [code for code, meta in manifest["locales"].items() if meta.get("enabled", True)]
    if manifest["defaultLocale"] not in enabled:
        errors.append("defaultLocale is not enabled")
    if manifest["fallbackLocale"] not in enabled:
        errors.append("fallbackLocale is not enabled")
    if BRAND_POLICY.get("version") != manifest.get("version"):
        errors.append("brand policy version does not match manifest")
    if not DEFAULT_BRAND_TOKEN:
        errors.append("brand policy defaultToken is missing")
    for policy_locale in BRAND_POLICY.get("localizedMessages", {}):
        if policy_locale not in enabled:
            errors.append(f"brand policy references disabled/unknown locale: {policy_locale}")
        if not localized_token(BRAND_POLICY, policy_locale):
            errors.append(f"brand policy locale has no localized token: {policy_locale}")

    catalogs: dict[str, dict[str, str]] = {}
    for locale in enabled:
        path = LOCALES / f"{locale}.json"
        if not path.is_file():
            errors.append(f"missing catalog: {path.relative_to(ROOT)}")
            continue
        payload = load(path)
        for error in Draft202012Validator(catalog_schema).iter_errors(payload):
            errors.append(f"{locale} catalog schema: {error.message}")
        if payload.get("_meta", {}).get("locale") != locale:
            errors.append(f"{locale} catalog metadata locale mismatch")
        if payload.get("_meta", {}).get("version") != manifest["version"]:
            errors.append(f"{locale} catalog version does not match manifest")
        catalogs[locale] = payload.get("messages", {})

    if manifest["fallbackLocale"] not in catalogs:
        errors.append("fallback catalog could not be loaded")
        fallback_keys: set[str] = set()
    else:
        fallback_keys = set(catalogs[manifest["fallbackLocale"]])

    for locale, messages in catalogs.items():
        keys = set(messages)
        if keys != fallback_keys:
            errors.append(
                f"{locale} keyset differs: missing={sorted(fallback_keys - keys)[:10]} "
                f"extra={sorted(keys - fallback_keys)[:10]}"
            )
        for key, message in messages.items():
            if not KEY_PATTERN.fullmatch(key):
                errors.append(f"invalid key syntax in {locale}: {key}")
            fallback_message = catalogs.get(manifest["fallbackLocale"], {}).get(key, "")
            if set(PLACEHOLDER_PATTERN.findall(message)) != set(PLACEHOLDER_PATTERN.findall(fallback_message)):
                errors.append(f"placeholder mismatch for {key}: {locale} vs fallback")
            expected_brand = message_token(BRAND_POLICY, locale, key)
            if expected_brand != DEFAULT_BRAND_TOKEN and expected_brand not in message:
                errors.append(f"localized brand token missing in {locale} catalog: {key}")
            if invalid_display_brand(message, expected_brand):
                errors.append(
                    f"brand policy violation in {locale} catalog: {key} "
                    f"(expected token {expected_brand!r})"
                )

    for policy_locale in BRAND_POLICY.get("localizedMessages", {}):
        for key in sorted(localized_message_keys(BRAND_POLICY, policy_locale)):
            if key not in fallback_keys:
                errors.append(f"brand policy references missing catalog key: {policy_locale}/{key}")

    references: dict[str, set[str]] = {}

    def record(key: str, source: Path) -> None:
        if key.endswith("."):
            return
        references.setdefault(key, set()).add(str(source.relative_to(ROOT)))

    shared_assets = {
        "/public/assets/velora-locale-registry.js",
        "/public/assets/velora-locale-bootstrap.js",
        "/public/assets/velora-localization.css",
        "/public/assets/velora-localization.js",
        "/public/assets/velora-data.js",
        "/public/assets/velora-dynamic-content.js",
    }
    expected_asset_suffix = f"?v={manifest['version']}"
    html_paths = sorted(ROOT.rglob("*.html"))
    for path in html_paths:
        text = path.read_text(encoding="utf-8", errors="ignore")
        soup = BeautifulSoup(text, "html.parser")
        try:
            localized_relative = path.relative_to(ROOT / "localized")
            html_locale = localized_relative.parts[0] if localized_relative.parts else None
        except ValueError:
            html_locale = None
        if html_locale not in enabled:
            html_locale = None
        for tag in soup.find_all(True):
            for attr, value in tag.attrs.items():
                if attr in I18N_KEY_ATTRS and isinstance(value, str) and value:
                    record(value, path)
                if attr in {"src", "href"} and isinstance(value, str):
                    asset_path = value.split("?", 1)[0]
                    if asset_path in shared_assets and value != asset_path + expected_asset_suffix:
                        errors.append(
                            f"stale or missing asset version in {path.relative_to(ROOT)}: {value}"
                        )
                if attr in {"alt", "title", "placeholder", "aria-label", "content", "value"} and isinstance(value, str):
                    attr_key = tag.get("data-i18n-" + attr)
                    expected_brand = message_token(
                        BRAND_POLICY,
                        html_locale,
                        attr_key if isinstance(attr_key, str) else None,
                    )
                    if invalid_public_brand(value, expected_brand):
                        errors.append(f"brand policy violation in {path.relative_to(ROOT)} attribute {attr}")
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
            expected_brand = message_token(BRAND_POLICY, html_locale, text_key)
            if invalid_public_brand(str(node), expected_brand):
                errors.append(f"brand policy violation in visible text: {path.relative_to(ROOT)}")
                break
        for script in soup.find_all("script", attrs={"type": "application/ld+json"}):
            if script.string and invalid_public_brand(script.string):
                errors.append(f"brand policy violation in JSON-LD: {path.relative_to(ROOT)}")
        for match in CALL_PATTERN.finditer(text):
            record(match.group(2), path)
        if soup.select_one("script[data-velora-i18n]") is not None:
            errors.append(f"legacy source-text translation map remains in {path.relative_to(ROOT)}")

    source_paths = (
        [path for path in sorted(ASSETS.glob("*.js")) if path.name != "velora-locale-registry.js"]
        + sorted((ROOT / "api" / "src").rglob("*.php"))
        + sorted((ROOT / "api" / "workers").rglob("*.php"))
        + [ROOT / "api" / "index.php"]
    )
    known_prefixes = {key.split(".", 1)[0] for key in fallback_keys}
    for path in source_paths:
        text = path.read_text(encoding="utf-8", errors="ignore")
        for match in CALL_PATTERN.finditer(text):
            record(match.group(2), path)
        # PHP and generated HTML frequently pass message keys through wrappers;
        # a quoted token in a known catalog namespace is a static reference.
        for match in QUOTED_KEY_PATTERN.finditer(text):
            key = match.group(2)
            line_start = text.rfind("\n", 0, match.start()) + 1
            line_end = text.find("\n", match.end())
            line = text[line_start: None if line_end == -1 else line_end]
            if "Config::get" in line:
                continue
            if key.split(".", 1)[0] in known_prefixes:
                record(key, path)

    missing = sorted(key for key in references if key not in fallback_keys)
    for key in missing:
        errors.append(f"missing key {key}, referenced by {', '.join(sorted(references[key]))}")

    legacy_asset = ASSETS / "velora-i18n.js"
    if legacy_asset.exists():
        errors.append("legacy runtime public/assets/velora-i18n.js must not exist")

    registry_path = ASSETS / "velora-locale-registry.js"
    registry = registry_path.read_text(encoding="utf-8")
    if json.dumps(manifest["version"]) not in registry:
        errors.append("generated locale registry version does not match manifest")
    if "__VELORA_PRELOADED_CATALOGS__" in registry or registry_path.stat().st_size > 10_000:
        errors.append("locale registry must not embed full catalogs")

    feature_manifest_path = LOCALES / "feature-manifest.json"
    feature_manifest: dict = {}
    chunk_keys: dict[str, dict[str, set[str]]] = {locale: {} for locale in enabled}
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
                chunk = LOCALES / "chunks" / metadata.get("path", "")
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
                if metadata.get("messages") != len(messages) or metadata.get("bytes") != len(rendered):
                    errors.append(f"feature chunk metadata mismatch: {locale}/{feature}")
                if metadata.get("sha256") != hashlib.sha256(rendered).hexdigest():
                    errors.append(f"feature chunk checksum mismatch: {locale}/{feature}")
                for key, value in messages.items():
                    if catalogs[locale].get(key) != value:
                        errors.append(f"feature chunk catalog mismatch: {locale}/{feature}/{key}")
            if locale != enabled[0]:
                expected_features = set(chunk_keys[enabled[0]])
                if set(chunk_keys[locale]) != expected_features:
                    errors.append(f"feature chunk set differs across locales: {locale}")
                for feature in expected_features & set(chunk_keys[locale]):
                    if chunk_keys[locale][feature] != chunk_keys[enabled[0]][feature]:
                        errors.append(f"feature keyset differs across locales: {locale}/{feature}")

    localized_root = ROOT / "localized"
    route_manifest = load(ROOT / "tools/localization/routes.json")
    expected_outputs = sum(
        len(set(route["outputs"]) | set(route.get("localeOutputs", {}).get(locale, [])))
        for route in route_manifest["routes"]
        for locale in enabled
    )
    output_templates = {
        (locale, output): ROOT / route["template"]
        for route in route_manifest["routes"]
        for locale in enabled
        for output in set(route["outputs"]) | set(route.get("localeOutputs", {}).get(locale, []))
    }

    def localized_url(locale: str, output: str) -> str:
        if output == "index.html":
            return f"/{locale}/"
        route_url = "/" + (output[:-len("index.html")] if output.endswith("/index.html") else output)
        return f"/{locale}{route_url}"

    output_seo: dict[tuple[str, str], tuple[str, dict[str, str]]] = {}
    for route in route_manifest["routes"]:
        preferred = {
            locale: route.get("localeOutputs", {}).get(locale, route["outputs"])[0]
            for locale in enabled
        }
        alternates = {locale: localized_url(locale, output) for locale, output in preferred.items()}
        for locale in enabled:
            for output in set(route["outputs"]) | set(route.get("localeOutputs", {}).get(locale, [])):
                output_seo[(locale, output)] = (alternates[locale], alternates)

    feature_config = load(ROOT / "tools/localization/feature-map.json")
    server_only_namespaces = set(feature_config.get("serverOnly", []))
    localized_paths = sorted(localized_root.rglob("*.html")) if localized_root.is_dir() else []
    if len(localized_paths) != expected_outputs:
        errors.append(f"localized HTML count mismatch: expected={expected_outputs} actual={len(localized_paths)}")
    for path in localized_paths:
        relative_parts = path.relative_to(localized_root).parts
        locale = relative_parts[0]
        output_relative = Path(*relative_parts[1:]).as_posix()
        source = path.read_text(encoding="utf-8", errors="ignore")
        localized_soup = BeautifulSoup(source, "html.parser")
        canonical_url, alternate_urls = output_seo.get((locale, output_relative), ("", {}))
        canonical = localized_soup.find("link", rel=lambda value: value and "canonical" in value)
        og_url = localized_soup.find("meta", attrs={"property": "og:url"})
        if not canonical or canonical.get("href") != "https://veloratrade.ir" + canonical_url:
            errors.append(f"localized canonical mismatch: {path.relative_to(ROOT)}")
        if not og_url or og_url.get("content") != "https://veloratrade.ir" + canonical_url:
            errors.append(f"localized og:url mismatch: {path.relative_to(ROOT)}")
        hreflangs = {
            link.get("hreflang"): link.get("href")
            for link in localized_soup.find_all("link", rel=lambda value: value and "alternate" in value)
            if link.get("hreflang")
        }
        expected_hreflangs = {
            code: "https://veloratrade.ir" + url for code, url in alternate_urls.items()
        }
        expected_hreflangs["x-default"] = "https://veloratrade.ir" + alternate_urls.get(manifest["fallbackLocale"], "")
        if hreflangs != expected_hreflangs:
            errors.append(f"localized hreflang mismatch: {path.relative_to(ROOT)}")
        if f'data-velora-prelocalized="{locale}"' not in source:
            errors.append(f"localized HTML marker mismatch: {path.relative_to(ROOT)}")
        root_tag = localized_soup.find("html")
        declared_features = [
            feature for feature in str(root_tag.get("data-i18n-features", "") if root_tag else "").split(",") if feature
        ]
        if not declared_features:
            errors.append(f"localized HTML feature declaration missing: {path.relative_to(ROOT)}")
        available_keys: set[str] = set()
        for feature in declared_features:
            if feature not in chunk_keys.get(locale, {}):
                errors.append(f"localized HTML declares missing chunk: {path.relative_to(ROOT)}:{feature}")
            available_keys.update(chunk_keys.get(locale, {}).get(feature, set()))
        template_path = output_templates.get((locale, output_relative))
        if template_path:
            template_source = template_path.read_text(encoding="utf-8", errors="ignore")
            required_keys = {
                key for key in fallback_keys
                if key in template_source and key.split(".", 1)[0] not in server_only_namespaces
            }
            unavailable = sorted(required_keys - available_keys)
            if unavailable:
                errors.append(
                    f"localized HTML catalog coverage missing: {path.relative_to(ROOT)}:{unavailable[:10]}"
                )
        else:
            errors.append(f"localized output not declared by route manifest: {path.relative_to(ROOT)}")
        if manifest["locales"].get(locale, {}).get("script") == "Latn":
            for node in localized_soup.find_all(string=True):
                parent = node.parent.name.lower() if node.parent and node.parent.name else ""
                if isinstance(node, Comment) or parent in {"script", "style", "code", "pre"}:
                    continue
                if re.search(r"[\u0600-\u06ff]", str(node)):
                    errors.append(f"non-Latin static copy in Latin locale: {path.relative_to(ROOT)}")
                    break

    # Locale-specific branches are an architecture regression. Locale metadata,
    # registry generation and locale tooling are excluded by design.
    locale_codes = "|".join(re.escape(code) for code in enabled)
    branch_pattern = re.compile(
        rf"\b(?:locale|language)\b[^\n;]{{0,80}}(?:===|!==|==|!=)[^\n;]{{0,20}}['\"](?:{locale_codes})['\"]",
        re.IGNORECASE,
    )
    branch_paths = html_paths + sorted(ASSETS.glob("*.js")) + sorted((ROOT / "api" / "src").rglob("*.php"))
    for path in branch_paths:
        if path.name == "velora-locale-registry.js":
            continue
        for line_no, line in enumerate(path.read_text(encoding="utf-8", errors="ignore").splitlines(), 1):
            if branch_pattern.search(line):
                errors.append(f"hard-coded locale branch: {path.relative_to(ROOT)}:{line_no}")

    if errors:
        print("LOCALIZATION_VALIDATION_FAILED")
        for error in errors:
            print(f"- {error}")
        return 1

    print(
        "LOCALIZATION_VALIDATION_OK "
        f"locales={len(catalogs)} keys={len(fallback_keys)} references={len(references)} html={len(html_paths)}"
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
