"""Protect the live 2011 West Bengal turnout revision while adding results."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_ac_2011_wb_live_rebased_results import (
    DISCREPANCY_CODES, EDITION, LIVE_SHA, NAME, revised_edition,
)


ROOT = Path(__file__).resolve().parents[1]


class WestBengalLiveRebaseTests(unittest.TestCase):
    def test_all_results_preserve_live_candidates_turnout_and_warnings(self):
        previous, revised, audit = revised_edition(ROOT)
        old, new = json.loads(previous), json.loads(revised)
        self.assertEqual(len(old['records']), 294)
        self.assertEqual(len(new['records']), 294)
        self.assertEqual(hashlib.sha256(previous).hexdigest(), LIVE_SHA)
        self.assertEqual(hashlib.sha256(revised).hexdigest(), audit['new_sha256'])
        self.assertEqual(old['source_url'], new['source_url'])
        self.assertEqual(old['source_sha256'], new['source_sha256'])
        for before, after in zip(old['records'], new['records']):
            self.assertEqual(before['code'], after['code'])
            self.assertEqual(before['candidates'], after['candidates'])
            self.assertEqual(before['votes_polled'], after['votes_polled'])
            self.assertEqual(before.get('original_extraction_warning'),
                             after.get('original_extraction_warning'))
            self.assertIsNotNone(after.get('summary_result'))
            if before['code'] in DISCREPANCY_CODES:
                self.assertTrue(after.get('source_discrepancy'))
                self.assertIn(before['error'], after['error'])
                self.assertIn('winner and margin', after['error'])

    def test_guarded_package_retains_exact_live_snapshot(self):
        path = ROOT / 'exports' / (NAME + '.zip')
        expected = hashlib.sha256(path.read_bytes()).hexdigest()
        self.assertEqual(path.with_suffix('.sha256').read_bytes(),
                         f'{expected}  {path.name}\n'.encode('ascii'))
        with zipfile.ZipFile(path) as bundle:
            audit = json.loads(bundle.read('AUDIT.json'))
            self.assertEqual(audit['previous_sha256'], LIVE_SHA)
            script = bundle.read('IMPORT.sh')
            for guard in (b'--allow-revision', b'check_disk', b'flock', b'archive:index-constituencies'):
                self.assertIn(guard, script)
            for line in bundle.read('SHA256SUMS').decode('ascii').splitlines():
                checksum, filename = line.split(None, 1)
                self.assertEqual(hashlib.sha256(bundle.read(filename)).hexdigest(), checksum)
            with zipfile.ZipFile(io.BytesIO(bundle.read(f'snapshot-{EDITION}.zip'))) as snapshot:
                old_body = snapshot.read(f'election-archive/{EDITION}/extraction-{LIVE_SHA}.json')
            with zipfile.ZipFile(io.BytesIO(bundle.read(f'correction-{EDITION}.zip'))) as correction:
                new_body = correction.read(f'election-archive/{EDITION}/extraction.json')
                manifest = json.loads(correction.read('manifest.json'))['files'][0]
        expected_old, expected_new, _ = revised_edition(ROOT)
        self.assertEqual(old_body, expected_old)
        self.assertEqual(new_body, expected_new)
        self.assertEqual(manifest['replaces_sha256'], LIVE_SHA)
        self.assertEqual(manifest['sha256'], audit['new_sha256'])


if __name__ == '__main__':
    unittest.main()
