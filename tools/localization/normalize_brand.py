#!/usr/bin/env python3
"""Enforce the centralized display-brand policy without touching URLs or identifiers."""
from __future__ import annotations

import json
import re
from pathlib import Path

from bs4 import BeautifulSoup, NavigableString

from brand_policy import default_token, is_localized_message, load_brand_policy, localized_token

ROOT = Path(__file__).resolve().parents[2]
BRAND_POLICY = load_brand_policy()
DEFAULT_BRAND_TOKEN = default_token(BRAND_POLICY)
PROTECTED_RE = re.compile(
    r"https?://[^\s<>'\"]+|"
    r"\b[^\s@]+@[^\s@]+\.[^\s@]+\b|"
    r"\b(?:[A-Za-z0-9-]+\.)*(?:velora(?:trade)?)(?:\.[A-Za-z]{2,})(?:/[^\s<>'\"]*)?",
    re.IGNORECASE,
)
LATIN_RE = re.compile(r"velora", re.IGNORECASE)
PERSIAN_RE = re.compile("ولورا")
VISIBLE_ATTRS = ("alt", "title", "placeholder", "aria-label", "content", "value")
SKIP_PARENTS = {"script", "style", "code", "pre"}


def normalize_text(value: str, replacement: str = DEFAULT_BRAND_TOKEN) -> str:
    if not value or not ("ولورا" in value or LATIN_RE.search(value)):
        return value
    pieces: list[str] = []
    cursor = 0
    for match in PROTECTED_RE.finditer(value):
        plain = value[cursor:match.start()]
        plain = PERSIAN_RE.sub(replacement, plain)
        plain = LATIN_RE.sub(replacement, plain)
        pieces.extend((plain, match.group(0)))
        cursor = match.end()
    plain = value[cursor:]
    pieces.append(LATIN_RE.sub(replacement, PERSIAN_RE.sub(replacement, plain)))
    return "".join(pieces)


def normalize_json(value, *, normalize_keys: bool = False):
    if isinstance(value, str):
        return normalize_text(value)
    if isinstance(value, list):
        return [normalize_json(item, normalize_keys=normalize_keys) for item in value]
    if isinstance(value, dict):
        return {
            (normalize_text(str(key)) if normalize_keys else key): normalize_json(item, normalize_keys=normalize_keys)
            for key, item in value.items()
        }
    return value


def normalize_html(path: Path) -> bool:
    original = path.read_text(encoding="utf-8")
    soup = BeautifulSoup(original, "html.parser")
    changed = False
    for node in list(soup.find_all(string=True)):
        parent = node.parent.name.lower() if node.parent and node.parent.name else ""
        if parent in SKIP_PARENTS or isinstance(node, type(soup.doctype)):
            continue
        updated = normalize_text(str(node))
        if updated != str(node):
            node.replace_with(NavigableString(updated))
            changed = True
    for tag in soup.find_all(True):
        for attr in VISIBLE_ATTRS:
            current = tag.get(attr)
            if not isinstance(current, str):
                continue
            updated = normalize_text(current)
            if updated != current:
                tag[attr] = updated
                changed = True
    # JSON-LD is public structured content, not executable application code.
    for script in soup.find_all("script", attrs={"type": "application/ld+json"}):
        raw = script.string
        if not raw:
            continue
        try:
            payload = json.loads(raw)
        except json.JSONDecodeError:
            continue
        normalized = normalize_json(payload)
        rendered = json.dumps(normalized, ensure_ascii=False, separators=(",", ":"))
        if rendered != raw:
            script.string = rendered
            changed = True
    if changed:
        path.write_text(str(soup), encoding="utf-8")
    return changed


def normalize_catalog(payload: dict, locale: str) -> dict:
    normalized = normalize_json(payload)
    messages = normalized.get("messages", {})
    token = localized_token(BRAND_POLICY, locale)
    if token:
        for key, value in list(messages.items()):
            if is_localized_message(BRAND_POLICY, locale, key) and isinstance(value, str):
                messages[key] = normalize_text(value, replacement=token)
    return normalized


def main() -> None:
    changed_files = 0
    localized_exceptions = 0
    for locale_path in sorted((ROOT / "public/locales").glob("*.json")):
        if locale_path.name.endswith("schema.json") or locale_path.name in {"manifest.json", "feature-manifest.json"}:
            continue
        payload = json.loads(locale_path.read_text(encoding="utf-8"))
        locale = str(payload.get("_meta", {}).get("locale", locale_path.stem))
        normalized = normalize_catalog(payload, locale)
        localized_exceptions += len(BRAND_POLICY.get("localizedMessages", {}).get(locale, []))
        if normalized != payload:
            locale_path.write_text(json.dumps(normalized, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
            changed_files += 1
    for name in ("manual-translations.json", "manual-english-to-persian.json", "legacy-phrase-pairs.json"):
        path = ROOT / "tools/localization" / name
        payload = json.loads(path.read_text(encoding="utf-8"))
        normalized = normalize_json(payload, normalize_keys=True)
        if normalized != payload:
            path.write_text(json.dumps(normalized, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
            changed_files += 1
    for path in sorted(ROOT.rglob("*.html")):
        if "localized" in path.parts or path.name.startswith("google"):
            continue
        changed_files += int(normalize_html(path))
    print(
        f"BRAND_NORMALIZATION_OK changed_files={changed_files} default_brand={DEFAULT_BRAND_TOKEN} "
        f"localized_exceptions={localized_exceptions}"
    )


if __name__ == "__main__":
    main()
