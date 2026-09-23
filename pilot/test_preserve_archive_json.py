import hashlib
import json
from pathlib import Path
import tempfile
import unittest
import zipfile

from preserve_archive_json import package


class PreserveArchiveJsonTest(unittest.TestCase):
    def test_buckets_preserve_exact_bytes_and_checksums(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / 'private'
            paths = [f'election-archive/{i:024x}/extraction.json' for i in range(12)]
            for index, relative in enumerate(paths):
                path = root / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(json.dumps({'source_url': 'https://eci.gov.in/',
                                             'records': [index]}, indent=2).encode())
            found = set()
            for bucket in range(2):
                output = Path(temporary) / f'package-{bucket}.zip'
                summary = package(root, output, 'election-archive', bucket, 2)
                self.assertEqual(summary['sha256'], hashlib.sha256(output.read_bytes()).hexdigest())
                with zipfile.ZipFile(output) as archive:
                    manifest = json.loads(archive.read('manifest.json'))
                    self.assertEqual(summary['files'], len(manifest['files']))
                    for entry in manifest['files']:
                        found.add(entry['path'])
                        self.assertEqual(archive.read(entry['path']), (root / entry['path']).read_bytes())
                        self.assertEqual(entry['sha256'], hashlib.sha256(archive.read(entry['path'])).hexdigest())
                with self.assertRaises(FileExistsError):
                    package(root, output, 'election-archive', bucket, 2)
            self.assertEqual(found, set(paths))


if __name__ == '__main__':
    unittest.main()
