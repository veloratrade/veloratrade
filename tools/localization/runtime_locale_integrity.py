#!/usr/bin/env python3
"""Runtime locale integrity guard — rendered-output FA/EN localization check.

Closes the gap the static freeze cannot see: what the DOM *actually renders*
per locale. Motivating incident (admin @ 17becaf8): EN mode rendered Persian
static chrome («پرش به محتوا», «متصل», «حالت توسعه‌دهنده», Persian aria-labels)
because a ``data-i18n`` attribute existed with no applier and no test asserted
rendered chrome. This guard makes that class unmergeable.

Invariant enforced (CI-blocking):
  EN — no Arabic-script text in application-owned UI elements/attributes.
  FA — no unexpected English UI words in application-owned UI elements/attributes.

UI-owned signals (in priority order, per the localization contract; a naive
body.innerText scan is deliberately NOT used so that Persian user names,
trade notes, journal content, AI output, emails, symbols and provider names
returned from the backend can never be flagged):
  1. elements carrying localization attributes ([data-i18n], [data-i18n-*])
  2. semantic UI elements: button, [role=button/menuitem/tab/option], th,
     label, legend, option
  3. accessibility attributes on every element: aria-label,
     aria-labelledby (resolved), title, placeholder, alt
  4. elements under the documented skip marker (data-l10n-skip) are ignored

Legitimate non-localizable values (brand / API / technical identifiers such
as Velora, MetaAPI, Resend, n8n, S3, Free/Pro tier names, FA/EN, ⌘K) are
precisely allowlisted in ``allowlist-runtime-locale.json`` — every entry
carries a category and a reason. No broad bypasses exist.

Usage:
  python -m tools.localization.runtime_locale_integrity                # admin (boots stub+server)
  python -m tools.localization.runtime_locale_integrity --app site     # localized/** via routes.json
  python -m tools.localization.runtime_locale_integrity --base-url URL # external server

Exit codes: 0 PASS · 1 violations found · 2 usage/environment error (e.g.
Playwright missing — this is a *hard* error in CI, never a silent skip).
"""
from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
import time
from pathlib import Path

DEFAULT_REPO_ROOT = Path(__file__).resolve().parents[2]
ALLOWLIST_PATH = DEFAULT_REPO_ROOT / "tools" / "localization" / "allowlist-runtime-locale.json"
STUB_PORT = 8141
STUB_MODE = "super"  # every permission granted -> full canonical route registry

# Arabic/Persian script ranges (same ranges as check_hardcoded_ui.PERSIAN_RE).
ARABIC_SCRIPT_RE = re.compile("[\u0600-\u06FF\u0750-\u077F\u08A0-\u08FF\uFB50-\uFDFF\uFE70-\uFEFF]")
# User-identity signal: attribute values carrying an email address are backend
# session/user data (e.g. the account avatar's title tooltip) — never UI copy.
EMAIL_RE = re.compile(r"[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}")
# Backend data containers: table data cells hold user/backend content by
# definition (names, notes, symbols, ids) — never application UI copy.
DATA_CELL_TAGS = {"td"}
# A Latin run of >=3 letters — candidate "unexpected English UI" in FA.
LATIN_WORD_RE = re.compile(r"[A-Za-z]{3}")

