import unittest
from pathlib import Path

from extract_state_election_1993 import extract


class Election1993ExtractionTest(unittest.TestCase):
    def test_full_report_reconciles_without_inventing_missing_constituencies(self):
        path = Path(__file__).resolve().parent.parent / 'application/storage/app/private/election-archive/69a21aa6b5a6d8bc3e736d22/69a21aa6b5a6d8bc3e736d22-7501.pdf'
        records = extract(path)
        self.assertEqual([r['code'] for r in records], [c for c in range(1, 426) if c not in [233, 279, 394]])
        self.assertEqual(sum(len(r['candidates']) for r in records), 9716)
        self.assertTrue(all(r['status'] == 'validated' for r in records))
        self.assertEqual(records[0]['winner'], 'BARFIYA LAL JUWANTHA')
        self.assertEqual(records[0]['margin'], 3321)
        puranpur = next(r for r in records if r['name'] == 'PURANPUR')
        self.assertEqual(puranpur['code'], 60)
        self.assertEqual(puranpur['winner'], 'VIRENDRA MOHAN SINGH')
        self.assertEqual(puranpur['margin'], 20705)
        self.assertEqual(len(puranpur['candidates']), 23)
        self.assertEqual(records[-1]['code'], 425)
        self.assertEqual(records[-1]['margin'], 500)
        for record in records:
            self.assertEqual(sum(c['votes'] for c in record['candidates']), record['valid_candidate_votes'])
            self.assertIsNone(record['candidates'][0]['postal_votes'])


if __name__ == '__main__':
    unittest.main()
