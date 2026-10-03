"""Check that only the source-backed 1969 declaration changes."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_ac_1969_chhibramau_declared_result import CODE, EDITION, NAME, OLD_SHA256, revised_edition


ROOT = Path(__file__).resolve().parents[1]


class Chhibramau1969DeclaredResultTests(unittest.TestCase):
    def test_official_result_does_not_change_candidate_or_turnout_evidence(self):
        old_body, new_body, detail = revised_edition(ROOT)
        old = json.loads(old_body)
        new = json.loads(new_body)
        before = next(row for row in old['records'] if row['code'] == CODE)
        after = next(row for row in new['records'] if row['code'] == CODE)
        for key in ('candidates', 'electors', 'votes_polled', 'valid_candidate_votes', 'summary_totals',
                    'summary_page', 'detail_page', 'status', 'number_of_seats'):
            self.assertEqual(before[key], after[key], key)
        self.assertEqual(after['original_extraction_warning'], before['error'])
        self.assertEqual(after['summary_result']['margin'], 4205)
        self.assertGreater(after['votes_polled'], after['electors'])
        self.assertEqual(detail['new_sha256'], hashlib.sha256(new_body).hexdigest())

    def test_guarded_bundle_retains_exact_prior_bytes(self):
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
            with zipfile.ZipFile(io.BytesIO(outer.read(f'snapshot-{EDITION}.zip'))) as inner:
                old_body = inner.read(f'election-archive/{EDITION}/extraction-{OLD_SHA256}.json')
            with zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{EDITION}.zip'))) as inner:
                new_body = inner.read(f'election-archive/{EDITION}/extraction.json')
                manifest = json.loads(inner.read('manifest.json'))['files'][0]
        self.assertEqual(old_body, (ROOT / 'application/storage/app/private/election-archive' / EDITION / 'extraction.json').read_bytes())
        self.assertEqual(manifest['replaces_sha256'], OLD_SHA256)
        self.assertEqual(hashlib.sha256(new_body).hexdigest(), manifest['sha256'])


if __name__ == '__main__':
    unittest.main()
