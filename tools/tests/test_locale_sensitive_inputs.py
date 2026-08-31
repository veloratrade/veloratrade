"""Class gate: locale-sensitive native input controls are forbidden repository-wide.

Root cause this guards against (session 2026-08-31): native widgets
(``type=number/date/time/datetime-local/month/week``) paint their glyphs with the
BROWSER/OS locale engine — the DOM ``.value`` stays ASCII, but the user sees
localized digits (e.g. Persian ۲۰۲۶/۰۸/۱۴) and no JavaScript patch, CSS rule or
MutationObserver can reach inside the native widget painting. The Velora
Latin-digit policy therefore requires that user-facing numeric/date/time entry
uses plain ``type="text"`` controls (optionally with ``inputmode``), whose glyphs
are the literal characters of ``.value`` and are already covered by
``velora-latin-digits.js`` (value normalization + Intl/toLocale patches) and
``velora-latin-digits.css``.

PR #94 removed the eight ``type=number`` trade-entry inputs; PR #96 removes the
two ``type=datetime-local`` open/close time inputs. This test fails if ANY
locale-sensitive input type is (re)introduced in any canonical template or
generated localized artifact, unless explicitly allowlisted.
"""
import json
import os
import re
import unittest

REPO = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
ALLOWLIST_PATH = os.path.join(os.path.dirname(__file__), 'fixtures', 'locale-sensitive-input-allowlist.json')
FORBIDDEN = ('number', 'date', 'time', 'datetime-local', 'month', 'week')
INPUT_RE = re.compile(r'<input\b[^>]*>')

SKIP_DIRS = {'.git', 'node_modules', 'docs', 'tools'}


def _input_types(tag):
    m = re.search(r'type="([a-z-]+)"', tag)
    return m.group(1) if m else 'text'


def _templates():
    for root, dirs, files in os.walk(REPO):
        dirs[:] = [d for d in dirs if d not in SKIP_DIRS]
        if os.path.basename(root) == 'localized':
            continue  # covered separately below (generated parity)
        for f in files:
            if f.endswith('.html'):
                yield os.path.relpath(os.path.join(root, f), REPO).replace(os.sep, '/')


def _localized():
    base = os.path.join(REPO, 'localized')
    if not os.path.isdir(base):
        return []
    out = []
    for locale in ('fa', 'en'):
        for root, dirs, files in os.walk(os.path.join(base, locale)):
            dirs[:] = [d for d in dirs if d not in SKIP_DIRS]
            for f in files:
                if f.endswith('.html'):
                    out.append(os.path.relpath(os.path.join(root, f), REPO).replace(os.sep, '/'))
    return out


def _allowlist():
    if not os.path.isfile(ALLOWLIST_PATH):
        return []
    return json.load(open(ALLOWLIST_PATH, encoding='utf-8'))


class LocaleSensitiveInputGateTest(unittest.TestCase):
    def test_no_locale_sensitive_native_inputs_anywhere(self):
        allow = set(_allowlist())
        offenders = []
        for rel in list(_templates()) + _localized():
            html = open(os.path.join(REPO, rel), encoding='utf-8').read()
            for tag in INPUT_RE.findall(html):
                kind = _input_types(tag)
                if kind in FORBIDDEN and f'{rel}#{kind}' not in allow:
                    offenders.append(f'{rel}: type="{kind}"')
        self.assertEqual(
            offenders, [],
            'Locale-sensitive native input controls paint digits with the browser '
            'locale (Latin-digit policy violation). Use type="text" (+ inputmode) '
            'instead, or add an explicit fixture allowlist entry with justification. '
            'Offenders: %r' % (offenders,))

    def test_trade_entry_datetime_fields_are_latin_safe_text(self):
        for rel in ('trades/new/index.html',
                    'localized/fa/trades/new/index.html',
                    'localized/en/trades/new/index.html'):
            with self.subTest(page=rel):
                html = open(os.path.join(REPO, rel), encoding='utf-8').read()
                for input_id in ('openTime', 'closeTime'):
                    tags = [t for t in INPUT_RE.findall(html)
                            if re.search(r'\bid="%s"' % input_id, t)]
                    self.assertEqual(len(tags), 1, '%s#%s: expected exactly one input' % (rel, input_id))
                    self.assertIn('type="text"', tags[0],
                                  '%s#%s must be type="text" (native datetime-local renders locale digits)' % (rel, input_id))
                    self.assertIn('required', tags[0], '%s#%s must keep required' % (rel, input_id))


if __name__ == '__main__':
    unittest.main()
