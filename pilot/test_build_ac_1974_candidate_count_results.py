"""Verify every declared result and checksum guard in the 1974 AC correction."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_ac_1974_candidate_count_results import EDITION, NAME, revised_edition


ROOT = Path(__file__).resolve().parents[1]


class Up1974CandidateCountResultsTests(unittest.TestCase):
    def test_only_verified_result_and_note_change(self):
        old_body, new_body, detail = revised_edition(ROOT)
        old = json.loads(old_body)
        new = json.loads(new_body)
        codes = set(detail['codes'])
        self.assertEqual(len(codes), 32)
        for before, after in zip(old['records'], new['records']):
            for key in ('candidates', 'electors', 'votes_polled', 'valid_candidate_votes', 'summary_totals',
                        'status', 'number_of_seats', 'original_extraction_warning', 'summary_page', 'detail_page'):
                self.assertEqual(before.get(key), after.get(key), (before['code'], key))
            if before['code'] in codes:
                result = after['summary_result']
                self.assertEqual(result['margin'], result['winner_votes'] - result['runner_votes'])
                self.assertGreater(result['margin'], 0)
        self.assertEqual(detail['new_sha256'], hashlib.sha256(new_body).hexdigest())

    def test_bundle_preserves_prior_extraction_and_rejects_conflicts(self):
        path = ROOT / 'exports' / (NAME + '.zip')
        self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(),
                         path.with_suffix('.sha256').read_text(encoding='ascii').split()[0])
        self.assertNotIn(b'\r', path.with_suffix('.sha256').read_bytes())
        with zipfile.ZipFile(path) as outer:
            self.assertIn(b'check_disk', outer.read('IMPORT.sh'))
            self.assertIn(b'--allow-revision', outer.read('IMPORT.sh'))
            for line in outer.read('SHA256SUMS').decode('ascii').splitlines():
                checksum, filename = line.split(None, 1)
                self.assertEqual(hashlib.sha256(outer.read(filename)).hexdigest(), checksum)
            detail = json.loads(outer.read('AUDIT.json'))
            old_sha = detail['previous_sha256']
            with zipfile.ZipFile(io.BytesIO(outer.read(f'snapshot-{EDITION}.zip'))) as inner:
                old_body = inner.read(f'election-archive/{EDITION}/extraction-{old_sha}.json')
            with zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{EDITION}.zip'))) as inner:
                new_body = inner.read(f'election-archive/{EDITION}/extraction.json')
                manifest = json.loads(inner.read('manifest.json'))['files'][0]
        expected_old, expected_new, _ = revised_edition(ROOT)
        self.assertEqual(old_body, expected_old)
        self.assertEqual(new_body, expected_new)
        self.assertEqual(manifest['replaces_sha256'], old_sha)
        self.assertEqual(hashlib.sha256(new_body).hexdigest(), manifest['sha256'])


if __name__ == '__main__':
    unittest.main()
