import unittest
from pathlib import Path

from extract_state_election_1996 import extract


class Election1996ExtractionTest(unittest.TestCase):
    def test_complete_source_coverage_keeps_historical_codes_and_conflicting_totals(self):
        path = Path(__file__).resolve().parent.parent / 'application/storage/app/private/election-archive/1cc8415ab4d57b66831417e8/1cc8415ab4d57b66831417e8-7503.pdf'
        records = extract(path)
        self.assertEqual([r['code'] for r in records], [c for c in range(1, 426) if c != 385])
        self.assertEqual(sum(len(r['candidates']) for r in records), 4429)
        self.assertEqual(sum(r['status'] == 'validated' for r in records), 64)
        self.assertEqual(records[0]['name'], 'UTTARKASHI (SC)')
        self.assertEqual(records[0]['candidates'][0]['votes'], 48862)
        puranpur = next(r for r in records if r['name'] == 'PURANPUR')
        self.assertEqual(puranpur['code'], 60)
        self.assertEqual(puranpur['valid_candidate_votes'], 167338)
        self.assertEqual(puranpur['summary_totals']['valid_candidate_votes'], 167333)
        self.assertEqual(puranpur['status'], 'needs_review')
        self.assertNotIn('winner', puranpur)
        self.assertEqual(records[-1]['code'], 425)
        self.assertEqual(len(records[-1]['candidates']), 8)


if __name__ == '__main__':
    unittest.main()
