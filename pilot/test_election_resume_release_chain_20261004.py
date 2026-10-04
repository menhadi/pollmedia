"""Verify the selected deployment chain and preservation of source records."""

from collections import Counter
import hashlib
import io
import json
from pathlib import Path
import unittest
import zipfile


ROOT = Path(__file__).resolve().parents[1]
EXPORTS = ROOT / 'exports'
RESUME = 'pollmedia-election-corrections-20261003-resume-v1'
WAVES = ([f'pollmedia-election-corrections-20261003-wave{i}' for i in range(8, 11)]
         + [f'pollmedia-election-corrections-20261004-wave{i}-v2' for i in range(11, 14)]
         + [f'pollmedia-election-corrections-20261003-wave{i}' for i in range(14, 24)]
         + ['pollmedia-election-corrections-20261004-wave24-v3',
            'pollmedia-election-corrections-20261004-wave25-v2',
            'pollmedia-election-corrections-20261004-wave26',
            'pollmedia-election-corrections-20261004-wave27'])


def verified_release(name: str) -> zipfile.ZipFile:
    path = EXPORTS / (name + '.zip')
    expected = hashlib.sha256(path.read_bytes()).hexdigest()
    if path.with_suffix('.sha256').read_bytes() != f'{expected}  {path.name}\n'.encode('ascii'):
        raise ValueError('Outer checksum differs: ' + name)
    return zipfile.ZipFile(path)


class ElectionReleaseChainTests(unittest.TestCase):
    def assert_source_records_preserved(self, release: zipfile.ZipFile, edition: dict):
        key = edition['edition']
        with zipfile.ZipFile(io.BytesIO(release.read(f'snapshot-{key}.zip'))) as snapshot:
            original_paths = [path for path in snapshot.namelist()
                              if path.startswith(f'election-archive/{key}/') and path.endswith('.json')]
            self.assertEqual(len(original_paths), 1)
            original_bytes = snapshot.read(original_paths[0])
        with zipfile.ZipFile(io.BytesIO(release.read(f'correction-{key}.zip'))) as correction:
            revised_bytes = correction.read(f'election-archive/{key}/extraction.json')
        self.assertEqual(hashlib.sha256(original_bytes).hexdigest(), edition['previous_sha256'])
        self.assertEqual(hashlib.sha256(revised_bytes).hexdigest(), edition['new_sha256'])

        original = json.loads(original_bytes)
        revised = json.loads(revised_bytes)
        for field in ('kind', 'year', 'source_url', 'source_file', 'source_sha256', 'identity_scope'):
            self.assertEqual(original.get(field), revised.get(field), (key, field))
        original_records = {record['code']: record for record in original['records']}
        revised_records = {record['code']: record for record in revised['records']}
        self.assertLessEqual(original_records.keys(), revised_records.keys(), key)
        for code, before in original_records.items():
            after = revised_records[code]
            previous_candidates = Counter(json.dumps(candidate, sort_keys=True)
                                          for candidate in before.get('candidates', []))
            current_candidates = Counter(json.dumps(candidate, sort_keys=True)
                                         for candidate in after.get('candidates', []))
            self.assertFalse(previous_candidates - current_candidates, (key, code))
            if before.get('original_extraction_warning'):
                self.assertEqual(before['original_extraction_warning'],
                                 after.get('original_extraction_warning'), (key, code))
            if before.get('error') and before['error'] != after.get('error'):
                self.assertTrue(after.get('error'), (key, code))

    def test_resume_and_subsequent_waves_have_no_revision_conflicts(self):
        with verified_release(RESUME) as resume:
            self.assertIn(b'--allow-revision', resume.read('IMPORT.sh'))
            self.assertIn(b'check_disk', resume.read('IMPORT.sh'))
            resume_editions = json.loads(resume.read('AUDIT.json'))['editions']
            for edition in resume_editions:
                self.assert_source_records_preserved(resume, edition)
            revisions = {row['edition']: row['new_sha256'] for row in resume_editions}
            self.assertEqual(len(revisions), 31)
        for wave in WAVES:
            with verified_release(wave) as release:
                names = release.read('ORDER').decode('ascii').splitlines()
                self.assertEqual(len(names), len(set(names)))
                script = release.read('IMPORT_ALL.sh')
                for guard in (b'flock', b'check_disk', b'sha256sum -c', b'git -c safe.directory'):
                    self.assertIn(guard, script)
                if wave.endswith('wave25-v2'):
                    self.assertIn(b'ee2c43a', script)
                if wave.endswith('wave11-v2'):
                    self.assertIn(b'526e30e', script)
                if wave.endswith(('wave12-v2', 'wave13-v2', 'wave24-v3')):
                    self.assertIn(b'db633d8', script)
                if wave.endswith('wave26'):
                    self.assertIn(b'247bcc8', script)
                if wave.endswith('wave27'):
                    self.assertIn(b'9dfacf1', script)
                self.assertNotIn('pollmedia-ac-karnataka-1983-bagewadi-summary-result-20261004', names)
                self.assertNotIn('pollmedia-ac-west-bengal-1982-champdani-declared-result-20261004', names)
                for name in names:
                    body = release.read(name + '.zip')
                    expected = hashlib.sha256(body).hexdigest()
                    self.assertEqual(release.read(name + '.sha256'),
                                     f'{expected}  {name}.zip\n'.encode('ascii'))
                    with zipfile.ZipFile(io.BytesIO(body)) as bundle:
                        self.assertIn(b'--allow-revision', bundle.read('IMPORT.sh'))
                        self.assertIn(b'check_disk', bundle.read('IMPORT.sh'))
                        audit = json.loads(bundle.read('AUDIT.json'))
                        for edition in audit.get('editions') or [audit]:
                            self.assert_source_records_preserved(bundle, edition)
                    for edition in audit.get('editions') or [audit]:
                        key = edition['edition']
                        if key in revisions:
                            self.assertIn(edition['previous_sha256'],
                                          (revisions[key], edition['new_sha256']), (wave, name, key))
                        revisions[key] = edition['new_sha256']
        self.assertEqual(len(revisions), 84)


if __name__ == '__main__':
    unittest.main()
