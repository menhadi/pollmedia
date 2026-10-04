"""Verify the deployment chain from observed live bytes through waves 8-25."""

import hashlib
import io
import json
from pathlib import Path
import unittest
import zipfile


ROOT = Path(__file__).resolve().parents[1]
EXPORTS = ROOT / 'exports'
RESUME = 'pollmedia-election-corrections-20261003-resume-v1'
WAVES = ([f'pollmedia-election-corrections-20261003-wave{i}' for i in range(8, 24)]
         + ['pollmedia-election-corrections-20261004-wave24-v2',
            'pollmedia-election-corrections-20261004-wave25-v2',
            'pollmedia-election-corrections-20261004-wave26'])


def verified_release(name: str) -> zipfile.ZipFile:
    path = EXPORTS / (name + '.zip')
    expected = path.with_suffix('.sha256').read_text(encoding='ascii').split()[0]
    if hashlib.sha256(path.read_bytes()).hexdigest() != expected:
        raise ValueError('Outer checksum differs: ' + name)
    return zipfile.ZipFile(path)


class ElectionReleaseChainTests(unittest.TestCase):
    def test_resume_and_subsequent_waves_have_no_revision_conflicts(self):
        with verified_release(RESUME) as resume:
            self.assertIn(b'--allow-revision', resume.read('IMPORT.sh'))
            self.assertIn(b'check_disk', resume.read('IMPORT.sh'))
            revisions = {row['edition']: row['new_sha256']
                         for row in json.loads(resume.read('AUDIT.json'))['editions']}
            self.assertEqual(len(revisions), 31)
        for wave in WAVES:
            with verified_release(wave) as release:
                names = release.read('ORDER').decode('ascii').splitlines()
                self.assertEqual(len(names), len(set(names)))
                script = release.read('IMPORT_ALL.sh')
                for guard in (b'flock', b'check_disk', b'sha256sum -c', b'git -c safe.directory'):
                    self.assertIn(guard, script)
                if wave.endswith(('wave24-v2', 'wave25-v2')):
                    self.assertIn(b'ee2c43a', script)
                if wave.endswith('wave26'):
                    self.assertIn(b'247bcc8', script)
                self.assertNotIn('pollmedia-ac-karnataka-1983-bagewadi-summary-result-20261004', names)
                self.assertNotIn('pollmedia-ac-west-bengal-1982-champdani-declared-result-20261004', names)
                for name in names:
                    body = release.read(name + '.zip')
                    expected = release.read(name + '.sha256').decode('ascii').split()[0]
                    self.assertEqual(hashlib.sha256(body).hexdigest(), expected)
                    with zipfile.ZipFile(io.BytesIO(body)) as bundle:
                        self.assertIn(b'--allow-revision', bundle.read('IMPORT.sh'))
                        self.assertIn(b'check_disk', bundle.read('IMPORT.sh'))
                        audit = json.loads(bundle.read('AUDIT.json'))
                    for edition in audit.get('editions') or [audit]:
                        key = edition['edition']
                        if key in revisions:
                            self.assertIn(edition['previous_sha256'],
                                          (revisions[key], edition['new_sha256']), (wave, name, key))
                        revisions[key] = edition['new_sha256']
        self.assertEqual(len(revisions), 83)


if __name__ == '__main__':
    unittest.main()
