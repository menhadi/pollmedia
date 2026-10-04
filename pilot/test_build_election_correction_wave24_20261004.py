"""Verify the guarded 26-bundle release and its revision order."""

import hashlib
import json
from pathlib import Path
import sys
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
import build_election_correction_wave24_20261004 as wave


ROOT = Path(__file__).resolve().parents[1]


class ElectionWave24Tests(unittest.TestCase):
    def test_release_hashes_order_and_guarded_import(self):
        path = ROOT / 'exports' / (wave.release.NAME + '.zip')
        expected = path.with_suffix('.sha256').read_text(encoding='ascii').split()[0]
        self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(), expected)
        self.assertNotIn(b'\r', path.with_suffix('.sha256').read_bytes())
        names = [name for name, _ in wave.release.BUNDLES]
        self.assertEqual(len(names), 26)
        self.assertEqual(len(names), len(set(names)))
        self.assertFalse(any('gujarat-2012' in name and not name.endswith('-v5') for name in names))
        with zipfile.ZipFile(path) as outer:
            self.assertEqual(outer.read('ORDER').decode('ascii').splitlines(), names)
            script = outer.read('IMPORT_ALL.sh')
            for guard in (b'flock', b'check_disk', b'sha256sum -c', b'git -c safe.directory',
                          wave.release.REQUIRED_COMMIT.encode('ascii')):
                self.assertIn(guard, script)
            audit = json.loads(outer.read('AUDIT.json'))
            self.assertEqual(audit['required_code_commit'], wave.release.REQUIRED_COMMIT)
            self.assertEqual([item['name'] for item in audit['bundles']], names)
            editions = {}
            for name, checksum in wave.release.BUNDLES:
                body = outer.read(name + '.zip')
                self.assertEqual(hashlib.sha256(body).hexdigest(), checksum)
                self.assertEqual(outer.read(name + '.sha256'),
                                 f'{checksum}  {name}.zip\n'.encode('ascii'))
                with zipfile.ZipFile(ROOT / 'exports' / (name + '.zip')) as inner:
                    self.assertIn(b'--allow-revision', inner.read('IMPORT.sh'))
                    self.assertIn(b'check_disk', inner.read('IMPORT.sh'))
                    package_audit = json.loads(inner.read('AUDIT.json'))
                for edition in package_audit.get('editions') or [package_audit]:
                    key = edition['edition']
                    if key in editions:
                        self.assertEqual(editions[key], edition['previous_sha256'])
                    editions[key] = edition['new_sha256']
            self.assertEqual(len(editions), 26)


if __name__ == '__main__':
    unittest.main()
