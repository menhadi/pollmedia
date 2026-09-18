import json
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest
from collect_goa_polling import links
from polling_manifest import load_manifest
from collect_nagaland_polling import report_links


class DropdownTest(unittest.TestCase):
    def test_post_downloads_keep_distinct_source_record_identifiers(self):
        body = b'<form><input name="id" value="560"><button name="download">download</button></form><form><input name="id" value="561"><button name="download">download</button></form><form><input name="id" value="562"><button name="login">login</button></form>'
        self.assertEqual([r['id'] for r in report_links(body)], ['560', '561'])
        with TemporaryDirectory() as directory:
            root = Path(directory)
            (root/'manifest.json').write_text(json.dumps({'documents': [], 'errors': []}))
            (root/'nagaland-supplement.json').write_text(json.dumps({'documents': [
                {'url': 'https://ceo.nagaland.gov.in/election-archive', 'request_id': '560', 'file': 'one.pdf'},
                {'url': 'https://ceo.nagaland.gov.in/election-archive', 'request_id': '561', 'file': 'two.pdf'}]}))
            self.assertEqual(len(load_manifest(root/'manifest.json')['documents']), 2)

    def test_only_result_table_viewers_are_collected(self):
        body = b'<a href="/Form20n21Viewer.aspx?id=outside">Navigation</a><table><tr><td>North Goa Form 20</td><td><a href="Form20n21Viewer.aspx?id=1">Open</a></td></tr></table>'
        records = links(body)
        self.assertEqual(len(records), 1)
        self.assertEqual(records[0]['url'], 'https://ceogoa.nic.in/appln/UIL/Form20n21Viewer.aspx?id=1')
        self.assertIn('North Goa', records[0]['label'])

    def test_supplement_keeps_archived_files_and_discovery_errors(self):
        with TemporaryDirectory() as directory:
            root = Path(directory)
            main = {'documents': [{'url': 'one', 'file': 'original.pdf'}, {'url': 'two', 'status': 'download_failed'}], 'errors': []}
            (root/'manifest.json').write_text(json.dumps(main))
            (root/'goa-supplement.json').write_text(json.dumps({'documents': [{'url': 'one', 'status': 'download_failed'}, {'url': 'two', 'file': 'second.pdf'}], 'errors': [{'error': 'source unavailable'}]}))
            result = load_manifest(root/'manifest.json')
            self.assertEqual([d['file'] for d in result['documents']], ['original.pdf', 'second.pdf'])
            self.assertEqual(len(result['errors']), 1)
            self.assertEqual(json.loads((root/'manifest.json').read_text()), main)


if __name__ == '__main__':
    unittest.main()
