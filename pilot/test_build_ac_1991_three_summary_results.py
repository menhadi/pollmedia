"""Verify three 1991 declarations and both guarded archive revisions."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_ac_1991_three_summary_results import NAME, SOURCES, revised_editions


ROOT = Path(__file__).resolve().parents[1]


class Three1991ResultsTests(unittest.TestCase):
    def test_declared_results_preserve_candidate_rows_and_prior_warnings(self):
        editions = revised_editions(ROOT)
        self.assertEqual({row[0] for row in editions}, set(SOURCES))
        self.assertEqual(sum(len(row[3]['results']) for row in editions), 3)
        for edition, old_body, new_body, audit in editions:
            old, new = json.loads(old_body), json.loads(new_body)
            for before, after in zip(old['records'], new['records']):
                self.assertEqual(before['code'], after['code'])
                for key in ('candidates', 'electors', 'votes_polled', 'valid_candidate_votes',
                            'status', 'number_of_seats', 'original_extraction_warning', 'detail_page'):
                    self.assertEqual(before.get(key), after.get(key), (edition, before['code'], key))
                if before['code'] in SOURCES[edition]['records']:
                    result = after['summary_result']
                    self.assertEqual(result['margin'], result['winner_votes'] - result['runner_votes'])
                    self.assertEqual(after['previous_review_note'], before['error'])
                else:
                    self.assertEqual(before, after)
            self.assertEqual(audit['new_sha256'], hashlib.sha256(new_body).hexdigest())

    def test_bundle_preserves_prior_bytes_and_rejects_conflicts(self):
        path = ROOT / 'exports' / (NAME + '.zip')
        self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(),
                         path.with_suffix('.sha256').read_text(encoding='ascii').split()[0])
        self.assertNotIn(b'\r', path.with_suffix('.sha256').read_bytes())
        expected = {row[0]: row for row in revised_editions(ROOT)}
        with zipfile.ZipFile(path) as outer:
            script = outer.read('IMPORT.sh')
            self.assertIn(b'check_disk', script)
            self.assertIn(b'--allow-revision', script)
            for line in outer.read('SHA256SUMS').decode('ascii').splitlines():
                checksum, filename = line.split(None, 1)
                self.assertEqual(hashlib.sha256(outer.read(filename)).hexdigest(), checksum)
            for audit in json.loads(outer.read('AUDIT.json'))['editions']:
                edition = audit['edition']
                with zipfile.ZipFile(io.BytesIO(outer.read(f'snapshot-{edition}.zip'))) as inner:
                    old_body = inner.read(f'election-archive/{edition}/extraction-{audit["previous_sha256"]}.json')
                with zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{edition}.zip'))) as inner:
                    new_body = inner.read(f'election-archive/{edition}/extraction.json')
                    manifest = json.loads(inner.read('manifest.json'))['files'][0]
                self.assertEqual(old_body, expected[edition][1])
                self.assertEqual(new_body, expected[edition][2])
                self.assertEqual(manifest['replaces_sha256'], audit['previous_sha256'])
                self.assertEqual(manifest['sha256'], audit['new_sha256'])


if __name__ == '__main__':
    unittest.main()
