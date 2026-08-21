#!/usr/bin/env python3
"""PR-01 freeze checker tests.

Runs the two freeze checkers against throwaway fixture trees and asserts exit
codes / messages. No pytest required: plain `python test_pr01_freeze.py`.

Exit 0 if all tests pass, 1 otherwise.
"""
from __future__ import annotations

import json
import subprocess
import sys
import tempfile
from pathlib import Path

LOCALIZATION = Path(__file__).resolve().parent
UI_CHECKER = LOCALIZATION / 'check_hardcoded_ui.py'
HASH_CHECKER = LOCALIZATION / 'check_frozen_hash_keys.py'
ANOMALY_CHECKER = LOCALIZATION / 'report_catalog_anomalies.py'

PASS = 0
FAILURES = []


def run_cmd(script: Path, args: list) -> subprocess.CompletedProcess:
    return subprocess.run(
        [sys.executable, str(script), *args],
        capture_output=True, text=True, timeout=60,
    )


def check(name: str, cond: bool, detail: str = '') -> None:
    if cond:
        print(f"  PASS  {name}")
    else:
        print(f"  FAIL  {name}  {detail}")
        FAILURES.append(name)


def make_ui_fixture() -> tuple:
    root = Path(tempfile.mkdtemp(prefix='pr01ui-'))
    (root / 'public' / 'assets').mkdir(parents=True)
    (root / 'localized' / 'fa').mkdir(parents=True)
    (root / 'localized' / 'en').mkdir(parents=True)

    (root / 'public' / 'assets' / 'known.js').write_text(
        "const a = '\u062a\u0623\u06cc\u06cc\u062f'; const b = '\u0627\u0646\u0635\u0631\u0627\u0641';",
        encoding='utf-8',
    )
    (root / 'public' / 'assets' / 'clean.js').write_text("const x = 'hello';", encoding='utf-8')
    (root / 'localized' / 'fa' / 'index.html').write_text(
        '<html><body><script>const r = /(lose|\u0628\u0627\u062e\u062a)/;</script></body></html>',
        encoding='utf-8',
    )
    (root / 'localized' / 'en' / 'index.html').write_text(
        '<html><body><script>const r = /(lose|loss)/;</script></body></html>',
        encoding='utf-8',
    )

    allowlist = {
        "version": 1,
        "generated_at": "t",
        "scope_note": "t",
        "categories": {"legacy-dictionary": "x", "regex-intent": "y"},
        "entries": [
            {"id": "V3-001", "file": "public/assets/known.js", "pattern": "x", "count": 2,
             "category": "legacy-dictionary", "reason": "", "resolution_pr": None},
            {"id": "V3-002", "file": "localized/fa/index.html", "pattern": "x", "count": 1,
             "category": "regex-intent", "reason": "", "resolution_pr": None},
        ],
    }
    al = root / 'allowlist.json'
    al.write_text(json.dumps(allowlist, ensure_ascii=False), encoding='utf-8')
    return root, al


def make_hash_fixture() -> tuple:
    root = Path(tempfile.mkdtemp(prefix='pr01hash-'))
    (root / 'public' / 'locales').mkdir(parents=True)
    fa = {"messages": {"common.ok": "\u062a\u0623\u06cc\u06cc\u062f", "pages.home.title.a1b2c3d4": "\u0639\u0646\u0648\u0627\u0646"}}
    en = {"messages": {"common.ok": "OK", "pages.home.title.a1b2c3d4": "Title"}}
    (root / 'public' / 'locales' / 'fa.json').write_text(json.dumps(fa, ensure_ascii=False), encoding='utf-8')
    (root / 'public' / 'locales' / 'en.json').write_text(json.dumps(en, ensure_ascii=False), encoding='utf-8')
    frozen = {"version": 1, "count": 1, "hash_suffix_re": r"[0-9a-f]{8}$", "keys": ["pages.home.title.a1b2c3d4"]}
    fp = root / 'frozen.json'
    fp.write_text(json.dumps(frozen, ensure_ascii=False), encoding='utf-8')
    return root, fp


def test_ui_positive() -> None:
    root, al = make_ui_fixture()
    p = run_cmd(UI_CHECKER, ['--root', str(root), '--allowlist', str(al)])
    check('ui positive (current-tree validation)', p.returncode == 0, p.stdout + p.stderr)


def test_ui_new_file_literal_fails() -> None:
    root, al = make_ui_fixture()
    (root / 'public' / 'assets' / 'new.js').write_text("const z = '\u062c\u062f\u06cc\u062f';", encoding='utf-8')
    p = run_cmd(UI_CHECKER, ['--root', str(root), '--allowlist', str(al)])
    check('ui new-file Persian literal fails', p.returncode == 1 and 'NEW VIOLATION' in p.stderr, p.stderr)


