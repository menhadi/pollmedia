"""Check the limited 2014 corrections preserve detailed evidence."""

import json
from pathlib import Path
import sys
import unittest


sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_ac_2014_haryana_jk_declared_results import SOURCES, revised_edition


class SmallDiscrepancyResultsTest(unittest.TestCase):
    def test_only_official_declarations_are_added(self):
        for source in SOURCES:
            with self.subTest(state=source.state):
                old_body, new_body, audit = revised_edition(source)
                before = json.loads(old_body)['records']
                after = json.loads(new_body)['records']
                self.assertEqual(len(before), len(after), source.seats)
                self.assertEqual({item['code'] for item in audit['results']}, source.codes)
                for old, new in zip(before, after):
                    changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
                    if old['code'] in source.codes:
                        self.assertEqual(changed, {'summary_result', 'error'})
                        self.assertEqual(new['summary_result']['margin'],
                                         new['summary_result']['winner_votes'] - new['summary_result']['runner_votes'])
                        self.assertIn('still need review', new['error'])
                    else:
                        self.assertEqual(old, new)


if __name__ == '__main__':
    unittest.main()
