import hashlib
import tempfile
import unittest
from pathlib import Path

import openpyxl
from extract_historical_a02 import extract


class HistoricalA02Test(unittest.TestCase):
    def test_1921_selection_preserves_printed_year_and_footnotes(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory)/'1921.xlsx'
            book = openpyxl.Workbook()
            sheet = book.active
            sheet.title = 'A-2'
            sheet.append(['A-02'])
            sheet.append(['State Code', 'District Code', 'State/District', 'Census Year', 'Persons'])
            sheet.append([None]); sheet.append([None])
            sheet.append(['01', '000', 'Printed state', '1921*', 10, None, None, 5, 5])
            sheet.append(['* Adjusted boundaries'])
            book.save(path)
            manifest = {'sha256': hashlib.sha256(path.read_bytes()).hexdigest(), 'boundary_basis': '2011 jurisdictions'}
            data = extract(path, manifest, years=(1921,))
            self.assertEqual([1921], [row['year'] for row in data['records']])
            self.assertEqual('1921*', data['records'][0]['year_label'])
            self.assertTrue(data['records'][0]['flags'])
            self.assertTrue(data['notes'])
            with self.assertRaisesRegex(ValueError, 'Requested year missing'):
                extract(path, manifest, years=(1911,))

    def test_split_header_requires_the_printed_year_label(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory)/'split.xlsx'
            book = openpyxl.Workbook()
            sheet = book.active
            sheet.title = 'A-2'
            sheet.append(['A-02'])
            sheet.append(['State', 'District', 'India/State/', 'Census', 'Persons'])
            sheet.append(['Code', 'Code', 'Union Territory', 'Year'])
            sheet.append([None])
            sheet.append(['00', '000', 'INDIA', 1901, 10, None, None, 5, 5])
            sheet.append([None, None, None, 1911, 12, None, None, 6, 6])
            book.save(path)
            manifest = {'sha256': hashlib.sha256(path.read_bytes()).hexdigest(), 'boundary_basis': '2011 jurisdictions'}
            self.assertEqual(len(extract(path, manifest)['records']), 2)
            sheet.append([None, '001', 'Printed district', 1901, 4, None, None, 2, 2])
            sheet.append([None, None, None, 1911, 6, None, None, 3, 3])
            book.save(path)
            manifest['sha256'] = hashlib.sha256(path.read_bytes()).hexdigest()
            sparse = extract(path, manifest)['records']
            self.assertEqual(sparse[-1]['district_code'], '001')
            self.assertEqual(sparse[-1]['state_code'], '00')
            sheet.cell(3, 4, 'Unverified')
            book.save(path)
            manifest['sha256'] = hashlib.sha256(path.read_bytes()).hexdigest()
            with self.assertRaisesRegex(ValueError, 'headers'):
                extract(path, manifest)
            for column in range(1, 6):
                sheet.cell(2, column).value = None
            for column, value in enumerate(['State Code', 'District Code', 'State/Union Territory/District', 'Census Year', 'Persons'], 1):
                sheet.cell(3, column).value = value
            book.save(path)
            manifest['sha256'] = hashlib.sha256(path.read_bytes()).hexdigest()
            self.assertEqual(len(extract(path, manifest)['records']), 4)

    def test_preserves_markers_formulas_and_uncertain_population(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory)/'original.xlsx'
            book = openpyxl.Workbook()
            sheet = book.active
            sheet.title = 'A-2 '
            sheet.append(['A-02'])
            sheet.append(['State Code', 'District Code', 'State/District', 'Census Year', 'Persons'])
            for _ in range(2):
                sheet.append([None])
            sheet.append([28, '001', 'District', '*1901', 10, '---', '---', 6, 3])
            sheet.append([None, None, None, 1911, 12, '=E7-E6', '=F7*100/E6', 6, 6])
            sheet.append(['* Boundaries adjusted to 2011'])
            sheet.append([None, None, None, '1911,+16,456 for 1921 and +8,240 for 1931. Source explanation'])
            book.save(path)
            manifest = {'sha256': hashlib.sha256(path.read_bytes()).hexdigest(), 'boundary_basis': '2011 jurisdictions'}
            data = extract(path, manifest)
            self.assertEqual(len(data['records']), 2)
            self.assertEqual(data['records'][1]['district_code'], '001')
            self.assertEqual(len(data['records'][0]['flags']), 2)
            self.assertEqual(data['raw_rows'][-3]['cells'][5], '=E7-E6')
            self.assertEqual(data['notes'][0]['source_row'], 7)
            self.assertEqual(data['records'][0]['source_row'], 5)
            self.assertEqual(len(data['notes']), 2)
            manifest['sha256'] = '0'*64
            with self.assertRaisesRegex(ValueError, 'checksum'):
                extract(path, manifest)


if __name__ == '__main__':
    unittest.main()
