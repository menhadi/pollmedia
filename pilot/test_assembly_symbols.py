import unittest
from types import SimpleNamespace
from unittest.mock import patch
from extract_assembly_symbols import candidate,finish,extract
from test_assembly_legacy import Document


def word(x,y,text,width=5): return (x,y,x+width,y+10,text)


class SymbolPage:
    rect=SimpleNamespace(width=612,height=792)
    def get_text(self,mode=None):
        if mode!='words': return 'DETAILED RESULTS SYMBOL VALID VOTES POLLED'
        headers=[word(x,100,text) for x,text in [(66,'CANDIDATE'),(180,'SEX'),(209,'AGE'),(230,'CATEGORY'),(288,'PARTY'),(324,'SYMBOL'),(384,'GENERAL'),(445,'POSTAL'),(486,'TOTAL'),(534,'POLLED')]]
        identity=[word(12,130,'Constituency'),word(120,130,'1.'),word(140,130,'Sample'),word(366,130,'TOTAL'),word(402,130,'ELECTORS'),word(462,130,':'),word(506,130,'100')]
        row=[word(x,769,t) for x,t in zip([43,54,189,212,243,294,330,396,451,494,543],['1','ONE','M','40','GEN','AAA','Lotus','58','2','60','60.00'])]
        return headers+identity+row


class SymbolTests(unittest.TestCase):
    def test_bottom_candidate_is_not_treated_as_footer(self):
        with patch('extract_assembly_symbols.fitz.open',return_value=Document([SymbolPage()])):
            r=extract('source.pdf','Example')[0]
        self.assertEqual(r['candidates'][0]['votes'],60)
        self.assertEqual(r['candidates'][0]['election_symbol'],'Lotus')
        self.assertIn('Detailed totals are missing',r['error'])

    def test_nota_excluded_from_candidate_total_but_included_in_reconciliation(self):
        cs=[candidate(['1','ONE','M','40','GEN','AAA','Lotus','58','2','60','60']),candidate(['2','None of the Above','','','','NOTA','NOTA','4','0','4','4'])]
        r=finish(dict(candidates=cs,electors=100,issues=[],detail_totals=dict(general_votes=62,postal_votes=2,votes=64)))
        self.assertEqual(r['valid_candidate_votes'],60)
        self.assertTrue(r['candidates'][1]['is_nota'])
        self.assertNotIn('winner',r)
        self.assertNotIn('differ',r['error'])

    def test_missing_votes_remain_null_and_do_not_create_aggregate(self):
        c=candidate(['1','ONE','M','40','GEN','AAA','Lotus','','0','',''])
        r=finish(dict(candidates=[c],electors=100,issues=[]))
        self.assertIsNone(c['votes'])
        self.assertNotIn('valid_candidate_votes',r)
        self.assertIn('unreadable',r['error'])

    def test_grand_total_does_not_replace_constituency_total(self):
        class TotalPage(SymbolPage):
            def get_text(self,mode=None):
                if mode!='words': return super().get_text(mode)
                words=super().get_text(mode)
                # Move candidate up to leave space for the two distinct total rows.
                words=[(w[0],200 if w[1]==769 else w[1],w[2],210 if w[1]==769 else w[3],w[4]) for w in words]
                for y,grand,n in [(230,False,'60'),(260,True,'999')]:
                    words += [word(228,y,'TOTAL:'),word(396,y,'58' if not grand else '997'),word(451,y,'2'),word(494,y,n)]
                    if grand: words.append(word(190,y,'GRAND'))
                return words
        with patch('extract_assembly_symbols.fitz.open',return_value=Document([TotalPage()])):
            r=extract('source.pdf','Example')[0]
        self.assertEqual(r['detail_totals']['votes'],60)
        self.assertNotIn('Multiple',r['error'])

if __name__=='__main__':unittest.main()
