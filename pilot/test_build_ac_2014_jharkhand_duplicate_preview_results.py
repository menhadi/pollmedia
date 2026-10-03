"""Check that the complete-page result preserves duplicate preview evidence."""

import json
from pathlib import Path
import sys
import unittest


sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_ac_2014_jharkhand_duplicate_preview_results import TARGETS, revised_edition


class JharkhandDuplicatePreviewResultsTest(unittest.TestCase):
    def test_only_verified_results_are_added(self):
        old_body, new_body, audit = revised_edition()
        old, new = json.loads(old_body), json.loads(new_body)
        self.assertEqual(len(old['records']), len(new['records']), 81)
        self.assertEqual({item['code'] for item in audit['results']}, set(TARGETS))
        self.assertEqual(old['source_url'], new['source_url'])
        self.assertEqual(old['source_sha256'], new['source_sha256'])
        for before, after in zip(old['records'], new['records']):
            if before['code'] not in TARGETS:
                self.assertEqual(before, after)
                continue
            self.assertEqual(before['candidates'], after['candidates'])
            self.assertEqual(before['error'], after['error'])
            self.assertEqual(before['turnout_totals'], after['turnout_totals'])
            self.assertIsNone(after.get('valid_candidate_votes'))
            result = after['official_detail_result']
            self.assertEqual(result['margin'], result['winner_votes'] - result['runner_votes'])
            self.assertEqual(result['source_page'], before['turnout_source_page'])
            self.assertEqual(result['duplicate_preview_page'], before['detail_page'])
            self.assertGreater(result['verified_valid_candidate_votes'], result['winner_votes'])


if __name__ == '__main__':
    unittest.main()
