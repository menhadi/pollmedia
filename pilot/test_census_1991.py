import hashlib
from datetime import time
import io
import json
from pathlib import Path
import tempfile
import unittest
from types import SimpleNamespace
from unittest.mock import patch
import zipfile

from collect_census_1991 import collect, extract, fetch


def workbook():
    stream = io.BytesIO()
    with zipfile.ZipFile(stream, 'w') as archive:
        archive.writestr('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>')
        archive.writestr('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="PCA" sheetId="1" r:id="rId1"/></sheets></workbook>')
        archive.writestr('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>')
        archive.writestr('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:E4"/><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>T_POPLN</t></is></c><c r="B1" t="inlineStr"><is><t>T_M_POPLN</t></is></c><c r="C1" t="inlineStr"><is><t>T_F_POPLN</t></is></c></row><row r="2"><c r="A2"><v>9</v></c><c r="B2"><v>4</v></c><c r="C2"><v>4</v></c><c r="D2" t="inlineStr"><is><t>001</t></is></c></row><row r="4"><c r="A4"><v>0</v></c><c r="B4"><f>1+1</f><v>2</v></c><c r="C4" t="e"><v>#N/A</v></c></row></sheetData></worksheet>')
    return stream.getvalue()


class Census1991Test(unittest.TestCase):
    def test_retains_excel_time_values_with_a_note_instead_of_failing(self):
        sheet = SimpleNamespace(title='PCA', reset_dimensions=lambda: None,
                                iter_rows=lambda: iter([[SimpleNamespace(value='NAME', data_type='s')],
                                                        [SimpleNamespace(value=time(7, 30), data_type='d')]]))
        class Book(list):
            def close(self):
                pass
        with patch('collect_census_1991.openpyxl.load_workbook', return_value=Book([sheet])):
            row = extract(b'')['sheets'][0]['rows'][1]
        self.assertEqual(['07:30:00'], row['cells'])
        self.assertIn('date/time', row['flags'][0])

    def test_preserves_identifiers_formulas_errors_and_source_row_numbers(self):
        rows = extract(workbook())['sheets'][0]['rows']
        self.assertEqual([1, 2, 4], [r['source_row'] for r in rows])
        self.assertEqual(['001'], rows[1]['cells'][3:])
        self.assertEqual([0, '=1+1', '#N/A'], rows[2]['cells'][:3])
        self.assertEqual([2], rows[2]['formula_columns'])
        self.assertEqual([3], rows[2]['error_columns'])
        self.assertIn('differs', rows[1]['flags'][0])
        self.assertIn('non-numeric', rows[2]['flags'][0])

    def test_reuses_verified_sources_and_rejects_changed_bytes(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp); folder = root / '43662'; folder.mkdir()
            entry = {'landing': 'https://censusindia.gov.in/nada/index.php/catalog/43662'}
            data = b'{"schema_version": 2}'; (folder / 'source.xlsx').write_bytes(data); (folder / 'source.json').write_bytes(data)
            digest = hashlib.sha256(data).hexdigest()
            manifest = dict(entry, files=[{'file': 'source.xlsx', 'sha256': digest, 'extraction': 'source.json', 'extraction_sha256': digest}])
            (folder / 'manifest.json').write_text(json.dumps(manifest), encoding='utf-8')
            with patch('collect_census_1991.fetch', side_effect=AssertionError('No refetch expected')):
                self.assertEqual(manifest, collect(entry, root))
                (folder / 'source.xlsx').write_bytes(b'changed')
                with self.assertRaisesRegex(ValueError, 'checksum'):
                    collect(entry, root)

    def test_rejects_nonofficial_download_host(self):
        with self.assertRaises(ValueError):
            fetch('https://example.com/file.xlsx')

    def test_saved_mode_reports_missing_sources_without_network_requests(self):
        with tempfile.TemporaryDirectory() as temp:
            with patch('collect_census_1991.fetch', side_effect=AssertionError('Offline mode must not fetch')):
                with self.assertRaisesRegex(ValueError, 'download pending'):
                    collect({'landing': 'https://censusindia.gov.in/nada/index.php/catalog/43662'}, Path(temp), saved=True)


if __name__ == '__main__':
    unittest.main()