# Application-owned UI text signals (Phase-2 priority order).
UI_TEXT_SELECTOR = (
    "[data-i18n], [data-i18n-title], [data-i18n-placeholder], [data-i18n-aria-label],"
    " [data-i18n-alt], [data-i18n-value], [data-i18n-content],"
    " button, [role='button'], [role='menuitem'], [role='tab'], [role='option'],"
    " th, label, legend, option"
)
ATTR_SCAN_JS = """() => {
  const out = [];
  const els = document.querySelectorAll('[aria-label], [title], [placeholder], [alt]');
  for (const el of els) {
    for (const attr of ['aria-label', 'title', 'placeholder', 'alt']) {
      const v = el.getAttribute(attr);
      if (v && v.trim()) out.push({attr, value: v.trim(), tag: el.tagName.toLowerCase(),
        id: el.id || '', cls: (el.className && el.className.baseVal !== undefined
          ? el.className.baseVal : el.className || '').toString().slice(0, 60)});
    }
  }
  return out;
}"""
LABELLED_BY_TEXT_JS = """() => {
  const out = [];
  for (const el of document.querySelectorAll('[aria-labelledby]')) {
    const ids = (el.getAttribute('aria-labelledby') || '').split(/\\s+/).filter(Boolean);
    const text = ids.map(i => (document.getElementById(i) || {}).textContent || '').join(' ').trim();
    if (text) out.push({attr: 'aria-labelledby', value: text, tag: el.tagName.toLowerCase(),
      id: el.id || '', cls: (el.className || '').toString().slice(0, 60)});
  }
  return out;
}"""
UI_TEXT_JS = """(sel) => {
  const out = [];
  for (const el of document.querySelectorAll(sel)) {
    if (el.closest('[data-l10n-skip]')) continue;
    const t = (el.textContent || '').replace(/\\s+/g, ' ').trim();
    if (!t) continue;
    out.push({tag: el.tagName.toLowerCase(), id: el.id || '',
      cls: (el.className && el.className.baseVal !== undefined ? el.className.baseVal : el.className || '').toString().slice(0, 60),
      text: t.slice(0, 120)});
  }
  return out;
}"""


def load_baseline(path: Path) -> dict:
    data = json.loads(path.read_text(encoding="utf-8"))
    return {"pins": data.get("pins", [])}


def _norm(text: str) -> str:
    return " ".join(str(text or "").split())


def _pin_match(finding: dict, pins: list[dict]) -> dict | None:
    """A finding is 'pinned' (known, classified pre-existing debt) when
    locale+route+element+attribute+normalized text ALL match a pin entry."""
    for pin in pins:
        if (pin.get("locale") == finding["locale"] and pin.get("route") == finding["route"]
                and pin.get("element") == finding["element"] and pin.get("attribute") == finding["attribute"]
                and _norm(pin.get("text")) == _norm(finding["detected"])):
            return pin
    return None


