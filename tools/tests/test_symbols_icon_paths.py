#!/usr/bin/env python3
"""F-09 regression check: every icon declared in symbols.json must resolve to a real file.

Scope is intentionally narrow: only public/assets/symbols/symbols.json is
inspected. Icon values use the canonical "assets/..." form which the runtime
(symbol-icons.js) rewrites to "public/assets/...", so both spellings resolve
to the same repository path. Entries without an "icon" field are ignored
(they render through the letter fallback and are not errors).
"""
from __future__ import annotations

import json
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
REGISTRY = REPO_ROOT / "public/assets/symbols/symbols.json"


def resolve_icon(icon: str) -> Path:
    relative = icon.lstrip("/")
    if relative.startswith("assets/"):
        relative = "public/" + relative
    return REPO_ROOT / relative


def main() -> int:
    registry = json.loads(REGISTRY.read_text(encoding="utf-8"))
    checked = 0
    broken: list[str] = []
    for symbol, meta in registry.items():
        if not isinstance(meta, dict):
            continue
        icon = meta.get("icon")
        if not icon:
            continue
        checked += 1
        target = resolve_icon(str(icon))
        if not target.is_file():
            broken.append(f"{symbol} -> {icon} (missing: {target.relative_to(REPO_ROOT)})")
    if broken:
        print("SYMBOLS_ICON_PATHS_FAIL broken=%d" % len(broken))
        for line in broken:
            print("  " + line)
        return 1
    print(f"SYMBOLS_ICON_PATHS_OK checked={checked} broken=0")
    return 0


if __name__ == "__main__":
    sys.exit(main())
