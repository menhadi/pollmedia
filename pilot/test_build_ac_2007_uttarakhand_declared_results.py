"""Regression checks for the source-verified Uttarakhand correction package."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_ac_2007_uttarakhand_declared_results import EDITION, NAME, REVIEW_CODES, revised_edition


ROOT = Path(__file__).resolve().parents[1]


class Uttarakhand2007DeclaredResultsTests(unittest.TestCase):
    def test_original_candidate_evidence_survives(self):
        old_body, new_body, detail = revised_edition(ROOT)
        old = {row['code']: row for row in json.loads(old_body)['records']}
        new = {row['code']: row for row in json.loads(new_body)['records']}
        self.assertEqual(set(new), set(range(1, 71)))
        self.assertEqual({row['code'] for row in detail['results']}, REVIEW_CODES | {59})
        for code, before in old.items():
            after = new[code]
            self.assertEqual(before['candidates'], after['candidates'])
            self.assertEqual(before.get('detail_totals'), after.get('detail_totals'))
            if code not in REVIEW_CODES:
                self.assertEqual(before, after)
        self.assertEqual(new[59]['candidates'], [])
        self.assertEqual(new[59]['summary_page'], 75)
        self.assertEqual(new[59]['summary_source_sha256'], detail['source_sha256'])

    def test_package_preserves_prior_bytes_and_requires_revision_guard(self):
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
            detail = json.loads(outer.read('AUDIT.json'))
            old_sha = detail['previous_sha256']
            with zipfile.ZipFile(io.BytesIO(outer.read(f'snapshot-{EDITION}.zip'))) as inner:
                snapshot = inner.read(f'election-archive/{EDITION}/extraction-{old_sha}.json')
            with zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{EDITION}.zip'))) as inner:
                corrected = inner.read(f'election-archive/{EDITION}/extraction.json')
                manifest = json.loads(inner.read('manifest.json'))['files'][0]
        expected_snapshot, expected_corrected, _ = revised_edition(ROOT)
        self.assertEqual(snapshot, expected_snapshot)
        self.assertEqual(corrected, expected_corrected)
        self.assertEqual(manifest['replaces_sha256'], old_sha)
        self.assertEqual(manifest['sha256'], hashlib.sha256(corrected).hexdigest())


if __name__ == '__main__':
    unittest.main()
