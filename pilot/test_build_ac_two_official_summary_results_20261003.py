"""Check the two official AC summaries and immutable archived evidence."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_ac_two_official_summary_results_20261003 import NAME, TARGETS, revised_edition


ROOT = Path(__file__).resolve().parents[1]


class TwoOfficialAcSummaryTests(unittest.TestCase):
    def test_both_official_results_match_detailed_winner_rows(self):
        for edition, (code, year, previous) in TARGETS.items():
            with self.subTest(edition=edition):
                old_body, new_body, detail = revised_edition(ROOT, edition, code, year, previous)
                old = json.loads(old_body)
                revised = json.loads(new_body)
                before = next(row for row in old['records'] if row['code'] == code)
                after = next(row for row in revised['records'] if row['code'] == code)
                self.assertEqual(before['candidates'], after['candidates'])
                self.assertEqual(after['summary_totals']['votes_polled'], before['votes_polled'])
                self.assertEqual(after['summary_totals']['valid_candidate_votes'], sum(c['votes'] for c in before['candidates']))
                self.assertEqual(after['summary_result']['winner'], before['candidates'][0]['candidate_name'])
                self.assertEqual(after['summary_result']['margin'],
                                 before['candidates'][0]['votes'] - before['candidates'][1]['votes'])
                self.assertEqual(detail['new_sha256'], hashlib.sha256(new_body).hexdigest())

    def test_bundle_has_prior_bytes_checksums_and_guarded_import(self):
        path = ROOT / 'exports' / (NAME + '.zip')
        self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(),
                         path.with_suffix('.sha256').read_text(encoding='ascii').split()[0])
        with zipfile.ZipFile(path) as outer:
            audit = json.loads(outer.read('AUDIT.json'))
            self.assertEqual({item['edition'] for item in audit['editions']}, set(TARGETS))
            self.assertIn(b'check_disk', outer.read('IMPORT.sh'))
            self.assertIn(b'--allow-revision', outer.read('IMPORT.sh'))
            for line in outer.read('SHA256SUMS').decode('ascii').splitlines():
                checksum, filename = line.split(None, 1)
                self.assertEqual(hashlib.sha256(outer.read(filename)).hexdigest(), checksum)
            for edition, (_, _, previous) in TARGETS.items():
                with zipfile.ZipFile(io.BytesIO(outer.read(f'snapshot-{edition}.zip'))) as inner:
                    prior = inner.read(f'election-archive/{edition}/extraction-{previous}.json')
                with zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{edition}.zip'))) as inner:
                    revision = inner.read(f'election-archive/{edition}/extraction.json')
                    manifest = json.loads(inner.read('manifest.json'))['files'][0]
                self.assertEqual(prior, (ROOT / 'application/storage/app/private/election-archive' / edition / 'extraction.json').read_bytes())
                self.assertEqual(manifest['replaces_sha256'], previous)
                self.assertEqual(hashlib.sha256(revision).hexdigest(), manifest['sha256'])


if __name__ == '__main__':
    unittest.main()
