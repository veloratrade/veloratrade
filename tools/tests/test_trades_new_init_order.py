#!/usr/bin/env python3
"""Structural regression test for the trades/new initializer (2026-08-25 session).

The symbol picker died because start() called renderSymbolList() before the
``var GROUP_TITLE / GROUP_ORDER / DEFAULT_STARS`` assignments later in the same
function body (var-hoisting left them undefined), which aborted the whole
initializer: empty symbol list, dead search, dead submit.

This test pins the fix statically on the root template and both localized
builds (fast, no browser — the behavioural spec lives in
tools/e2e/trades-new.spec.js):

  1. GROUP_ORDER/GROUP_TITLE/DEFAULT_STARS are assigned BEFORE the first
     direct renderSymbolList(allSymbols) call;
  2. renderSymbolList keeps the init-order guard;
  3. the dropdown max-height rule is NOT ``!important`` (it must lose to the
     viewport-aware inline max-height set by velora-sym-picker.js);
  4. pages reference the visualViewport-aware picker build (>= 2026.08.25.1).
"""

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
FILES = [
    ROOT / "trades" / "new" / "index.html",
    ROOT / "localized" / "fa" / "trades" / "new" / "index.html",
    ROOT / "localized" / "en" / "trades" / "new" / "index.html",
]
MIN_PICKER_VERSION = (2026, 8, 25, 1)

DIRECT_RENDER_CALL = re.compile(r"^\s{4}renderSymbolList\(allSymbols\);", re.M)


def check(path: Path) -> None:
    src = path.read_text(encoding="utf-8")

    order_fix = src.find("var GROUP_ORDER")
    first_call = DIRECT_RENDER_CALL.search(src)
    assert order_fix > 0, f"{path}: GROUP_ORDER assignment missing"
    assert first_call, f"{path}: direct renderSymbolList(allSymbols) call not found"
    assert order_fix < first_call.start(), (
        f"{path}: GROUP_ORDER is assigned AFTER the first renderSymbolList call "
        "(init-order regression — the initializer would crash again)"
    )
    for constant in ("var GROUP_TITLE", "var DEFAULT_STARS"):
        pos = src.find(constant)
        assert 0 < pos < first_call.start(), (
            f"{path}: {constant} must be assigned before the first render call"
        )

    guard = src.find("Array.isArray(GROUP_ORDER)")
    render_start = src.find("function renderSymbolList(list)")
    assert guard > render_start > 0, f"{path}: init-order guard missing inside renderSymbolList"

    assert "max-height: min(58vh, 420px) !important" not in src, (
        f"{path}: dropdown max-height must not be !important — it would beat the "
        "viewport-aware inline max-height from velora-sym-picker.js (keyboard/short viewports)"
    )

    versions = re.findall(r"velora-sym-picker\.js\?v=([0-9.]+)", src)
    assert versions, f"{path}: velora-sym-picker.js reference not found"
    for raw in versions:
        parsed = tuple(int(part) for part in raw.split("."))
        assert parsed >= MIN_PICKER_VERSION, (
            f"{path}: velora-sym-picker.js?v={raw} predates the visualViewport fix"
        )


def main() -> int:
    picker = (ROOT / "public" / "assets" / "velora-sym-picker.js").read_text(encoding="utf-8")
    assert "visualViewport" in picker, (
        "public/assets/velora-sym-picker.js lost the visualViewport-aware sizing"
    )
    for path in FILES:
        assert path.is_file(), f"missing file: {path}"
        check(path)
    print(f"TRADES_NEW_INIT_ORDER: PASS ({len(FILES)} page(s) + picker asset checked)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
