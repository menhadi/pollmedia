import unittest
from types import SimpleNamespace
from unittest.mock import patch
from extract_assembly_flat import extract,EXPECTED
from extract_assembly_modern import CellWorkbook
from extract_assembly_legacy import select_source

class FlatWorkbookTests(unittest.TestCase):
    def read(self, rows, label='Total valid votes polled +NOTA'):
        book=CellWorkbook([SimpleNamespace(title='Results',values=[EXPECTED+[label]]+rows)])
        with patch('extract_assembly_flat.load_cells',return_value=book): return extract('detail.xlsx','Example')

    def rows(self):
        return [[1,'Sample','ONE','M',40,'GEN','AAA',58,2,60,100,64],[1,'Sample','None of the Above',None,None,None,'NOTA',4,0,4,100,64]]

    def test_nota_and_vote_components_with_source_row(self):
        r=self.read(self.rows())[0]
        self.assertEqual(r['valid_candidate_votes'],60)
        self.assertEqual(r['candidates'][0]['workbook_row'],2)
        self.assertTrue(r['candidates'][1]['is_nota'])
        self.assertNotIn('winner',r)
        self.assertNotIn('source_row',r['candidates'][0])

    def test_ambiguous_totals_not_relabelled_as_voters(self):
        r=self.read(self.rows(),'Total Votes')[0]
        self.assertNotIn('votes_polled',r)
        self.assertNotIn('valid_candidate_votes',r)
        self.assertEqual(r['reported_totals'][0]['label'],'Total Votes')

    def test_formulas_and_blank_votes_are_preserved_without_evaluation(self):
        rows=self.rows();rows[0][9]='=58+2';rows[0][8]=None
        r=self.read(rows)[0]
        self.assertIsNone(r['candidates'][0]['votes'])
        self.assertEqual(r['candidates'][0]['source_values'][9],'=58+2')
        self.assertIn('formulas',r['error'])

    def test_constituency_identity_conflict_is_rejected(self):
        rows=self.rows();rows[1][1]='Different'
        with self.assertRaisesRegex(ValueError,'conflicting'):self.read(rows)

    def test_named_detail_source_selected_without_guessing_or_ambiguity(self):
        manifest={'files':[{'file':'a.pdf','name':'Summary.pdf'},{'file':'b.pdf','name':'10. Detailed Result.pdf'},{'file':'c.xls','name':'10.Detailed Resulsts.xls'}]}
        self.assertEqual(select_source(manifest)['file'],'b.pdf')
        self.assertEqual(select_source(manifest,'workbook')['file'],'c.xls')
        manifest['files'].append({'file':'d.pdf','name':'Detailed Results.pdf'})
        with self.assertRaisesRegex(ValueError,'unique'):select_source(manifest)

if __name__=='__main__':unittest.main()
