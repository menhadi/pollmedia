import copy
import unittest

from build_assembly_workbook_summary_bundle import AMBIGUOUS, DUPLICATE_NAMES, PENDING, RECONCILED_CANDIDATE_DIFFERENCE, RECONCILED_MISMATCH, RECONCILED_NOTA, RECONCILED_RECOVERED, TOTAL_MISMATCH, revised_records


class AssemblyWorkbookSummaryBundleTest(unittest.TestCase):
    def record(self):
        candidates = [
            {'candidate_name': 'Winner', 'party_at_election': 'AGP', 'votes': 65768,
             'general_votes': 65575, 'postal_votes': 193, 'source_sheet': 'DetailedResult', 'workbook_row': 858},
            {'candidate_name': 'Runner', 'party_at_election': 'INC', 'votes': 60599,
             'general_votes': 60548, 'postal_votes': 51, 'source_sheet': 'DetailedResult', 'workbook_row': 859},
            {'candidate_name': 'Other', 'party_at_election': 'IND', 'votes': 8244,
             'general_votes': 8244, 'postal_votes': 0, 'source_sheet': 'DetailedResult', 'workbook_row': 860},
            {'candidate_name': 'None of the Above', 'party_at_election': 'NOTA', 'votes': 1179,
             'general_votes': 1179, 'postal_votes': 0, 'source_sheet': 'DetailedResult',
             'workbook_row': 861, 'is_nota': True},
        ]
        return {'code': 87, 'name': 'Barhampur', 'status': 'needs_review',
                'error': PENDING + '; ' + AMBIGUOUS, 'number_of_seats': 1,
                'electors': 162019, 'votes_polled': None, 'reported_totals':
                [{'label': 'Total Votes', 'value': 135790}], 'candidates': candidates}

    def summary(self):
        return {87: {'code': 87, 'name': 'Barhampur', 'electors': 162019,
                     'votes_polled': 135812, 'valid_candidate_votes': 135790,
                     'summary_page': 87}}

    def test_only_exactly_matched_source_pages_add_voter_totals(self):
        record = self.record()
        data = {'kind': 'ac', 'records': [record]}
        source = {'source_file': 'summary.pdf', 'source_sha256': 'a' * 64}

        revised, count = revised_records(data, self.summary(), source)

        self.assertEqual(count, 1)
        result = revised['records'][0]
        self.assertEqual(result['votes_polled'], 135812)
        self.assertEqual(result['summary_totals']['valid_candidate_votes'], 135790)
        self.assertEqual(result['summary_source_file'], 'summary.pdf')
        self.assertEqual(result['source_warning_code'], 'workbook_pdf_summary')
        self.assertEqual(result['error'], RECONCILED_NOTA)
        self.assertEqual(result['original_extraction_warning'], record['error'])
        self.assertEqual(result['candidates'], record['candidates'])
        self.assertEqual(data['records'][0], record)

    def test_conflicting_totals_or_identity_leave_source_unchanged(self):
        source = {'source_file': 'summary.pdf', 'source_sha256': 'a' * 64}
        for change in ({'name': 'Other seat'}, {'electors': 162020},
                       {'reported_totals': [{'label': 'Total Votes', 'value': 135789}]}):
            record = self.record() | change
            revised, count = revised_records({'records': [record]}, self.summary(), source)
            self.assertEqual(count, 0)
            self.assertEqual(revised['records'][0], record)
        record = copy.deepcopy(self.record())
        record['candidates'][1]['votes'] += 1
        revised, count = revised_records({'records': [record]}, self.summary(), source)
        self.assertEqual(count, 0)

    def test_separate_pdf_summary_keeps_conflicting_workbook_total_with_note(self):
        record = self.record()
        record['error'] = PENDING + '; ' + TOTAL_MISMATCH
        record['reported_totals'][0]['value'] = 136000
        summary = self.summary()
        summary[87]['valid_candidate_votes'] = 134611
        summary[87]['nota_votes'] = 1179
        source = {'source_file': 'summary.pdf', 'source_sha256': 'a' * 64}

        revised, count = revised_records({'records': [record]}, summary, source)

        self.assertEqual(count, 1)
        result = revised['records'][0]
        self.assertEqual(result['votes_polled'], 135812)
        self.assertEqual(result['reported_totals'][0]['value'], 136000)
        self.assertEqual(result['summary_totals']['nota_votes'], 1179)
        self.assertEqual(result['error'], RECONCILED_MISMATCH)
        self.assertEqual(result['source_discrepancy']['workbook_value'], 136000)
        self.assertEqual(record['votes_polled'], None)

    def test_small_candidate_total_difference_keeps_both_source_values(self):
        record = self.record()
        summary = self.summary()
        summary[87]['valid_candidate_votes'] = 135789
        source = {'source_file': 'summary.pdf', 'source_sha256': 'a' * 64}

        revised, count = revised_records({'records': [record]}, summary, source)

        self.assertEqual(count, 1)
        result = revised['records'][0]
        self.assertEqual(result['votes_polled'], 135812)
        self.assertEqual(result['error'], RECONCILED_CANDIDATE_DIFFERENCE)
        self.assertEqual(result['source_discrepancy']['difference'], 1)
        self.assertEqual(result['reported_totals'], record['reported_totals'])

        summary[87]['valid_candidate_votes'] -= 200
        revised, count = revised_records({'records': [record]}, summary, source)
        self.assertEqual(count, 0)

    def test_repeated_candidate_name_does_not_hide_confirmed_turnout(self):
        record = self.record()
        record['error'] = PENDING + DUPLICATE_NAMES + '; ' + AMBIGUOUS
        record['candidates'][2]['candidate_name'] = 'Runner'
        record['candidates'][2]['party_at_election'] = 'INC'
        source = {'source_file': 'summary.pdf', 'source_sha256': 'a' * 64}

        revised, count = revised_records({'records': [record]}, self.summary(), source)

        self.assertEqual(count, 1)
        self.assertEqual(revised['records'][0]['votes_polled'], 135812)
        self.assertEqual(revised['records'][0]['original_extraction_warning'], record['error'])

    def test_recovered_rows_preserve_small_elector_component_conflict(self):
        record = self.record()
        record['error'] = PENDING + ' Candidate rows recovered from the same archived source; earlier extraction note: Elector totals conflict: detailed report 162019; summary components 162017.'
        record['electors'] = None
        record['reported_totals'] = None
        source = {'source_file': 'summary.pdf', 'source_sha256': 'a' * 64}

        revised, count = revised_records({'records': [record]}, self.summary(), source)

        self.assertEqual(count, 1)
        result = revised['records'][0]
        self.assertEqual(result['error'], RECONCILED_RECOVERED)
        self.assertEqual(result['electors'], 162019)
        self.assertEqual(result['votes_polled'], 135812)
        self.assertEqual(result['source_discrepancy']['component_value'], 162017)
        self.assertIsNone(record['electors'])

        record['error'] = record['error'].replace('162017', '162015')
        revised, count = revised_records({'records': [record]}, self.summary(), source)
        self.assertEqual(count, 0)


if __name__ == '__main__':
    unittest.main()
