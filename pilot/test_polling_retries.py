import contextlib
import io
import json
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest
from unittest.mock import patch
from collect_polling_sources import crawl


class PollingRetryTest(unittest.TestCase):
    def test_exhausted_page_stays_documented_and_explicit_retry_can_recover(self):
        entry = {'id': 'source', 'state': 'TEST', 'url': 'https://example.gov.in/results'}
        with TemporaryDirectory() as directory, contextlib.redirect_stdout(io.StringIO()):
            root = Path(directory)
            with patch('collect_polling_sources.fetch', side_effect=ValueError('Unavailable')) as fetch:
                for _ in range(4):
                    crawl(entry, root, 5)
                self.assertEqual(fetch.call_count, 3)
            manifest = root/'source/manifest.json'
            data = json.loads(manifest.read_text())
            self.assertEqual(data['errors'][0]['attempts'], 3)
            self.assertTrue(data['errors'][0]['retry_exhausted'])
            self.assertEqual(data['pending_pages'], [])
            self.assertEqual(data['errors'][0]['url'], entry['url'])
            with patch('collect_polling_sources.fetch', return_value=(b'<html><body>Results archive</body></html>', entry['url'])) as fetch:
                crawl(entry, root, 5, rediscover=True)
                self.assertEqual(fetch.call_count, 1)
            data = json.loads(manifest.read_text())
            self.assertEqual(data['errors'], [])
            self.assertEqual(data['page_failures'], {})
            self.assertEqual(len(data['pages']), 1)
            self.assertTrue(list((root/'source').glob('manifest-*.json')))


if __name__ == '__main__':
    unittest.main()
