"""Verify Champdani's source declaration and guarded revision chain."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_ac_wb_1982_champdani_declared_result import EDITION, NAME, PRIOR_PACKAGE, revised_edition


ROOT = Path(__file__).resolve().parents[1]


class Champdani1982ResultTests(unittest.TestCase):
    def test_declared_result_preserves_invalid_turnout_and_candidate_rows(self):
        old_body, new_body, audit = revised_edition(ROOT)
        old, new = json.loads(old_body), json.loads(new_body)
        for before, after in zip(old['records'], new['records']):
            self.assertEqual(before['code'], after['code'])
            for key in ('candidates', 'electors', 'votes_polled', 'valid_candidate_votes',
                        'status', 'number_of_seats', 'detail_page'):
                self.assertEqual(before.get(key), after.get(key), (before['code'], key))
            if before['code'] != 181:
                self.assertEqual(before, after)
        fixed = next(record for record in new['records'] if record['code'] == 181)
        self.assertGreater(fixed['votes_polled'], fixed['electors'])
        self.assertEqual(fixed['summary_result']['margin'], 6619)
        self.assertEqual(fixed['original_extraction_warning'],
                         next(record for record in old['records'] if record['code'] == 181)['error'])
        self.assertEqual(audit['prior_package'], PRIOR_PACKAGE)
        self.assertEqual(audit['new_sha256'], hashlib.sha256(new_body).hexdigest())

    def test_package_preserves_prior_bytes_and_rejects_conflicting_live_revision(self):
        path = ROOT / 'exports' / (NAME + '.zip')
        self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(),
                         path.with_suffix('.sha256').read_text(encoding='ascii').split()[0])
        self.assertNotIn(b'\r', path.with_suffix('.sha256').read_bytes())
        with zipfile.ZipFile(path) as outer:
            script = outer.read('IMPORT.sh')
            self.assertIn(b'check_disk', script)
            self.assertIn(b'--allow-revision', script)
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
