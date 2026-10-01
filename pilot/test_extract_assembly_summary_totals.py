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
        self.assertTrue(corroborates(record | {'name': 'Ratabari'}, summary))
        self.assertTrue(corroborates(record | {'name': 'Ratabari (SC) (SC)'}, summary))
        self.assertFalse(corroborates(record | {'candidates': [{'votes': 85640}]}, summary))

    def test_reject_inconsistent_poll_percentage_or_missing_official_labels(self):
        self.assertIsNone(parse_summary_page(SUMMARY.replace('62.76', '99.99'), 18))
        self.assertIsNone(parse_summary_page(SUMMARY.replace('4. TOTAL 85648', '4. UNKNOWN 85648'), 18))
        self.assertIsNone(parse_summary_page(SUMMARY.replace('85641', '85649'), 18))

    def test_official_valid_vote_discrepancy_requires_explicit_review_mode(self):
        page = SUMMARY.replace('85641', '85649')
        self.assertIsNone(parse_summary_page(page, 18))
        self.assertEqual(parse_summary_page(page, 18, allow_vote_discrepancy=True)['votes_polled'], 85648)

    def test_later_reports_can_number_totals_differently(self):
        later = (SUMMARY.replace('3. TOTAL 70830 65640 136470', '4. TOTAL 70830 65640 0 136470')
                 .replace('4. TOTAL 85648', '5. TOTAL 85648')
                 .replace('3. TOTAL VALID VOTES POLLED 85641', '7.TOTAL VALID VOTES POLLED 85641'))
        self.assertEqual(parse_summary_page(later, 28)['votes_polled'], 85648)

    def test_constituency_identity_with_colon_and_dash(self):
        layout = SUMMARY.replace('CONSTITUENCY 1 - Ratabari(SC)', 'CONSTITUENCY :- 1 - Ratabari(SC)')
        self.assertEqual(parse_summary_page(layout, 18)['name'], 'Ratabari(SC)')

    def test_older_report_without_percentage_can_show_gender_voter_columns(self):
        older = (SUMMARY.replace('4. TOTAL 85648\nIII(A). POLLING PERCENTAGE 62.76',
                                 '4. TOTAL 43659 52421 96121')
                 .replace('3. TOTAL VALID VOTES POLLED 85641', '3. TOTAL VALID VOTES POLLED 96109'))
        self.assertEqual(parse_summary_page(older, 28)['votes_polled'], 96121)

    def test_older_report_labels_electors_who_voted_and_valid_votes(self):
        older = (SUMMARY.replace('III. VOTERS', 'III. ELECTORS WHO VOTED')
                 .replace('3. TOTAL VALID VOTES POLLED 85641', '2. VALID 85641'))
        self.assertEqual(parse_summary_page(older, 28)['valid_candidate_votes'], 85641)
        self.assertIsNone(parse_summary_page(older.replace('2. VALID 85641', '2. VALID Uncontested'), 28))

    def test_nota_is_matched_separately_from_valid_candidate_votes(self):
        later = (SUMMARY.replace('3. TOTAL VALID VOTES POLLED 85641',
                                 "7.TOTAL VALID VOTES POLLED 85641\n9.VOTES POLLED FOR 'NOTA' (INCLUDING POSTAL) 7"))
        summary = parse_summary_page(later, 35)
        self.assertEqual(summary['nota_votes'], 7)
        record = {'code': 1, 'name': 'Ratabari(SC)', 'valid_candidate_votes': 85641,
                  'candidates': [{'votes': 33555}, {'votes': 52086}, {'votes': 7, 'is_nota': True}]}
        self.assertTrue(corroborates(record, summary))
        record['candidates'][-1]['votes'] = 8
        self.assertFalse(corroborates(record, summary))

    def test_workbook_summary_can_include_nota_in_its_valid_vote_total(self):
        summary = {'code': 87, 'name': 'Barhampur', 'electors': 162019,
                   'votes_polled': 135812, 'valid_candidate_votes': 135790}
        record = {'code': 87, 'name': 'Barhampur', 'electors': 162019,
                  'candidates': [{'votes': 134611}, {'votes': 1179, 'is_nota': True}]}
        self.assertFalse(corroborates(record, summary))
        self.assertTrue(corroborates(record, summary, allow_inclusive_nota=True))
        record['valid_candidate_votes'] = 134611
        self.assertTrue(corroborates(record, summary, allow_inclusive_nota=True))
        record['valid_candidate_votes'] -= 1
        self.assertFalse(corroborates(record, summary, allow_inclusive_nota=True))

    def test_small_candidate_total_difference_requires_an_explicit_allowance(self):
        summary = {'code': 5, 'name': 'Dina Nagar (SC)', 'electors': 181798,
                   'votes_polled': 130600, 'valid_candidate_votes': 130524}
        record = {'code': 5, 'name': 'Dina Nagar', 'electors': 181798,
                  'valid_candidate_votes': 129294,
                  'candidates': [{'votes': 129294}, {'votes': 1231, 'is_nota': True}]}
        self.assertFalse(corroborates(record, summary, allow_inclusive_nota=True))
        self.assertTrue(corroborates(record, summary, allow_inclusive_nota=True,
                                     allow_candidate_difference=True))
        record['candidates'][0]['votes'] -= 200
        self.assertFalse(corroborates(record, summary, allow_inclusive_nota=True,
                                      allow_candidate_difference=True))


if __name__ == '__main__':
    unittest.main()
