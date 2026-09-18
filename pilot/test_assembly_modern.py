import unittest
from types import SimpleNamespace
from unittest.mock import patch
from extract_assembly_modern import extract


class Book(list):
    @property
    def active(self): return self[0]
    def close(self): pass


class AssemblyExtractionTest(unittest.TestCase):
    def books(self, missing=False, discrepancy=False):
        headers = ['STATE/UT NAME', 'AC NO.', 'AC NAME', 'CANDIDATE NAME', 'GENDER', 'AGE', 'CATEGORY', 'PARTY', 'SYMBOL', 'GENERAL', 'POSTAL', 'TOTAL', 'OVER VALID VOTES + NOTA', 'OVER TOTAL ELECTORS', 'TOTAL ELECTORS']
        rows = [[None]*15]*3 + [headers]
        for i, name, party, votes in [(1,'One','A',60),(2,'Two','B',30),(3,'Nota','NOTA',2)]:
            rows.append(['Haryana',1,'KALKA',str(i)+' '+name,None,None,None,party,None,votes,0,None if missing and i==1 else votes,None,None,100])
        rows.append(['TURN OUT',None,None,None,None,None,'TOTAL:',None,None,92,0,92,None,None,None])
        summary = [[None]*10, ['State/UT','S07-Haryana','Constituency Name','1-KALKA-(GEN)',None,None,None,None,None,None]]
        for label, value in [('4. Total',100),('5. Total',92),('7. Total Valid Votes Polled',91 if discrepancy else 90)]:
            summary.append([None,label,None,None,None,value,None,None,None,None])
        summary += [[None,'Winner',None,'Party','One',60,None,None,None,None], [None,'Margin',None,30,None,None,None,None,None,None]]
        return [Book([SimpleNamespace(values=rows,title='Sheet1')]),Book([SimpleNamespace(values=summary,title='Kalka')])]

    def test_reconciled_candidate_results_and_nota(self):
        with patch('extract_assembly_modern.openpyxl.load_workbook', side_effect=self.books()):
            row=extract('detail','summary','Haryana')[0]
        self.assertEqual(row['status'],'validated')
        self.assertEqual(row['winner'],'One')
        self.assertEqual(row['margin'],30)
        self.assertTrue(row['candidates'][2]['is_nota'])

    def test_legacy_fourteen_columns_and_unparenthesized_reservation(self):
        books = self.books()
        rows = books[0].active.values
        rows[3] = rows[3][:4] + ['SEX'] + rows[3][5:12] + ['% VOTES POLLED','TOTAL ELECTORS']
        for i in range(4,len(rows)):
            rows[i] = rows[i][:13] + [rows[i][14]]
        books[1].active.values[1][3] = '1-KALKA-GEN'
        with patch('extract_assembly_modern.openpyxl.load_workbook',side_effect=books):
            row=extract('detail','summary','Haryana')[0]
        self.assertEqual(row['status'],'validated')
        self.assertEqual(row['electors'],100)

    def test_older_summary_uses_explicit_sheet_code_and_section_totals(self):
        books = self.books()
        sheet = books[1].active
        sheet.title = 'S07-1'
        sheet.values = [[None]*7, ['State/UT & Code','S07','Constituency Name & Code','KALKA-GEN',None,None,None]]
        for section, label, value in [('ELECTORS','Total',100),('VOTERS','Total',92),('VOTES','Total Valid Votes Polled',90)]:
            sheet.values.extend([[section,None,None,None,None,None,None], [None,label,None,None,None,None,value]])
        sheet.values.extend([[None,'Winner',None,'Party','One',60,None], [None,'Margin',None,30,None,None,None]])
        with patch('extract_assembly_modern.openpyxl.load_workbook',side_effect=books):
            row=extract('detail','summary','Haryana')[0]
        self.assertEqual(row['status'],'validated')
        self.assertEqual(row['valid_candidate_votes'],90)
        self.assertEqual(row['electors'],100)

    def test_corrupt_formatting_fallback_preserves_zero_blank_and_formula_cells(self):
        import tempfile, zipfile
        from pathlib import Path
        from extract_assembly_modern import load_cells
        ns='http://schemas.openxmlformats.org/spreadsheetml/2006/main'
        with tempfile.TemporaryDirectory() as tmp:
            p=Path(tmp)/'source.xlsx'
            with zipfile.ZipFile(p,'w') as z:
                z.writestr('xl/workbook.xml', '<workbook xmlns="'+ns+'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Data" r:id="rId1"/></sheets></workbook>')
                z.writestr('xl/_rels/workbook.xml.rels','<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>')
                z.writestr('xl/worksheets/sheet1.xml','<worksheet xmlns="'+ns+'"><sheetData><row r="1"><c r="A1"><v>0</v></c><c r="C1"><f>1+2</f><v>3</v></c></row></sheetData></worksheet>')
            with patch('extract_assembly_modern.openpyxl.load_workbook',side_effect=ValueError('stylesheet')):
                book=load_cells(p)
            self.assertEqual(book.active.values[0],[0.0,None,'=1+2'])

    def test_delhi_alias_does_not_allow_a_different_state(self):
        from extract_assembly_modern import state_matches
        self.assertTrue(state_matches('NCT of Delhi','Delhi'))
        self.assertFalse(state_matches('Haryana','Delhi'))

    def test_source_difference_is_retained_with_note(self):
        with patch('extract_assembly_modern.openpyxl.load_workbook', side_effect=self.books(discrepancy=True)):
            row=extract('detail','summary','Haryana')[0]
        self.assertEqual(row['valid_candidate_votes'],91)
        self.assertEqual(len(row['candidates']),3)
        self.assertEqual(row['status'],'needs_review')

    def test_missing_votes_do_not_become_zero(self):
        with patch('extract_assembly_modern.openpyxl.load_workbook', side_effect=self.books(missing=True)):
            row=extract('detail','summary','Haryana')[0]
        self.assertIsNone(row['candidates'][0]['votes'])
        self.assertEqual(row['status'],'needs_review')

if __name__=='__main__': unittest.main()
