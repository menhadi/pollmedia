import json
from pathlib import Path
from types import SimpleNamespace
import unittest
from unittest.mock import patch
from openpyxl.worksheet.formula import ArrayFormula, DataTableFormula
from extract_assembly_modern import CellWorkbook
from extract_polling_sources import spreadsheet_pages, numeric, readable_text_share, UNRELIABLE_TEXT_SHARE


class PollingSpreadsheetTest(unittest.TestCase):
    def test_formula_metadata_survives_json_without_becoming_vote_counts(self):
        book = CellWorkbook([SimpleNamespace(title='Form 20', values=[
            [ArrayFormula(ref='A1:A2', text='=SUM(B1:B2)'), DataTableFormula(ref='B1:B2'), 7],
        ])])
        with patch('extract_assembly_modern.load_cells', return_value=book):
            page = json.loads(json.dumps(list(spreadsheet_pages(Path('source.xlsx')))[0]))
        values = page['tables'][0]['cells'][0]
        self.assertEqual(values[0]['text'], '=SUM(B1:B2)')
        self.assertEqual(values[0]['attributes']['ref'], 'A1:A2')
        self.assertEqual(values[1]['formula_type'], 'dataTable')
        self.assertIsNone(numeric(values[0]))
        self.assertEqual(values[2], 7)

    def test_wide_polling_sheet_retains_all_columns_and_remains_bounded(self):
        book = CellWorkbook([SimpleNamespace(title='Form 20', values=[list(range(120))])])
        with patch('extract_assembly_modern.load_cells', return_value=book) as load:
            pages = list(spreadsheet_pages(Path('source.xls')))
        load.assert_called_once_with(Path('source.xls'), max_columns=256)
        self.assertEqual(pages[0]['tables'][0]['cells'][0], list(range(120)))
        book[0].values = [list(range(257))]
        with patch('extract_assembly_modern.load_cells', return_value=book):
            with self.assertRaisesRegex(ValueError, 'dimensions'):
                list(spreadsheet_pages(Path('source.xls')))

    def test_garbled_embedded_text_falls_below_the_unreliable_share(self):
        garbled = '.\n_\n- -\n-\nFORM 20 \n~--, -11--~i~- I .. 11 p-- u,, I ! 11-~1~ \n~ \n~ I \n~ \n. ~ \n~ \n~-, \nI \n~ \n~-\n'
        readable = 'FINAL RESULT SHEET Total No of Electors in Assembly Constituency NUMBER OF VALID VOTES CAST IN FAVOUR OF candidate'
        self.assertLess(readable_text_share(garbled), UNRELIABLE_TEXT_SHARE)
        self.assertGreaterEqual(readable_text_share(readable), UNRELIABLE_TEXT_SHARE)
        self.assertEqual(readable_text_share(''), 0.0)


if __name__ == '__main__':
    unittest.main()
