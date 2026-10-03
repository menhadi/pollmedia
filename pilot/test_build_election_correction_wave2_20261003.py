"""Check the consolidated election import contains only ordered verified bundles."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_election_correction_wave2_20261003 import BUNDLES, NAME, REQUIRED_COMMIT


ROOT = Path(__file__).resolve().parents[1]


class ElectionCorrectionWaveTests(unittest.TestCase):
    def test_release_checksum_order_and_guarded_bundles(self):
        path = ROOT / 'exports' / (NAME + '.zip')
        sidecar = path.with_suffix('.sha256').read_bytes()
        self.assertNotIn(b'\r', sidecar)
        self.assertEqual(sidecar, (hashlib.sha256(path.read_bytes()).hexdigest() + '  ' + path.name + '\n').encode('ascii'))
        with zipfile.ZipFile(path) as release:
            self.assertIsNone(release.testzip())
            self.assertEqual(release.read('ORDER').decode('ascii').splitlines(), [name for name, _ in BUNDLES])
            script = release.read('IMPORT_ALL.sh')
            for required in (b'merge-base --is-ancestor ' + REQUIRED_COMMIT.encode(), b'flock -n 9',
                             b'10485760', b'sha256sum -c "$name.sha256"', b'bash "$workdir/IMPORT.sh"'):
                self.assertIn(required, script)
            self.assertEqual(set(release.namelist()), {'ORDER', 'IMPORT_ALL.sh', 'AUDIT.json'}
                             | {name + suffix for name, _ in BUNDLES for suffix in ('.zip', '.sha256')})
            audit = json.loads(release.read('AUDIT.json'))
            self.assertEqual(audit['required_code_commit'], REQUIRED_COMMIT)
            self.assertEqual([row['name'] for row in audit['bundles']], [name for name, _ in BUNDLES])
            editions = {}
            for name, expected_sha in BUNDLES:
                inner_body = release.read(name + '.zip')
                self.assertEqual(hashlib.sha256(inner_body).hexdigest(), expected_sha)
                self.assertEqual(release.read(name + '.sha256'),
                                 f'{expected_sha}  {name}.zip\n'.encode('ascii'))
                with zipfile.ZipFile(io.BytesIO(inner_body)) as bundle:
                    self.assertIsNone(bundle.testzip())
                    self.assertIn(b'check_disk', bundle.read('IMPORT.sh'))
                    self.assertIn(b'--allow-revision', bundle.read('IMPORT.sh'))
                    info = json.loads(bundle.read('AUDIT.json'))
                    for row in (info.get('editions') or [info]):
                        key = row['edition']
                        if key in editions:
                            self.assertEqual(editions[key], row['previous_sha256'])
                        editions[key] = row['new_sha256']
            self.assertEqual(len(editions), 4)


if __name__ == '__main__':
    unittest.main()
