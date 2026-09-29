import hashlib
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

from civic_queue_feeder import Links, feed, official


class FeederTests(unittest.TestCase):
    def test_health_review_is_explicitly_allowlisted_not_arbitrary_health_paths(self):
        url = 'https://nhm.gov.in/New-Update-2024-26/CRM/16th_CRM_Report_2024.pdf'
        self.assertTrue(official(url))
        self.assertFalse(official(url.replace('16th_', 'unknown_')))
        self.assertFalse(official(url.replace('nhm.gov.in', 'nhm.gov.in.evil')))
        self.assertFalse(official(url + '?redirect=elsewhere'))

    def test_explicit_education_pdf_registry_preserves_period_and_resumes(self):
        url = 'https://dashboard.udiseplus.gov.in/report2026/static/media/UDISE+2023_24_Booklet_nep.f2ab818294eb169c0adc.pdf'
        self.assertTrue(official(url))
        self.assertFalse(official(url.replace('udiseplus.gov.in', 'udiseplus.gov.in.evil')))
        self.assertFalse(official(url + '?redirect=elsewhere'))
        self.assertFalse(official(url.replace('/report2026/', '/unreviewed/')))
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root/'ocr-worker/queue').mkdir(parents=True)
            (root/'catalogues.json').write_text('[]')
            item = {'source_url':url, 'landing':'https://dashboard.udiseplus.gov.in/',
                    'academic_year':'2023-24', 'structure':'NEP'}
            (root/'direct-pdf-sources.json').write_text(json.dumps([item]))
            with patch('civic_queue_feeder.resources_ok', return_value=True), patch('civic_queue_feeder.fetch', side_effect=lambda u,p,c,l:p.write_bytes(b'%PDF-fixture')) as download:
                feed(root)
                feed(root)
                self.assertEqual(download.call_count, 1)
            state = json.loads((root/'feeder-status.json').read_text())
            self.assertEqual(state['downloads'][url]['metadata'], item)
            self.assertEqual(len(list((root/'queue').glob('text-*.json'))), 1)

    def test_workbook_acquisition_flows_into_raw_cell_parser(self):
        import openpyxl, sqlite3
        from contextlib import closing
        from civic_queue_feeder import queue_workbook
        from census_server_worker import run
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory);(root/'packages').mkdir()
            def fetch(url,path,context,limit):
                book=openpyxl.Workbook();book.active.append(['Village','School']);book.active.append(['00123',None]);book.save(path)
            item={'key':'amenities-test','source_url':'https://censusindia.gov.in/nada/test.xlsx','edition':2011}
            with patch('civic_queue_feeder.fetch',side_effect=fetch):queue_workbook(root,item,None)
            with patch('census_server_worker.resources_ok',return_value=True):run(root)
            with closing(sqlite3.connect(root/'census-review.sqlite')) as db:
                self.assertEqual(db.execute('select row_count from workbooks').fetchone()[0],2)
                self.assertIn('00123',db.execute('select cells_json from raw_rows where source_row=2').fetchone()[0])
                self.assertEqual(db.execute('select status from jobs').fetchone()[0],'complete')
                from validate_civic_workbook import validate
                job=json.loads(next((root/'queue').glob('*.json')).read_text())
                result=validate(root,root/'packages'/job['package'],job['sha256'],db)
                self.assertEqual(result['sources'][0]['rows_checked'],2)
                db.execute("update raw_rows set cells_json='[\"changed\"]' where source_row=2");db.commit()
                with self.assertRaisesRegex(ValueError,'mismatch'):
                    validate(root,root/'packages'/job['package'],job['sha256'],db)

    def test_pending_workbooks_uses_current_registry_and_retains_history(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory); (root/'ocr-worker/queue').mkdir(parents=True)
            (root/'catalogues.json').write_text('[]')
            (root/'workbook-sources.json').write_text(json.dumps([
                {'key':'replacement','source_url':'new'}, {'key':'unseen','source_url':'unseen'}]))
            historical={'error':'invalid original', 'retry_after':123}
            (root/'feeder-status.json').write_text(json.dumps({
                'catalogues':{}, 'downloads':{},
                'workbook_downloads':{'old':historical,'new':{'complete':True}}}))
            with patch('civic_queue_feeder.fetch') as download:
                feed(root, download_limit=0)
                download.assert_not_called()
            state=json.loads((root/'feeder-status.json').read_text())
            self.assertEqual(state['pending_workbooks'],1)
            self.assertEqual(state['workbook_downloads']['old'],historical)

    def test_official_pdf_links_only(self):
        parser = Links('https://censusindia.gov.in/nada/index.php/catalog/1')
        parser.feed('<a href="/nada/index.php/catalog/1/download/2/a.pdf">PDF</a>'
                    '<a href="https://example.org/nada/a.pdf">bad</a>')
        self.assertEqual(len(parser.urls), 1)
        self.assertFalse(official('https://censusindia.gov.in.evil/nada/a.pdf'))

    @patch('civic_queue_feeder.resources_ok', return_value=True)
    def test_download_handoff_resume_and_tamper(self, _):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root/'ocr-worker/queue').mkdir(parents=True)
            url = 'https://censusindia.gov.in/nada/index.php/catalog/1'
            (root/'catalogues.json').write_text(json.dumps([{'url':url,'year':1951}]))
            def fetch(url, path, context, limit):
                path.write_bytes(b'%PDF-test' if url.endswith('.pdf') else
                                 b'<a href="/nada/index.php/catalog/1/download/2/a.pdf">PDF</a>')
            with patch('civic_queue_feeder.fetch', side_effect=fetch) as download:
                feed(root)
                self.assertEqual(download.call_count, 2)
                job = json.loads(next((root/'queue').glob('*.json')).read_text())
                h = job['sha256']; prefix = root/'source-evidence'/('pdf-'+h)
                pages = prefix.with_suffix('.pages.jsonl')
                pages.write_text(''.join(json.dumps({'page':p,'has_text':False})+'\n' for p in range(1, 28)))
                prefix.with_suffix('.manifest.json').write_text(json.dumps({
                    'original_sha256':h,'pages_jsonl_sha256':hashlib.sha256(pages.read_bytes()).hexdigest(),
                    'source_url':job['source_url']}))
                feed(root)
                self.assertEqual(download.call_count, 2)
                batches = [json.loads(p.read_text())['pages'] for p in (root/'ocr-worker/queue').glob('*.json')]
                self.assertEqual(sorted(map(len,batches)), [2,25])
                feed(root)
                self.assertEqual(len(list((root/'ocr-worker/queue').glob('*.json'))), 2)
                pages.write_text('tampered')
                with self.assertRaisesRegex(ValueError,'checksum'): feed(root)

    @patch('civic_queue_feeder.resources_ok', return_value=True)
    def test_failed_catalogue_does_not_stop_next(self, _):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory); (root/'ocr-worker/queue').mkdir(parents=True)
            base='https://censusindia.gov.in/nada/index.php/catalog/'
            (root/'catalogues.json').write_text(json.dumps([{'url':base+'1'},{'url':base+'2'}]))
            def fetch(url,path,context,limit):
                if url==base+'1': raise OSError('temporary failure')
                path.write_bytes(b'%PDF-test' if url.endswith('.pdf') else
                                 b'<a href="/nada/index.php/catalog/2/download/2/a.pdf">PDF</a>')
            with patch('civic_queue_feeder.fetch',side_effect=fetch): feed(root)
            state=json.loads((root/'feeder-status.json').read_text())
            self.assertIn('retry_after',state['catalogues'][base+'1'])
            self.assertTrue(state['catalogues'][base+'2']['complete'])
            self.assertEqual(len(list((root/'queue').glob('*.json'))),1)


if __name__ == '__main__': unittest.main()
