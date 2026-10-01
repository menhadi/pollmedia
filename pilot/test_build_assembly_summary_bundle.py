import copy
import unittest

from build_assembly_summary_bundle import PENDING, RECONCILED, revised_records


class AssemblySummaryBundleTest(unittest.TestCase):
    def test_only_matching_records_gain_sourced_totals_without_losing_original_data(self):
        first = {
            'code': 1, 'name': 'RATABARI (SC)', 'status': 'needs_review',
            'error': PENDING, 'electors': None, 'votes_polled': None,
            'valid_candidate_votes': 80, 'detail_page': 12,
            'candidates': [{'candidate_name': 'A', 'votes': 50}, {'candidate_name': 'B', 'votes': 30}],
        }
        second = copy.deepcopy(first) | {'code': 2, 'name': 'OTHER', 'candidates': [{'votes': 79}]}
        data = {'kind': 'ac', 'records': [first, second]}
        summaries = {
            1: {'code': 1, 'name': 'Ratabari(SC)', 'electors': 100,
                'votes_polled': 82, 'valid_candidate_votes': 80, 'summary_page': 6},
            2: {'code': 2, 'name': 'Other', 'electors': 100,
                'votes_polled': 82, 'valid_candidate_votes': 80, 'summary_page': 7},
        }

        revised, count = revised_records(data, summaries)

        self.assertEqual(count, 1)
        self.assertEqual(revised['records'][0]['votes_polled'], 82)
        self.assertEqual(revised['records'][0]['summary_page'], 6)
        self.assertEqual(revised['records'][0]['summary_totals']['valid_candidate_votes'], 80)
        self.assertEqual(revised['records'][0]['original_extraction_warning'], PENDING)
        self.assertEqual(revised['records'][0]['error'], RECONCILED)
        self.assertEqual(revised['records'][0]['status'], 'needs_review')
        self.assertEqual(revised['records'][0]['candidates'], first['candidates'])
        self.assertEqual(revised['records'][1], second)
        self.assertEqual(data['records'][0], first)


if __name__ == '__main__':
    unittest.main()
