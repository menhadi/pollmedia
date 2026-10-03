"""Verify that the Satyavedu correction changes only source-backed result metadata."""

import json
from pathlib import Path
import sys
import unittest


sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_ac_2014_andhra_satyavedu_detail_result import revised_edition


class SatyaveduDetailResultTest(unittest.TestCase):
    def test_only_satyavedu_result_changes(self):
        old_body, new_body, audit = revised_edition()
        old_records = json.loads(old_body)['records']
        new_records = json.loads(new_body)['records']
        self.assertEqual(len(old_records), len(new_records), 294)
        self.assertEqual([row['code'] for row in audit['results']], [288])
        for before, after in zip(old_records, new_records):
            changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
            if before['code'] == 288:
                self.assertEqual(changed, {'official_detail_result'})
                self.assertEqual(after['official_detail_result']['margin'], 4227)
                self.assertEqual(after['error'], before['error'])
                self.assertEqual(after['candidates'], before['candidates'])
            else:
                self.assertEqual(before, after)


if __name__ == '__main__':
    unittest.main()
