import hashlib
import io
import json
from pathlib import Path
import tempfile
import unittest

from botocore.exceptions import ClientError
from sync_polling_pdfs_r2 import upload


class Body:
    def __init__(self, data):
        self.data = data

    def iter_chunks(self, chunk_size):
        yield self.data

    def close(self):
        pass


class Bucket:
    def __init__(self):
        self.objects = {}
        self.uploads = 0

    def head_object(self, Bucket, Key):
        if Key not in self.objects:
            raise ClientError({'Error': {'Code': '404', 'Message': 'Missing'}}, 'HeadObject')
        data, metadata = self.objects[Key]
        return {'ContentLength': len(data), 'Metadata': metadata}

    def upload_file(self, filename, bucket, key, ExtraArgs):
        self.objects[key] = (Path(filename).read_bytes(), ExtraArgs['Metadata'])
        self.uploads += 1

    def get_object(self, Bucket, Key):
        return {'Body': Body(self.objects[Key][0])}


class SyncPollingPdfsTest(unittest.TestCase):
    def test_parallel_upload_writes_one_verified_receipt_per_source(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            folder = root / ('a' * 24)
            folder.mkdir()
            sources = []
            for number in range(12):
                data = f'%PDF-verified original {number}'.encode()
                digest = hashlib.sha256(data).hexdigest()
                (folder / (digest + '.pdf')).write_bytes(data)
                sources.append({'id': f'{number:024x}', 'folder': 'a' * 24, 'file': digest + '.pdf',
                                'sha256': digest, 'state': 'EXAMPLE',
                                'source_url': 'https://eci.gov.in/source.pdf', 'discovered_on': 'https://eci.gov.in/'})
            receipts = root / 'receipts.jsonl'
            bucket = Bucket()
            self.assertEqual(upload({'sources': sources}, root, receipts, bucket, 'test-bucket', 'pollmedia',
                                    workers=4), len(sources))
            records = [json.loads(line) for line in receipts.read_text(encoding='utf-8').splitlines()]
            self.assertEqual({record['source_id'] for record in records}, {source['id'] for source in sources})
            self.assertEqual(bucket.uploads, len(sources))
            self.assertEqual(upload({'sources': sources}, root, receipts, bucket, 'test-bucket', 'pollmedia',
                                    workers=4), 0)

    def test_verified_upload_receipt_and_resume(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            folder = root / ('a' * 24)
            folder.mkdir()
            data = b'%PDF-verified original'
            digest = hashlib.sha256(data).hexdigest()
            (folder / (digest + '.pdf')).write_bytes(data)
            source = {'id': 'b' * 24, 'folder': 'a' * 24, 'file': digest + '.pdf', 'sha256': digest,
                      'state': 'EXAMPLE', 'source_url': 'https://eci.gov.in/source.pdf',
                      'discovered_on': 'https://eci.gov.in/'}
            receipts = root / 'receipts.jsonl'
            bucket = Bucket()
            self.assertEqual(upload({'sources': [source]}, root, receipts, bucket, 'test-bucket', 'pollmedia'), 1)
            self.assertEqual(upload({'sources': [source]}, root, receipts, bucket, 'test-bucket', 'pollmedia'), 0)
            self.assertEqual(bucket.uploads, 1)
            record = json.loads(receipts.read_text(encoding='utf-8'))
            self.assertEqual(record['sha256'], digest)
            self.assertEqual(record['source_url'], source['source_url'])

    def test_changed_pdf_is_rejected_before_upload(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            folder = root / ('a' * 24)
            folder.mkdir()
            digest = hashlib.sha256(b'%PDF-original').hexdigest()
            (folder / (digest + '.pdf')).write_bytes(b'%PDF-changed')
            source = {'id': 'b' * 24, 'folder': 'a' * 24, 'file': digest + '.pdf', 'sha256': digest,
                      'state': 'EXAMPLE'}
            bucket = Bucket()
            with self.assertRaisesRegex(ValueError, 'missing or changed'):
                upload({'sources': [source]}, root, root / 'receipts.jsonl', bucket, 'test-bucket', 'pollmedia')
            self.assertEqual(bucket.uploads, 0)


if __name__ == '__main__':
    unittest.main()
