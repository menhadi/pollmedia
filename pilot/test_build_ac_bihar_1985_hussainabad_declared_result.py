"""Verify the Hussainabad declaration and its ordered archive revision."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_ac_bihar_1985_hussainabad_declared_result import EDITION, NAME, revised_edition


ROOT = Path(__file__).resolve().parents[1]


class Hussainabad1985ResultTests(unittest.TestCase):
    def test_declaration_preserves_prior_candidate_and_turnout_values(self):
        old_body, new_body, audit = revised_edition(ROOT)
        old, new = json.loads(old_body), json.loads(new_body)
        for before, after in zip(old['records'], new['records']):
            self.assertEqual(before['code'], after['code'])
            for key in ('candidates', 'electors', 'votes_polled', 'summary_totals',
                        'original_extraction_warning', 'source_warning_code', 'status'):
                self.assertEqual(before.get(key), after.get(key), (before['code'], key))
            if before['code'] != 324:
                self.assertEqual(before, after)
        record = next(row for row in new['records'] if row['code'] == 324)
        self.assertEqual(record['previous_review_note'],
                         next(row for row in old['records'] if row['code'] == 324)['error'])
        self.assertEqual(record['summary_result']['margin'], 2326)
        self.assertEqual(audit['new_sha256'], hashlib.sha256(new_body).hexdigest())

    def test_bundle_preserves_exact_prior_bytes_and_requires_revision(self):
        path = ROOT / 'exports' / (NAME + '.zip')
        self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(),
                         path.with_suffix('.sha256').read_text(encoding='ascii').split()[0])
        self.assertNotIn(b'\r', path.with_suffix('.sha256').read_bytes())
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
