import hashlib
import json
from pathlib import Path
import tempfile
import unittest

from sync_archived_pdfs_r2 import discover, upload
from test_sync_polling_pdfs_r2 import Bucket


class ArchivedPdfUploadTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.private = Path(self.temporary.name) / 'private'
        self.folder = self.private / 'election-archive' / ('a' * 24)
        self.folder.mkdir(parents=True)
        self.data = b'%PDF-official preserved file'
        self.digest = hashlib.sha256(self.data).hexdigest()
        self.pdf = self.folder / 'report.pdf'
        self.pdf.write_bytes(self.data)
        self.manifest = self.folder / 'manifest.json'
        self._manifest({'files': [{'file': self.pdf.name, 'sha256': self.digest,
                                   'source_page': 'https://eci.gov.in/report'}]})

    def _manifest(self, data):
        self.manifest.write_text(json.dumps(data), encoding='utf-8')

    def test_discovers_only_manifested_pdfs_with_source(self):
        (self.folder / 'orphan.pdf').write_bytes(self.data)
        items = discover(self.private)
        self.assertEqual(len(items), 1)
        self.assertEqual(items[0]['relative'], 'election-archive/' + 'a' * 24 + '/report.pdf')
        self.assertEqual(items[0]['source_url'], 'https://eci.gov.in/report')

    def test_rejects_traversal_and_missing_source(self):
        self._manifest({'files': [{'file': '../report.pdf', 'sha256': self.digest,
                                   'source_page': 'https://eci.gov.in/report'}]})
        with self.assertRaisesRegex(ValueError, 'Unsafe'):
            discover(self.private)
        self._manifest({'files': [{'file': 'report.pdf', 'sha256': self.digest}]})
        with self.assertRaisesRegex(ValueError, 'official source URL'):
            discover(self.private)
        self._manifest({'files': [{'file': 'report.pdf', 'sha256': self.digest,
                                   'source_page': 'https://example.com/report'}]})
        with self.assertRaisesRegex(ValueError, 'official source URL'):
            discover(self.private)

    def test_verified_receipt_resume_and_changed_original(self):
        items = discover(self.private)
        receipts = self.private / 'receipts.jsonl'
        bucket = Bucket()
        self.assertEqual(upload(items, receipts, bucket, 'bucket', 'pollmedia', 'https://r2.example'), 1)
        self.assertEqual(bucket.uploads, 1)
        receipt = json.loads(receipts.read_text(encoding='utf-8'))
        self.assertEqual(receipt['sha256'], self.digest)
        self.assertEqual(receipt['source_url'], 'https://eci.gov.in/report')
        self.assertEqual(upload(items, receipts, bucket, 'bucket', 'pollmedia', 'https://r2.example'), 0)
        self.pdf.write_bytes(b'%PDF-changed')
        other_receipts = self.private / 'new-receipts.jsonl'
        with self.assertRaisesRegex(ValueError, 'changed'):
            upload(items, other_receipts, bucket, 'bucket', 'pollmedia', 'https://r2.example')
        self.assertFalse(other_receipts.exists())

    def test_census_manifest_relative_path(self):
        census = self.private / 'census-archive' / '2001'
        census.mkdir(parents=True)
        (census / 'book.pdf').write_bytes(self.data)
        (census / 'manifest.json').write_text(json.dumps({
            'path': 'census-archive/2001/book.pdf', 'sha256': self.digest,
            'url': 'https://censusindia.gov.in/book.pdf',
        }), encoding='utf-8')
        items = discover(self.private)
        self.assertEqual({item['category'] for item in items}, {'election-archive', 'census-archive'})


if __name__ == '__main__':
    unittest.main()
