#!/usr/bin/env python3
"""Build first-paint localized HTML and feature-scoped browser catalogs."""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import shutil
from pathlib import Path
from typing import Any

from bs4 import BeautifulSoup, Comment, NavigableString

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
) -> tuple[dict[str, Any], dict[str, set[str]]]:
    if CHUNKS_DIR.exists():
        shutil.rmtree(CHUNKS_DIR)
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
            path = CHUNKS_DIR / locale / f"{feature}.json"
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
    (LOCALES_DIR / "feature-manifest.json").write_text(
        json.dumps(feature_manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    return feature_manifest, feature_plan


def build() -> tuple[int, int, int]:
    manifest = load_json(LOCALES_DIR / "manifest.json")
    config = load_json(ROOT / "tools/localization/feature-map.json")
    routes = load_json(ROOT / "tools/localization/routes.json")["routes"]
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
    feature_manifest, feature_plan = build_chunks(manifest, config, routes, all_keys, catalogs)

    if OUTPUT_DIR.exists():
        shutil.rmtree(OUTPUT_DIR)
    rendered_count = 0
    template_count = 0
    for route in routes:
        template = ROOT / route["template"]
        if not template.is_file():
            raise FileNotFoundError(f"missing template: {route['template']}")
        source = template.read_text(encoding="utf-8")
        features = collect_page_features(route["template"], feature_plan, config)
        missing_features = [
            feature for feature in features
            if any(feature not in feature_manifest["locales"][locale] for locale in catalogs)
        ]
        if missing_features:
            raise RuntimeError(f"template {route['template']} requires missing chunks: {missing_features}")
        template_count += 1
        preferred_outputs = {
            locale: route.get("localeOutputs", {}).get(locale, route["outputs"])[0]
            for locale in catalogs
        }
        alternate_urls = {
            locale: localized_public_url(locale, preferred_output)
            for locale, preferred_output in preferred_outputs.items()
        }
        for locale, messages in catalogs.items():
            locale_outputs = list(route["outputs"]) + list(route.get("localeOutputs", {}).get(locale, []))
            for output_relative in dict.fromkeys(locale_outputs):
                output = OUTPUT_DIR / locale / output_relative
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
    return template_count, rendered_count, sum(len(x) for x in feature_manifest["locales"].values())


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.parse_args()
    templates, html_files, chunks = build()
    print(f"LOCALIZED_BUILD_OK templates={templates} html={html_files} feature_chunks={chunks}")


if __name__ == "__main__":
    main()
