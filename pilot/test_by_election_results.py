import unittest
from extract_by_elections import index_card, historical_summary, source_navigation_table
from collect_polling_sources import discover_links
from extract_polling_sources import (map_table, spreadsheet_pages, numeric, table_header, map_rows,
                                     continues_table, CARRIED_HEADER_NOTE)
from unittest.mock import patch
from types import SimpleNamespace


def table(cells, name='Worksheet'):
    return {'name': name, 'rows': [{'row': i, 'cells': row} for i, row in enumerate(cells, 1)]}


class ResultsTest(unittest.TestCase):
    def test_historical_placeholders_do_not_become_candidates_or_cross_states(self):
        source = table([[None]*11 for _ in range(5)] + [
            ['HP', 1, 1956, 'GHUMARWIN', 'INC', 100, 'A', 'IND', 50, 'B'],
            [None, 2, None, '-', '-', '-', '-', '-', '-', '-'],
            ['KUTCH', None, None, '-', '-', '-', '-', '-', '-', '-'],
            [None, None, None, '-', 'IND', 20, 'Unassigned named result'],
            ['VP', 1, 1956, 'NEXT', 'INC', 0, 'C']], 'Assembly')
        from copy import deepcopy
        original = deepcopy(source)
        records = historical_summary(source)
        self.assertEqual(source, original)
        self.assertEqual(len(records), 2)
        self.assertEqual([c['name'] for c in records[0]['candidates']], ['A', 'B'])
        self.assertFalse(any('Additional member' in n for n in records[0]['notes']))
        self.assertEqual(records[1]['state'], 'VP')
        self.assertEqual(records[1]['candidates'][0]['votes'], 0)

    def test_historical_named_additional_members_retain_separate_source_rows(self):
        source = table([[None]*11 for _ in range(5)] + [
            ['BOMBAY', 4, 1952, 'NAWAPUR SAKRI', 'INC', 26520, 'D.Y. SAKHARAM'],
            [None, 5, None, '-', 'INC', 25759, 'K.B. SUKARAM']], 'Assembly')
        record, = historical_summary(source)
        self.assertEqual([c['votes'] for c in record['candidates']], [26520, 25759])
        self.assertEqual([c['source_row'] for c in record['candidates']], [6, 7])
        self.assertTrue(any('no single-seat winner or margin' in n for n in record['notes']))
        self.assertEqual(record['status'], 'needs_review')

    def test_historical_state_label_alone_does_not_discard_named_continuations(self):
        source = table([[None]*11 for _ in range(5)] + [
            ['UP', 17, 1952, 'BAREILLY MUNICIPALITY', 'INC', 15282, 'J. SARAN'],
            ['WB', None, None, None, None, None, None, 'IND', 8561, 'L. NARAIN'],
            [None, 1, None, 'GOGHAT', 'IND', 14821, 'P.R. KRISHNA']], 'Assembly')
        first, second = historical_summary(source)
        self.assertEqual([c['name'] for c in first['candidates']], ['J. SARAN', 'L. NARAIN'])
        self.assertEqual(first['candidates'][1]['source_cells'], source['rows'][6]['cells'])
        self.assertEqual((first['state'], second['state']), ('UP', 'WB'))

    def test_misspelled_assembly_heading_preserves_printed_state_and_code(self):
        for heading, state, code in [
                ('Legislative Assemby of Uttat Pradesh Code - S24', 'Uttat Pradesh', 'S24'),
                ('Legislative Assembly of Tamil Nadu Code - S22', 'Tamil Nadu', 'S22'),
                ('Legislative Assemby of Maharashtra Code - S13', 'Maharashtra', 'S13')]:
            with self.subTest(heading=heading):
                result = index_card([table([['MP-234-LA'], [heading],
                    ['Legislative Constituency - 234-Example'],
                    ['S.No.', 'Candidate', 'Party', 'Votes'], [1, 'A', 'P', 12]])], 1997)
                self.assertEqual((result['kind'], result['state'], result['source_state_code']), ('ac', state, code))
                self.assertEqual(result['source_identity_heading'], heading)
                self.assertTrue(any('Original spelling' in n for n in result['notes']))

    def test_directory_style_label_alone_does_not_supply_state(self):
        result = index_card([table([['S24/UP-42-LA.html'], ['Legislative Constituency - 42-Sahaswan'],
            ['S.No.', 'Candidate', 'Party', 'Votes'], [1, 'A', 'P', 12]])], 1997)
        self.assertIsNone(result['state'])

    def test_blank_result_does_not_consume_the_next_index_card_metadata(self):
        cells = [['Assembly Constituency of Kerala'], ['Assembly Constituency- 85-Piravom'],
                 ['S.No.', 'Candidate', 'Sex', 'Party', 'Votes'], [1, None, None, None, None],
                 ['Election Commission of India'], ['BYE- ELECTION- 2012'],
                 ['I. CANDIDATE'], [1, 'NOMINATED', None, None, None],
                 [2, 'REJECTED', None, None, None], ['II. ELECTORS'], [1, 'GENERAL', None, None, None]]
        self.assertIsNone(index_card([table(cells)], 2009))
        cells[3] = [1, 'A', 'M', 'P', 100]
        result = index_card([table(cells)], 2009)
        self.assertEqual([c['name'] for c in result['candidates']], ['A'])
        self.assertEqual(result['candidates'][0]['votes'], 100)

    def test_candidate_parser_does_not_cross_worksheet_boundary(self):
        first = table([['Assembly Constituency of Kerala'], ['Assembly Constituency- 85-Piravom'],
                       ['S.No.', 'Candidate', 'Party', 'Votes'], [1, 'A', 'P', 100]], 'First')
        second = table([[1, 'NOMINATED', None, None]], 'Next form')
        result = index_card([first, second], 2012)
        self.assertEqual([c['name'] for c in result['candidates']], ['A'])

    def test_by_election_list_is_navigation_only_when_it_has_no_result_cells(self):
        navigation = [table([['STATE', 'CONSTITUENCY'],
                             ['GUJARAT', '23-BROACH'],
                             ['PUNJAB', '03-TARN TARAN']], 'HTML table')]
        self.assertTrue(source_navigation_table(navigation))
        result = [table([['STATE', 'CONSTITUENCY', 'CANDIDATE', 'VOTES'],
                         ['GUJARAT', '23-BROACH', 'A', '12000']], 'HTML table')]
        self.assertFalse(source_navigation_table(result))

    def test_spreadsheet_preserves_row_positions_formulas_and_zero(self):
        cells = [[None]*6,
                 ['Serial No Of Polling Station', None, 'Votes Cast In Favour Of', None, 'Total Valid Votes', 'Total'],
                 [None, None, 'A', 'B', None, None],
                 [1.0, 1.0, 10.0, 0.0, 10.0, 10.0],
                 [2.0, 2.0, '=SUM(A1:A2)', None, None, None]]
        class Book(list):
            closed = False
            def close(self):
                self.closed = True
        book = Book([SimpleNamespace(title='Form 20', values=cells)])
        with patch('extract_assembly_modern.load_cells', return_value=book):
            pages = list(spreadsheet_pages('source.xlsx'))
        self.assertTrue(book.closed)
        self.assertEqual(pages[0]['sheet'], 'Form 20')
        self.assertEqual(pages[0]['tables'][0]['cells'], cells)
        self.assertEqual(len(pages[0]['polling_rows']), 2)
        self.assertEqual(pages[0]['polling_rows'][0]['candidate_votes'][1]['votes'], 0)
        self.assertEqual(pages[0]['polling_rows'][0]['source_table_row'], 4)
        self.assertIsNone(pages[0]['polling_rows'][1]['candidate_votes'][0]['votes'])
        self.assertTrue(pages[0]['polling_rows'][1]['notes'])
        self.assertEqual(numeric(10.0), 10)
        self.assertIsNone(numeric(10.5))

    def test_centered_workbook_header_does_not_drop_first_candidates(self):
        cells = [[None]*7]*10 + [
            ['S. No.', 'No. and Name of Polling Station', None, 'Number of valid votes cast in favour of', None, 'Total Number of valid votes', 'Total'],
            [None, None, 'A', 'B', 'C', None, None],
            [1, 1, 3, 4, 5, 12, 12]]
        rows = map_table(cells)
        self.assertEqual([r['votes'] for r in rows[0]['candidate_votes']], [3, 4, 5])
        cells[12][1] = '1-Nachiyan Balla'
        cells[12][5] = '=SUM(C13:E13)'
        rows = map_table(cells)
        self.assertEqual(rows[0]['polling_station'], '1-Nachiyan Balla')
        self.assertIsNone(rows[0]['valid_votes'])
        self.assertTrue(rows[0]['notes'])
        cells[12][1] = 'POSTAL BALLOTS'
        self.assertEqual(map_table(cells), [])
        cells[10][1] = 'No. and Name of Assembly Segment'
        self.assertEqual(map_table(cells), [])

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

    def test_headerless_continuation_page_uses_the_document_header(self):
        cells = [['Serial No Of Polling Station', None, 'No of Valid Votes Cast in favour of', None, 'Total of Valid Votes', 'No of Rejected Votes', 'Total'],
                 [None, None, 'A', 'B', None, None, None],
                 ['1', '1', '10', '5', '15', '0', '15']]
        header = table_header(cells)
        self.assertEqual(len(map_table(cells)), 1)
        continuation = [['2', '2', '7', '8', '15', '0', '15'],
                        ['3', '3', '4', '1', '5', '0', '5']]
        self.assertTrue(continues_table(continuation, header))
        rows = map_rows(continuation, header, 0, CARRIED_HEADER_NOTE)
        self.assertEqual([row['polling_station'] for row in rows], ['2', '3'])
        self.assertEqual([candidate['name'] for candidate in rows[0]['candidate_votes']], ['A', 'B'])
        self.assertEqual([candidate['votes'] for candidate in rows[0]['candidate_votes']], [7, 8])
        self.assertEqual(rows[0]['valid_votes'], 15)
        self.assertEqual(rows[0]['notes'][0], CARRIED_HEADER_NOTE)

    def test_form20_separate_station_number_and_name_columns(self):
        cells = [
            ['Sl. No.', 'Name of the Polling Station', None, 'No. of valid votes cast in favour of', None,
             'Total of Valid Votes', 'No. of rejected votes', 'Total'],
            [None, None, None, 'Candidate A', 'Candidate B', None, None, None],
            [None, 'No.', 'Name', 'P1', 'P2', None, None, None],
            ['1', '7', 'Kamargaon LP School', '4', '6', '10', '', '10'],
            ['2', '8', 'Another School', '3', '5', '9', '0', '9'],
            ['Total', None, None, '7', '11', '19', '0', '19'],
        ]
        rows = map_table(cells)
        self.assertEqual([row['polling_station'] for row in rows],
                         ['7 Kamargaon LP School', '8 Another School'])
        self.assertEqual(rows[0]['candidate_votes'],
                         [{'name': 'Candidate A', 'votes': 4}, {'name': 'Candidate B', 'votes': 6}])
        self.assertIsNone(rows[0]['rejected_votes'])
        self.assertTrue(any('absent' in note for note in rows[0]['notes']))
        self.assertTrue(any('do not equal' in note for note in rows[1]['notes']))
        self.assertEqual(rows[0]['source_cells'], cells[3])

    def test_form20_named_station_requires_printed_no_and_name_subheaders(self):
        cells = [
            ['Name of the Polling Station', None, 'No. of valid votes cast in favour of', None,
             'Total of Valid Votes', 'No. of rejected votes', 'NOTA', 'Total'],
            [None, None, 'Candidate A', 'Candidate B', None, None, None, None],
            ['No.', 'Name', 'P1', 'P2', None, None, None, None],
            ['1', 'Suffry Bagan School', '2', '3', '5', '0', '1', '6'],
        ]
        self.assertEqual(map_table(cells)[0]['polling_station'], '1 Suffry Bagan School')
        self.assertEqual(map_table(cells)[0]['notes'], [])
        cells[2][1] = 'Other'
        self.assertEqual(map_table(cells), [])

    def test_form20_named_station_and_source_header_typos(self):
        cells = [
            ['Form 20', None, None, None, None, None, None, None],
            ['Sl.No. of Polling Station', 'Name of Polling Station',
             'No. of valid votes case in favour of', None,
             'Total of Valid Votes Polled', 'NOTA', 'No. of rejected votes',
             'Total (Valid & Rejected & Nota) Votes', 'Tenderd Vote'],
            [None, None, 'Candidate A', 'Candidate B', None, None, None, None, None],
            ['', None, '1', '2', '', None, None, None, None],
            ['1', 'Govt Primary School', '4', '6', '10', '2', '0', '12', '0'],
        ]
        rows = map_table(cells)
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0]['polling_station'], '1 Govt Primary School')
        self.assertEqual((rows[0]['valid_votes'], rows[0]['nota'], rows[0]['total_votes']), (10, 2, 12))
        self.assertEqual(rows[0]['notes'], [])

    def test_form20_compact_continuation_header_maps_printed_candidate_columns(self):
        cells = [
            ['Serial No.', 'Serial No. Of Polling Station', 'DILLIP KUMAR PANDA',
             'RITA SAHU', 'Total of Valid Votes', 'No. Of Rejected Votes',
             'NOTA', 'Total', 'No. Of Tendered Votes'],
            ['24', '24', '29', '518', '547', '0', '12', '559', '0'],
            ['25', '25', '15', '330', '345', '0', '14', '359', '0'],
        ]
        rows = map_table(cells)
        self.assertEqual([row['polling_station'] for row in rows], ['24', '25'])
        self.assertEqual(rows[0]['candidate_votes'][1], {'name': 'RITA SAHU', 'votes': 518})
        self.assertEqual(rows[0]['notes'], [])
        cells[0][1] = 'Other serial'
        self.assertEqual(map_table(cells), [])

    def test_continuation_requires_a_resolved_header_and_the_same_width(self):
        cells = [['Serial No Of Polling Station', None, 'No of Valid Votes Cast in favour of', None, 'Total of Valid Votes'],
                 [None, None, 'A', 'B', None],
                 ['1', '1', '10', '5', '15']]
        header = table_header(cells)
        self.assertFalse(continues_table([['2', '2', '7', '8', '15', '0']], header))
        self.assertFalse(continues_table([['2', '2']], header))
        self.assertEqual(map_table([['2', '2', '7', '8', '15'], ['3', '3', '1', '2', '3']]), [])

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
        _, pages = discover_links(b'<a href="election">Elections</a><a href="login">Official Login</a>', 'https://ceo.example.gov.in/index')
        self.assertEqual([p['url'] for p in pages], ['https://ceo.example.gov.in/election'])

    def test_election_dropdown_ids_are_resolved_using_the_source_query(self):
        html = b'<select id="OCEO_ElectionDetails_ElectionFilterId"><option value="0">Select Election Year</option><option value="46">Lok Sabha Elections 2024</option></select>'
        docs, pages = discover_links(html, 'https://www.ceopunjab.gov.in/electiondetails?id=1&fltr=0')
        self.assertEqual(docs, [])
        self.assertEqual([p['url'] for p in pages], ['https://www.ceopunjab.gov.in/electiondetails?id=1&fltr=46'])

    def test_discovery_reads_official_redirects_and_form20_download_tables(self):
        html = b'<meta http-equiv="refresh" content="0;url=https://new.example.gov.in/"><table><tr><td>Form 20 - 2024</td><td><a href="/uploads/01.pdf">View</a></td></tr></table><select><option value="/Form20/2023.pdf">2023</option></select>'
        docs, pages = discover_links(html, 'https://ceo.example.gov.in/')
        self.assertEqual(len(docs), 2)
        self.assertEqual(pages[0]['url'], 'https://new.example.gov.in/')
        docs, _ = discover_links(b'<a href="/uploads/02.pdf">Constituency 2</a>', 'https://ceo.example.gov.in/Form20_2024.html')
        self.assertEqual(len(docs), 1)
        docs, _ = discover_links(b'<a href="\\Downloads\\Form20\\01.pdf">Result</a>', 'https://ceo.example.gov.in/Candidate/614')
        self.assertEqual(docs[0]['url'], 'https://ceo.example.gov.in/Downloads/Form20/01.pdf')
        docs, _ = discover_links(b'<div><h4>Form-20 Election Result 2022</h4><table><tr><td><a href="/CommonControls/ViewCMSFile?qs=abc">1 Example</a></td></tr></table></div>', 'https://ceo.example.gov.in/')
        self.assertEqual(len(docs), 1)


if __name__ == '__main__':
    unittest.main()
