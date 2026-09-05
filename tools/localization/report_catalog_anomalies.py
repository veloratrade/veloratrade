#!/usr/bin/env python3
"""PR-01 (V-7) — Catalog anomaly report with optional blocking groups.

Prints a human-readable inventory of catalog quality anomalies. By default it
remains report-only and exits 0. When ``--fail`` is passed, callers may promote
all or selected groups into blocking checks. An exact-key allowlist may be
provided so the current repository baseline can be tolerated while CI blocks any
new anomalies beyond that approved set.

Reported groups (definitions):
  1. en.empty         — EN value is an empty string.
  2. fa.no-persian    — FA value contains no Persian-script characters.
  3. fa.en.identical  — FA value equals EN value for the same key.
  4. fa.duplicates    — a FA value is reused by more than one key.
  5. en.multi-fa      — an EN value maps from more than one distinct FA value.
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from collections import defaultdict
from pathlib import Path
from typing import Iterable

PERSIAN_RE = re.compile('[\u0600-\u06FF\u0750-\u077F\u08A0-\u08FF\uFB50-\uFDFF\uFE70-\uFEFF]')

# Masked-secret display placeholder: Velora renders an obscured credential/token
# value as a run of bullet characters (U+2022), e.g. "••••••••". This is a
# redaction sentinel, not translatable text, so it must never be flagged as an
# untranslated-English value by `fa.en.identical`. The rule is deliberately
# narrow: it matches ONLY strings composed entirely of U+2022 bullets and nothing
# else, so it cannot exempt arbitrary punctuation, Latin words, or real English.
MASKED_SECRET_BULLET = '\u2022'


def _is_masked_secret_placeholder(value: str) -> bool:
    """True iff ``value`` is the masked-secret display placeholder (a non-empty
    string made only of U+2022 bullet characters). Used to keep a redacted token
    sentinel out of the ``fa.en.identical`` (untranslated-English) check."""
    return bool(value) and all(ch == MASKED_SECRET_BULLET for ch in value)


DEFAULT_REPO_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_ALLOWLIST = DEFAULT_REPO_ROOT / 'tools' / 'localization' / 'catalog-quality-allowlist.json'
CATALOG_RELS = (('public', 'locales', 'fa.json'), ('public', 'locales', 'en.json'))
LIST_GROUPS = ('en.empty', 'fa.no-persian', 'fa.en.identical')
DICT_GROUPS = ('fa.duplicates', 'en.multi-fa')
ALL_GROUPS = LIST_GROUPS + DICT_GROUPS


def _load(root: Path) -> dict:
    out = {}
    for rel in CATALOG_RELS:
        p = root.joinpath(*rel)
        data = json.loads(p.read_text(encoding='utf-8', errors='replace'))
        messages = data.get('messages')
        if not isinstance(messages, dict):
            raise ValueError(f"{p}: 'messages' key missing or not an object")
        out[p.name.split('.')[0]] = messages
    return out


def _str_values(messages: dict) -> dict:
    return {k: v for k, v in messages.items() if isinstance(v, str)}


def report(root: Path, limit: int) -> dict:
    catalogs = _load(root)
    fa = _str_values(catalogs['fa'])
    en = _str_values(catalogs['en'])
    common = sorted(set(fa) | set(en))

    groups = {}

    groups['en.empty'] = [k for k in common if k in en and en[k].strip() == '']

    groups['fa.no-persian'] = [k for k in sorted(fa) if not PERSIAN_RE.search(fa[k])]

    groups['fa.en.identical'] = [
        k for k in common
        if k in fa and k in en and fa[k] == en[k] and not _is_masked_secret_placeholder(fa[k])
    ]

    fa_by_value = defaultdict(list)
    for k, v in fa.items():
        fa_by_value[v].append(k)
    dup_values = {v: ks for v, ks in fa_by_value.items() if len(ks) > 1}
    groups['fa.duplicates'] = dup_values  # value -> [keys]

    en_to_fa = defaultdict(set)
    for k in common:
        if k in fa and k in en:
            en_to_fa[en[k]].add(fa[k])
    groups['en.multi-fa'] = {v: sorted(src) for v, src in en_to_fa.items() if len(src) > 1}

    return groups


def _print(title: str, items, limit: int) -> None:
    print(f"\n## {title} — {len(items)}")
    for item in list(items)[:limit]:
        print(f"   {item}")


def _load_allowlist(path: Path | None) -> dict[str, set[str]]:
    if path is None:
        return {}
    payload = json.loads(path.read_text(encoding='utf-8'))
    section = payload.get('catalogAnomalies', {})
    if not isinstance(section, dict):
        raise ValueError(f"{path}: catalogAnomalies must be an object")
    result: dict[str, set[str]] = {}
    for group, items in section.items():
        if group not in LIST_GROUPS:
            raise ValueError(f"{path}: unsupported anomaly allowlist group: {group}")
        if not isinstance(items, list) or any(not isinstance(item, str) for item in items):
            raise ValueError(f"{path}: catalogAnomalies.{group} must be an array of strings")
        result[group] = set(items)
    return result


def _allowed_current(group_name: str, current: Iterable[str], allowlist: dict[str, set[str]]) -> tuple[list[str], list[str]]:
    current_set = set(current)
    allowed = allowlist.get(group_name, set())
    stale = sorted(allowed - current_set)
    blocking = sorted(current_set - allowed)
    return blocking, stale


def run(argv) -> int:
    parser = argparse.ArgumentParser(description="Catalog anomaly report (report-only by default)")
    parser.add_argument('--root', help="repo root (default: derive from script location)")
    parser.add_argument('--limit', type=int, default=15, help="max examples printed per group (default 15)")
    parser.add_argument('--fail', action='store_true', help="exit 1 if selected anomaly groups remain after allowlisting")
    parser.add_argument(
        '--fail-group',
        action='append',
        choices=ALL_GROUPS,
        default=[],
        help='anomaly group(s) to make blocking under --fail; repeatable; default = all groups',
    )
    parser.add_argument(
        '--allowlist',
        help='JSON file with catalogAnomalies allowlist (default: none)',
    )
    args = parser.parse_args(argv)

    root = (Path(args.root) if args.root else DEFAULT_REPO_ROOT).resolve()
    allowlist_path = Path(args.allowlist).resolve() if args.allowlist else None
    allowlist = _load_allowlist(allowlist_path)
    g = report(root, args.limit)

    print("VELORA catalog anomaly report")

    blocking_groups = set(args.fail_group) if args.fail_group else set(ALL_GROUPS)
    blocking_hits: list[str] = []

    for group in LIST_GROUPS:
        blocking, stale = _allowed_current(group, g[group], allowlist)
        allowlisted_count = len(g[group]) - len(blocking)
        label = {
            'en.empty': 'en.empty (EN empty strings)',
            'fa.no-persian': 'fa.no-persian (FA values without Persian script)',
            'fa.en.identical': 'fa.en.identical (FA == EN)',
        }[group]
        print(
            f"\n## {label} — total={len(g[group])} allowlisted={allowlisted_count} blocking={len(blocking)}"
        )
        for item in blocking[:args.limit]:
            print(f"   {item}")
        for item in stale[:args.limit]:
            print(f"   STALE_ALLOWLIST {item}")
        if args.fail and group in blocking_groups and (blocking or stale):
            if blocking:
                blocking_hits.append(f"{group}: {len(blocking)} blocking item(s)")
            if stale:
                blocking_hits.append(f"{group}: {len(stale)} stale allowlist item(s)")

    print(f"\n## fa.duplicates (FA value reused by >1 key) — {len(g['fa.duplicates'])} values")
    for v, ks in list(g['fa.duplicates'].items())[:args.limit]:
        print(f"   [{len(ks)} keys] {v[:60]!r} -> {ks[:3]}")
    if args.fail and 'fa.duplicates' in blocking_groups and g['fa.duplicates']:
        blocking_hits.append(f"fa.duplicates: {len(g['fa.duplicates'])} value(s)")

    print(f"\n## en.multi-fa (EN value from >1 distinct FA) — {len(g['en.multi-fa'])} values")
    for v, srcs in list(g['en.multi-fa'].items())[:args.limit]:
        print(f"   {v[:60]!r} <- {srcs[:3]}")
    if args.fail and 'en.multi-fa' in blocking_groups and g['en.multi-fa']:
        blocking_hits.append(f"en.multi-fa: {len(g['en.multi-fa'])} value(s)")

    nonempty = sum(1 for grp in (g['en.empty'], g['fa.no-persian'], g['fa.en.identical'], g['fa.duplicates'], g['en.multi-fa']) if grp)
    mode = 'blocking' if args.fail else 'report-only'
    print(f"\nSummary: {nonempty}/5 anomaly groups non-empty ({mode}).")
    if args.fail and blocking_hits:
        for hit in blocking_hits:
            print(f"BLOCKING: {hit}", file=sys.stderr)
        return 1
    return 0


def main(argv: list[str] | None = None) -> int:
    return run(argv)


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
