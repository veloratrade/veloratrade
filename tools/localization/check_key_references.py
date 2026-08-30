#!/usr/bin/env python3
"""VELORA — frontend translation reference-completeness gate.

Why this exists (incident 2026-08-30): a deployed profile page referenced
profile.aiConsent.* keys while the served catalog was a previous build without
them. The runtime loader (public/assets/velora-localization.js `t()`) returns a
missing key verbatim, so the missing key became visible UI text. Existing gates
validated catalog *parity* and *hardcoded literals*, but nothing proved that
every catalog key *referenced* by templates/JS actually exists in BOTH fa and en.

This checker closes that gap and also serves as a deploy-time probe:

  * Extracts catalog-key references from source templates and JS assets:
      - `data-i18n` / `data-i18n-*` attribute values
      - literal dotted keys passed to t() / tr() / errorMessage()
      - literal dotted keys in inline key dictionaries (script blocks)
  * Verifies each referenced key exists in BOTH fa and en catalogs.
  * Ignores CSS/selectors/URLs/assets: <style> blocks, HTML comments and
    querySelector(...) arguments are stripped before scanning, and only keys
    whose first segment is a known catalog namespace are considered.
  * Reports dynamic key construction ('ns.' + x) as WARN (not checkable).
  * Modes:
      repo (default): scan the repository tree.
      --page X --catalog-fa F --catalog-en E: check one deployed HTML against
      two specific catalogs (post-deploy probe).
  * Exit 1 on any missing key; exit 0 when complete.

Scope limits (documented on purpose):
  * Source templates only. localized/**, en/, fa/ are generated (NP-5) and are
    rebuilt from these templates, so scanning the source suffices in repo mode.
  * Backend messageKey parity (api/locales/*.php) is a separate system and is
    intentionally out of scope here.
  * Multi-language keys built dynamically at runtime cannot be verified
    statically; those heads are reported as WARN for manual review.

Usage:
  python tools/localization/check_key_references.py
  python tools/localization/check_key_references.py --json
  python tools/localization/check_key_references.py --page deployed/profile/index.html \
      --catalog-fa deployed-fa.json --catalog-en deployed-en.json
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

REPO_HINT = Path(__file__).resolve().parents[2]

# Generated / non-frontend trees never scanned in repo mode.
EXCLUDED_DIRS = {
    "localized", "en", "fa", "public", "api", "docs", "tools", "content",
    "_database", "404", ".git", ".github", "node_modules",
}
# Test / meta files that are not user-facing templates.
EXCLUDED_FILES = {"test-localization.html", "googleacbef8d6416f1474.html"}

# Segments that mark a dotted string as non-key noise (file extensions,
# top-level domains) — never a catalog key.
NON_KEY_SEGMENTS = {
    "js", "json", "png", "jpg", "jpeg", "svg", "css", "php", "html", "htm",
    "ico", "webp", "gif", "txt", "md", "pdf", "gz", "zip", "csv", "sql",
    "ttf", "woff", "woff2", "mp4", "mp3", "xml", "yaml", "yml",
    "ir", "com", "org", "net", "io", "de", "co", "uk", "info", "me", "app",
    "dev", "do", "in", "to",
}

# A catalog key: lowercase-led first segment; later segments may start with a
# digit (hash-suffixed keys like common.dashboard.2aea7aaf, pages.profile.p05.x).
KEY_RE = re.compile(r"\b([a-z][a-zA-Z0-9_-]*(?:\.[A-Za-z0-9_-]+){1,})\b")
ATTR_RE = re.compile(r'data-i18n(?:\-[a-z-]+)?="\s*([^"\s]+)\s*"')

# A key followed by a concatenation continuation ("ns." + x) is dynamic.
DYN_HINT = re.compile(r"([a-z][a-zA-Z0-9_.-]*)\.\s*['\"]?\s*\+")
DYN_KEY_SUFFIX = re.compile(r"\.\s*['\"]?\s*\+")

NAMESPACES: set[str] = set()  # filled from the loaded fa catalog


def load_catalog(path: Path) -> dict[str, str]:
    """Flatten a catalog (root {_meta, messages:{...}} or chunk form) to
    dotted-key -> value."""
    data = json.loads(path.read_text(encoding="utf-8"))
    if isinstance(data, dict) and isinstance(data.get("messages"), dict):
        messages = data["messages"]
        if any("." in str(k) for k in messages):
            return {str(k): str(v) for k, v in messages.items()}
        data = messages
    out: dict[str, str] = {}

    def walk(node, prefix=""):
        if isinstance(node, dict):
            for k, v in node.items():
                walk(v, f"{prefix}.{k}" if prefix else str(k))
        else:
            out[prefix] = str(node)

    walk(data)
    return out


def is_plausible_key(key: str) -> bool:
    segs = key.split(".")
    if len(segs) < 2 or segs[0] not in NAMESPACES:
        return False
    return all(s not in NON_KEY_SEGMENTS and re.match(r"^[a-z][a-zA-Z0-9_-]*$", s) is not None for s in segs)


def is_dynamic_continuation(key: str, text: str, start: int) -> bool:
    return bool(DYN_KEY_SUFFIX.search(text[start + len(key): start + len(key) + 12]))


def strip_css_context(text: str) -> str:
    text = re.sub(r"<style\b[^>]*>.*?</style>", " ", text, flags=re.S | re.I)
    text = re.sub(r"<!--.*?-->", " ", text, flags=re.S)
    return text


def strip_js_selectors(text: str) -> str:
    sel = re.compile(
        r"(?:querySelector|querySelectorAll|matches|closest)\s*\(\s*[\"'`][^\"'`]*[\"'`]\s*\)"
    )
    text = sel.sub(" ", text)
    text = re.sub(r"(?:insertRule|addRule)\([^)]*\)", " ", text)
    return text


STRING_RE = re.compile(
    r'"([^"\\]*(?:\\.[^"\\]*)*)"'
    r"|'([^'\\]*(?:\\.[^'\\]*)*)'"
    r"|`([^`]*(?:\\.[^`]*)*)`"
)


def quoted_strings(text: str):
    """Literal string contents. Backtick templates are cut at interpolation."""
    for m in STRING_RE.finditer(text):
        s = m.group(1) or m.group(2) or m.group(3) or ""
        if m.group(3) is not None and "${" in s:
            s = s.split("${", 1)[0]
        yield s


def _scan_string(key_text: str, keys: set[str], src: str):
    for m in KEY_RE.finditer(key_text):
        k = m.group(1)
        if is_plausible_key(k) and not is_dynamic_continuation(k, key_text, m.start(1)):
            keys.add(k)


def _extract(text: str, is_html: bool, warnings: list[str]) -> set[str]:
    keys: set[str] = set()
    if is_html:
        text = strip_css_context(text)
        for m in ATTR_RE.finditer(text):
            if is_plausible_key(m.group(1)):
                keys.add(m.group(1))
        for block in re.findall(r"<script\b[^>]*>(.*?)</script>", text, flags=re.S | re.I):
            for s in quoted_strings(block):
                _scan_string(s, keys, s)
        for s in quoted_strings(text):  # e.g. inline onclick / non-script attributes
            _scan_string(s, keys, s)
    else:
        text = strip_js_selectors(text)
        for s in quoted_strings(text):
            _scan_string(s, keys, s)
    for m in DYN_HINT.finditer(text):
        if m.group(1).split(".", 1)[0] in NAMESPACES:
            warnings.append(
                f"dynamic key construction '{m.group(1)}.' detected (not statically checkable):"
                f" verify runtime construction manually"
            )
            break
    return keys


def extract_from_html(text: str) -> tuple[set[str], list[str]]:
    warnings: list[str] = []
    return _extract(text, True, warnings), warnings


def extract_from_js(text: str) -> tuple[set[str], list[str]]:
    warnings: list[str] = []
    return _extract(text, False, warnings), warnings


def scan_repo(root: Path) -> tuple[dict[str, list[str]], list[str]]:
    refs: dict[str, list[str]] = {}
    warnings: list[str] = []

    def add(key: str, path: Path):
        refs.setdefault(key, []).append(str(path.relative_to(root)))

    for p in sorted(root.rglob("index.html")):
        rel = p.relative_to(root)
        if rel.parts and rel.parts[0] in EXCLUDED_DIRS:
            continue
        keys, warns = extract_from_html(p.read_text(encoding="utf-8", errors="replace"))
        for k in keys:
            add(k, p)
        warnings.extend(f"{rel}: {w}" for w in warns)

    for p in sorted((root / "public" / "assets").glob("*.js")):
        keys, warns = extract_from_js(p.read_text(encoding="utf-8", errors="replace"))
        for k in keys:
            add(k, p)
        warnings.extend(f"{p.relative_to(root)}: {w}" for w in warns)

    for p in sorted(f for f in root.glob("*.html") if f.name not in EXCLUDED_FILES):
        keys, warns = extract_from_html(p.read_text(encoding="utf-8", errors="replace"))
        for k in keys:
            add(k, p)
        warnings.extend(f"{p.name}: {w}" for w in warns)

    return refs, warnings


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--page", help="single HTML file to check (pair mode)")
    ap.add_argument("--catalog-fa", help="fa catalog file (pair mode)")
    ap.add_argument("--catalog-en", help="en catalog file (pair mode)")
    ap.add_argument("--json", action="store_true", help="machine-readable output")
    ap.add_argument("--root", default=str(REPO_HINT))
    args = ap.parse_args()

    pair_mode = bool(args.page or args.catalog_fa or args.catalog_en)
    if pair_mode and not (args.page and args.catalog_fa and args.catalog_en):
        print("pair mode requires --page and --catalog-fa and --catalog-en", file=sys.stderr)
        return 2

    root = Path(args.root)
    fa_catalog = load_catalog(Path(args.catalog_fa)) if args.catalog_fa else \
        load_catalog(root / "public" / "locales" / "fa.json")
    en_catalog = load_catalog(Path(args.catalog_en)) if args.catalog_en else \
        load_catalog(root / "public" / "locales" / "en.json")
    NAMESPACES.update(k.split(".", 1)[0] for k in fa_catalog)

    if pair_mode:
        page = Path(args.page)
        keys, warns = extract_from_html(page.read_text(encoding="utf-8", errors="replace"))
        refs = {k: [str(page)] for k in keys}
        warnings = warns
    else:
        refs, warnings = scan_repo(root)

    missing_fa = {k for k in refs if k not in fa_catalog}
    missing_en = {k for k in refs if k not in en_catalog}
    missing_both = missing_fa & missing_en

    report = {
        "referenced_keys": len(refs),
        "missing_in_fa": sorted(missing_fa),
        "missing_in_en": sorted(missing_en),
        "missing_in_both": sorted(missing_both),
        "warnings": warnings,
    }

    if args.json:
        print(json.dumps(report, ensure_ascii=False, indent=2))
    else:
        print(f"referenced keys: {len(refs)}")
        if missing_both:
            print(f"FAIL: {len(missing_both)} referenced keys exist in NEITHER catalog:")
            for k in sorted(missing_both):
                print(f"   {k}  ({', '.join(refs[k][:3])})")
        for loc, miss in (("fa", missing_fa - missing_both), ("en", missing_en - missing_both)):
            if miss:
                print(f"FAIL: {len(miss)} referenced keys missing in {loc}:")
                for k in sorted(miss):
                    print(f"   {k}  ({', '.join(refs[k][:3])})")
        for w in warnings:
            print(f"WARN: {w}")
        if not missing_fa and not missing_en:
            print("REFERENCE-COMPLETENESS: PASS (all referenced keys exist in fa AND en)")

    return 1 if (missing_fa or missing_en) else 0


if __name__ == "__main__":
    sys.exit(main())
