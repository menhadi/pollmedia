import json
from pathlib import Path
import tempfile
import unittest

from civic_source_backlog import summarize


class BacklogTests(unittest.TestCase):
    def test_html_queue_visible_before_db_registration_and_deduplicated(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory); (root/'queue').mkdir(); (root/'packages').mkdir()
            job = {'kind':'mgnrega_html','sha256':'a'*64,'package':'report.html','source_url':'https://example.test/report'}
            for name in ('html-a.json','html-duplicate.json'):
                (root/'queue'/name).write_text(json.dumps(job))
            self.assertEqual(summarize(root)['counts'], {'html_original_missing':1})
            (root/'packages/report.html').write_text('<table></table>')
            self.assertEqual(summarize(root)['counts'], {'html_queued_pending_extraction':1})
            (root/'source-evidence').mkdir()
            manifest = root/'source-evidence'/('mgnrega-html-'+'a'*64+'.manifest.json')
            manifest.write_text(json.dumps({'original_sha256':'b'*64}))
            self.assertEqual(summarize(root)['counts'], {'html_receipt_mismatch':1})
            manifest.write_text(json.dumps({'original_sha256':'a'*64}))
            self.assertEqual(summarize(root)['counts'], {'html_evidence_present_pending_review':1})

    def test_invalid_html_path_is_not_followed(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory); (root/'queue').mkdir()
            (root/'queue/html-a.json').write_text(json.dumps({'kind':'mgnrega_html','sha256':'a'*64,'package':'../outside.html'}))
            self.assertEqual(summarize(root)['counts'], {'html_descriptor_invalid':1})

    def test_csv_access_hold_is_distinct_from_acquired_original(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory); url='https://example.test/a.csv'
            (root/'direct-csv-sources.json').write_text(json.dumps([{'source_url':url}]))
            p=root/'feeder-status.json'
            p.write_text(json.dumps({'csv_downloads':{url:{'error':'HTTP 403','access_review_required':True}}}))
            self.assertEqual(summarize(root)['counts'], {'access_review_required':1})
            p.write_text(json.dumps({'csv_downloads':{url:{'complete':True,'sha256':'a'*64}}}))
            self.assertEqual(summarize(root)['counts'], {'acquired_pending_csv_validation':1})

    def test_unseen_sources_are_pending_even_when_saved_counter_is_zero(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            item = {'source_url': 'https://example.test/a.pdf', 'academic_year': '2023-24'}
            (root/'direct-pdf-sources.json').write_text(json.dumps([item, item]))
            (root/'feeder-status.json').write_text('{"pending_downloads":0,"downloads":{}}')
            self.assertEqual(summarize(root)['counts'], {'registered_pending_admission': 1})

    def test_download_and_text_receipts_do_not_imply_publication(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            url = 'https://example.test/a.pdf'
            digest = 'a'*64
            (root/'direct-pdf-sources.json').write_text(json.dumps([{'source_url': url}]))
            (root/'feeder-status.json').write_text(json.dumps({'downloads': {url: {'complete': True, 'sha256': digest}}}))
            self.assertEqual(summarize(root)['counts'], {'downloaded_text_pending': 1})
            (root/'source-evidence').mkdir()
            (root/'source-evidence'/f'pdf-{digest}.manifest.json').write_text(json.dumps({'original_sha256': digest}))
            self.assertEqual(summarize(root)['counts'], {'text_evidence_present_pending_review': 1})


if __name__ == '__main__':
    unittest.main()
