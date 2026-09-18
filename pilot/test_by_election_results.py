import unittest
from extract_by_elections import index_card, historical_summary
from collect_polling_sources import discover_links
from extract_polling_sources import map_table


def table(cells, name='Worksheet'):
    return {'name': name, 'rows': [{'row': i, 'cells': row} for i, row in enumerate(cells, 1)]}


class ResultsTest(unittest.TestCase):
    def test_historical_dates_do_not_inherit_the_previous_year(self):
        rows = [[]]*5
        for label, name in [(1956, 'First'), ('9.3.57', 'Second'), ('24.1158', 'Unclear'), ('4.12.58', 'Fourth')]:
            rows.append(['ASSAM', 1, label, name, 'P', 100, 'Winner', 'Q', 80, 'Other'])
        records = historical_summary(table(rows, 'Lok sabha'))
        self.assertEqual([r['year'] for r in records], [1956, 1957, None, 1958])
        self.assertEqual(records[1]['source_date'], '1957-03-09')
        self.assertEqual(records[2]['source_year_text'], '24.1158')
        self.assertTrue(any('unclear' in note for note in records[2]['notes']))

    def test_uncontested_source_without_candidate_name_stays_visible_with_note(self):
        result = index_card([table([['Legislative Assembly of- Andhra Pradesh'],
                                   ['Number and name Assembly Constituency - 248-Pulivendla'],
                                   ['DATES','UNCONTESTED AS PER ORDER DATED 05.12.2009']])],2009)
        self.assertEqual(result['state'],'Andhra Pradesh')
        self.assertEqual(result['election_status'],'reported_uncontested')
        self.assertEqual(result['candidates'],[])
        self.assertEqual(result['status'],'needs_review')

    def test_legacy_html_identity_and_condensed_total(self):
        result = index_card([table([['23-Akabarpur (Uttar Pradesh)'], ['Candidates','Valid Votes in PC'],
                                   ['Sl no.', 'Name', 'Party', 'Number', 'Percentage'], [1,'A','P',10,'100'],
                                   ['Total Valid Votes',10,'100']])], 2004)
        self.assertEqual((result['kind'],result['state'],result['constituency']), ('pc','Uttar Pradesh','Akabarpur'))
        self.assertEqual(result['reported_candidate_total'],10)

    def test_older_house_of_people_heading_supplies_source_state(self):
        result = index_card([table([['House of the People of Orissa'], ['Parliament Constituency - 10 Aska'],
                                   ['Sl no.', 'Name', 'Party', 'Number'], [1,'A','P',10]])], 2000)
        self.assertEqual((result['kind'],result['state'],result['constituency']),('pc','Orissa','Aska'))
        result = index_card([table([['Punjab State Code - S-19'], ['Parliamentary Constituency - 7 Ropar'],
                                   ['Sl no.', 'Name', 'Party', 'Number'], [1,'A','P',10]])], 1997)
        self.assertEqual((result['kind'],result['state'],result['constituency']),('pc','Punjab','Ropar'))

    def test_polling_rows_reconcile_votes_and_keep_zero_distinct_from_missing(self):
        cells = [['Serial No Of Polling Station', None, 'No of Valid Votes Cast in favour of', None, 'Total of Valid Votes', 'No of Rejected Votes', 'Votes for NOTA', 'Total'],
                 [None,None,'A','B',None,None,None,None],
                 ['1','1','10','0','10','0','2','12'],
                 ['2','2(A)','7','4','10','0','0','10'],
                 ['Total',None,'17','4','20','0','2','22']]
        result = map_table(cells)
        self.assertEqual(len(result), 2)
        self.assertEqual(result[0]['candidate_votes'][1]['votes'], 0)
        self.assertEqual(result[0]['notes'], [])
        self.assertEqual(result[1]['polling_station'], '2(A)')
        self.assertTrue(result[1]['notes'])
        cells[0][4] = 'Total No.of Valid Votes'
        prefixed = map_table([[None]*8]+cells)
        self.assertEqual(prefixed[0]['valid_votes'], 10)
        self.assertEqual(prefixed[0]['source_table_row'], 4)
        self.assertEqual(len(prefixed), 2)

    def test_modern_pc_uses_total_not_first_assembly_segment(self):
        source = table([
            ['Parliamentary Constituency of Example State, District Example'],
            ['No. and Name of Parliamentary Constituancy 4 (GEN) Example'],
            [4, 'Contested', 2, 0, 0, 2],
            ['Sl No.', 'Name of Candidates', 'Full name of Party', 'Valid Votes counted From Electronic Voting Machines', None, 'Valid Postal Votes', 'Total Valid Votes'],
            [1, 'Candidate A', 'Party A', 20, 30, 2, 52], [2, 'Candidate B', 'Party B', 10, 5, 0, 15],
            ['TOTAL', None, None, 30, 35, 2, 67],
        ])
        result = index_card([source], 2024)
        self.assertEqual(result['state'], 'Example State')
        self.assertEqual(result['code'], 4)
        self.assertEqual([c['votes'] for c in result['candidates']], [52, 15])
        self.assertEqual(result['notes'], [])

    def test_formula_votes_stay_missing_and_mismatch_gets_note(self):
        source = table([['Assembly Consituancy of Assam'], ['No. and Name of Assembly Consituancy 1 Example'],
                        ['S.No.', 'Candidate', 'Party', 'Votes'], [1, 'A', 'P', '=SUM(A1:A2)'], [2, 'B', 'Q', 0], ['Total', None, None, 12]])
        result = index_card([source], 2017)
        self.assertIsNone(result['candidates'][0]['votes'])
        self.assertEqual(result['candidates'][1]['votes'], 0)
        self.assertEqual(result['state'], 'Assam')
        self.assertEqual(result['status'], 'needs_review')

    def test_historical_multi_member_record_keeps_reported_roles_and_scope(self):
        source = table([[]]*5+[['ASSAM', 1, 1952, 'Example', 'P', 100, 'Winner', 'Q', 80, 'Other'],
                              [None, None, None, '-', 'P', 95, 'Second winner', None, None, None]], 'Lok sabha')
        result = historical_summary(source)[0]
        self.assertEqual(len(result['candidates']), 3)
        self.assertEqual(result['candidates'][2]['role'], 'reported_winner')
        self.assertTrue(any('Additional member' in note for note in result['notes']))

    def test_polling_discovery_follows_form20_but_not_rolls_or_unofficial_hosts(self):
        html = b'<a href="/Form20/2024/01.pdf">Result</a><a href="/rolls.pdf">Electoral roll</a><a href="https://example.org/form20.pdf">Other</a><a href="/archive">Election archive</a>'
        docs, pages = discover_links(html, 'https://ceo.example.gov.in/')
        self.assertEqual(len(docs), 1)
        self.assertEqual(len(pages), 1)

    def test_discovery_reads_official_redirects_and_form20_download_tables(self):
        html = b'<meta http-equiv="refresh" content="0;url=https://new.example.gov.in/"><table><tr><td>Form 20 - 2024</td><td><a href="/uploads/01.pdf">View</a></td></tr></table><select><option value="/Form20/2023.pdf">2023</option></select>'
        docs, pages = discover_links(html, 'https://ceo.example.gov.in/')
        self.assertEqual(len(docs), 2)
        self.assertEqual(pages[0]['url'], 'https://new.example.gov.in/')
        docs, _ = discover_links(b'<a href="/uploads/02.pdf">Constituency 2</a>', 'https://ceo.example.gov.in/Form20_2024.html')
        self.assertEqual(len(docs), 1)


if __name__ == '__main__':
    unittest.main()
