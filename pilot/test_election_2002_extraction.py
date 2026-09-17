import unittest
from pathlib import Path

from extract_state_election_2002 import extract


class Election2002ExtractionTest(unittest.TestCase):
    def test_official_report_handles_page_continuations_and_missing_final_page(self):
        path = Path(__file__).resolve().parent.parent / 'application/storage/app/private/election-archive/0a8d70ea7e38075bea7a6253/0a8d70ea7e38075bea7a6253-7505.pdf'
        records = extract(path)
        self.assertEqual(len(records), 403)
        self.assertEqual(sum(r['status'] == 'validated' for r in records), 402)
        self.assertEqual(records[0]['winner'], 'QUTUBUDEEN')
        self.assertEqual(records[0]['margin'], 2149)
        self.assertEqual(records[0]['candidates'][0]['votes'], 37853)
        self.assertEqual(records[2]['name'], 'AFZALGARH')
        self.assertEqual(len(records[2]['candidates']), 24)
        self.assertEqual(records[401]['status'], 'validated')
        self.assertEqual(records[401]['candidates'][8]['source_row'], 10)
        self.assertEqual(records[402]['name'], 'MUZAFFARABAD')
        self.assertEqual(records[402]['status'], 'needs_review')
        self.assertIn('page 136', records[402]['error'])
        self.assertNotIn('winner', records[402])
        for record in records:
            for candidate in record['candidates']:
                self.assertIsNone(candidate['general_votes'])
                self.assertIsNone(candidate['postal_votes'])


if __name__ == '__main__':
    unittest.main()
