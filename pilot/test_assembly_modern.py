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
