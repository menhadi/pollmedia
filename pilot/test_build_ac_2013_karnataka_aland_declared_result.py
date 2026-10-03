"""Check the Aland declaration leaves all other source evidence intact."""

import json
from pathlib import Path
import sys
import unittest


sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_ac_2013_karnataka_aland_declared_result import revised_edition


class AlandDeclaredResultTest(unittest.TestCase):
    def test_only_aland_declaration_changes(self):
        old_body, new_body, audit = revised_edition()
        old_records = json.loads(old_body)['records']
        new_records = json.loads(new_body)['records']
        self.assertEqual(len(old_records), len(new_records), 224)
        self.assertEqual([row['code'] for row in audit['results']], [46])
        for before, after in zip(old_records, new_records):
            changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
            if before['code'] == 46:
                self.assertEqual(changed, {'summary_result', 'error'})
                self.assertEqual(after['summary_result']['margin'], 17114)
                self.assertIn('Detailed electors: 192,986', after['error'])
                self.assertIn('official summary valid votes: 132,384', after['error'])
            else:
                self.assertEqual(before, after)


if __name__ == '__main__':
    unittest.main()
