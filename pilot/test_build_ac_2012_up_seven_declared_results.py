"""Regression checks for the seven source-backed Uttar Pradesh declarations."""

import json
from pathlib import Path
import sys
import unittest


sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_ac_2012_up_seven_declared_results import TARGET_CODES, revised_edition


class UttarPradesh2012DeclaredResultsTest(unittest.TestCase):
    def test_only_seven_declared_results_change(self):
        before_bytes, after_bytes, audit = revised_edition()
        before = json.loads(before_bytes)['records']
        after = json.loads(after_bytes)['records']
        self.assertEqual(len(before), len(after), 403)
        self.assertEqual({item['code'] for item in audit['results']}, TARGET_CODES)
        for old, new in zip(before, after):
            changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
            if old['code'] in TARGET_CODES:
                self.assertEqual(changed, {'summary_result', 'error'})
                result = new['summary_result']
                self.assertEqual(result['margin'], result['winner_votes'] - result['runner_votes'])
                self.assertIn('still need review', new['error'])
            else:
                self.assertEqual(old, new)


if __name__ == '__main__':
    unittest.main()
