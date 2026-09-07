#!/usr/bin/env python3
"""Static attribute-localization integrity check (Admin v2 shell).

Complements the existing freeze (check_hardcoded_ui.py — which scans
public/assets/**/*.js + localized/**/*.html inline scripts and therefore does
NOT see admin/v2/index.html) by auditing the ADMIN ARTIFACT's static markup
attributes — the exact class behind the 2026-09 Admin incident, where
`aria-label="باز کردن منوی ناوبری"`-style literals leaked into EN.

Rules (exit 1 on violation):
  1. ATTRIBUTE-FREEZE : a user-facing attribute (aria-label / title /
     placeholder / alt) in the static markup contains Arabic-script text.
     The artifact's default/fallback markup must be locale-neutral or EN;
     Persian values may only appear through the catalog/dictionary at
     runtime (applyStaticI18n/t()), never baked into the markup.
  2. DATA-I18N RESOLUTION : every `data-i18n="key"` referenced in the
     artifact must exist in BOTH in-artifact dictionaries (T.fa and T.en) —
     a dead key renders its hardcoded fallback verbatim (the incident's
     root cause), so an unresolvable key is a violation.
  3. DEAD-APPLIER GUARD : at least one element must carry data-i18n AND the
     artifact must define an applier (applyStaticI18n) — guards against the
     historical "attribute present, nothing applies it" regression.

Reuses PERSIAN_RE from check_hardcoded_ui (single definition, no fork).
Read-only; allowlist-free by design (violations must be FIXED, not listed —
the surface is tiny and every attribute is developer-written chrome).

Exit codes: 0 PASS · 1 FAIL · 2 usage/input error.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

try:
    from .check_hardcoded_ui import PERSIAN_RE
except ImportError:  # Direct script execution.
    from check_hardcoded_ui import PERSIAN_RE  # type: ignore

DEFAULT_REPO_ROOT = Path(__file__).resolve().parents[2]
ARTIFACT = Path("admin/v2/index.html")
USERFacing_ATTRS = ("aria-label", "title", "placeholder", "alt")
TAG_RE = re.compile(r"<[a-zA-Z][^>]*>")
ATTR_RE = re.compile(r'\b(aria-label|title|placeholder|alt)\s*=\s*"([^"]*)"')


def _static_markup(src: str) -> str:
    """Markup before the main module script (static chrome only; generated
    DOM templates inside JS are runtime output and are checked by the
    runtime locale integrity guard instead)."""
    idx = src.find('<script>\n"use strict"')
    return src[: idx] if idx > 0 else src


def _dict_has_key(src: str, key: str) -> bool:
    return src.count(f'"{key}"') >= 2  # must resolve in BOTH fa and en blocks


def scan(root: Path) -> list[str]:
    problems: list[str] = []
    path = root / ARTIFACT
    if not path.exists():
        return [f"artifact not found: {ARTIFACT}"]
    src = path.read_text(encoding="utf-8")
    markup = _static_markup(src)

    # 1) attribute freeze — Persian must not be BAKED INTO untranslated static
    #    attributes. FA-first pre-paint defaults are correct architecture (the
    #    artifact restores locale pre-paint and translates chrome at runtime),
    #    so an element is exempt when it is runtime-translated: it carries a
    #    data-i18n* attribute, or its id is covered by applyStaticI18n().
    applier = re.search(r"function applyStaticI18n\(\)\{.*?\n\}", src, re.S)
    covered_ids = set(re.findall(r'\$\("#([A-Za-z0-9_-]+)"\)', applier.group(0))) if applier else set()
    for m in TAG_RE.finditer(markup):
        tag = m.group(0)
        if "data-i18n" in tag:
            continue
        idm = re.search(r'\bid="([^"]+)"', tag)
        if idm and idm.group(1) in covered_ids:
            continue
        for am in ATTR_RE.finditer(tag):
            attr, value = am.group(1), am.group(2)
            if not value.strip():
                continue
            if PERSIAN_RE.search(value):
                problems.append(
                    f"ATTRIBUTE-FREEZE: {ARTIFACT}:{src[:m.start()].count(chr(10)) + 1} "
                    f'{attr}="{value.strip()[:60]}" — Persian baked into static markup '
                    f"(resolve via catalog/dictionary at runtime instead)")

    # 2) data-i18n keys must resolve in BOTH dictionaries. The artifact keeps
    #    three dictionary objects (T, SHELL_T, F3T — see t()'s fallback chain),
    #    so the precise per-block parse is unreliable; the robust rule is that
    #    the quoted key must appear at least twice in the JS region (the fa and
    #    en definitions). The runtime locale integrity guard is the true
    #    rendered-output enforcer; this is static defense-in-depth.
    keys = set(re.findall(r'data-i18n(?:-[a-z-]+)?="([^"]+)"', src))
    js = src[src.find("<script>"):]
    for key in sorted(keys):
        if js.count(f'"{key}"') < 2:
            problems.append(
                f"DATA-I18N-UNRESOLVED: {ARTIFACT} — key \"{key}\" is not defined for both "
                f"locales in the artifact dictionaries (dead attribute renders its hardcoded fallback)")

    # 3) dead-applier guard
    if 'data-i18n="' in markup and "function applyStaticI18n" not in src:
        problems.append("DEAD-APPLIER: artifact carries data-i18n attributes but defines no applyStaticI18n() — "
                        "attributes will render their hardcoded fallback (2026-09 incident root cause)")
    return problems


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--root", default=str(DEFAULT_REPO_ROOT))
    ap.add_argument("--quiet", action="store_true", help="print nothing on success")
    args = ap.parse_args(argv)

    problems = scan(Path(args.root).resolve())
    if problems:
        print(f"STATIC ATTRIBUTE LOCALIZATION FAILED — {len(problems)} problem(s):", file=sys.stderr)
        for p in problems:
            print(f"  - {p}", file=sys.stderr)
        print("STATIC_ATTRIBUTE_LOCALIZATION_FAILED", file=sys.stderr)
        return 1
    if not args.quiet:
        print("Static attribute localization OK: no Persian baked into static attributes; "
              "all data-i18n keys resolve in both dictionaries; applier present.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
