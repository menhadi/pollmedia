import hashlib
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

from collect_by_elections import discover, raw_tables, collect_entry


class ByElectionTest(unittest.TestCase):
    def test_legacy_links_with_spaces_are_encoded_for_download(self):
        url = 'https://old.eci.gov.in/ByeElection/2007/index.htm'
        def download(source, path):
            body = b'<table><tr><td>Result</td></tr></table>'
            if source == url:
                body += b'<a href="result one.htm">Result</a>'
            else:
                self.assertTrue(source.endswith('result%20one.htm'))
            path.write_bytes(body)
            return body
        with tempfile.TemporaryDirectory() as tmp:
            entry = {'id': hashlib.sha256(url.encode()).hexdigest()[:24], 'label': '2007', 'year': 2007, 'url': url}
            with patch('collect_by_elections.fetch', side_effect=download):
                result = collect_entry(entry, Path(tmp))
            self.assertEqual(result['status'], 'collected')
            self.assertEqual(result['files'], 2)

    def test_discovery_preserves_missing_links_and_different_periods(self):
        html = '<table><tr><td><a href="/statistical-report/be/2026/37">July 2026</a><a href="#">Jan 2010</a><a href="https://old.eci.gov.in/ByeElection/2014/ac.xls">2014 (LA)</a><a href="https://old.eci.gov.in/ByeElection/2014/pc.xls">2014 (PC)</a></td></tr></table><a href="https://old.eci.gov.in/files/file/2511/">Details of Bye Elections from 1952 to 1995</a>'
        entries = discover(json.dumps({'cmsPagesData': {'page_content': html}}).encode())['entries']
        self.assertEqual(len(entries), 5)
        self.assertEqual(entries[1]['status'], 'missing_official_link')
        self.assertNotEqual(entries[2]['id'], entries[3]['id'])
        self.assertEqual(entries[4]['years'], [1952, 1995])

    def test_html_preserves_spans_and_does_not_follow_unrelated_or_external_links(self):
        url = 'https://old.eci.gov.in/ByeElection/2001/index.htm'
        body = b'<table><tr><th colspan="2">Result</th></tr><tr><td>Zero</td><td>0</td></tr></table><a href="https://example.com/table.htm">External</a><a href="/other/index.htm">Other</a>'
        def download(source, path):
            self.assertEqual(source, url)
            path.write_bytes(body)
            return body
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            entry = {'id': hashlib.sha256(url.encode()).hexdigest()[:24], 'label': '2001', 'year': 2001, 'url': url}
            with patch('collect_by_elections.fetch', side_effect=download) as mocked:
                result = collect_entry(entry, root)
            self.assertEqual(mocked.call_count, 1)
            self.assertEqual(result['raw_rows'], 2)
            file = next((root/entry['id']).glob('*-tables.json'))
            data = json.loads(file.read_text())
            self.assertEqual(data['status'], 'raw_tables_need_mapping')
            self.assertEqual(data['tables'][0]['rows'][0]['spans'][0]['colspan'], '2')
            self.assertEqual(data['tables'][0]['rows'][1]['cells'][1], '0')


if __name__ == '__main__':
    unittest.main()
