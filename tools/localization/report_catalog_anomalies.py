#!/usr/bin/env python3
"""PR-01 (V-7) — Catalog anomaly report (REPORT-ONLY).

Prints a human-readable inventory of catalog quality anomalies. This is NOT a
blocking check in PR-01: it always exits 0 unless --fail is passed. The
anomalies become blocking rules in a later phase (P6) after triage.

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

PERSIAN_RE = re.compile('[\u0600-\u06FF\u0750-\u077F\u08A0-\u08FF\uFB50-\uFDFF\uFE70-\uFEFF]')

DEFAULT_REPO_ROOT = Path(__file__).resolve().parents[2]
CATALOG_RELS = (('public', 'locales', 'fa.json'), ('public', 'locales', 'en.json'))


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

    groups['fa.en.identical'] = [k for k in common if k in fa and k in en and fa[k] == en[k]]

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


def run(argv) -> int:
    parser = argparse.ArgumentParser(description="Catalog anomaly report (report-only, PR-01 V-7)")
    parser.add_argument('--root', help="repo root (default: derive from script location)")
    parser.add_argument('--limit', type=int, default=15, help="max examples printed per group (default 15)")
    parser.add_argument('--fail', action='store_true', help="exit 1 if any anomaly group is non-empty (off by default in PR-01)")
    args = parser.parse_args(argv)

    root = (Path(args.root) if args.root else DEFAULT_REPO_ROOT).resolve()
    g = report(root, args.limit)

    print("VELORA catalog anomaly report (report-only)")
    _print("en.empty (EN empty strings)", g['en.empty'], args.limit)
    _print("fa.no-persian (FA values without Persian script)", g['fa.no-persian'], args.limit)
    _print("fa.en.identical (FA == EN)", g['fa.en.identical'], args.limit)
    print(f"\n## fa.duplicates (FA value reused by >1 key) — {len(g['fa.duplicates'])} values")
    for v, ks in list(g['fa.duplicates'].items())[:args.limit]:
        print(f"   [{len(ks)} keys] {v[:60]!r} -> {ks[:3]}")
    print(f"\n## en.multi-fa (EN value from >1 distinct FA) — {len(g['en.multi-fa'])} values")
    for v, srcs in list(g['en.multi-fa'].items())[:args.limit]:
        print(f"   {v[:60]!r} <- {srcs[:3]}")

    nonempty = sum(1 for grp in (g['en.empty'], g['fa.no-persian'], g['fa.en.identical'], g['fa.duplicates'], g['en.multi-fa']) if grp)
    print(f"\nSummary: {nonempty}/5 anomaly groups non-empty (report-only, not blocking).")
    if args.fail and nonempty:
        print("--fail requested and anomalies present.", file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(run(sys.argv[1:]))
