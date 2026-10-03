"""Check the Onda 1982 official source choice and immutable detail evidence."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_ac_wb_1982_onda_summary_result import CODE, EDITION, NAME, OLD_SHA256, revised_edition


ROOT = Path(__file__).resolve().parents[1]


class Onda1982SummaryResultTests(unittest.TestCase):
    def test_official_result_preserves_detail_rows_and_discrepancy(self):
        old_body, new_body, detail = revised_edition(ROOT)
        old = json.loads(old_body)
        new = json.loads(new_body)
        before = next(record for record in old['records'] if record['code'] == CODE)
        after = next(record for record in new['records'] if record['code'] == CODE)
        self.assertEqual(before['candidates'], after['candidates'])
        self.assertEqual(before['error'], after['original_extraction_warning'])
        self.assertEqual(after['summary_totals']['votes_polled'] - before['votes_polled'], 7)
        self.assertEqual(after['summary_result']['margin'], 6459)
        self.assertEqual(after['summary_result']['winner'], before['candidates'][0]['candidate_name'])
        self.assertEqual(detail['new_sha256'], hashlib.sha256(new_body).hexdigest())

    def test_guarded_bundle_preserves_previous_bytes(self):
        path = ROOT / 'exports' / (NAME + '.zip')
        self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(),
                         path.with_suffix('.sha256').read_text(encoding='ascii').split()[0])
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
