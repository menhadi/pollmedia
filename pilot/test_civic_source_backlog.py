import json
from pathlib import Path
import tempfile
import unittest

from civic_source_backlog import summarize


class BacklogTests(unittest.TestCase):
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
