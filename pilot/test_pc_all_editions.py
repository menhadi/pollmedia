import json
import unittest
from pathlib import Path

from extract_pc_legacy import extract, summary_figures, reconcile
import fitz
from extract_pc_modern import pdf_records, xlsx_records, reconcile_2014, reconcile_2019
import copy
import openpyxl

ROOT = Path(__file__).resolve().parent.parent / 'application/storage/app/private/election-archive'


class AllEditionsTest(unittest.TestCase):
    def test_legacy_missing_gender_and_continued_names(self):
        cases=[('02f72a2ef53a457f8e97e539',1962,'9740','9741','KAIRA',2,'THAKORSHRI FATEHSINHJI RATANSINHJI DABHI',121499),('92de082304013ee1f62be87a',1989,'9761','9762','MEHSANA',5,'YOGI RAVINDRANATH MAHANSTSHREE HANUMANNATHJI MAHJARAJ',1830)]
        for archive,year,detail,summary,name,serial,candidate,votes in cases:
            folder=ROOT/archive
            records,_=extract(folder/f'{archive}-{detail}.pdf',folder/f'{archive}-{summary}.pdf',year)
            record=next(r for r in records if r['constituency_name']==name)
            row=next(c for c in record['candidates'] if c['source_row']==serial)
            self.assertEqual((row['candidate_name'],row['votes']),(candidate,votes))
            self.assertEqual(record['status'],'validated')

    def test_2019_variants_reconcile_separately_and_keep_real_state_names(self):
        variants=[('2e749f2174f08a9ea1fc803d','85be7b21fd43938b468f6f83-37787.pdf','2153706307f65ea1628b2ba3-37789.pdf',543,540),('70e603b1037bf7ca8e1350b0','44abcc4f98a9130d7b8eed2d-30003.pdf','001feea54c10b86f6969e32e-30005.pdf',542,539)]
        for archive,detail,summary,count,validated in variants:
            folder=ROOT/archive
            records=pdf_records(folder/detail,2019)
            self.assertEqual(len(records),count)
            self.assertTrue(all(not r['state_name'].startswith('Page ') for r in records))
            records=reconcile_2019(records,folder/summary)
            self.assertEqual(sum(r['status']=='validated' for r in records),validated)
            self.assertEqual(len({(r['state_code'],r['official_pc_code']) for r in records}),count)
            first=records[0]
            self.assertEqual(first['winner'],'Goddeti. Madhavi')
            self.assertEqual(first['margin'],224089)
            self.assertEqual(first['votes_polled'],1078235)
            self.assertEqual(first['valid_candidate_votes'],1026561)
            ahmedabad=next(r for r in records if r['constituency_name']=='Ahmedabad East')
            self.assertEqual(sorted((c['candidate_name'],c['votes']) for c in ahmedabad['candidates'] if c['candidate_name'] in ['Jayswal Nareshkumar Babulal (Raju Mataji)','Devda Dasharath Misarilal']), [('Devda Dasharath Misarilal',1395),('Jayswal Nareshkumar Babulal (Raju Mataji)',2517)])
            vellore=[r for r in records if r['constituency_name'].lower()=='vellore']
            self.assertEqual(len(vellore),int(count==543))
            records[0]['electors']+=1
            reconcile_2019(records,folder/summary)
            self.assertEqual(records[0]['status'],'needs_review')
            self.assertNotIn('winner',records[0])

    def test_legacy_summary_cells_and_discrepancies(self):
        info=json.loads((Path(__file__).parent/'data/pc-layout-inspection.json').read_text(encoding='utf-8'))
        for year,expected in [(1999,(985232,676413,658100,96882)),(1957,(416861,153238,153238,16356))]:
            source=info[str(year)]['summary']
            with fitz.open(next(ROOT.rglob(source['file']))) as doc:
                figures=summary_figures(doc[source['page']-1],year)
            self.assertEqual(tuple(figures[k] for k in ['electors','votes_polled','valid_candidate_votes','margin']),expected)
        figures=dict(electors=100,votes_polled=80,valid_candidate_votes=75,contested=2,winner_votes=50,runner_votes=25,margin=25)
        record=dict(electors=100,votes_polled=80,valid_candidate_votes=75,candidates=[dict(candidate_name='A',votes=50),dict(candidate_name='B',votes=25)])
        reconcile(record,figures,[])
        self.assertEqual(record['status'],'validated')
        self.assertEqual(record['winner'],'A')
        changed=dict(figures,valid_candidate_votes=74)
        reconcile(record,changed,[])
        self.assertEqual(record['status'],'needs_review')
        self.assertNotIn('winner',record)
        reconcile(record,figures,['Multi-member constituency: individual winners require review'])
        self.assertEqual(record['status'],'needs_review')
        self.assertNotIn('winner',record)

    def test_all_collected_pc_editions_have_reviewable_artifacts(self):
        count = 0
        for path in ROOT.glob('*/manifest.json'):
            manifest = json.loads(path.read_text(encoding='utf-8'))
            if manifest['kind'] != 'pc': continue
            count += 1
            result = json.loads(path.with_name('extraction.json').read_text(encoding='utf-8'))
            self.assertEqual(result['source_url'], manifest['url'])
            self.assertEqual(result['year'], manifest['year'])
            records = result['records']
            self.assertTrue(records)
            self.assertEqual(len({r['code'] for r in records}), len(records))
            for record in records:
                self.assertTrue(record['name'])
                if record['status'] == 'needs_review':
                    self.assertTrue(record['error'])
                    self.assertNotIn('winner', record)
                for candidate in record['candidates']:
                    self.assertGreaterEqual(candidate['votes'], 0)
        self.assertEqual(count, 21)

    def test_1967_keeps_state_transitions_and_all_detailed_tables(self):
        folder = ROOT / 'd7365c7939cfc38b09eb9581'
        records, coverage = extract(folder / 'd7365c7939cfc38b09eb9581-9743.pdf', folder / 'd7365c7939cfc38b09eb9581-9744.pdf', 1967)
        cachar = next(r for r in records if r['constituency_name'] == 'CACHAR')
        self.assertEqual(cachar['state_name'], 'Assam')
        self.assertEqual(cachar['candidates'][0]['votes'], 89713)
        self.assertEqual(coverage['detailed_pages'], 79)
        self.assertEqual(len(records), 520)
        self.assertEqual(coverage['unmatched_summaries'], [])
        cuddapah = next(r for r in records if r['constituency_name'] == 'CUDDAPAH')
        self.assertEqual(cuddapah['state_name'], 'Andhra Pradesh')
        self.assertEqual(cuddapah['candidates'][0]['votes'], 191736)
        kishanganj=next(r for r in records if r['constituency_name']=='KISHANGANJ')
        self.assertEqual(len(kishanganj['candidates']),6)
        self.assertEqual(kishanganj['candidates'][3]['votes'],15765)
        self.assertEqual(kishanganj['status'],'validated')
        delhi=next(r for r in records if r['constituency_name']=='NEW DELHI')
        self.assertEqual(delhi['status'],'needs_review')
        self.assertTrue(delhi['unparsed_candidate_rows'])

    def test_2024_continuations_nota_and_source_coverage(self):
        path = ROOT / '349e04305ee4652986f79497/349e04305ee4652986f79497-saved.pdf'
        records = pdf_records(path, 2024)
        self.assertEqual(len(records), 542)
        self.assertTrue(all(not r['state_name'].startswith('Page ') for r in records))
        araku = records[0]
        self.assertEqual(araku['constituency_name'], 'Araku')
        self.assertEqual(sum(c['votes'] for c in araku['candidates'] if c['is_nota']), 50470)
        self.assertEqual(sum(c['votes'] for c in araku['candidates'] if not c['is_nota']), araku['valid_candidate_votes'])
        self.assertEqual(araku['votes_polled'], 1165787)
        visakhapatnam = next(r for r in records if r['constituency_name'] == 'Visakhapatnam')
        self.assertEqual(len(visakhapatnam['candidates']), 34)
        damoh=next(r for r in records if r['constituency_name']=='DAMOH')
        self.assertEqual([(c['candidate_name'],c['votes']) for c in damoh['candidates'][-2:]], [('Durga Mousi',1124),('Tarvar Singh Lodhi',1094)])
        summary=path.parent/'349e04305ee4652986f79497-summary.pdf'
        original_polled={r['code']:r.get('votes_polled') for r in records}
        reconcile_2019(records,summary,2024)
        self.assertEqual(sum(r['status']=='validated' for r in records),525)
        self.assertEqual(records[0]['winner'],'Gumma Thanuja Rani')
        self.assertEqual(records[0]['margin'],50580)
        conflicts=[r for r in records if 'polled votes differ' in r.get('error','')]
        self.assertEqual(len(conflicts),17)
        for record in conflicts:
            self.assertEqual(record['votes_polled'],original_polled[record['code']])
            self.assertNotEqual(record['votes_polled'],record['summary_totals']['votes_polled'])
            self.assertNotIn('winner',record)

    def test_2014_recovers_workbook_omissions_from_pdf(self):
        folder = ROOT / '3a136496a89deb7c38ecfe18'
        records = xlsx_records(folder / '97f53be05d08ca1e1d50c7a4-6464.xlsx', folder / '51512f85716a7b6349923689-6469.xlsx', folder / '97f53be05d08ca1e1d50c7a4-6463.pdf')
        self.assertEqual(len(records), 543)
        self.assertTrue(all(r['candidates'] for r in records))
        recovered = [r for r in records if r.get('source_locator', '').startswith('Detailed PDF')]
        self.assertEqual(len(recovered), 32)
        kalahandi=next(r for r in records if r['constituency_name']=='Kalahandi')
        self.assertEqual(kalahandi['candidates'][7]['party_at_election'],'CPI(ML)(L)')
        self.assertEqual(kalahandi['candidates'][7]['votes'],4885)
        self.assertEqual(sum(r['status']=='validated' for r in records),539)
        self.assertEqual(sum(r['status']=='needs_review' for r in records),4)
        self.assertEqual(sum(r['status']=='validated' for r in recovered),30)
        self.assertEqual(len({(r['state_code'],r['official_pc_code']) for r in records}), 543)
        first=records[0]
        self.assertEqual(first['winner'],'GODAM NAGESH')
        self.assertEqual(first['margin'],171290)
        self.assertEqual(first['valid_candidate_votes'],1028755)
        self.assertEqual(first['votes_polled'],1055593)
        book=openpyxl.load_workbook(folder / '51512f85716a7b6349923689-6469.xlsx',read_only=True,data_only=False)
        rows=list(book.worksheets[0].values);title=book.worksheets[0].title;book.close()
        changed=copy.deepcopy(first)
        changed['candidates'][0]['votes']+=1
        reconcile_2014(changed,rows,title)
        self.assertEqual(changed['status'],'needs_review')
        self.assertIn('Candidate votes differ',changed['error'])
        self.assertNotIn('winner',changed)
        formula_rows=[list(row) for row in rows];formula_rows[12][6]='=SUM(D13:F13)'
        changed=copy.deepcopy(first);reconcile_2014(changed,formula_rows,title)
        self.assertEqual(changed['status'],'needs_review')
        self.assertIn('formula-based',changed['error'])


if __name__ == '__main__': unittest.main()