def write_baseline(findings: list[dict], path: Path, classifications: list[dict] | None = None) -> None:
    """Write/refresh the baseline from *findings* (locale/route/element/
    attribute/text). Classifications (category/reason) are carried over from
    an existing file or from *classifications* matcher dicts
    ({"match": <substring>, "category": ..., "reason": ...})."""
    old = {}
    if path.exists():
        for pin in json.loads(path.read_text(encoding="utf-8")).get("pins", []):
            old[_norm(pin.get("text"))] = (pin.get("category", "legacy-deferred-debt"),
                                           pin.get("reason", ""))
    seen = set()
    pins = []
    for f in findings:
        key = _norm(f["detected"])
        dedup = (f["locale"], f["route"], f["element"], f["attribute"], key)
        if dedup in seen:
            continue
        seen.add(dedup)
        cat, reason = old.get(key, ("", ""))
        if not cat:
            for m in classifications or []:
                if m["match"] in key:
                    cat, reason = m["category"], m["reason"]
                    break
        pins.append({"locale": f["locale"], "route": f["route"], "element": f["element"],
                     "attribute": f["attribute"], "text": f["detected"],
                     "category": cat or "legacy-deferred-debt",
                     "reason": reason or "Pre-existing finding pinned by baseline ratchet (classify me)."})
    path.write_text(json.dumps({
        "version": time.strftime("%Y.%m.%d.1"),
        "doc": ("Site runtime-locale baseline (ratchet). Every entry is a KNOWN, classified "
                "pre-existing finding; any NEW locale leakage (not matching a pin) fails CI. "
                "Entries are removed by FIXING the finding, never by blanket rules."),
        "pins": pins}, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def load_tokens(path: Path = ALLOWLIST_PATH) -> list[dict]:
    data = json.loads(path.read_text(encoding="utf-8"))
    return data.get("tokens", [])


def _token_allowlisted(value: str, tokens: list[dict]) -> bool:
    v = value.strip()
    for tok in tokens:
        tv = str(tok.get("value", ""))
        if not tv:
            continue
        if tok.get("match") == "regex":
            if re.search(tv, v):
                return True
        elif v == tv or (tok.get("contains") and tv in v):
            return True
    return False


def check_values(values: list[dict], locale: str, route: str, tokens: list[dict]) -> list[dict]:
    """Classify UI-owned strings/attributes for one locale. Returns findings."""
    findings = []
    for item in values:
        value = str(item.get("value") or item.get("text") or "").strip()
        if not value or _token_allowlisted(value, tokens):
            continue
        if not item.get("attr") and str(item.get("tag", "")).lower() in DATA_CELL_TAGS:
            continue  # backend/user data cell
        if item.get("attr") and EMAIL_RE.search(value):
            continue  # user identity data (name/email tooltips), not UI copy
        kind = "attribute" if item.get("attr") else "text"
        bad = None
        if locale == "en" and ARABIC_SCRIPT_RE.search(value):
            bad = "arabic-script UI copy in EN"
        elif locale == "fa" and not ARABIC_SCRIPT_RE.search(value) and LATIN_WORD_RE.search(value):
            bad = "untranslated English UI copy in FA"
        if bad:
            findings.append({
                "locale": locale.upper(), "route": route, "kind": kind,
                "element": f"<{item.get('tag', '?')}"
                           f"{'#' + item['id'] if item.get('id') else ''}"
                           f"{'.' + item['cls'].split()[0] if item.get('cls') else ''}>",
                "attribute": item.get("attr", "(text)"),
                "detected": value[:80], "reason": bad,
            })
    return findings


def findings_on_page(pg, locale: str, route: str, tokens: list[dict], settle_ms: int = 900) -> list[dict]:
    """Scan one already-loaded page. Importable for fixture tests (file:// ok)."""
    pg.wait_for_timeout(settle_ms)
    pg.wait_for_function("document.readyState === 'complete'")
    findings = []
    findings += check_values(pg.evaluate(UI_TEXT_JS, UI_TEXT_SELECTOR), locale, route, tokens)
    findings += check_values(pg.evaluate(ATTR_SCAN_JS), locale, route, tokens)
    findings += check_values(pg.evaluate(LABELLED_BY_TEXT_JS), locale, route, tokens)
    return findings


def _fmt(f: dict) -> str:
    return (f"  Locale: {f['locale']} | Route: {f['route']}\n"
            f"  Element: {f['element']}  Attribute: {f['attribute']}\n"
            f"  Detected: \"{f['detected']}\"\n"
            f"  Reason: {f['reason']} — Expected source: localization catalog (t(...)/data-i18n*)\n")


def _admin_routes(pg) -> list[str]:
    """One source of truth: the page's own canonical route registry (REG)."""
    routes = pg.evaluate("()=>REG.map(r=>r.route)")
    if not routes:
        raise RuntimeError("admin route registry (REG) not found on page")
    return [("users/7" if r.endswith("/:id") else r) for r in routes]


def _site_routes(root: Path, manifest: Path) -> list[str]:
    data = json.loads(manifest.read_text(encoding="utf-8"))
    return [r["outputs"][0] for r in data.get("routes", []) if r.get("outputs")]


def run(root: Path, app: str, locales: list[str], viewport: str, settle_ms: int,
        base_url: str | None, manifest: Path, json_out: Path | None,
        baseline_path: Path | None = None, update_baseline: bool = False) -> tuple[bool, list[str]]:
    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        print("RUNTIME LOCALE INTEGRITY: ENVIRONMENT ERROR — playwright is not installed.\n"
              "  CI must install it (pip install playwright && playwright install chromium);\n"
              "  this check is blocking and must never be silently skipped.", file=sys.stderr)
        return False, ["environment error: playwright missing"]

    stub = server = None
    mode_file = root / "admin" / "v2" / "tests" / "mode.json"
    if base_url is None and app == "admin":
        mode_file.write_text(json.dumps({"mode": STUB_MODE, "logout_called": False}), encoding="utf-8")
        stub = subprocess.Popen([sys.executable, str(root / "admin/v2/tests/f1_stub.py")],
                                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        time.sleep(1.2)
        base_url = f"http://127.0.0.1:{STUB_PORT}"
    elif base_url is None and app == "site":
        import http.server
        import threading

        handler = lambda *a, **kw: http.server.SimpleHTTPRequestHandler(*a, directory=str(root), **kw)
        server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), handler)
        threading.Thread(target=server.serve_forever, daemon=True).start()
        base_url = f"http://127.0.0.1:{server.server_address[1]}"
    tokens = load_tokens(root / "tools/localization/allowlist-runtime-locale.json")
    w, h = (int(x) for x in viewport.split("x"))
    lines: list[str] = []
    all_findings: list[dict] = []
    try:
        with sync_playwright() as p:
            browser = p.chromium.launch()
            ctx = browser.new_context(viewport={"width": w, "height": h})
            pg = ctx.new_page()
            if app == "admin":
                pg.goto(base_url + "/admin/v2/index.html", wait_until="load")
                pg.wait_for_timeout(settle_ms)
                routes = _admin_routes(pg)
            else:
                routes = _site_routes(root, manifest)
            for locale in locales:
                for rt in routes:
                    if app == "admin":
                        # use the app's own language switch (setLang) — the real
                        # runtime mechanism — then navigate the SPA hash router.
                        pg.evaluate(f"()=>{{setLang('{locale}'); location.hash='#/{rt}';}}")
                        label = f"/admin/index.html#/{rt}"
                    else:
                        pg.goto(f"{base_url}/localized/{locale}/{rt}", wait_until="load")
                        label = f"/{locale}/{rt}"
                    f = findings_on_page(pg, locale, label, tokens, settle_ms)
                    all_findings += f
            browser.close()
    finally:
        if stub:
            stub.terminate()
            try:
                mode_file.unlink()
            except OSError:
                pass
        if server:
            server.shutdown()

    pins = load_baseline(baseline_path)["pins"] if baseline_path and baseline_path.exists() else []
    if update_baseline and baseline_path is not None:
        write_baseline(all_findings, baseline_path)
        lines.append(f"baseline updated: {len(pins)} -> {len(load_baseline(baseline_path)['pins'])} pins "
                     f"({baseline_path})")
        return True, lines
    new_findings = [f for f in all_findings if baseline_path is None or not _pin_match(f, pins)]
    for f in new_findings:
        lines.append(_fmt(f))
    ok = not new_findings
    if json_out:
        json_out.write_text(json.dumps(new_findings, ensure_ascii=False, indent=1), encoding="utf-8")
    if baseline_path is not None and ok:
        lines.append(f"baseline ratchet: {len(all_findings) - len(new_findings)} pinned pre-existing, "
                     f"{len(new_findings)} NEW")
    return ok, lines


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--app", choices=["admin", "site"], default="admin")
    ap.add_argument("--base-url", help="external server URL (default: boot the self-contained stub)")
    ap.add_argument("--locales", default="en,fa")
    ap.add_argument("--viewport", default="1440x950")
    ap.add_argument("--settle-ms", type=int, default=900)
    ap.add_argument("--routes-manifest", default=str(DEFAULT_REPO_ROOT / "tools/localization/routes.json"))
    ap.add_argument("--json", dest="json_out", help="write findings JSON here")
    ap.add_argument("--baseline", help="baseline ratchet file: only findings NOT pinned there fail")
    ap.add_argument("--update-baseline", action="store_true",
                    help="write current findings into the baseline (explicit maintainer action)")
    ap.add_argument("--root", default=str(DEFAULT_REPO_ROOT))
    args = ap.parse_args(argv)

    print("Runtime Locale Integrity Guard")
    print("=" * 31)
    ok, lines = run(Path(args.root).resolve(), args.app, [x.strip() for x in args.locales.split(",") if x.strip()],
                    args.viewport, args.settle_ms, args.base_url, Path(args.routes_manifest),
                    Path(args.json_out) if args.json_out else None,
                    Path(args.baseline).resolve() if args.baseline else None, args.update_baseline)
    if not ok:
        print(f"LOCALIZATION RUNTIME INTEGRITY FAILED ({len(lines)} finding(s) — first shown, full list in --json):\n")
        print(lines[0] if lines else "")
        print("LOCALIZATION_RUNTIME_INTEGRITY_FAILED", file=sys.stderr)
        return 1
    for l in lines:
        print(l)
    print("LOCALIZATION_RUNTIME_INTEGRITY_OK")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
