import json
from pathlib import Path
import unittest

from restore_confirmed_ac_gaps import tamil_rows


class RestoreConfirmedAcGapsTest(unittest.TestCase):
    def test_tamil_1991_page_293_has_four_complete_seat_tables(self):
        folder = Path(__file__).resolve().parents[1] / 'application/storage/app/private/election-archive/f0d9e36a60bcef19312cabc4'
        data = json.loads((folder / 'extraction.json').read_text(encoding='utf-8'))
        rows = tamil_rows(folder / data['source_file'])
        self.assertEqual([(row['code'], len(row['candidates'])) for row in rows],
                         [(132, 11), (133, 8), (134, 8), (135, 10)])
        for row in rows:
            self.assertEqual(sum(candidate['votes'] for candidate in row['candidates']),
                             row['valid_candidate_votes'])
            self.assertEqual(row['status'], 'needs_review')


if __name__ == '__main__':
    unittest.main()
