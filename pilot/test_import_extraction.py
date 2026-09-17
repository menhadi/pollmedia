import io
import tempfile
import unittest
from pathlib import Path
from extract_import import extract, table

class ImportExtractionTest(unittest.TestCase):
    def test_row_limit_rejects_overflow_without_silent_truncation(self):
        with self.assertRaisesRegex(ValueError, 'row limit'):
            table([['code'], ['001'], ['002']], max_rows=1)
        self.assertEqual(len(table([['code'], ['001'], ['002']], max_rows=2)[1]), 2)
        headers, rows = table([['code', 'TRU'], ['001', 'Rural'], ['001', 'Total'], ['001', 'Urban']], max_rows=1, row_filter=('TRU', 'Total'))
        self.assertEqual(rows, [{'code':'001', 'TRU':'Total'}])

    def test_composite_keys_keep_residence_and_geography_distinct(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / 'input.csv'
            path.write_text('State,District,TRU\n01,001,Total\n01,001,Rural\n02,001,Total\n')
            result = extract(path, 'csv', {'key_columns':['State','District','TRU']})
            self.assertEqual(len({r['source_record_key'] for r in result['rows']}), 3)
            self.assertIn('01', result['rows'][0]['source_record_key'])
            with self.assertRaisesRegex(ValueError, 'composite'):
                extract(path, 'csv', {'key_columns':['Missing']})

    def test_xlsx_preserves_formatted_codes_and_filters_rows(self):
        import openpyxl
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / "input.xlsx"
            workbook = openpyxl.Workbook()
            sheet = workbook.active
            sheet.append(["code", "Level", "name"])
            sheet.append([7, "VILLAGE", "Example"])
            sheet['A2'].number_format = '000000'
            sheet.append([0, "DISTRICT", "Total"])
            workbook.save(path)
            result = extract(path, 'xlsx', {'filter_column':'Level', 'filter_value':'VILLAGE'})
            self.assertEqual(result['rows'][0]['code'], '000007')
            self.assertEqual(len(result['rows']), 1)
            sheet['C2'] = '=1+1'
            workbook.save(path)
            with self.assertRaisesRegex(ValueError, 'formulas'):
                extract(path, 'xlsx', {})
    def test_pdf_grid_extracts_and_scanned_style_page_requires_review(self):
        from reportlab.pdfgen import canvas
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / 'table.pdf'
            pdf = canvas.Canvas(str(path))
            for x in [50,150,300]: pdf.line(x,700,x,760)
            for y in [700,730,760]: pdf.line(50,y,300,y)
            pdf.drawString(60,740,'code'); pdf.drawString(160,740,'name')
            pdf.drawString(60,710,'001'); pdf.drawString(160,710,'Example')
            pdf.save()
            result = extract(path, 'pdf', {})
            self.assertEqual(result['rows'], [{'code':'001','name':'Example'}])
            pdf = canvas.Canvas(str(path)); pdf.drawString(50,750,'No table'); pdf.save()
            with self.assertRaisesRegex(ValueError, 'No matching table'):
                extract(path, 'pdf', {})
    def test_paginated_json_and_duplicate_headers_are_rejected(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / 'data.json'
            path.write_text('{"total":50,"records":[{"code":"001"}]}')
            with self.assertRaisesRegex(ValueError, 'paginated'):
                extract(path, 'json', {'json_path':'records'})
            path.write_text('code,code\n001,Example\n')
            with self.assertRaisesRegex(ValueError, 'duplicated'):
                extract(path, 'csv', {})

if __name__ == '__main__': unittest.main()
