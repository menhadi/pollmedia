import unittest
from pathlib import Path

from extract_pc_2004 import extract


class LokSabha2004ExtractionTest(unittest.TestCase):
    def test_all_state_scoped_seats_reconcile_without_using_modern_codes(self):
        root = Path(__file__).resolve().parent.parent / 'application/storage/app/private/election-archive/2855182c4a7e95dbef89777a'
        records = extract(root / '2855182c4a7e95dbef89777a-9780.pdf', root / '2855182c4a7e95dbef89777a-9781.pdf')
        self.assertEqual(len(records), 543)
        self.assertEqual(len({(r['state_code'], r['official_pc_code']) for r in records}), 543)
        self.assertEqual(sum(len(r['candidates']) for r in records), 5435)
        self.assertTrue(all(r['status'] == 'validated' for r in records))
        pilibhit = next(r for r in records if r['state_code'] == 'S24' and r['constituency_name'] == 'PILIBHIT')
        self.assertEqual(pilibhit['official_pc_code'], 9)
        self.assertEqual(pilibhit['winner'], 'MANEKA GANDHI')
        self.assertEqual(pilibhit['margin'], 102720)
        for record in records:
            self.assertEqual(sum(c['votes'] for c in record['candidates']), record['summary_totals']['valid_candidate_votes'])
            for candidate in record['candidates']:
                self.assertEqual(candidate['general_votes'] + candidate['postal_votes'], candidate['votes'])


if __name__ == '__main__':
    unittest.main()
