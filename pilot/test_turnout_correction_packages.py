"""Verify prepared election revisions preserve all existing source data."""

import hashlib
import io
import json
from pathlib import Path
import unittest
import zipfile

from build_pc_ac_zero_turnout_bundle import current_body


ROOT = Path(__file__).resolve().parents[1]
NAMES = ('pollmedia-bihar-2005-turnout-correction-20261001',
         'pollmedia-pc-ac-zero-turnout-corrections-20261001-v3',
         'pollmedia-arunachal-2014-turnout-correction-20261001',
         'pollmedia-gujarat-2012-turnout-correction-20261001')
ALLOWED = {'electors', 'votes_polled', 'error', 'original_extraction_warning',
           'source_warning_code', 'source_discrepancy', 'summary_totals',
           'summary_page', 'summary_source_file', 'summary_source_sha256',
           'summary_ocr_file', 'summary_ocr_sha256', 'turnout_totals',
           'turnout_source_page', 'turnout_source_file', 'turnout_source_sha256',
           'turnout_ocr_file', 'turnout_ocr_sha256'}


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


class TurnoutCorrectionPackageTest(unittest.TestCase):
    def test_outer_inner_hashes_and_unchanged_existing_fields(self):
        seen = set()
        recovered = 0
        for name in NAMES:
            outer_path = ROOT / 'exports' / (name + '.zip')
            expected = outer_path.with_suffix('.sha256').read_text(encoding='ascii').split()[0]
            self.assertEqual(digest(outer_path.read_bytes()), expected)
            with zipfile.ZipFile(outer_path) as outer:
                self.assertIsNone(outer.testzip())
                members = outer.namelist()
                checks = outer.read('SHA256SUMS').decode('ascii').splitlines()
                for line in checks:
                    checksum, member = line.split()
                    self.assertEqual(digest(outer.read(member)), checksum)
                editions = outer.read('ARCHIVES').decode('ascii').splitlines()
                self.assertEqual(len(editions) * 2, len(checks))
                for edition in editions:
                    self.assertNotIn(edition, seen)
                    seen.add(edition)
                    prior_path = ROOT / 'application/storage/app/private/election-archive' / edition / 'extraction.json'
                    prior = current_body(ROOT, edition) if name.endswith('-v3') else prior_path.read_bytes()
                    snap = zipfile.ZipFile(io.BytesIO(outer.read(f'snapshot-{edition}.zip')))
                    self.assertEqual(len([member for member in snap.namelist() if member.endswith('.json')
                                          and member != 'manifest.json']), 1)
                    snapshot_name = next(member for member in snap.namelist() if 'extraction-' in member)
                    self.assertEqual(snap.read(snapshot_name), prior)
                    inner = zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{edition}.zip')))
                    manifest = json.loads(inner.read('manifest.json'))['files'][0]
                    self.assertEqual(manifest['replaces_sha256'], digest(prior))
                    self.assertEqual(manifest['previous_path'], snapshot_name)
                    revised = inner.read(manifest['path'])
                    self.assertEqual(digest(revised), manifest['sha256'])
                    before, after = json.loads(prior), json.loads(revised)
                    self.assertEqual(len(before['records']), len(after['records']))
                    for old, new in zip(before['records'], after['records']):
                        self.assertEqual({key: value for key, value in old.items() if key not in ALLOWED},
                                         {key: value for key, value in new.items() if key not in ALLOWED})
                        if old != new:
                            self.assertIn(old.get('votes_polled'), (None, 0))
                            self.assertIsInstance(new['votes_polled'], int)
                            self.assertGreater(new['votes_polled'], 0)
                            if old.get('electors') is not None:
                                self.assertEqual(old['electors'], new['electors'])
                            recovered += 1
        self.assertEqual(len(seen), 33)
        self.assertEqual(recovered, 1034)


if __name__ == '__main__':
    unittest.main()
