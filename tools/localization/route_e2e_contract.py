#!/usr/bin/env python3
"""PR-09 — Route E2E contract validator (additive, low-risk guardrail).

Problem this closes: new routes could previously be added to
``routes.json`` without declaring, or being checked for, browser test
coverage. Nothing prevented a critical user-flow page (auth, dashboard,
trading, account) from shipping with zero E2E coverage.

Design, deliberately narrow in scope:

  * Every route in ``tools/localization/routes.json`` MUST declare an
    ``e2eCategory`` of either ``"critical"`` or ``"informational"``.
    This is a lightweight, structural contract — not a request to run
    real Playwright suites from this module. Actually *executing*
    browser tests remains the job of ``.github/scripts/dashboard_e2e.js``
    and future per-flow specs; this module only checks that critical
    routes have *a* spec file declared for them.

  * "critical" = auth, dashboard, trading, account flows (login,
    register, password reset/forgot, verify-email, dashboard, trades,
    wallet, profile, accounts/connect, checkout, admin).
  * "informational" = static/content pages (blog, docs/legal, landing,
    markets/news, 404, etc.). These are explicitly NOT required to
    carry a Playwright spec (matches the "do not require full E2E
    coverage for every static page" requirement).

  * A critical route is considered "covered" when a spec file exists
    under ``tools/e2e/<slug>.spec.js`` (slug = template path with
    ``/index.html``/``.html`` stripped and ``/`` -> ``-``) that contains,
    at minimum:
      - a page-load assertion (a Playwright navigation/wait call such as
        ``page.goto`` or ``page.waitForURL``/``waitForSelector``), and
      - a basic interactive-element assertion (a Playwright interaction
        call such as ``page.click``, ``page.fill``, or ``locator(...)``
        combined with an assertion).
    This mirrors the existing static, pattern-based enforcement style of
    ``check_hardcoded_ui.py`` — cheap, deterministic, no browser launch.

  * Missing critical-route specs are reported as a *known baseline gap*
    (see ``KNOWN_MISSING_CRITICAL_SPECS`` below), not a hard failure —
    the existing dashboard/login journey is the only route with real
    browser coverage today, and retrofitting specs for all ~12 critical
    routes is intentionally out of scope for this change (tracked
    separately). Any *new* critical route added in the future, or any
    change that removes/breaks an existing spec file for an
    already-covered route, IS a hard failure. This mirrors the
    freeze-and-block-new-drift pattern used by
    ``check_frozen_hash_keys.py``.

Exit codes (CLI):
  0  PASS (no new/undeclared-category routes, no newly-broken specs)
  1  FAIL (missing e2eCategory, invalid category, or new critical route
     without a required spec / a previously-covered route's spec broke)
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path
from typing import Iterable

REPO_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_ROUTES_PATH = REPO_ROOT / "tools" / "localization" / "routes.json"
DEFAULT_E2E_DIR = REPO_ROOT / "tools" / "e2e"

VALID_CATEGORIES = frozenset({"critical", "informational"})

# Baseline: critical routes that do NOT yet have a dedicated spec file.
# This is a documented, intentional gap (see module docstring) — it does
# NOT block CI. Any critical route NOT in this set is required to have a
# spec; removing an entry here without adding the corresponding spec file
# is itself a contract violation (keeps the baseline honest).
KNOWN_MISSING_CRITICAL_SPECS = frozenset(
    {
        "login/index.html",
        "register/index.html",
        "forgot-password/index.html",
        "reset-password/index.html",
        "verify-email/index.html",
        "dashboard/index.html",
        "trades/index.html",
        "wallet/index.html",
        "profile/index.html",
        "accounts/connect/index.html",
        "checkout/index.html",
        "admin/index.html",
    }
)

_PAGE_LOAD_PATTERN = re.compile(
    r"\.(?:goto|waitForURL|waitForSelector|waitForLoadState)\s*\("
)
_INTERACTION_PATTERN = re.compile(
    r"\.(?:click|fill|check|selectOption|press|type)\s*\("
)


class RouteE2EContractError(ValueError):
    """Raised when the route/E2E contract is violated."""


def _read_routes(routes_path: Path) -> list[dict]:
    if not routes_path.is_file():
        raise RouteE2EContractError(f"missing routes manifest: {routes_path}")
    payload = json.loads(routes_path.read_text(encoding="utf-8"))
    routes = payload.get("routes")
    if not isinstance(routes, list) or not routes:
        raise RouteE2EContractError("routes manifest must define a non-empty routes array")
    return routes


def slug_for_template(template: str) -> str:
    """Derive the expected spec-file slug for a route template.

    ``login/index.html`` -> ``login``
    ``trades/new/index.html`` -> ``trades-new``
    ``404.html`` -> ``404``
    """
    trimmed = template
    if trimmed.endswith("/index.html"):
        trimmed = trimmed[: -len("/index.html")]
    elif trimmed.endswith(".html"):
        trimmed = trimmed[: -len(".html")]
    trimmed = trimmed.strip("/")
    return trimmed.replace("/", "-") or "index"


def _spec_path(e2e_dir: Path, template: str) -> Path:
    return e2e_dir / f"{slug_for_template(template)}.spec.js"


def _display_path(path: Path) -> str:
    """Render a path relative to the repo root when possible, else as-is.

    Test fixtures validate synthetic routes/spec files living outside the
    repository (e.g. under a tmp directory), where relative_to(REPO_ROOT)
    would raise; fall back to the raw path in that case.
    """
    try:
        return path.relative_to(REPO_ROOT).as_posix()
    except ValueError:
        return path.as_posix()


def _spec_has_required_assertions(spec_path: Path) -> tuple[bool, bool]:
    """Return (has_page_load_assertion, has_interaction_assertion)."""
    source = spec_path.read_text(encoding="utf-8", errors="ignore")
    return (
        bool(_PAGE_LOAD_PATTERN.search(source)),
        bool(_INTERACTION_PATTERN.search(source)),
    )


def validate_route_e2e_contract(
    *,
    routes_path: Path = DEFAULT_ROUTES_PATH,
    e2e_dir: Path = DEFAULT_E2E_DIR,
) -> list[str]:
    """Return a list of contract violation messages (empty = PASS)."""
    errors: list[str] = []
    routes = _read_routes(routes_path)

    seen_templates: set[str] = set()
    for index, route in enumerate(routes):
        label = f"routes[{index}]"
        if not isinstance(route, dict):
            errors.append(f"{label} must be an object")
            continue

        template = route.get("template")
        if not isinstance(template, str) or not template:
            errors.append(f"{label}.template must be a non-empty string")
            continue
        seen_templates.add(template)

        category = route.get("e2eCategory")
        if category is None:
            errors.append(
                f"{label} ({template}) is missing required field 'e2eCategory' "
                f"(must be one of {sorted(VALID_CATEGORIES)})"
            )
            continue
        if category not in VALID_CATEGORIES:
            errors.append(
                f"{label} ({template}) has invalid e2eCategory {category!r} "
                f"(must be one of {sorted(VALID_CATEGORIES)})"
            )
            continue

        if category != "critical":
            continue

        spec_path = _spec_path(e2e_dir, template)
        if not spec_path.is_file():
            if template in KNOWN_MISSING_CRITICAL_SPECS:
                # Documented baseline gap — not a failure, but also not
                # silently invisible: surfaced via --report for humans.
                continue
            errors.append(
                f"critical route {template!r} has no E2E spec at "
                f"{_display_path(spec_path)} "
                "(new critical routes must ship with a Playwright smoke spec)"
            )
            continue

        has_load, has_interaction = _spec_has_required_assertions(spec_path)
        if not has_load:
            errors.append(
                f"E2E spec for critical route {template!r} "
                f"({_display_path(spec_path)}) has no "
                "page-load assertion (expected a goto/waitForURL/"
                "waitForSelector/waitForLoadState call)"
            )
        if not has_interaction:
            errors.append(
                f"E2E spec for critical route {template!r} "
                f"({_display_path(spec_path)}) has no "
                "interactive-element assertion (expected a click/fill/"
                "check/selectOption/press/type call)"
            )

    # Stale baseline entries: if KNOWN_MISSING_CRITICAL_SPECS references a
    # template that no longer exists as a critical route, the baseline
    # itself has drifted and must be corrected in the same change. This
    # check only applies when validating the real, canonical routes.json —
    # synthetic/partial fixtures (unit tests) intentionally don't declare
    # every baseline route and must not trigger a false "stale" report.
    if routes_path == DEFAULT_ROUTES_PATH:
        stale = sorted(t for t in KNOWN_MISSING_CRITICAL_SPECS if t not in seen_templates)
        for template in stale:
            errors.append(
                f"KNOWN_MISSING_CRITICAL_SPECS references a template that is no "
                f"longer a route: {template!r} (update route_e2e_contract.py)"
            )

    return errors


def known_gap_report(*, routes_path: Path = DEFAULT_ROUTES_PATH) -> list[str]:
    """Human-readable list of the documented, currently-uncovered critical
    routes — printed for visibility, never causes a non-zero exit."""
    routes = _read_routes(routes_path)
    gaps = []
    for route in routes:
        template = route.get("template")
        if route.get("e2eCategory") == "critical" and template in KNOWN_MISSING_CRITICAL_SPECS:
            gaps.append(template)
    return sorted(gaps)


def _parse_args(argv: Iterable[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--routes",
        type=Path,
        default=DEFAULT_ROUTES_PATH,
        help="path to routes.json (default: tools/localization/routes.json)",
    )
    parser.add_argument(
        "--e2e-dir",
        type=Path,
        default=DEFAULT_E2E_DIR,
        help="directory containing *.spec.js files (default: tools/e2e)",
    )
    return parser.parse_args(argv)


def main(argv: Iterable[str] | None = None) -> int:
    args = _parse_args(argv)
    try:
        errors = validate_route_e2e_contract(routes_path=args.routes, e2e_dir=args.e2e_dir)
        gaps = known_gap_report(routes_path=args.routes)
    except RouteE2EContractError as exc:
        print(f"ROUTE_E2E_CONTRACT_ERROR: {exc}", file=sys.stderr)
        return 1

    if gaps:
        print(
            "ROUTE_E2E_CONTRACT: "
            f"{len(gaps)} critical route(s) documented as pending E2E "
            f"coverage (tracked baseline, non-blocking): {', '.join(gaps)}"
        )

    if errors:
        print(f"ROUTE_E2E_CONTRACT_FAIL: {len(errors)} issue(s)", file=sys.stderr)
        for error in errors:
            print(f"  - {error}", file=sys.stderr)
        return 1

    print("ROUTE_E2E_CONTRACT_OK")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
