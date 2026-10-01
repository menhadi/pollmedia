import copy
import unittest

from build_assembly_summary_bundle import PENDING, RECONCILED, RECONCILED_SUMMARY_ONLY, RECONCILED_WARNINGS, revised_records


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

    def test_separate_official_summary_and_nota_are_preserved(self):
        record = {'code': 1, 'name': 'Sirpur', 'status': 'needs_review', 'error': PENDING,
                  'electors': 190962, 'votes_polled': None, 'valid_candidate_votes': 149532,
                  'candidates': [{'votes': 149532}, {'votes': 1756, 'is_nota': True}]}
        summary = {1: {'code': 1, 'name': 'Sirpur', 'electors': 190962,
                       'votes_polled': 151565, 'valid_candidate_votes': 149532,
                       'nota_votes': 1756, 'summary_page': 35}}
        secondary = {'source_file': 'summary.pdf', 'source_sha256': 'a' * 64}

        revised, count = revised_records({'records': [record]}, summary, secondary)

        self.assertEqual(count, 1)
        self.assertEqual(revised['records'][0]['summary_totals']['nota_votes'], 1756)
        self.assertEqual(revised['records'][0]['summary_source_file'], 'summary.pdf')
        self.assertEqual(revised['records'][0]['summary_source_sha256'], 'a' * 64)
        self.assertEqual(revised['records'][0]['candidates'], record['candidates'])

    def test_small_elector_difference_keeps_both_source_values_and_warning(self):
        record = {'code': 1, 'name': 'Nippani', 'status': 'needs_review', 'error': PENDING,
                  'electors': 189696, 'votes_polled': None, 'valid_candidate_votes': 152690,
                  'candidates': [{'votes': 152690}]}
        summary = {1: {'code': 1, 'name': 'Nippani', 'electors': 189698,
                       'votes_polled': 152927, 'valid_candidate_votes': 152690, 'summary_page': 25}}

        revised, count = revised_records({'records': [record]}, summary)

        self.assertEqual(count, 1)
        result = revised['records'][0]
        self.assertEqual(result['electors'], 189696)
        self.assertEqual(result['summary_totals']['electors'], 189698)
        self.assertEqual(result['votes_polled'], 152927)
        self.assertEqual(result['source_warning_code'], 'summary_elector_difference')
        self.assertEqual(result['source_discrepancy']['detail_value'], 189696)
        self.assertEqual(result['status'], 'needs_review')
        self.assertEqual(record['electors'], 189696)

        summary[1]['electors'] = 180000
        unchanged, count = revised_records({'records': [record]}, summary)
        self.assertEqual(count, 0)
        self.assertEqual(unchanged['records'][0], record)

    def test_official_summary_restores_turnout_when_candidate_text_needs_review(self):
        error = PENDING + '; Some candidate text could not be parsed; see the original PDF.; Detailed totals are missing or use an unsupported layout.'
        record = {'code': 1, 'name': 'Sheopur', 'status': 'needs_review', 'error': error,
                  'electors': 100, 'votes_polled': None, 'valid_candidate_votes': None,
                  'detail_page': 9, 'candidates': [{'votes': 50}, {'votes': 30}]}
        summary = {1: {'code': 1, 'name': 'Sheopur', 'electors': 100,
                       'votes_polled': 82, 'valid_candidate_votes': 80, 'summary_page': 4}}

        revised, count = revised_records({'records': [record]}, summary)

        self.assertEqual(count, 1)
        result = revised['records'][0]
        self.assertEqual(result['votes_polled'], 82)
        self.assertEqual(result['source_warning_code'], 'summary_turnout_with_detail_warnings')
        self.assertTrue(result['error'].startswith(RECONCILED_WARNINGS))
        self.assertEqual(result['original_extraction_warning'], error)
        self.assertIsNone(result['valid_candidate_votes'])

        record['candidates'][1]['votes'] = 29
        unchanged, count = revised_records({'records': [record]}, summary)
        self.assertEqual(count, 1)
        self.assertEqual(unchanged['records'][0]['source_warning_code'], 'summary_only_turnout')
        self.assertEqual(unchanged['records'][0]['candidates'], record['candidates'])

    def test_independent_summary_turnout_survives_incomplete_candidate_rows(self):
        record = {'code': 1, 'name': 'Rajmahal', 'status': 'needs_review',
                  'error': PENDING + '; One or more candidate cells are missing or unreadable; original cells are retained.',
                  'electors': 100, 'votes_polled': None, 'valid_candidate_votes': None,
                  'detail_page': 9, 'candidates': [{'votes': 50}, {'votes': None}]}
        summary = {1: {'code': 1, 'name': 'Rajmahal', 'electors': 100,
                       'votes_polled': 82, 'valid_candidate_votes': 80, 'summary_page': 4}}

        revised, count = revised_records({'records': [record]}, summary)

        self.assertEqual(count, 1)
        result = revised['records'][0]
        self.assertEqual(result['votes_polled'], 82)
        self.assertEqual(result['source_warning_code'], 'summary_only_turnout')
        self.assertTrue(result['error'].startswith(RECONCILED_SUMMARY_ONLY))
        self.assertEqual(result['candidates'], record['candidates'])
        self.assertEqual(result['original_extraction_warning'], record['error'])

        summary[1]['electors'] = 101
        unchanged, count = revised_records({'records': [record]}, summary)
        self.assertEqual(count, 0)
        self.assertEqual(unchanged['records'][0], record)


if __name__ == '__main__':
    unittest.main()
