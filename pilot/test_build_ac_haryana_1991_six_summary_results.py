"""Verify six Haryana declarations and guarded source revision."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_ac_haryana_1991_six_summary_results import EDITION, EXPECTED, NAME, PRIOR_PACKAGE, revised_edition


ROOT = Path(__file__).resolve().parents[1]


class Haryana1991ResultsTests(unittest.TestCase):
    def test_six_results_keep_candidate_rows_and_original_warnings(self):
        old_body, new_body, audit = revised_edition(ROOT)
        old, new = json.loads(old_body), json.loads(new_body)
        self.assertEqual({row['code'] for row in audit['results']}, set(EXPECTED))
        for before, after in zip(old['records'], new['records']):
            self.assertEqual(before['code'], after['code'])
            for key in ('candidates', 'electors', 'votes_polled', 'valid_candidate_votes',
                        'status', 'number_of_seats', 'detail_page'):
                self.assertEqual(before.get(key), after.get(key), (before['code'], key))
            if before['code'] in EXPECTED:
                result = after['summary_result']
                self.assertEqual(result['margin'], result['winner_votes'] - result['runner_votes'])
                self.assertEqual(after['summary_totals']['votes_polled'], after['votes_polled'])
                self.assertEqual(after['previous_review_note'], before['error'])
            else:
                self.assertEqual(before, after)
        self.assertEqual(audit['prior_package'], PRIOR_PACKAGE)
        self.assertEqual(audit['new_sha256'], hashlib.sha256(new_body).hexdigest())

    def test_bundle_preserves_previous_revision_and_rejects_conflicts(self):
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
