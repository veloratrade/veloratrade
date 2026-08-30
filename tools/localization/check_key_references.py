#!/usr/bin/env python3
"""VELORA — frontend translation reference-completeness gate (G1) + runtime
feature-chunk consistency gate (G2).

Why this exists (incident 2026-08-30): a deployed profile page referenced
profile.aiConsent.* keys while the served catalog was a previous build without
them. The runtime loader (public/assets/velora-localization.js `t()`) returns a
missing key verbatim, so the missing key became visible UI text. Existing gates
validated catalog *parity* and *hardcoded literals*, but nothing proved that
every catalog key *referenced* by templates/JS actually exists in BOTH fa and en
— and nothing proved the key is covered by the feature CHUNKS the served page
actually loads.

Fixed extractor guarantees (2026-08-30 audit, no false positives from):
  * CSS selectors/rule bodies      (<style> stripped; code-like strings skipped)
  * HTML comments                  (stripped)
  * JavaScript comments            (string-aware comment stripper)
  * ordinary JS identifiers        (only dotted, namespace-anchored keys count)
  * property access / expressions  (strings containing JS code chars are
                                    excluded from key candidates entirely)

Modes:
  repo (default):
      G1 — every key referenced by source templates/JS exists in BOTH catalogs.
      G2 — every key referenced by a SERVED localized/fa|en page exists in the
           feature chunks that page loads (data-i18n-features parity), and each
           declared feature chunk file exists. This mirrors the runtime loader,
           so "key present in the root catalog but never loaded" is a hard FAIL.
      (G2 activates when localized/ is materialized; --require-runtime turns a
       missing localized/ tree into a hard failure — used in CI where the full
       checkout exists.)
  pair:
      --page X --catalog-fa F --catalog-en E — G1 for one deployed HTML against
      two specific catalogs (post-deploy probe).

Exit codes: 0 = pass, 1 = any missing key / runtime-feature violation,
2 = usage error.

Usage:
  python tools/localization/check_key_references.py [--require-runtime] [--json]
  python tools/localization/check_key_references.py --page deployed.html \\
      --catalog-fa f.json --catalog-en e.json
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
    "localized", "fa", "public", "api", "docs", "tools", "content",
    "_database", ".git", ".github", "node_modules",
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

# String-literal content that is NOT a localization key: any JS code signal.
# A catalog key is a bare dotted identifier — it never contains these.
CODE_SIGNALS = set("<>;{}()=+'`|&, \t\n\r")

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
    # `text` here is the window that begins right AFTER the key (possibly
    # including the trailing dot of an "ns." head literal) and is followed by
    # the original source tail. A dynamic head looks like:  "ns." + x
    return bool(DYN_KEY_SUFFIX.search(text[start:start + 12]))


def strip_css_context(text: str) -> str:
    text = re.sub(r"<style\b[^>]*>.*?</style>", " ", text, flags=re.S | re.I)
    text = re.sub(r"<!--.*?-->", " ", text, flags=re.S)
    return text


def strip_js_comments(text: str) -> str:
    """Remove /* */ and // comments without touching string literals."""
    out = []
    i, n = 0, len(text)
    quote = None
    while i < n:
        c = text[i]
        if quote:
            out.append(c)
            if c == "\\" and i + 1 < n:
                out.append(text[i + 1])
                i += 2
                continue
            if c == quote:
                quote = None
            i += 1
            continue
        if c in "\"'`":
            quote = c
            out.append(c)
            i += 1
            continue
        if c == "/" and i + 1 < n:
            nxt = text[i + 1]
            if nxt == "/":
                j = text.find("\n", i)
                if j == -1:
                    i = n
                    continue
                out.append(" ")
                i = j
                continue
            if nxt == "*":
                j = text.find("*/", i + 2)
                if j == -1:
                    i = n
                    continue
                out.append(" ")
                i = j + 2
                continue
        out.append(c)
        i += 1
    return "".join(out)


