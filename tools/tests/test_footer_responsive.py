#!/usr/bin/env python3
"""F1 regression: narrow-viewport footer newsletter must not overflow.

Root cause (F1): inside the single-column footer grid at narrow widths the
newsletter row (`.f-news .nl-form`, flex-wrap:nowrap) could force the grid
track wider than the viewport, clipping the subscribe button.

Fix (Option A): `.f-news .nl-form input{width:140px}` scoped to the existing
`@media(max-width:720px)` block only.

Checks 7 invariants per page/viewport over EN/FA x 320/360/390 = 42 checks.

Requires Playwright (Python) with Chromium. If Playwright is unavailable the
test exits 0 with an explicit SKIP so static/compile-only pipelines stay green.
"""
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PAGES = (
    ("EN", ROOT / "localized/en/index.html"),
    ("FA", ROOT / "localized/fa/index.html"),
)
WIDTHS = (320, 360, 390)
TOLERANCE_PX = 2  # sub-pixel rounding only; not a licence for real overflow


def run() -> int:
    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        print("SKIP test_footer_responsive: playwright not installed")
        return 0

    failures: list[str] = []
    checks = 0

    with sync_playwright() as p:
        browser = p.chromium.launch()
        for label, page_path in PAGES:
            if not page_path.is_file():
                failures.append(f"{label}: missing page {page_path}")
                continue
            for width in WIDTHS:
                page = browser.new_page(viewport={"width": width, "height": 800})
                page.goto(page_path.as_uri(), wait_until="load")
                m = page.evaluate(
                    """() => {
                        const de = document.documentElement;
                        const form = document.querySelector('.f-news .nl-form');
                        const input = form && form.querySelector('input');
                        const btn = form && form.querySelector('.btn, button');
                        const r = (el) => {
                            if (!el) return null;
                            const b = el.getBoundingClientRect();
                            return {left: b.left, right: b.right, width: b.width};
                        };
                        return {
                            scrollWidth: de.scrollWidth,
                            bodyScrollWidth: document.body.scrollWidth,
                            form: r(form), input: r(input), btn: r(btn),
                        };
                    }"""
                )
                page.close()
                ctx = f"{label}@{width}"
                tol = TOLERANCE_PX

                def check(name: str, ok: bool, detail: str) -> None:
                    nonlocal checks
                    checks += 1
                    status = "PASS" if ok else "FAIL"
                    print(f"{status} {ctx} {name}: {detail}")
                    if not ok:
                        failures.append(f"{ctx} {name}: {detail}")

                check("doc-no-hscroll", m["scrollWidth"] <= width + tol,
                      f"documentElement.scrollWidth={m['scrollWidth']} vw={width}")
                check("body-no-hscroll", m["bodyScrollWidth"] <= width + tol,
                      f"body.scrollWidth={m['bodyScrollWidth']} vw={width}")
                check("form-present", m["form"] is not None, "newsletter form found")
                if m["form"] is None:
                    continue
                check("form-right-in-vw", m["form"]["right"] <= width + tol,
                      f"form.right={m['form']['right']:.1f} vw={width}")
                check("form-left-in-vw", m["form"]["left"] >= -tol,
                      f"form.left={m['form']['left']:.1f}")
                ok_btn = (
                    m["btn"] is not None
                    and m["btn"]["right"] <= width + tol
                    and m["btn"]["left"] >= -tol
                )
                check("subscribe-fully-visible", ok_btn,
                      f"btn={m['btn']}")
                ok_input = (
                    m["input"] is not None
                    and m["input"]["right"] <= width + tol
                    and m["input"]["left"] >= -tol
                )
                check("input-fully-visible", ok_input,
                      f"input={m['input']}")
        browser.close()

    print(f"TOTAL checks={checks} failures={len(failures)}")
    if failures:
        for f in failures:
            print(f"FAILURE: {f}")
        return 1
    if checks != 42:
        print(f"FAILURE: expected 42 checks, ran {checks}")
        return 1
    print("F1_FOOTER_RESPONSIVE_OK 42/42")
    return 0


if __name__ == "__main__":
    sys.exit(run())
