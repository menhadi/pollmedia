import copy
import unittest

from build_bihar_2005_turnout_bundle import PENDING, RECONCILED, ROUNDS, revised_records


class Bihar2005TurnoutBundleTest(unittest.TestCase):
    def records_and_summaries(self):
        records = []
        matched = {}
        for round_name, offset in ROUNDS.items():
            summaries = {}
            for code in range(1, 244):
                valid = 40000 + code + (1000 if round_name == '2005-oct' else 0)
                records.append({
                    'code': offset + code, 'official_ac_code': code,
                    'name': f'Seat {code} / {round_name}', 'election_round': round_name,
                    'source_document': round_name + '.pdf', 'status': 'needs_review',
                    'detail_page': code + 200, 'summary_page': None,
                    'electors': None, 'votes_polled': None, 'valid_candidate_votes': valid,
                    'error': PENDING + '; Candidate text needs review.',
                    'candidates': [{'candidate_name': 'A', 'votes': valid - 100},
                                   {'candidate_name': 'B', 'votes': 99}],
                })
                summaries[code] = {'code': code, 'name': f'Seat {code}',
                                   'electors': 90000 + code, 'votes_polled': valid + 400,
                                   'valid_candidate_votes': valid, 'summary_page': code + 20}
            matched[round_name] = {'summaries': summaries, 'file': round_name + '.pdf',
                                   'sha256': ('a' if round_name == '2005-feb' else 'b') * 64}
        return {'kind': 'ac', 'year': 2005, 'records': records}, matched

    def test_round_specific_summaries_fill_only_missing_turnout(self):
        data, matched = self.records_and_summaries()
        original = copy.deepcopy(data)

        revised, count = revised_records(data, matched)

        self.assertEqual(count, 486)
        self.assertEqual(revised['records'][0]['votes_polled'], 40401)
        self.assertEqual(revised['records'][243]['votes_polled'], 41401)
        self.assertEqual(revised['records'][0]['summary_source_file'], '2005-feb.pdf')
        self.assertEqual(revised['records'][243]['summary_source_file'], '2005-oct.pdf')
        self.assertEqual(revised['records'][0]['candidates'], original['records'][0]['candidates'])
        self.assertEqual(revised['records'][0]['status'], original['records'][0]['status'])
        self.assertEqual(revised['records'][0]['original_extraction_warning'], original['records'][0]['error'])
        self.assertTrue(revised['records'][0]['error'].startswith(RECONCILED))
        self.assertEqual(data, original)

    def test_wrong_round_identity_or_valid_votes_stops_the_revision(self):
        data, matched = self.records_and_summaries()
        data['records'][243]['code'] = 100001
        with self.assertRaisesRegex(ValueError, 'round-specific constituency code'):
            revised_records(data, matched)

        data, matched = self.records_and_summaries()
        data['records'][243]['valid_candidate_votes'] += 1
        with self.assertRaisesRegex(ValueError, 'cannot be safely revised'):
            revised_records(data, matched)


if __name__ == '__main__':
    unittest.main()
