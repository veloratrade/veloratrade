#!/usr/bin/env python3
"""PR-01 (V-3 / V-0) — Hardcoded UI freeze checker.

Guards the i18n freeze baseline for Velora: no NEW hardcoded user-facing copy
may be introduced outside the central translation catalog, and the allowlist
that encodes today's known violations must stay in sync with the tree.

Scan scopes (relative to repo root):
  * public/assets/**/*.js   — hardcoded Persian literals, plus probable
    hardcoded English UI literals in string literals
  * localized/**/*.html     — same, but only inside inline <script>

Rules enforced (exit code 1):
  * NEW VIOLATION : a scanned file contains a hardcoded UI literal but has
    no allowlist entry.
  * DRIFT / STALE : an allowlisted file's literal count changed.
  * ORPHAN        : an allowlist entry matches no violation (file missing or
    zero literals) — entries must be removed together with the fix.

Technical / non-user-facing literals may be exempted precisely through the
same allowlist file via ``literal_allowlist`` rules (exact strings or regexes),
so the validator remains single-source and deterministic.

Exit codes:
  0  PASS
  1  FAIL (freeze violation / allowlist drift / orphan entry)
  2  usage or input error (bad args, malformed or missing allowlist)

This checker is additive and read-only: it never modifies any scanned file.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

PERSIAN_RE = re.compile('[\u0600-\u06FF\u0750-\u077F\u08A0-\u08FF\uFB50-\uFDFF\uFE70-\uFEFF]')
LATIN_RE = re.compile(r'[A-Za-z]')
PERSIAN_PATTERN_STR = r'[\u0600-\u06FF\u0750-\u077F\u08A0-\u08FF\uFB50-\uFDFF\uFE70-\uFEFF]'
STRING_LIT_RE = re.compile(r'"(?:\\.|[^"\\])*"|\'(?:\\.|[^\'\\])*\'|`(?:\\.|[^`\\])*`')
REGEX_LIT_RE = re.compile(r'/(?:\\.|[^/\n\\])*/[a-z]*')
SCRIPT_RE = re.compile(r'<script\b(?![^>]*\bsrc\s*=)[^>]*>(.*?)</script>', re.I | re.S)
ENGLISH_WORD_RE = re.compile(r"[A-Za-z][A-Za-z0-9'\-/]*")
HEX_RE = re.compile(r'^#(?:[0-9A-Fa-f]{3}|[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})$')
HTMLISH_RE = re.compile(r'[<>]|&(?:amp|lt|gt|quot|#39);')
CSSISH_RE = re.compile(
    r'(?:^|[;{])\s*'
    r'(?:display|position|padding|margin|border|background|color|align-|justify-|gap|grid|flex|inset|'
    r'top|left|right|bottom|width|height|font|line-height|letter-spacing|box-shadow|transform|transition|z-index)\s*:'
    r'|(?:rgba?|hsla?)\(|linear-gradient\(|blur\('
)
CODE_FRAGMENT_RE = re.compile(
    r'(?:\b(?:function|return|classList|textContent|innerHTML|setAttribute|getAttribute|'
    r'encodeURIComponent|escapeHtml|VeloraLocale|addEventListener|querySelector|appendChild|removeChild|'
    r'createElement|document|window|forEach|replace|trim|closest|matchMedia|fetch|dataset|localStorage|'
    r'sessionStorage)\b|[+={}]|\.style|=>)'
)

ALLOWED_CATEGORIES = {
    "legacy-dead": "file is scheduled for deletion",
    "legacy-dictionary": "inline dictionary scheduled for catalog migration",
    "regex-intent": "intent-detection regex, not user-facing copy",
    "seo-meta": "JSON-LD/SEO meta string hardcoded in a localized page",
    "meta": "legitimate metadata token (e.g. nativeName)",
    "runtime-fallback": "shared runtime JS contains inline fallback/error copy that must eventually move to the catalog",
}

LITERAL_ALLOW_CATEGORIES = {
    "url": "URLs, endpoints, anchors, mailto/tel/data/blob URLs, and route-like paths",
    "brand-name": "approved product, vendor, broker, platform, or language names that remain literal",
    "api-name": "HTTP/API/library/schema tokens that are technical rather than user-facing copy",
    "technical-identifier": "technical identifiers, event names, keyboard tokens, config keys, or machine-oriented literals",
    "css-class": "CSS selectors, classes, utility tokens, and style-only literals",
    "file-path": "file paths and asset names",
}

UI_SINGLE_WORDS = {
    "Active", "Admin", "Analytics", "Back", "Blog", "Broker", "Buy", "Cancel", "Close",
    "Comparison", "Confirm", "Connected", "Dashboard", "Delete", "Export", "Help", "Home",
    "Import", "Login", "Logout", "Markets", "News", "Performance", "Privacy", "Profile",
    "Register", "Save", "Sell", "Settings", "Support", "Terms", "Trade", "Trades", "Wallet",
}

DEFAULT_REPO_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_ALLOWLIST = DEFAULT_REPO_ROOT / 'tools' / 'localization' / 'allowlist-hardcoded.json'


def _now_iso() -> str:
    return datetime.now(timezone.utc).isoformat(timespec='seconds')


def _read(path: Path) -> str:
    return path.read_text(encoding='utf-8', errors='replace')


def _rel(root: Path, path: Path) -> str:
    return os.path.relpath(path, root).replace(os.sep, '/')


def _normalize_literal(text: str) -> str:
    return ' '.join(text.replace('\xa0', ' ').split())


def _alpha_ratio(text: str) -> float:
    if not text:
        return 0.0
    letters = sum(ch.isalpha() for ch in text)
    return letters / len(text)


def strip_js_comments(text: str) -> str:
    """Remove // line and /* */ block comments (same tokenizer as
    tools/localization/build_localized_static.py strip_js_comments, F-04/M3).

    A conservative single-pass tokenizer tracks string, template-literal and
    regex-literal state so `//` and `/* */` inside literals are never touched.
    On any ambiguity the input is returned unchanged (fail-safe: keeping a
    comment is always behavior-preserving)."""
    out: list[str] = []
    i = 0
    n = len(text)
    regex_prefix_chars = set("(,=:[!&|?{};+-*%~^<>\\n")
    regex_prefix_words = {"return", "typeof", "instanceof", "in", "of", "new",
                          "delete", "void", "throw", "case", "do", "else", "yield"}
    while i < n:
        ch = text[i]
        nxt = text[i + 1] if i + 1 < n else ""
        if ch in "'\"":
            quote = ch
            out.append(ch)
            i += 1
            while i < n:
                out.append(text[i])
                if text[i] == "\\":
                    if i + 1 < n:
                        out.append(text[i + 1])
                    i += 2
                    continue
                if text[i] == quote:
                    i += 1
                    break
                i += 1
            continue
        if ch == "`":
            out.append(ch)
            i += 1
            while i < n:
                out.append(text[i])
                if text[i] == "\\":
                    if i + 1 < n:
                        out.append(text[i + 1])
                    i += 2
                    continue
                if text[i] == "`":
                    i += 1
                    break
                i += 1
            continue
        if ch == "/" and nxt == "/":
            end = text.find("\n", i)
            i = n if end == -1 else end
            continue
        if ch == "/" and nxt == "*":
            end = text.find("*/", i + 2)
            if end == -1:
                out.append(text[i:])
                break
            i = end + 2
            continue
        if ch == "/":
            tail = "".join(out).rstrip()
            word = re.search(r"[A-Za-z_$][A-Za-z0-9_$]*$", tail)
            starts_regex = (
                tail == ""
                or tail[-1] in regex_prefix_chars
                or (word is not None and word.group(0) in regex_prefix_words)
            )
            if starts_regex:
                out.append(ch)
                i += 1
                in_class = False
                while i < n:
                    out.append(text[i])
                    if text[i] == "\\":
                        if i + 1 < n:
                            out.append(text[i + 1])
                        i += 2
                        continue
                    if text[i] == "[":
                        in_class = True
                    elif text[i] == "]":
                        in_class = False
                    elif text[i] == "/" and not in_class:
                        i += 1
                        break
                    elif text[i] == "\n":
                        i += 1
                        break
                    i += 1
                continue
            out.append(ch)
            i += 1
            continue
        out.append(ch)
        i += 1
    return "".join(out)


def _is_probable_english_ui_literal(text: str) -> bool:
    raw = text
    normalized = _normalize_literal(text)
    if not normalized or not LATIN_RE.search(normalized):
        return False
    if any(ch in raw for ch in '\n\r\t'):
        return False
    if HTMLISH_RE.search(normalized):
        return False
    if CSSISH_RE.search(normalized):
        return False
    if CODE_FRAGMENT_RE.search(normalized):
        return False
    words = ENGLISH_WORD_RE.findall(normalized)
    if not words:
        return False
    if _alpha_ratio(normalized) < 0.55:
        return False
    if len(words) == 1:
        word = words[0]
        return word in UI_SINGLE_WORDS or (word[0].isupper() and len(word) >= 5)
    first_alpha = next((ch for ch in normalized if ch.isalpha()), '')
    return bool(first_alpha and first_alpha.isupper())


def _extract_candidate_literals(text: str) -> set[str]:
    """Return raw candidate hardcoded UI literals from JS text.

    Persian-script string/regex literals remain first-class violations. English
    detection is intentionally narrower: it only flags probable UI copy, not
    arbitrary technical tokens. Legitimate technical strings are further removed
    later via the allowlist's literal-level exact/regex rules.
    """
    text = strip_js_comments(text)
    found: set[str] = set()
    for match in STRING_LIT_RE.finditer(text):
        literal = match.group(0)[1:-1]
        if PERSIAN_RE.search(literal):
            found.add(_normalize_literal(literal))
            continue
        if _is_probable_english_ui_literal(literal):
            found.add(_normalize_literal(literal))
    for match in REGEX_LIT_RE.finditer(text):
        literal = match.group(0)
        if PERSIAN_RE.search(literal):
            found.add(literal)
    return found


def scan(root: Path) -> dict[str, list[str]]:
    """Return {relative_path: sorted list of raw candidate literals}."""
    violations: dict[str, list[str]] = {}

    assets = root / 'public' / 'assets'
    if assets.is_dir():
        for path in sorted(assets.rglob('*.js')):
            literals = _extract_candidate_literals(_read(path))
            if literals:
                violations[_rel(root, path)] = sorted(literals)

    localized = root / 'localized'
    if localized.is_dir():
        for path in sorted(localized.rglob('*.html')):
            literals: set[str] = set()
            for script_body in SCRIPT_RE.findall(_read(path)):
                literals |= _extract_candidate_literals(script_body)
            if literals:
                violations[_rel(root, path)] = sorted(literals)

    return violations


def _compile_literal_allowlist(data: dict[str, Any]) -> list[dict[str, Any]]:
    section = data.get('literal_allowlist') or {}
    if section == {}:
        return []
    if not isinstance(section, dict):
        raise ValueError("allowlist 'literal_allowlist' must be an object")
    categories = section.get('categories') or {}
    rules = section.get('rules') or []
    if not isinstance(categories, dict):
        raise ValueError("allowlist 'literal_allowlist.categories' must be an object")
    if not isinstance(rules, list):
        raise ValueError("allowlist 'literal_allowlist.rules' must be a list")

    allowed_categories = set(categories)
    unknown = sorted(allowed_categories - set(LITERAL_ALLOW_CATEGORIES))
    if unknown:
        raise ValueError(f"unknown literal allowlist category '{unknown[0]}'")

    compiled: list[dict[str, Any]] = []
    seen_ids: set[str] = set()
    for rule in rules:
        if not isinstance(rule, dict):
            raise ValueError("literal allowlist rules must be objects")
        rid = str(rule.get('id', '')).strip()
        category = str(rule.get('category', '')).strip()
        kind = str(rule.get('kind', '')).strip()
        if not rid:
            raise ValueError("literal allowlist rule missing id")
        if rid in seen_ids:
            raise ValueError(f"duplicate literal allowlist id '{rid}'")
        seen_ids.add(rid)
        if category not in allowed_categories:
            raise ValueError(f"literal allowlist rule '{rid}' has unknown category '{category}'")
        if kind == 'exact':
            value = rule.get('value')
            if not isinstance(value, str) or not value:
                raise ValueError(f"literal allowlist rule '{rid}' exact value must be a non-empty string")
            compiled.append({'id': rid, 'kind': 'exact', 'category': category, 'value': value})
            continue
        if kind == 'regex':
            pattern = rule.get('pattern')
            if not isinstance(pattern, str) or not pattern:
                raise ValueError(f"literal allowlist rule '{rid}' regex pattern must be a non-empty string")
            try:
                regex = re.compile(pattern)
            except re.error as exc:
                raise ValueError(f"literal allowlist rule '{rid}' invalid regex: {exc}") from exc
            compiled.append({'id': rid, 'kind': 'regex', 'category': category, 'pattern': pattern, 'regex': regex})
            continue
        raise ValueError(f"literal allowlist rule '{rid}' has unsupported kind '{kind}'")
    return compiled


def _literal_is_allowlisted(literal: str, rules: list[dict[str, Any]]) -> bool:
    for rule in rules:
        if rule['kind'] == 'exact':
            if literal == rule['value']:
                return True
            continue
        if rule['regex'].fullmatch(literal):
            return True
    return False


def _apply_literal_allowlist(violations: dict[str, list[str]], data: dict[str, Any]) -> dict[str, list[str]]:
    rules = _compile_literal_allowlist(data)
    if not rules:
        return {path: list(literals) for path, literals in violations.items()}
    filtered: dict[str, list[str]] = {}
    for path, literals in violations.items():
        kept = [literal for literal in literals if not _literal_is_allowlisted(literal, rules)]
        if kept:
            filtered[path] = kept
    return filtered


def _generate(root: Path, allowlist_path: Path) -> int:
    raw_violations = scan(root)
    data: dict[str, Any]
    if allowlist_path.exists():
        try:
            data = json.loads(_read(allowlist_path))
        except Exception as exc:  # noqa: BLE001
            print(f"ERROR: malformed allowlist JSON: {exc}", file=sys.stderr)
            return 2
    else:
        data = {}
    violations = _apply_literal_allowlist(raw_violations, data)
    entries = []
    for i, (file_path, literals) in enumerate(sorted(violations.items()), 1):
        entries.append({
            "id": f"V3-{i:03d}",
            "file": file_path,
            "pattern": PERSIAN_PATTERN_STR,
            "count": len(literals),
            "category": "legacy-dictionary",
            "reason": "",
            "resolution_pr": None,
        })
    generated = {
        "version": 2,
        "generated_at": _now_iso(),
        "scope_note": (
            "PR-01 freeze baseline. Each entry documents one known hardcoded UI literal source "
            "(Persian-script literals plus probable English UI copy in the shared JS / inline-script scopes). "
            "Counts are distinct candidate literals after JS comment stripping and after applying "
            "literal_allowlist exact/regex exemptions for legitimate technical strings. Resolve an entry "
            "via its resolution_pr; the entry must be removed in the same change that eliminates its violations."
        ),
        "categories": ALLOWED_CATEGORIES,
        "literal_allowlist": data.get('literal_allowlist', {
            'categories': LITERAL_ALLOW_CATEGORIES,
            'rules': [],
        }),
        "entries": entries,
    }
    allowlist_path.write_text(json.dumps(generated, ensure_ascii=False, indent=2) + "\n", encoding='utf-8')
    print(f"allowlist written: {allowlist_path} ({len(entries)} entries)")
    return 0


def _validate(violations: dict[str, list[str]], data: dict[str, Any]) -> int:
    errors: list[str] = []
    categories = set((data.get('categories') or {}).keys())
    entries = data.get('entries') or []
    if not isinstance(entries, list):
        print("ERROR: allowlist 'entries' must be a list", file=sys.stderr)
        return 2

    try:
        violations = _apply_literal_allowlist(violations, data)
    except ValueError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2

    by_file: dict[str, dict[str, Any]] = {}
    seen_ids: set[str] = set()
    for entry in entries:
        fid = entry.get('id', '<no-id>')
        file_path = entry.get('file')
        count = entry.get('count')
        if not file_path:
            errors.append(f"allowlist entry '{fid}' missing 'file'")
            continue
        if not isinstance(count, int):
            errors.append(f"allowlist entry '{fid}' ({file_path}) missing/invalid 'count'")
            continue
        if fid in seen_ids:
            errors.append(f"duplicate allowlist id '{fid}'")
        seen_ids.add(fid)
        if file_path in by_file:
            errors.append(f"duplicate allowlist file '{file_path}'")
        by_file[file_path] = entry

    for file_path in sorted(violations):
        actual = len(violations[file_path])
        entry = by_file.get(file_path)
        if entry is None:
            errors.append(f"NEW VIOLATION: '{file_path}' has {actual} hardcoded UI literal(s) but no allowlist entry")
            continue
        if entry['count'] != actual:
            errors.append(f"DRIFT/STALE: '{file_path}' allowlist count={entry['count']} but tree has {actual}")
        if entry.get('category') not in categories:
            errors.append(f"'{file_path}' has unknown allowlist category '{entry.get('category')}'")

    for file_path in sorted(by_file):
        if file_path not in violations:
            entry = by_file[file_path]
            errors.append(
                f"ORPHAN: allowlist entry '{entry.get('id')}' for '{file_path}' matches no violation (file missing or zero literals)"
            )

    total_files = len(violations)
    total_literals = sum(len(items) for items in violations.values())
    print(f"hardcoded-UI scan: {len(entries)} allowlist entries; tree has {total_files} file(s) / {total_literals} hardcoded UI literal(s)")

    if errors:
        for message in errors:
            print(f"ERROR: {message}", file=sys.stderr)
        return 1
    print("PASS: hardcoded-UI freeze intact (no new violations, no allowlist drift/orphans).")
    return 0


def run(argv) -> int:
    parser = argparse.ArgumentParser(description="Hardcoded UI freeze checker (PR-01 V-3/V-0)")
    parser.add_argument('--root', help="repo root (default: derive from script location)")
    parser.add_argument('--allowlist', help="allowlist JSON path (default: tools/localization/allowlist-hardcoded.json)")
    parser.add_argument('--generate-allowlist', action='store_true', help="(re)generate the allowlist from the current tree and exit")
    args = parser.parse_args(argv)

    root = (Path(args.root) if args.root else DEFAULT_REPO_ROOT).resolve()
    allowlist_path = Path(args.allowlist) if args.allowlist else (root / 'tools' / 'localization' / 'allowlist-hardcoded.json')

    if args.generate_allowlist:
        return _generate(root, allowlist_path)

    raw_violations = scan(root)
    if not allowlist_path.exists():
        print(f"ERROR: allowlist not found: {allowlist_path}", file=sys.stderr)
        return 2
    try:
        data = json.loads(_read(allowlist_path))
    except Exception as exc:  # noqa: BLE001
        print(f"ERROR: malformed allowlist JSON: {exc}", file=sys.stderr)
        return 2
    return _validate(raw_violations, data)


def main(argv: list[str] | None = None) -> int:
    return run(argv or [])


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
