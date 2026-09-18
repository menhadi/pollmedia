import contextlib
import io
import json
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest
from unittest.mock import patch
from collect_polling_sources import crawl, navigation_exclusion


class PollingRetryTest(unittest.TestCase):
    def test_adjacent_news_is_retained_without_expanding_result_crawl(self):
        news = {'url': 'https://old.eci.gov.in/files/file/12-media-coverage/', 'label': 'Previous File Media coverage of general election'}
        self.assertIsNotNone(navigation_exclusion(news))
        self.assertIsNone(navigation_exclusion(news | {'label': 'Previous File Statistical results of general election'}))
        self.assertIsNone(navigation_exclusion(news | {'label': 'Media coverage of general election'}))
        entry = {'id': 'source', 'state': 'TEST', 'url': 'https://example.gov.in/results'}
        with TemporaryDirectory() as directory, contextlib.redirect_stdout(io.StringIO()):
            root = Path(directory); folder = root/'source'; folder.mkdir()
            (folder/'manifest.json').write_text(json.dumps({'pending_pages': [news]}))
            with patch('collect_polling_sources.fetch') as fetch:
                crawl(entry, root, 5)
                fetch.assert_not_called()
            data = json.loads((folder/'manifest.json').read_text())
            self.assertEqual(data['deferred_navigation'][0]['url'], news['url'])
            self.assertIn('reason', data['deferred_navigation'][0])
            self.assertEqual(data['pending_pages'], [])

    def test_file_retry_limit_preserves_failure_and_allows_explicit_recovery(self):
        entry = {'id': 'source', 'state': 'TEST', 'url': 'https://example.gov.in/results'}
        url = 'https://example.gov.in/Form20/report.pdf'
        with TemporaryDirectory() as directory, contextlib.redirect_stdout(io.StringIO()):
            root = Path(directory)
            def fetch_page_or_fail_file(target_url, *args, **kwargs):
                if target_url == url:
                    raise ValueError('PDF unavailable')
                return ('<html><a href="'+url+'">Form 20</a></html>').encode(), target_url
            with patch('collect_polling_sources.fetch', side_effect=fetch_page_or_fail_file) as fetch:
                for _ in range(4):
                    crawl(entry, root, 5)
                self.assertEqual(sum(call.args[0] == url for call in fetch.call_args_list), 3)
            manifest = root/'source/manifest.json'
            doc = json.loads(manifest.read_text())['documents'][0]
            self.assertEqual(doc['url'], url)
            self.assertEqual(doc['error'], 'PDF unavailable')
            self.assertTrue(doc['retry_exhausted'])
            self.assertNotIn('file', doc)
            with patch('collect_polling_sources.fetch', return_value=(b'%PDF-preserved original', url)):
                crawl(entry, root, 5, rediscover=True)
            doc = json.loads(manifest.read_text())['documents'][0]
            self.assertEqual(doc['status'], 'archived_requires_extraction')
            self.assertNotIn('error', doc)
            self.assertNotIn('retry_exhausted', doc)
            self.assertEqual((root/'source'/doc['file']).read_bytes(), b'%PDF-preserved original')

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
