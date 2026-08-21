#!/usr/bin/env python3
"""PR-01 (V-4) — Frozen hash-key validator.

Velora's catalog keys historically carry a content-hash suffix (8 hex chars),
e.g. `common.30.days.6ff5e162`. New work must use semantic keys only. This
checker freezes the current set of hashed keys and fails the gate when a NEW
hashed key appears in either catalog.

Key set = union of `messages` keys in `public/locales/fa.json` and
`public/locales/en.json`. A key is "hashed" when it ends in 8 hex chars.

Exit codes:
  0  PASS
  1  FAIL (new hashed key, or frozen snapshot self-inconsistency)
  2  usage or input error (bad args, malformed/missing files)
"""
from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

HASH_SUFFIX_RE = re.compile(r'[0-9a-f]{8}$')

DEFAULT_REPO_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_FROZEN = DEFAULT_REPO_ROOT / 'tools' / 'localization' / 'frozen-hash-keys.json'
CATALOG_RELS = (('public', 'locales', 'fa.json'), ('public', 'locales', 'en.json'))


def _now_iso() -> str:
    return datetime.now(timezone.utc).isoformat(timespec='seconds')


def _read(path: Path) -> str:
    return path.read_text(encoding='utf-8', errors='replace')


def load_union(root: Path) -> set:
    keys = set()
    for rel in CATALOG_RELS:
        p = root.joinpath(*rel)
        if not p.exists():
            raise FileNotFoundError(str(p))
        data = json.loads(_read(p))
        messages = data.get('messages')
        if not isinstance(messages, dict):
            raise ValueError(f"{p}: 'messages' key missing or not an object")
        keys |= set(messages.keys())
    return keys


def hashed_keys(keys) -> set:
    return {k for k in keys if HASH_SUFFIX_RE.search(k)}


def _head_sha() -> str:
    try:
        out = subprocess.run(['git', 'rev-parse', 'HEAD'], capture_output=True, text=True, timeout=10)
        if out.returncode == 0:
            return out.stdout.strip()[:8]
    except Exception:  # noqa: BLE001
        pass
    return 'unknown'


def _generate(root: Path, frozen_path: Path) -> int:
    hashed = hashed_keys(load_union(root))
    data = {
        "version": 1,
        "generated_at": _now_iso(),
        "source": f"public/locales/{{fa,en}}.json messages union @ {_head_sha()}",
        "hash_suffix_re": r"[0-9a-f]{8}$",
        "count": len(hashed),
        "keys": sorted(hashed),
    }
    frozen_path.write_text(json.dumps(data, ensure_ascii=False, indent=1) + "\n", encoding='utf-8')
    print(f"frozen-hash-keys written: {frozen_path} ({len(hashed)} keys)")
    return 0


def _check(root: Path, frozen_path: Path) -> int:
    if not frozen_path.exists():
        print(f"ERROR: frozen file not found: {frozen_path}", file=sys.stderr)
        return 2
    try:
        data = json.loads(_read(frozen_path))
    except Exception as e:  # noqa: BLE001
        print(f"ERROR: malformed frozen JSON: {e}", file=sys.stderr)
        return 2

    declared = set(data.get('keys') or [])
    declared_count = data.get('count')
    if not isinstance(declared_count, int) or declared_count != len(declared):
        print(f"ERROR: frozen snapshot self-inconsistent (count={declared_count!r}, len(keys)={len(declared)})", file=sys.stderr)
        return 1

    hashed = hashed_keys(load_union(root))
    new = sorted(hashed - declared)
    removed = sorted(declared - hashed)

    print(f"frozen-hash scan: {len(hashed)} hashed keys in tree; {len(declared)} frozen")
    for k in removed:
        print(f"WARNING: hashed key removed from catalogs (no longer present): {k}")

    if new:
        for k in new:
            print(f"ERROR: NEW HASHED KEY: {k} (use a semantic key instead; hashed keys are frozen)", file=sys.stderr)
        return 1

    print("PASS: no new hashed keys (freeze intact).")
    return 0


def run(argv) -> int:
    parser = argparse.ArgumentParser(description="Frozen hash-key validator (PR-01 V-4)")
    parser.add_argument('--root', help="repo root (default: derive from script location)")
    parser.add_argument('--frozen', help="frozen JSON path (default: tools/localization/frozen-hash-keys.json)")
    parser.add_argument('--generate', action='store_true', help="(re)generate the frozen snapshot from the current catalogs and exit")
    args = parser.parse_args(argv)

    root = (Path(args.root) if args.root else DEFAULT_REPO_ROOT).resolve()
    frozen_path = Path(args.frozen) if args.frozen else (root / 'tools' / 'localization' / 'frozen-hash-keys.json')

    if args.generate:
        return _generate(root, frozen_path)
    return _check(root, frozen_path)


if __name__ == '__main__':
    sys.exit(run(sys.argv[1:]))
