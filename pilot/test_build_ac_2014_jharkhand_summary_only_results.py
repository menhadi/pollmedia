"""Verify Jharkhand result declarations leave the archived candidate evidence intact."""

import json
from pathlib import Path
import sys
import unittest


sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_ac_2014_jharkhand_summary_only_results import (
    PRINTED_MARGIN_DIFFERENCES, revised_edition,
)


class JharkhandSummaryOnlyResultsTest(unittest.TestCase):
    def test_only_summary_only_seats_gain_official_declarations(self):
        old_body, new_body, audit = revised_edition()
        old = json.loads(old_body)
        new = json.loads(new_body)
        self.assertEqual(old['source_url'], new['source_url'])
        self.assertEqual(old['source_sha256'], new['source_sha256'])
        self.assertEqual(len(old['records']), len(new['records']), 81)
        self.assertEqual(len(audit['results']), 45)
        self.assertEqual({r['code'] for r in audit['results']},
                         {r['code'] for r in old['records']
                          if r.get('source_warning_code') == 'summary_only_turnout'})
        for before, after in zip(old['records'], new['records']):
            if before.get('source_warning_code') != 'summary_only_turnout':
                self.assertEqual(before, after)
                continue
            self.assertEqual(after['candidates'], before['candidates'])
            self.assertEqual(after['summary_totals'], before['summary_totals'])
            result = after['summary_result']
            self.assertEqual(result['margin'], result['winner_votes'] - result['runner_votes'])
            self.assertLessEqual(result['winner_votes'], after['summary_totals']['valid_candidate_votes'])
            if before['code'] in PRINTED_MARGIN_DIFFERENCES:
                printed, calculated = PRINTED_MARGIN_DIFFERENCES[before['code']]
                self.assertEqual(after['source_discrepancy']['printed_value'], printed)
                self.assertEqual(result['margin'], calculated)
                self.assertIn('printed margin', after['error'])
            else:
                self.assertNotIn('source_discrepancy', after)


if __name__ == '__main__':
    unittest.main()
