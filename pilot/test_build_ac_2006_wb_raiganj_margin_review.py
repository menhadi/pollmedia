"""Check that the one disputed printed margin remains visible in preserved evidence."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_ac_2006_wb_raiganj_margin_review import EDITION, NAME, revised_edition


ROOT = Path(__file__).resolve().parents[1]


class RaiganjMarginReviewTests(unittest.TestCase):
    def test_only_disputed_seat_changes(self):
        old_body, new_body, detail = revised_edition(ROOT)
        old = json.loads(old_body)
        new = json.loads(new_body)
        self.assertEqual(len(old['records']), len(new['records']))
        for before, after in zip(old['records'], new['records']):
            if before['code'] == 31:
                self.assertEqual(after['summary_reported_margin'], 16103)
                self.assertEqual(after['summary_result']['margin'], 15760)
                self.assertEqual(after['summary_result']['winner_votes'] - after['summary_result']['runner_votes'], 15760)
                self.assertIn('16,103', after['error'])
                self.assertIn('15,760', after['error'])
                for key in ('candidates', 'electors', 'votes_polled', 'summary_totals', 'summary_page',
                            'source_warning_code', 'status', 'number_of_seats', 'original_extraction_warning'):
                    self.assertEqual(before.get(key), after.get(key), key)
            else:
                self.assertEqual(before, after)
        self.assertEqual(detail['previous_sha256'], hashlib.sha256(old_body).hexdigest())
        self.assertEqual(detail['new_sha256'], hashlib.sha256(new_body).hexdigest())

    def test_bundle_preserves_prior_bytes_and_refuses_other_live_checksum(self):
        path = ROOT / 'exports' / (NAME + '.zip')
        self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(),
                         path.with_suffix('.sha256').read_text(encoding='ascii').split()[0])
        self.assertNotIn(b'\r', path.with_suffix('.sha256').read_bytes())
        with zipfile.ZipFile(path) as outer:
            self.assertIsNone(outer.testzip())
            self.assertIn(b'check_disk', outer.read('IMPORT.sh'))
            self.assertIn(b'--allow-revision', outer.read('IMPORT.sh'))
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
