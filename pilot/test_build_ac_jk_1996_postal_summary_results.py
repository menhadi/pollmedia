"""Verify the printed postal components, preserved rows and guarded revision."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_ac_jk_1996_postal_summary_results import EDITION, EXPECTED, NAME, revised_edition


ROOT = Path(__file__).resolve().parents[1]


class JammuKashmir1996PostalTests(unittest.TestCase):
    def test_three_results_reconcile_general_and_postal_votes(self):
        old_body, new_body, audit = revised_edition(ROOT)
        old, new = json.loads(old_body), json.loads(new_body)
        self.assertEqual({row['code'] for row in audit['corrected']}, set(EXPECTED))
        for before, after in zip(old['records'], new['records']):
            self.assertEqual(before['code'], after['code'])
            self.assertEqual(before['candidates'], after['candidates'])
            self.assertEqual(before['valid_candidate_votes'], after['valid_candidate_votes'])
            if before['code'] not in EXPECTED:
                self.assertEqual(before, after)
                continue
            totals = after['summary_totals']
            self.assertEqual(after['original_extraction_warning'], before['error'])
            self.assertEqual(after['original_detail_totals']['votes_polled'], before['votes_polled'])
            self.assertEqual(after['votes_polled'], before['votes_polled'] + totals['postal_votes'])
            self.assertEqual(after['valid_candidate_votes'],
                             totals['valid_candidate_votes'] + totals['postal_votes'])
            self.assertEqual(after['votes_polled'], after['valid_candidate_votes'] + totals['rejected_votes'])
            self.assertEqual(after['summary_result']['margin'],
                             after['summary_result']['winner_votes'] - after['summary_result']['runner_votes'])
        self.assertEqual(audit['new_sha256'], hashlib.sha256(new_body).hexdigest())

    def test_bundle_keeps_prior_bytes_and_rejects_conflicting_revision(self):
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
