import unittest
from pathlib import Path

from extract_pc_2009 import extract


class LokSabha2009ExtractionTest(unittest.TestCase):
    def test_national_coverage_preserves_state_identities_and_source_conflicts(self):
        root = Path(__file__).resolve().parent.parent / 'application/storage/app/private/election-archive/e5346f9160ad32fb68a34578'
        records = extract(root / '5556fc48df7b5645106ff974-6640.pdf', root / 'e73b9b69acde3f3d048bb45d-6634.pdf')
        self.assertEqual(len(records), 543)
        self.assertEqual(len({(r['state_code'], r['official_pc_code']) for r in records}), 543)
        self.assertEqual(sum(len(r['candidates']) for r in records), 8070)
        self.assertEqual(sum(r['status'] == 'validated' for r in records), 31)
        self.assertEqual(records[0]['candidates'][2]['candidate_name'], 'RATHOD RAMESH')
        self.assertEqual(records[0]['candidates'][2]['votes'], 372268)
        self.assertEqual(records[0]['valid_candidate_votes'], 863581)
        self.assertEqual(records[0]['summary_totals']['valid_candidate_votes'], 862997)
        pilibhit = next(r for r in records if r['state_code'] == 'S24' and r['official_pc_code'] == 26)
        self.assertEqual(pilibhit['constituency_name'], 'Pilibhit')
        self.assertEqual(len(pilibhit['candidates']), 16)
        for record in records:
            for candidate in record['candidates']:
                self.assertEqual(candidate['general_votes'] + candidate['postal_votes'], candidate['votes'])
            if record['status'] != 'validated':
                self.assertNotIn('winner', record)


if __name__ == '__main__':
    unittest.main()
