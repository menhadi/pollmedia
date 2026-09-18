import hashlib,json,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
from extract_assembly_multiple import run

class MultipleReportTests(unittest.TestCase):
    def test_separate_reports_keep_official_codes_and_all_source_hashes(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory);entry=dict(url='https://example.test/report',year=2005,state='Example');folder=root/hashlib.sha256(entry['url'].encode()).hexdigest()[:24];folder.mkdir();files=[]
            for month in ['feb','oct']:
                name='2005-'+month+'.pdf';(folder/name).write_bytes(month.encode());files.append(dict(file=name,name=name,sha256=hashlib.sha256(month.encode()).hexdigest()))
            (folder/'manifest.json').write_text(json.dumps(dict(url=entry['url'],year=2005,files=files)))
            def rows(*args):return [dict(code=1,name='Sample',candidates=[dict(candidate_name='ONE')],status='needs_review',error='Source note')]
            with patch('extract_assembly_multiple.extract',side_effect=rows):run(entry,root)
            data=json.loads((folder/'extraction.json').read_text());self.assertEqual([r['code'] for r in data['records']],[100001,200001]);self.assertEqual([r['official_ac_code'] for r in data['records']],[1,1]);self.assertEqual(data['additional_sources'][0]['file'],'2005-oct.pdf');self.assertIn('2005-feb',data['records'][0]['source_locator'])
            with self.assertRaisesRegex(ValueError,'preserved'):run(entry,root)

if __name__=='__main__':unittest.main()
