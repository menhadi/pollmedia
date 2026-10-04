"""Check Champdani's rebase preserves the live Onda correction."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_ac_wb_1982_champdani_rebased_resume import EDITION, NAME, revised_edition


ROOT = Path(__file__).resolve().parents[1]


class ChampdaniResumeTests(unittest.TestCase):
    def test_only_champdani_changes_from_observed_live_bytes(self):
        old_body, new_body, audit = revised_edition(ROOT)
        old, new = json.loads(old_body), json.loads(new_body)
        self.assertEqual(len(old['records']), len(new['records']))
        for before, after in zip(old['records'], new['records']):
            self.assertEqual(before['code'], after['code'])
            if before['code'] != 181:
                self.assertEqual(before, after)
            else:
                self.assertEqual(before['candidates'], after['candidates'])
                self.assertEqual(before['votes_polled'], after['votes_polled'])
                self.assertEqual(after['summary_result']['margin'], 6619)
        self.assertEqual(audit['previous_sha256'], hashlib.sha256(old_body).hexdigest())
        self.assertEqual(audit['new_sha256'], hashlib.sha256(new_body).hexdigest())

    def test_guarded_bundle_stores_exact_observed_live_snapshot(self):
        path = ROOT / 'exports' / (NAME + '.zip')
        self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(),
                         path.with_suffix('.sha256').read_text(encoding='ascii').split()[0])
        with zipfile.ZipFile(path) as outer:
            script = outer.read('IMPORT.sh')
            for guard in (b'check_disk', b'--allow-revision', b'for file in snapshot-*.zip', b'flock'):
                self.assertIn(guard, script)
            for line in outer.read('SHA256SUMS').decode('ascii').splitlines():
                checksum, filename = line.split(None, 1)
                self.assertEqual(hashlib.sha256(outer.read(filename)).hexdigest(), checksum)
            audit = json.loads(outer.read('AUDIT.json'))
            with zipfile.ZipFile(io.BytesIO(outer.read(f'snapshot-{EDITION}.zip'))) as inner:
                old_body = inner.read(f'election-archive/{EDITION}/extraction-{audit["previous_sha256"]}.json')
            with zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{EDITION}.zip'))) as inner:
                new_body = inner.read(f'election-archive/{EDITION}/extraction.json')
                manifest = json.loads(inner.read('manifest.json'))['files'][0]
        expected_old, expected_new, _ = revised_edition(ROOT)
        self.assertEqual(old_body, expected_old)
        self.assertEqual(new_body, expected_new)
        self.assertEqual(manifest['replaces_sha256'], audit['previous_sha256'])
        self.assertEqual(manifest['sha256'], audit['new_sha256'])


if __name__ == '__main__':
    unittest.main()
