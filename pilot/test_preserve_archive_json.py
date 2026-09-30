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

    def test_exact_path_package_excludes_changed_neighbors(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / 'private'
            category = 'election-by-elections'
            paths = [f'{category}/structured/index-{i:064x}.json' for i in range(3)]
            for relative in paths:
                path = root / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text('{"records":[]}', encoding='utf-8')
            chosen = paths[0]
            bucket = hashlib.sha256(chosen.encode()).digest()[0] % 8
            output = Path(temporary) / 'delta.zip'
            summary = package(root, output, category, bucket, 8, [chosen])
            self.assertEqual(summary['files'], 1)
            with zipfile.ZipFile(output) as archive:
                self.assertEqual(json.loads(archive.read('manifest.json'))['files'][0]['path'], chosen)
            with self.assertRaises(ValueError):
                package(root, Path(temporary) / 'wrong.zip', category, (bucket + 1) % 8, 8, [chosen])
            with self.assertRaises(ValueError):
                package(root, Path(temporary) / 'duplicate.zip', category, bucket, 8,
                        [chosen, chosen])

    def test_revision_requires_matching_preserved_previous_bytes(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / 'private'
            category = 'election-by-elections'
            current = f'{category}/results.json'
            old_body = b'{"records":[1]}'
            old_sha = hashlib.sha256(old_body).hexdigest()
            previous = f'{category}/results-{old_sha}.json'
            for relative, body in ((current, b'{"records":[2]}'), (previous, old_body)):
                path = root / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(body)
            bucket = hashlib.sha256(current.encode()).digest()[0] % 8
            output = Path(temporary) / 'revision.zip'
            package(root, output, category, bucket, 8, [current], old_sha, previous)
            with zipfile.ZipFile(output) as archive:
                entry = json.loads(archive.read('manifest.json'))['files'][0]
                self.assertEqual(entry['replaces_sha256'], old_sha)
                self.assertEqual(entry['previous_path'], previous)
            (root / previous).write_bytes(b'corrupted')
            with self.assertRaises(ValueError):
                package(root, Path(temporary) / 'bad.zip', category, bucket, 8,
                        [current], old_sha, previous)


if __name__ == '__main__':
    unittest.main()
