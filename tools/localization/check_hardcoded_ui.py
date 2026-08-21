#!/usr/bin/env python3
"""PR-01 (V-3 / V-0) — Hardcoded UI freeze checker.

Guards the i18n freeze baseline for Velora: no NEW hardcoded user-facing copy
may be introduced outside the central translation catalog, and the allowlist
that encodes today's known violations must stay in sync with the tree.

Scan scopes (relative to repo root):
  * public/assets/**/*.js   — Persian-script string / regex literals
  * localized/**/*.html     — Persian-script literals inside inline <script>

Rules enforced (exit code 1):
  * NEW VIOLATION : a scanned file contains a Persian-script literal but has
    no allowlist entry.
  * DRIFT / STALE : an allowlisted file's literal count changed.
  * ORPHAN        : an allowlist entry matches no violation (file missing or
    zero literals) — entries must be removed together with the fix.

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

PERSIAN_RE = re.compile('[\u0600-\u06FF\u0750-\u077F\u08A0-\u08FF\uFB50-\uFDFF\uFE70-\uFEFF]')
PERSIAN_PATTERN_STR = r'[\u0600-\u06FF\u0750-\u077F\u08A0-\u08FF\uFB50-\uFDFF\uFE70-\uFEFF]'
STRING_LIT_RE = re.compile(r'"(?:\\.|[^"\\])*"|\'(?:\\.|[^\'\\])*\'|`(?:\\.|[^`\\])*`')
REGEX_LIT_RE = re.compile(r'/(?:\\.|[^/\n\\])*/[a-z]*')
SCRIPT_RE = re.compile(r'<script\b(?![^>]*\bsrc\s*=)[^>]*>(.*?)</script>', re.I | re.S)

ALLOWED_CATEGORIES = {
    "legacy-dead": "file is scheduled for deletion",
    "legacy-dictionary": "inline dictionary scheduled for catalog migration",
    "regex-intent": "intent-detection regex, not user-facing copy",
    "seo-meta": "JSON-LD/SEO meta string hardcoded in a localized page",
    "meta": "legitimate metadata token (e.g. nativeName)",
}

DEFAULT_REPO_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_ALLOWLIST = DEFAULT_REPO_ROOT / 'tools' / 'localization' / 'allowlist-hardcoded.json'


def _now_iso() -> str:
    return datetime.now(timezone.utc).isoformat(timespec='seconds')


def _read(path: Path) -> str:
    return path.read_text(encoding='utf-8', errors='replace')


def _rel(root: Path, path: Path) -> str:
    return os.path.relpath(path, root).replace(os.sep, '/')


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
    last_significant = ""
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
            last_significant = quote
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
            last_significant = "`"
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
                last_significant = "/"
                continue
            out.append(ch)
            last_significant = ch
            i += 1
            continue
        out.append(ch)
        if not ch.isspace():
            last_significant = ch
        i += 1
    return "".join(out)


def persian_literals(text: str) -> set:
    """Return distinct string/regex literals in *text* that contain Persian script.
    JS comments are stripped first so comment prose is not treated as copy."""
    text = strip_js_comments(text)
    found = set()
    for m in STRING_LIT_RE.finditer(text):
        s = m.group(0)
        if PERSIAN_RE.search(s):
            found.add(s[1:-1])  # strip delimiters for strings
    for m in REGEX_LIT_RE.finditer(text):
        s = m.group(0)
        if PERSIAN_RE.search(s):
            found.add(s)  # keep /pattern/flags form for regexes
    return found


def scan(root: Path) -> dict:
    """Return {relative_path: sorted list of Persian literals} for all scopes."""
    violations = {}

    assets = root / 'public' / 'assets'
    if assets.is_dir():
        for p in sorted(assets.rglob('*.js')):
            lits = persian_literals(_read(p))
            if lits:
                violations[_rel(root, p)] = sorted(lits)

    localized = root / 'localized'
    if localized.is_dir():
        for p in sorted(localized.rglob('*.html')):
            lits = set()
            for script_body in SCRIPT_RE.findall(_read(p)):
                lits |= persian_literals(script_body)
            if lits:
                violations[_rel(root, p)] = sorted(lits)

    return violations


def _generate(root: Path, allowlist_path: Path) -> int:
    violations = scan(root)
    entries = []
    for i, (f, lits) in enumerate(sorted(violations.items()), 1):
        entries.append({
            "id": f"V3-{i:03d}",
            "file": f,
            "pattern": PERSIAN_PATTERN_STR,
            "count": len(lits),
            "category": "legacy-dictionary",
            "reason": "",
            "resolution_pr": None,
        })
    data = {
        "version": 1,
        "generated_at": _now_iso(),
        "scope_note": (
            "PR-01 freeze baseline. Each entry documents one known hardcoded "
            "Persian-script literal source. Resolve the entry via its "
            "resolution_pr; an entry must be removed in the same change that "
            "eliminates its violations."
        ),
        "categories": ALLOWED_CATEGORIES,
        "entries": entries,
    }
    allowlist_path.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding='utf-8')
    print(f"allowlist written: {allowlist_path} ({len(entries)} entries)")
    return 0


def _validate(violations: dict, data: dict) -> int:
    errors = []
    categories = set((data.get('categories') or {}).keys())
    entries = data.get('entries') or []
    if not isinstance(entries, list):
        print("ERROR: allowlist 'entries' must be a list", file=sys.stderr)
        return 2

    by_file = {}
    seen_ids = set()
    for e in entries:
        fid = e.get('id', '<no-id>')
        f = e.get('file')
        cnt = e.get('count')
        cat = e.get('category')
        if not f:
            errors.append(f"allowlist entry '{fid}' missing 'file'")
            continue
        if not isinstance(cnt, int):
            errors.append(f"allowlist entry '{fid}' ({f}) missing/invalid 'count'")
            continue
        if fid in seen_ids:
            errors.append(f"duplicate allowlist id '{fid}'")
        seen_ids.add(fid)
        if f in by_file:
            errors.append(f"duplicate allowlist file '{f}'")
        by_file[f] = e

    for f in sorted(violations):
        actual = len(violations[f])
        e = by_file.get(f)
        if e is None:
            errors.append(f"NEW VIOLATION: '{f}' has {actual} Persian literal(s) but no allowlist entry")
            continue
        if e['count'] != actual:
            errors.append(f"DRIFT/STALE: '{f}' allowlist count={e['count']} but tree has {actual}")
        if e.get('category') not in categories:
            errors.append(f"'{f}' has unknown allowlist category '{e.get('category')}'")

    for f in sorted(by_file):
        if f not in violations:
            e = by_file[f]
            errors.append(f"ORPHAN: allowlist entry '{e.get('id')}' for '{f}' matches no violation (file missing or zero literals)")

    total_files = len(violations)
    total_lits = sum(len(v) for v in violations.values())
    print(f"hardcoded-UI scan: {len(entries)} allowlist entries; tree has {total_files} file(s) / {total_lits} Persian literal(s)")

    if errors:
        for m in errors:
            print(f"ERROR: {m}", file=sys.stderr)
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

    violations = scan(root)
    if not allowlist_path.exists():
        print(f"ERROR: allowlist not found: {allowlist_path}", file=sys.stderr)
        return 2
    try:
        data = json.loads(_read(allowlist_path))
    except Exception as e:  # noqa: BLE001
        print(f"ERROR: malformed allowlist JSON: {e}", file=sys.stderr)
        return 2
    return _validate(violations, data)


if __name__ == '__main__':
    sys.exit(run(sys.argv[1:]))
