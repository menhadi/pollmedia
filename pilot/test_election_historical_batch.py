import json
import unittest
from pathlib import Path

from extract_state_election_2002 import extract


class HistoricalBatchExtractionTest(unittest.TestCase):
    def test_remaining_official_editions_preserve_coverage_and_flag_unresolved_records(self):
        expected = {
            1991: (419, 417), 1989: (425, 425), 1985: (425, 424),
            1980: (425, 425), 1977: (425, 425), 1974: (424, 392),
            1969: (425, 424), 1967: (425, 425), 1962: (430, 426),
            1957: (341, 247), 1951: (347, 231),
        }
        root = Path(__file__).resolve().parent.parent / 'application/storage/app/private/election-archive'
        manifests = {}
        for path in root.glob('*/manifest.json'):
            manifest = json.loads(path.read_text(encoding='utf-8'))
            if manifest.get('kind') == 'ac':
                manifests[manifest['year']] = (path, manifest)
        for year, (count, validated) in expected.items():
            with self.subTest(year=year):
                path, manifest = manifests[year]
                records = extract(path.parent / manifest['files'][0]['file'], year=year)
                self.assertEqual(len(records), count)
                self.assertEqual(len({r['code'] for r in records}), count)
                self.assertEqual(sum(r['status'] == 'validated' for r in records), validated)
                for record in records:
                    if record['status'] == 'validated':
                        self.assertEqual(sum(c['votes'] for c in record['candidates']), record['valid_candidate_votes'])
                        self.assertLessEqual(record['valid_candidate_votes'], record['votes_polled'])
                        self.assertLessEqual(record['votes_polled'], record['electors'])
                        self.assertEqual(record['number_of_seats'], 1)
                    else:
                        self.assertNotIn('winner', record)
                        self.assertTrue(record['error'])
                if year == 1957:
                    self.assertEqual(records[0]['number_of_seats'], 2)
                    self.assertEqual(records[0]['candidates'][0]['votes'], 14984)
                    self.assertEqual(records[0]['status'], 'needs_review')
                if year == 1991:
                    kanpur = next(r for r in records if r['code'] == 290)
                    self.assertEqual(kanpur['name'], 'KANPUR CANTONMENT')
                    self.assertEqual(kanpur['candidates'][0]['candidate_name'], 'SATISH MAHANA')


if __name__ == '__main__':
    unittest.main()