def strip_js_selectors(text: str) -> str:
    sel = re.compile(
        r"(?:querySelector|querySelectorAll|matches|closest)\s*\(\s*['\"`][^'\"`]*['\"`]\s*\)"
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
    """Yield (content, end_index) for literal strings that can plausibly be a
    catalog key.

    Backtick templates are cut at interpolation, and any string containing
    JavaScript code signals (quotes, brackets, assignment, operators,
    whitespace, template syntax) is skipped — a catalog key can never contain
    those characters. This is what keeps `accounts.map(...)`, CSS-bodied
    strings and minified code blocks out of the key universe.
    """
    for m in STRING_RE.finditer(text):
        s = m.group(1) or m.group(2) or m.group(3) or ""
        end = m.end()
        if m.group(3) is not None and "${" in s:
            s = s.split("${", 1)[0]
        if not s:
            continue
        if any(ch in CODE_SIGNALS for ch in s):
            continue
        yield s, end


def _scan_string(key_text: str, keys: set[str], tail: str = ""):
    for m in KEY_RE.finditer(key_text):
        k = m.group(1)
        # A dynamic head ("ns." + x) is not a key itself: extend the check
        # window with the source text that follows the literal.
        window = key_text[m.start(1) + len(k):] + tail
        if is_plausible_key(k) and not is_dynamic_continuation(k, window, 0):
            keys.add(k)


def _extract(text: str, is_html: bool, warnings: list[str]) -> set[str]:
    keys: set[str] = set()
    if is_html:
        text = strip_css_context(text)
        for m in ATTR_RE.finditer(text):
            if is_plausible_key(m.group(1)):
                keys.add(m.group(1))
        for block in re.findall(r"<script\b[^>]*>(.*?)</script>", text, flags=re.S | re.I):
            block = strip_js_comments(block)
            for s, end in quoted_strings(block):
                _scan_string(s, keys, block[end:end + 12])
        for s, end in quoted_strings(text):  # inline handlers / attributes
            _scan_string(s, keys, text[end:end + 12])
    else:
        text = strip_js_selectors(strip_js_comments(text))
        for s, end in quoted_strings(text):
            _scan_string(s, keys, text[end:end + 12])
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


# ---------------------------------------------------------------------------
# G2 — runtime feature-chunk consistency (served artifacts only)
# ---------------------------------------------------------------------------

DEFAULT_FEATURES = ("common", "errors")


def page_features(html: str) -> list[str]:
    m = re.search(r'data-i18n-features="([^"]*)"', html)
    raw = m.group(1) if m else ",".join(DEFAULT_FEATURES)
    seen = set()
    out = []
    for item in raw.split(","):
        item = item.strip()
        if item and item not in seen:
            seen.add(item)
            out.append(item)
    return out


class RuntimeFeatureReport:
    def __init__(self) -> None:
        self.problems: list[str] = []
        self.pages = 0
        self.keys = 0


def scan_runtime_features(root: Path, report: RuntimeFeatureReport) -> None:
    """Verify every key referenced by a SERVED localized page exists in the
    feature chunks that page actually loads (fa AND en independently)."""
    localized = root / "localized"
    if not localized.is_dir():
        return  # cone / not materialized — caller decides via --require-runtime
    chunk_base = root / "public" / "locales" / "chunks"
    chunk_cache: dict[tuple[str, str], dict[str, str] | None] = {}

    def chunk(locale: str, feature: str):
        key = (locale, feature)
        if key not in chunk_cache:
            path = chunk_base / locale / f"{feature}.json"
            if path.is_file():
                chunk_cache[key] = load_catalog(path)
            else:
                chunk_cache[key] = None
        return chunk_cache[key]

    for locale in ("fa", "en"):
        ldir = localized / locale
        if not ldir.is_dir():
            report.problems.append(f"missing served locale dir: localized/{locale}/")
            continue
        pages = sorted(ldir.rglob("index.html")) + sorted(ldir.glob("*.html"))
        for p in pages:
            rel = p.relative_to(root)
            try:
                html = p.read_text(encoding="utf-8", errors="replace")
            except OSError as exc:
                report.problems.append(f"{rel}: unreadable ({exc})")
                continue
            report.pages += 1
            features = page_features(html)
            available: dict[str, str] = {}
            for feature in features:
                data = chunk(locale, feature)
                if data is None:
                    report.problems.append(
                        f"{rel}: declares feature '{feature}' but chunk "
                        f"chunks/{locale}/{feature}.json is missing (runtime 404 → raw keys)"
                    )
                else:
                    available.update(data)
            keys, warns = extract_from_html(html)
            report.keys += len(keys)
            missing = sorted(k for k in keys if k not in available)
            if missing:
                report.problems.append(
                    f"{rel}: {len(missing)} referenced key(s) not covered by loaded "
                    f"features [{','.join(features)}] in {locale}: "
                    + ", ".join(missing[:6]) + (" …" if len(missing) > 6 else "")
                )


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--page", help="single HTML file to check (pair mode)")
    ap.add_argument("--catalog-fa", help="fa catalog file (pair mode)")
    ap.add_argument("--catalog-en", help="en catalog file (pair mode)")
    ap.add_argument("--json", action="store_true", help="machine-readable output")
    ap.add_argument("--root", default=str(REPO_HINT))
    ap.add_argument(
        "--require-runtime",
        action="store_true",
        help="fail if localized/** is not materialized (full-tree CI mode)",
    )
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
        runtime = None
    else:
        refs, warnings = scan_repo(root)
        runtime = RuntimeFeatureReport()
        scan_runtime_features(root, runtime)
        if not (root / "localized").is_dir() and args.require_runtime:
            print("FAIL: localized/** not materialized (--require-runtime set)", file=sys.stderr)
            return 1

    missing_fa = {k for k in refs if k not in fa_catalog}
    missing_en = {k for k in refs if k not in en_catalog}
    missing_both = missing_fa & missing_en

    runtime_problems = runtime.problems if runtime else []

    report = {
        "referenced_keys": len(refs),
        "missing_in_fa": sorted(missing_fa),
        "missing_in_en": sorted(missing_en),
        "missing_in_both": sorted(missing_both),
        "runtime_feature_errors": runtime_problems,
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
        if runtime is not None and (root / "localized").is_dir():
            print(
                f"RUNTIME-FEATURES: {runtime.pages} served page(s), "
                f"{runtime.keys} referenced key(s)"
            )
            for problem in runtime_problems:
                print(f"FAIL: {problem}")
            if not runtime_problems:
                print("RUNTIME-FEATURES: PASS (all served-page keys covered by loaded chunks)")
        for w in warnings:
            print(f"WARN: {w}")
        if not missing_fa and not missing_en and not runtime_problems:
            print("REFERENCE-COMPLETENESS: PASS (all referenced keys exist in fa AND en)")

    return 1 if (missing_fa or missing_en or runtime_problems) else 0


if __name__ == "__main__":
    sys.exit(main())
