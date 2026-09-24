import hashlib
import json
from pathlib import Path
import tempfile
import unittest

from sync_archive_originals_r2 import discover, upload
from test_sync_polling_pdfs_r2 import Bucket


class ArchiveOriginalsUploadTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.private = Path(self.temporary.name) / 'private'
        self.folder = self.private / 'election-archive' / ('a' * 24)
        self.folder.mkdir(parents=True)
        self.collection = 'https://old.eci.gov.in/files/category/123/'
        self.workbook = self.folder / 'report.xlsx'
        self.body = b'PK\x03\x04preserved workbook'
        self.workbook.write_bytes(self.body)
        self.manifest = self.folder / 'manifest.json'
        self.save_manifest({'url': self.collection, 'files': [{
            'file': self.workbook.name, 'sha256': hashlib.sha256(self.body).hexdigest(),
            'bytes': len(self.body), 'source_page': 'https://old.eci.gov.in/files/file/123/',
        }]})

    def save_manifest(self, data):
        self.manifest.write_text(json.dumps(data), encoding='utf-8')

    def test_discovers_listed_and_recovered_page_originals_with_honest_url_scope(self):
        page = 'https://old.eci.gov.in/files/file/456/'
        page_id = hashlib.sha256(page.encode()).hexdigest()[:24]
        (self.folder / 'category-saved.html').write_text(
            '<a href="/files/file/456/">Report</a>', encoding='utf-8')
        (self.folder / ('page-' + page_id + '.html')).write_text('<p>source page</p>', encoding='utf-8')
        items = {item['path'].name: item for item in discover(self.private)}
        self.assertEqual(len(items), 3)
        self.assertEqual(items['report.xlsx']['url_scope'], 'page')
        self.assertEqual(items['page-' + page_id + '.html']['source_url'], page + '?do=download')
        self.assertEqual(items['category-saved.html']['url_scope'], 'collection')

    def test_upload_readback_receipt_and_resume_without_reupload(self):
        items = discover(self.private)
        bucket = Bucket()
        receipts = self.private / 'receipts.jsonl'
        self.assertEqual(upload(items, receipts, bucket, 'pollmedia', 'pollmedia',
                                'https://r2.example', workers=2), 1)
        record = json.loads(receipts.read_text(encoding='utf-8'))
        self.assertEqual(record['sha256'], hashlib.sha256(self.body).hexdigest())
        self.assertEqual(record['url_scope'], 'page')
        self.assertEqual(record['object_key'], 'pollmedia/archive-source-originals/' + items[0]['relative'])
        self.assertEqual(upload(items, receipts, bucket, 'pollmedia', 'pollmedia',
                                'https://r2.example', workers=2), 0)
        self.assertEqual(bucket.uploads, 1)

    def test_changed_listed_file_is_rejected_before_upload(self):
        self.workbook.write_bytes(b'changed')
        with self.assertRaisesRegex(ValueError, 'metadata differs'):
            discover(self.private)


if __name__ == '__main__':
    unittest.main()
