import unittest

from extract_assembly_summary_totals import corroborates, parse_summary_page


SUMMARY = '''
CONSTITUENCY DATA - SUMMARY
CONSTITUENCY 1 - Ratabari(SC)
II. ELECTORS
3. TOTAL 70830 65640 136470
III. VOTERS
4. TOTAL 85648
III(A). POLLING PERCENTAGE 62.76
IV. VOTES
3. TOTAL VALID VOTES POLLED 85641
V. POLLING STATIONS
'''


class AssemblySummaryTotalsTest(unittest.TestCase):
    def test_read_labelled_totals_and_reconcile_with_detailed_candidate_votes(self):
        summary = parse_summary_page(SUMMARY, 18)
        self.assertEqual(summary, {
            'code': 1, 'name': 'Ratabari(SC)', 'electors': 136470,
            'votes_polled': 85648, 'valid_candidate_votes': 85641,
            'summary_page': 18,
        })
        record = {'code': 1, 'name': 'RATABARI (SC)', 'valid_candidate_votes': 85641,
                  'candidates': [{'votes': 33555}, {'votes': 52086}]}
        self.assertTrue(corroborates(record, summary))
        self.assertFalse(corroborates(record | {'name': 'Another seat'}, summary))
        self.assertFalse(corroborates(record | {'candidates': [{'votes': 85640}]}, summary))

    def test_reject_inconsistent_poll_percentage_or_missing_official_labels(self):
        self.assertIsNone(parse_summary_page(SUMMARY.replace('62.76', '99.99'), 18))
        self.assertIsNone(parse_summary_page(SUMMARY.replace('4. TOTAL 85648', '4. UNKNOWN 85648'), 18))
        self.assertIsNone(parse_summary_page(SUMMARY.replace('85641', '85649'), 18))


if __name__ == '__main__':
    unittest.main()
