import copy
import unittest

from build_pc_ac_zero_turnout_bundle import revised_records


class PcAcZeroTurnoutBundleTest(unittest.TestCase):
    def test_only_matching_zero_turnout_is_filled_and_candidate_rows_remain(self):
        record = {'code': 1, 'name': 'Lumla (ST)', 'status': 'needs_review',
                  'detail_page': 72, 'electors': None, 'votes_polled': None,
                  'valid_candidate_votes': 6169, 'error': 'Candidate extraction needs review.',
                  'candidates': [{'candidate_name': 'A', 'votes': 3318},
                                 {'candidate_name': 'B', 'votes': 2851}]}
        no_match = copy.deepcopy(record) | {'code': 2, 'name': 'Another'}
        nonzero = copy.deepcopy(record) | {'code': 3, 'name': 'Three', 'votes_polled': 5100}
        data = {'records': [record, no_match, nonzero]}
        original = copy.deepcopy(data)
        summaries = {1: {'code': 1, 'name': 'Lumla (ST)', 'electors': 7007,
                         'votes_polled': 6122, 'valid_candidate_votes': 6169, 'summary_page': 13}}

        revised, counts = revised_records(data, summaries, {'file': 'official.pdf', 'sha256': 'a' * 64})

        self.assertEqual(counts['recovered'], 1)
        self.assertEqual(counts['source_valid_exceeds_voters'], 1)
        self.assertEqual(revised['records'][0]['electors'], 7007)
        self.assertEqual(revised['records'][0]['votes_polled'], 6122)
        self.assertIn('6,169 valid votes but 6,122 voters', revised['records'][0]['error'])
        self.assertEqual(revised['records'][0]['candidates'], record['candidates'])
        self.assertEqual(revised['records'][1:], original['records'][1:])
        self.assertEqual(data, original)

    def test_conflicting_nonzero_electors_are_not_overwritten(self):
        record = {'code': 1, 'name': 'Karnah', 'status': 'needs_review', 'detail_page': 104,
                  'electors': 29000, 'votes_polled': None, 'candidates': []}
        summary = {1: {'code': 1, 'name': 'Karnah', 'electors': 28948,
                       'votes_polled': 18922, 'valid_candidate_votes': 18922, 'summary_page': 17}}

        revised, counts = revised_records({'records': [record]}, summary,
                                          {'file': 'official.pdf', 'sha256': 'a' * 64})

        self.assertEqual(counts['recovered'], 0)
        self.assertEqual(counts['elector_conflicts_skipped'], 1)
        self.assertEqual(revised['records'][0], record)

    def test_small_elector_difference_keeps_original_and_documents_summary_denominator(self):
        record = {'code': 1, 'name': 'Aland', 'status': 'needs_review',
                  'electors': 192986, 'votes_polled': None, 'candidates': []}
        summary = {1: {'code': 1, 'name': 'Aland', 'electors': 192992,
                       'votes_polled': 133039, 'valid_candidate_votes': 132000, 'summary_page': 40}}

        revised, counts = revised_records({'records': [record]}, summary,
                                          {'file': 'official.pdf', 'sha256': 'a' * 64})

        self.assertEqual(counts['recovered'], 1)
        self.assertEqual(counts['elector_difference'], 1)
        self.assertEqual(revised['records'][0]['electors'], 192986)
        self.assertEqual(revised['records'][0]['summary_totals']['electors'], 192992)
        self.assertEqual(revised['records'][0]['source_discrepancy']['detail_value'], 192986)

    def test_previously_located_summary_page_can_supply_its_missing_totals(self):
        record = {'code': 403, 'name': 'Muzaffarabad', 'status': 'needs_review',
                  'electors': None, 'votes_polled': None, 'summary_page': 442}
        summary = {403: {'code': 403, 'name': 'Muzaffarabad', 'electors': 201400,
                         'votes_polled': 132839, 'valid_candidate_votes': 132824, 'summary_page': 442}}
        source = {'file': 'official.pdf', 'sha256': 'a' * 64}

        revised, counts = revised_records({'records': [record]}, summary, source)
        self.assertEqual(counts['recovered'], 1)
        self.assertEqual(revised['records'][0]['votes_polled'], 132839)

        record['summary_page'] = 441
        revised, counts = revised_records({'records': [record]}, summary, source)
        self.assertEqual(counts['recovered'], 0)
        self.assertEqual(revised['records'][0], record)


if __name__ == '__main__':
    unittest.main()
