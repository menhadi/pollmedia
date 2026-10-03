"""Verify three official results without replacing their detailed candidate evidence."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_ac_three_reviewed_summary_results_20261003 import NAME, TARGETS, revised_edition


ROOT = Path(__file__).resolve().parents[1]


class ThreeReviewedSummaryResultTests(unittest.TestCase):
    def test_printed_summary_results_and_discrepancies(self):
        for edition, (code, year, previous, difference) in TARGETS.items():
            with self.subTest(edition=edition):
                old_body, new_body, audit = revised_edition(ROOT, edition, code, year, previous, difference)
                old = json.loads(old_body)
                revised = json.loads(new_body)
                original = next(row for row in old['records'] if row['code'] == code)
                record = next(row for row in revised['records'] if row['code'] == code)
                self.assertEqual(original['candidates'], record['candidates'])
                self.assertEqual(original['error'], record['original_extraction_warning'])
                self.assertEqual(sum(candidate['votes'] for candidate in original['candidates'])
                                 - record['summary_totals']['valid_candidate_votes'], difference)
                self.assertEqual(record['summary_result']['winner'], original['candidates'][0]['candidate_name'])
                self.assertEqual(record['summary_result']['margin'],
                                 original['candidates'][0]['votes'] - original['candidates'][1]['votes'])
                self.assertEqual(audit['turnout_discrepancy'], edition == '1cc8415ab4d57b66831417e8')

    def test_bundle_checksums_snapshots_and_import_guards(self):
        path = ROOT / 'exports' / (NAME + '.zip')
        self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(),
                         path.with_suffix('.sha256').read_text(encoding='ascii').split()[0])
        with zipfile.ZipFile(path) as outer:
            audit = json.loads(outer.read('AUDIT.json'))
            self.assertEqual({item['edition'] for item in audit['editions']}, set(TARGETS))
            script = outer.read('IMPORT.sh')
            self.assertIn(b'check_disk', script)
            self.assertIn(b'--allow-revision', script)
            for line in outer.read('SHA256SUMS').decode('ascii').splitlines():
                checksum, filename = line.split(None, 1)
                self.assertEqual(hashlib.sha256(outer.read(filename)).hexdigest(), checksum)
            for edition, (_, _, previous, _) in TARGETS.items():
                with zipfile.ZipFile(io.BytesIO(outer.read(f'snapshot-{edition}.zip'))) as inner:
                    original = inner.read(f'election-archive/{edition}/extraction-{previous}.json')
                with zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{edition}.zip'))) as inner:
                    revised = inner.read(f'election-archive/{edition}/extraction.json')
                    manifest = json.loads(inner.read('manifest.json'))['files'][0]
                self.assertEqual(original, (ROOT / 'application/storage/app/private/election-archive' / edition / 'extraction.json').read_bytes())
                self.assertEqual(manifest['replaces_sha256'], previous)
                self.assertEqual(hashlib.sha256(revised).hexdigest(), manifest['sha256'])


if __name__ == '__main__':
    unittest.main()
