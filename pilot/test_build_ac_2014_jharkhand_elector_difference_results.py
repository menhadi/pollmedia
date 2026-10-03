"""Confirm the elector-difference correction changes no original vote evidence."""

import json
from pathlib import Path
import sys
import unittest


sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_ac_2014_jharkhand_elector_difference_results import (
    EXPECTED_CODES, MARGIN_DIFFERENCE, revised_edition,
)


class JharkhandElectorDifferenceResultsTest(unittest.TestCase):
    def test_only_declared_results_are_added(self):
        old_body, new_body, audit = revised_edition()
        old, new = json.loads(old_body), json.loads(new_body)
        self.assertEqual(len(old['records']), len(new['records']), 81)
        self.assertEqual({item['code'] for item in audit['results']}, EXPECTED_CODES)
        self.assertEqual(old['source_url'], new['source_url'])
        self.assertEqual(old['source_sha256'], new['source_sha256'])
        for before, after in zip(old['records'], new['records']):
            if before['code'] not in EXPECTED_CODES:
                self.assertEqual(before, after)
                continue
            self.assertEqual(before['candidates'], after['candidates'])
            self.assertEqual(before['source_discrepancy'], after['source_discrepancy'])
            self.assertEqual(before['summary_totals'], after['summary_totals'])
            result = after['summary_result']
            self.assertEqual(result['margin'], result['winner_votes'] - result['runner_votes'])
            self.assertLessEqual(result['winner_votes'], after['summary_totals']['valid_candidate_votes'])
            if before['code'] in MARGIN_DIFFERENCE:
                printed, calculated = MARGIN_DIFFERENCE[before['code']]
                self.assertEqual(after['result_margin_discrepancy']['printed_value'], printed)
                self.assertEqual(result['margin'], calculated)
            else:
                self.assertNotIn('result_margin_discrepancy', after)


if __name__ == '__main__':
    unittest.main()
