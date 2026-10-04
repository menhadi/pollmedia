"""Check Modakurichi's printed result and the guarded archive revision."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_ac_tn_1996_modakurichi_summary_result import EDITION, NAME, revised_edition


ROOT = Path(__file__).resolve().parents[1]


class Modakurichi1996SummaryTests(unittest.TestCase):
    def test_summary_result_preserves_detailed_evidence_and_discrepancy(self):
        old_body, new_body, audit = revised_edition(ROOT)
        old, new = json.loads(old_body), json.loads(new_body)
        self.assertEqual(len(old['records']), len(new['records']))
        for before, after in zip(old['records'], new['records']):
            self.assertEqual(before['code'], after['code'])
            for key in ('candidates', 'electors', 'votes_polled', 'valid_candidate_votes',
                        'status', 'number_of_seats', 'detail_page'):
                self.assertEqual(before.get(key), after.get(key), (before['code'], key))
            if before['code'] != 118:
                self.assertEqual(before, after)
        record = next(row for row in new['records'] if row['code'] == 118)
        self.assertEqual(len(record['candidates']), 1033)
        self.assertEqual(record['original_extraction_warning'],
                         next(row for row in old['records'] if row['code'] == 118)['error'])
        self.assertEqual(record['candidate_source_discrepancy'],
                         {'candidate_sum': 117215, 'printed_valid_votes': 117210, 'difference': 5})
        self.assertEqual(record['summary_result']['margin'], 39540)
        self.assertEqual(audit['new_sha256'], hashlib.sha256(new_body).hexdigest())

    def test_bundle_keeps_prior_bytes_and_rejects_conflicting_revisions(self):
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
            bundle_audit = json.loads(outer.read('AUDIT.json'))
            with zipfile.ZipFile(io.BytesIO(outer.read(f'snapshot-{EDITION}.zip'))) as inner:
                old_body = inner.read(f'election-archive/{EDITION}/extraction-{bundle_audit["previous_sha256"]}.json')
            with zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{EDITION}.zip'))) as inner:
                new_body = inner.read(f'election-archive/{EDITION}/extraction.json')
                manifest = json.loads(inner.read('manifest.json'))['files'][0]
        expected_old, expected_new, _ = revised_edition(ROOT)
        self.assertEqual(old_body, expected_old)
        self.assertEqual(new_body, expected_new)
        self.assertEqual(manifest['replaces_sha256'], bundle_audit['previous_sha256'])
        self.assertEqual(manifest['sha256'], bundle_audit['new_sha256'])


if __name__ == '__main__':
    unittest.main()
