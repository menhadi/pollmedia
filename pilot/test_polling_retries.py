import contextlib
from argparse import Namespace
import io
import json
from pathlib import Path
import subprocess
from tempfile import TemporaryDirectory
import unittest
from unittest.mock import patch
from collect_polling_sources import crawl, fetch, navigation_exclusion
from polling_manifest import replace_checkpoint
import run_polling_pipeline


class PollingRetryTest(unittest.TestCase):
    def test_pipeline_resume_preserves_pending_retry_state(self):
        with TemporaryDirectory() as directory, contextlib.redirect_stdout(io.StringIO()):
            root = Path(directory)
            store = root/'application/storage/app/private/polling-station-sources'
            folder = store/'source'; folder.mkdir(parents=True)
            manifest = {'state': 'TEST', 'pending_pages': [{'url': 'https://example.gov.in/results'}], 'page_failures': {'https://example.gov.in/results': 2}}
            (folder/'manifest.json').write_text(json.dumps(manifest))
            (store/'index.json').write_text(json.dumps({'sources': [], 'states': [{'pending_pages': 1}]}))
            args = Namespace(resume=True, defer_state=[], wait_pid=[], wait_extraction_pid=[], pages=120, output=None)
            with patch.object(run_polling_pipeline, '__file__', str(root/'pilot/run_polling_pipeline.py')), patch.object(run_polling_pipeline.subprocess, 'run') as run:
                run_polling_pipeline.main(args)
            commands = [call.args[0] for call in run.call_args_list]
            collection = [command for command in commands if command[1].endswith('collect_polling_sources.py')]
            self.assertEqual(len(collection), 1)
            self.assertNotIn('--rediscover', collection[0])
            self.assertEqual(json.loads((folder/'manifest.json').read_text()), manifest)
            self.assertTrue(any(command[1].endswith('extract_polling_sources.py') for command in commands))

    def test_checkpoint_retries_transient_lock_without_removing_previous_data(self):
        with TemporaryDirectory() as directory:
            target = Path(directory)/'manifest.json'; target.write_text('previous')
            temporary = Path(directory)/'manifest.tmp'; temporary.write_text('new')
            original_replace = Path.replace
            attempts = []
            def locked_replace(path, destination):
                attempts.append(path)
                self.assertEqual(target.read_text(), 'previous')
                if len(attempts) < 3:
                    raise PermissionError('Temporary reader lock')
                return original_replace(path, destination)
            with patch.object(Path, 'replace', locked_replace), patch('polling_manifest.time.sleep'):
                replace_checkpoint(temporary, target)
            self.assertEqual(target.read_text(), 'new')
            temporary.write_text('next')
            with patch.object(Path, 'replace', side_effect=PermissionError('Permanent denial')) as replace, patch('polling_manifest.time.sleep'):
                with self.assertRaises(PermissionError):
                    replace_checkpoint(temporary, target)
                self.assertEqual(replace.call_count, 8)
            self.assertEqual(target.read_text(), 'new')
            self.assertEqual(temporary.read_text(), 'next')

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

    def test_retired_http_link_is_recovered_over_https(self):
        requested = 'http://ceo.example.gov.in/uploads/form202014.pdf'
        secured = 'https://ceo.example.gov.in/uploads/form202014.pdf'
        with TemporaryDirectory() as directory:
            target = Path(directory)/'document.part'
            attempts = []
            def download(command, capture_output):
                url = command[command.index('%{url_effective}')+1]
                attempts.append(url)
                if url == requested:
                    return subprocess.CompletedProcess(command, 22, b'')
                Path(command[command.index('-o')+1]).write_bytes(b'%PDF-preserved original')
                return subprocess.CompletedProcess(command, 0, url.encode())
            with patch('collect_polling_sources.subprocess.run', download):
                body, final = fetch(requested, target)
            self.assertEqual(body, b'%PDF-preserved original')
            self.assertEqual(final, secured)
            self.assertEqual(attempts, [requested, secured])
            self.assertEqual(target.read_bytes(), b'%PDF-preserved original')

    def test_failed_https_link_is_not_retried_or_left_behind(self):
        url = 'https://ceo.example.gov.in/uploads/form202014.pdf'
        with TemporaryDirectory() as directory:
            target = Path(directory)/'document.part'
            attempts = []
            def download(command, capture_output):
                attempts.append(command[command.index('%{url_effective}')+1])
                return subprocess.CompletedProcess(command, 22, b'')
            with patch('collect_polling_sources.subprocess.run', download):
                with self.assertRaises(ValueError) as error:
                    fetch(url, target)
            self.assertEqual(str(error.exception), 'Official download failed; curl 22')
            self.assertEqual(attempts, [url])
            self.assertFalse(target.exists())


if __name__ == '__main__':
    unittest.main()
