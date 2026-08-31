"""Regression guard: trade-entry numeric inputs must never be native number controls.

Native <input type="number"> widgets are visually localized by some browser
engines (digits painted per browser/OS locale) even while el.value stays ASCII
Latin, so DOM scans and the Latin-digit enforcer cannot see the rendered
digits. The trade-entry form therefore uses type="text" inputmode="decimal"
(see session fix 2026-08-31 / releaseId 2026.08.31.1).

Checked for the canonical template and both localized artifacts.
"""
import os
import re
import unittest

REPO = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
PAGES = [
    'trades/new/index.html',
    'localized/fa/trades/new/index.html',
    'localized/en/trades/new/index.html',
]
NUMERIC_IDS = ['entry', 'exit', 'volume', 'contract', 'commission', 'swap', 'sl', 'tp']


def _inputs(html, input_id):
    return [m.group(0) for m in re.finditer(r'<input\b[^>]*>', html)
            if re.search(r'\bid="%s"' % re.escape(input_id), m.group(0))]


class TradesNewInputModeTest(unittest.TestCase):
    def test_numeric_inputs_are_text_decimal(self):
        for rel in PAGES:
            with self.subTest(page=rel):
                html = open(os.path.join(REPO, rel), encoding='utf-8').read()
                for input_id in NUMERIC_IDS:
                    tags = _inputs(html, input_id)
                    self.assertEqual(len(tags), 1, '%s#%s: expected exactly one input' % (rel, input_id))
                    tag = tags[0]
                    self.assertIn('type="text"', tag, '%s#%s must be type="text"' % (rel, input_id))
                    self.assertIn('inputmode="decimal"', tag, '%s#%s must carry inputmode="decimal"' % (rel, input_id))

    def test_no_native_number_inputs_remain(self):
        for rel in PAGES:
            with self.subTest(page=rel):
                html = open(os.path.join(REPO, rel), encoding='utf-8').read()
                native = re.findall(r'<input\b[^>]*type="number"[^>]*>', html)
                self.assertEqual(native, [], '%s still contains native number controls: %r' % (rel, native))

    def test_selectors_preserved(self):
        for rel in PAGES:
            with self.subTest(page=rel):
                html = open(os.path.join(REPO, rel), encoding='utf-8').read()
                for input_id in NUMERIC_IDS + ['symbol', 'openTime', 'closeTime', 'submitBtn']:
                    self.assertIsNotNone(re.search(r'\bid="%s"' % re.escape(input_id), html),
                                         '%s lost id="%s"' % (rel, input_id))


if __name__ == '__main__':
    unittest.main()