def test_ui_added_literal_in_allowlisted_file_fails() -> None:
    root, al = make_ui_fixture()
    (root / 'public' / 'assets' / 'known.js').write_text(
        "const a = '\u062a\u0623\u06cc\u06cc\u062f'; const b = '\u0627\u0646\u0635\u0631\u0627\u0641'; const c = '\u0633\u0648\u0645';",
        encoding='utf-8',
    )
    p = run_cmd(UI_CHECKER, ['--root', str(root), '--allowlist', str(al)])
    check('ui added literal in allowlisted file fails', p.returncode == 1 and 'DRIFT' in p.stderr, p.stderr)


def test_ui_orphan_allowlist_fails() -> None:
    root, al = make_ui_fixture()
    data = json.loads(al.read_text(encoding='utf-8'))
    data['entries'].append({"id": "V3-099", "file": "public/assets/ghost.js", "pattern": "x", "count": 1,
                            "category": "legacy-dictionary", "reason": "", "resolution_pr": None})
    al.write_text(json.dumps(data, ensure_ascii=False), encoding='utf-8')
    p = run_cmd(UI_CHECKER, ['--root', str(root), '--allowlist', str(al)])
    check('ui orphan allowlist entry fails', p.returncode == 1 and 'ORPHAN' in p.stderr, p.stderr)


def test_ui_stale_allowlist_fails() -> None:
    root, al = make_ui_fixture()
    data = json.loads(al.read_text(encoding='utf-8'))
    for e in data['entries']:
        if e['file'] == 'public/assets/known.js':
            e['count'] = 3  # no longer matches tree (2)
    al.write_text(json.dumps(data, ensure_ascii=False), encoding='utf-8')
    p = run_cmd(UI_CHECKER, ['--root', str(root), '--allowlist', str(al)])
    check('ui stale allowlist count fails', p.returncode == 1 and 'DRIFT' in p.stderr, p.stderr)


def test_ui_generate_allowlist_roundtrip() -> None:
    root, _ = make_ui_fixture()
    gen = root / 'gen-allowlist.json'
    p = run_cmd(UI_CHECKER, ['--root', str(root), '--allowlist', str(gen), '--generate-allowlist'])
    check('ui --generate-allowlist writes file', p.returncode == 0 and gen.exists(), p.stdout + p.stderr)
    data = json.loads(gen.read_text(encoding='utf-8'))
    check('ui generated allowlist counts match tree', all(e['count'] >= 1 for e in data['entries']), str(data))
    p2 = run_cmd(UI_CHECKER, ['--root', str(root), '--allowlist', str(gen)])
    check('ui generated allowlist passes validation', p2.returncode == 0, p2.stdout + p2.stderr)


def test_hash_positive() -> None:
    root, fp = make_hash_fixture()
    p = run_cmd(HASH_CHECKER, ['--root', str(root), '--frozen', str(fp)])
    check('hash positive (frozen set intact)', p.returncode == 0, p.stdout + p.stderr)


def test_hash_new_key_fails() -> None:
    root, fp = make_hash_fixture()
    fa_path = root / 'public' / 'locales' / 'fa.json'
    fa = json.loads(fa_path.read_text(encoding='utf-8'))
    fa['messages']['pages.home.sub.1234abcd'] = '\u0632\u06cc\u0631'
    fa_path.write_text(json.dumps(fa, ensure_ascii=False), encoding='utf-8')
    p = run_cmd(HASH_CHECKER, ['--root', str(root), '--frozen', str(fp)])
    check('hash new hashed key fails', p.returncode == 1 and 'NEW HASHED KEY' in p.stderr, p.stderr)


def test_hash_generate_roundtrip() -> None:
    root, _ = make_hash_fixture()
    gen = root / 'gen-frozen.json'
    p = run_cmd(HASH_CHECKER, ['--root', str(root), '--frozen', str(gen), '--generate'])
    check('hash --generate writes file', p.returncode == 0 and gen.exists(), p.stdout + p.stderr)
    p2 = run_cmd(HASH_CHECKER, ['--root', str(root), '--frozen', str(gen)])
    check('hash generated snapshot passes validation', p2.returncode == 0, p2.stdout + p2.stderr)


def test_anomalies_report_only() -> None:
    root, _ = make_hash_fixture()
    p = run_cmd(ANOMALY_CHECKER, ['--root', str(root)])
    check('anomaly report exits 0 (report-only)', p.returncode == 0, p.stdout + p.stderr)


def main() -> int:
    print("PR-01 freeze checker tests")
    tests = [
        test_ui_positive,
        test_ui_new_file_literal_fails,
        test_ui_added_literal_in_allowlisted_file_fails,
        test_ui_orphan_allowlist_fails,
        test_ui_stale_allowlist_fails,
        test_ui_generate_allowlist_roundtrip,
        test_hash_positive,
        test_hash_new_key_fails,
        test_hash_generate_roundtrip,
        test_anomalies_report_only,
    ]
    for t in tests:
        print(f"\n[{t.__name__}]")
        t()
    print(f"\n{'=' * 50}\n{len(tests) - len(FAILURES)}/{len(tests)} passed")
    return 1 if FAILURES else 0


if __name__ == '__main__':
    sys.exit(main())
