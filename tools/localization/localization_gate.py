#!/usr/bin/env python3
"""Localization Feature Gate — a single, minimal entry point that prevents new
untranslated UI strings / missing catalog keys from entering the project.

Design goal (per the FA/EN bilingual closure task): this is intentionally a
*composition* layer, not new detection logic. It reuses the existing,
already-independently-blocking validators exactly as-is:

  * ``validate_localization.py``  — catalog schema, FA/EN keyset parity,
    missing-key references, CSP linkage, generated-output consistency.
  * ``check_hardcoded_ui.py``     — the hardcoded-Persian-literal freeze
    (new hardcoded UI copy outside the catalog is rejected; the allowlist
    must stay in sync with the tree).
  * ``check_static_attributes.py`` — admin artifact static-attribute
    localization integrity (no Persian baked into untranslated attributes;
    every data-i18n key resolves; the applier exists).
  * ``runtime_locale_integrity``  — rendered-output FA/EN guard over the
    real UI (Playwright): EN must render no Arabic-script application copy,
    FA no unexpected English copy — UI-owned signals only, so user data is
    never flagged.

All checks already run in ``quality-gate.yml``/``ci.yml`` as separate,
explicit, blocking steps. This module stays the single, obviously-named
command: "did I break localization?" has one clear answer.

Runtime layer note: browser-based checks are NOT silently skipped. Without
Playwright installed the gate FAILS with an install hint; local runs may pass
``--skip-runtime`` explicitly to opt out (CI never does).

Usage:
    python -m tools.localization.localization_gate
    python -m tools.localization.localization_gate --root /path/to/repo
    python -m tools.localization.localization_gate --skip-runtime   # local only

Exit codes:
  0  PASS — all checks passed.
  1  FAIL — at least one check failed; the printed output identifies which.
  2  usage or input error (mirrors the underlying tools' own exit codes).
"""
from __future__ import annotations

import argparse
import sys
from pathlib import Path

try:
    from .validate_localization import validate_localization
    from . import check_hardcoded_ui as _hardcoded_ui
    from . import check_static_attributes as _static_attrs
except ImportError:  # Direct script execution.
    from validate_localization import validate_localization  # type: ignore
    import check_hardcoded_ui as _hardcoded_ui  # type: ignore
    import check_static_attributes as _static_attrs  # type: ignore

DEFAULT_REPO_ROOT = Path(__file__).resolve().parents[2]


def run_gate(root: Path, skip_runtime: bool = False) -> tuple[bool, list[str]]:
    """Run the existing validators against *root*. Returns (ok, messages)."""
    messages: list[str] = []
    ok = True

    # 1. Catalog parity / missing-key / route-contract validation (existing,
    #    already-blocking check — reused verbatim, no reimplementation).
    result = validate_localization(root)
    if result.errors:
        ok = False
        messages.append("CATALOG VALIDATION FAILED (validate_localization.py):")
        for error in result.errors:
            messages.append(f"  - {error}")
    else:
        messages.append(
            "Catalog validation OK: "
            f"routes={result.routes} canonical={result.canonical} "
            f"localized={result.localized} locales={result.locales}"
        )

    # 2. Hardcoded-UI-string freeze (existing, already-blocking check — reused
    #    verbatim; a NEW untranslated literal outside the catalog fails here).
    allowlist_path = root / "tools" / "localization" / "allowlist-hardcoded.json"
    violations = _hardcoded_ui.scan(root)
    if not allowlist_path.exists():
        ok = False
        messages.append(f"HARDCODED-UI CHECK FAILED: allowlist not found: {allowlist_path}")
    else:
        import json

        try:
            data = json.loads(allowlist_path.read_text(encoding="utf-8"))
        except Exception as exc:  # noqa: BLE001
            ok = False
            messages.append(f"HARDCODED-UI CHECK FAILED: malformed allowlist JSON: {exc}")
            data = None
        if data is not None:
            # _validate() prints its own PASS/ERROR lines; capture the exit code
            # by calling it directly rather than reimplementing its comparison
            # logic (keeps this module a thin composition layer, not a fork).
            code = _hardcoded_ui._validate(violations, data)
            if code != 0:
                ok = False
                messages.append(
                    "HARDCODED-UI CHECK FAILED (check_hardcoded_ui.py) — "
                    "see stderr above for NEW VIOLATION / DRIFT / ORPHAN detail."
                )
            else:
                total_lits = sum(len(v) for v in violations.values())
                messages.append(
                    f"Hardcoded-UI freeze OK: {len(data.get('entries', []))} allowlist "
                    f"entries, {len(violations)} file(s) / {total_lits} tracked literal(s)."
                )

    # 3. Static attribute localization integrity (admin artifact) — reused
    #    verbatim; Persian must never be baked into untranslated attributes.
    attr_problems = _static_attrs.scan(root)
    if attr_problems:
        ok = False
        messages.append("STATIC ATTRIBUTE LOCALIZATION FAILED (check_static_attributes.py):")
        messages.extend(f"  - {p}" for p in attr_problems)
    else:
        messages.append("Static attribute localization OK (admin artifact attributes + data-i18n resolution).")

    # 4. Runtime locale integrity (rendered output) — blocking wherever the
    #    gate runs; only an explicit --skip-runtime (local convenience) omits
    #    it, so it can never be silently skipped.
    if skip_runtime:
        messages.append("Runtime locale integrity: SKIPPED BY EXPLICIT --skip-runtime (local runs only; CI runs it).")
    else:
        import subprocess

        proc = subprocess.run(
            [sys.executable, "-m", "tools.localization.runtime_locale_integrity", "--root", str(root)],
            cwd=str(root), capture_output=True, text=True, timeout=600)
        tail = (proc.stdout or "").strip().splitlines() or (proc.stderr or "").strip().splitlines()
        if proc.returncode == 0:
            messages.append("Runtime locale integrity OK: " + (tail[-1] if tail else "EN/FA rendered output clean."))
        else:
            ok = False
            messages.append("RUNTIME LOCALE INTEGRITY FAILED (runtime_locale_integrity.py):")
            messages.extend(f"  {l}" for l in tail[-12:])

    return ok, messages


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", help="repo root (default: derive from script location)")
    parser.add_argument("--skip-runtime", action="store_true",
                        help="local convenience: omit the Playwright runtime layer (CI never passes this)")
    args = parser.parse_args(argv)

    root = (Path(args.root) if args.root else DEFAULT_REPO_ROOT).resolve()

    print("Velora Localization Feature Gate")
    print("=" * 33)
    ok, messages = run_gate(root, skip_runtime=args.skip_runtime)
    for m in messages:
        print(m)

    if ok:
        print("\nLOCALIZATION_GATE_OK")
        return 0
    print("\nLOCALIZATION_GATE_FAILED", file=sys.stderr)
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
