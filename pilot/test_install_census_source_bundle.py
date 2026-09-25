import hashlib
import json
from pathlib import Path
import tempfile
import unittest
import zipfile

from install_census_source_bundle import PREFIX, install, verify


class CensusBundleInstallTest(unittest.TestCase):
    def make_bundle(self, directory, corrupt=False, unsafe=False):
        identity = '1991-123-' + 'a' * 16
        files = {
            PREFIX + 'index.json': json.dumps({'sources': [{'id': identity}], 'pending': []}).encode(),
            PREFIX + identity + '/manifest.json': b'{}',
            PREFIX + identity + '/pages.jsonl': b'{"source_row":1}\n',
        }
        if unsafe:
            files[PREFIX + '../escape.txt'] = b'bad'
        checksums = {name: hashlib.sha256(body).hexdigest() for name, body in files.items()}
        if corrupt:
            checksums[PREFIX + identity + '/pages.jsonl'] = '0' * 64
        bundle = Path(directory) / 'bundle.zip'
        with zipfile.ZipFile(bundle, 'w') as archive:
            archive.writestr('manifest.json', json.dumps({'workbooks': 1, 'pending': 0, 'files': checksums}))
            for name, body in files.items():
                archive.writestr(name, body)
        return bundle, hashlib.sha256(bundle.read_bytes()).hexdigest()

    def test_verifies_and_installs_without_replacing(self):
        with tempfile.TemporaryDirectory() as directory:
            bundle, sha256 = self.make_bundle(directory)
            self.assertEqual(verify(bundle, sha256), {'workbooks': 1, 'pending': 0, 'files': 3})
            private = Path(directory) / 'private'
            private.mkdir()
            destination = private / 'census-source-tables'
            install(bundle, destination)
            self.assertTrue((destination / 'index.json').exists())
            with self.assertRaises(FileExistsError):
                install(bundle, destination)

    def test_rejects_member_hash_mismatch_and_traversal(self):
        with tempfile.TemporaryDirectory() as directory:
            bundle, sha256 = self.make_bundle(directory, corrupt=True)
            with self.assertRaises(ValueError):
                verify(bundle, sha256)
        with tempfile.TemporaryDirectory() as directory:
            bundle, sha256 = self.make_bundle(directory, unsafe=True)
            with self.assertRaises(ValueError):
                verify(bundle, sha256)


if __name__ == '__main__':
    unittest.main()
