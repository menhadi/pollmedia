import csv,hashlib,io,json,tempfile,unittest,zipfile
from pathlib import Path
from export_assembly_results import export

class AssemblyExportTests(unittest.TestCase):
    def test_export_retains_sources_notes_missing_values_and_safe_csv_cells(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory);fixtures=root/'application/database/fixtures';fixtures.mkdir(parents=True)
            entry=dict(state='Example',year=2005,url='https://example.test/report');(fixtures/'eci-assembly-national.json').write_text(json.dumps(dict(entries=[entry])))
            folder=root/'application/storage/app/private/election-archive'/hashlib.sha256(entry['url'].encode()).hexdigest()[:24];folder.mkdir(parents=True)
            raw=b'official';digest=hashlib.sha256(raw).hexdigest();(folder/'report.pdf').write_bytes(raw)
            (folder/'manifest.json').write_text(json.dumps(dict(files=[dict(file='report.pdf',name='Report.pdf',sha256=digest)])))
            (folder/'extraction.json').write_text(json.dumps(dict(year=2005,source_url=entry['url'],source_file='report.pdf',source_sha256=digest,records=[dict(code=1,name='Sample',status='needs_review',error='Source discrepancy',candidates=[dict(candidate_name='=1+1',party_at_election='AAA',votes=0,postal_votes=None)])])))
            output=root/'export.zip';export(root,output)
            with zipfile.ZipFile(output) as archive:
                rows=list(csv.DictReader(io.StringIO(archive.read('assembly-results.csv').decode('utf-8-sig'))));manifest=json.loads(archive.read('manifest.json'))
            self.assertEqual(rows[0]['candidate'],"'=1+1")
            self.assertEqual(rows[0]['total_votes'],'0')
            self.assertEqual(rows[0]['postal_votes'],'')
            self.assertEqual(rows[0]['data_note'],'Source discrepancy')
            self.assertEqual(manifest['candidate_and_nota_rows'],1)
            self.assertEqual(rows[0]['source_sha256'],digest)
            with self.assertRaisesRegex(ValueError,'preserved'):export(root,output)

if __name__=='__main__':unittest.main()
