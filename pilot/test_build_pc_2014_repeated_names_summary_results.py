"""Verify four official 2014 results and preserve namesake candidate rows."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_pc_2014_repeated_names_summary_results import EDITION, NAME, OLD_SHA256, TARGETS, revised_edition


ROOT = Path(__file__).resolve().parents[1]


class RepeatedNamesSummaryTests(unittest.TestCase):
    def test_all_four_results_reconcile_including_nota(self):
        old_body, new_body, audit = revised_edition(ROOT)
        old = json.loads(old_body)
        revised = json.loads(new_body)
        self.assertEqual({item['code'] for item in audit['records_reconciled']}, set(TARGETS))
        for before, after in zip(old['records'], revised['records']):
            self.assertEqual(before['candidates'], after['candidates'])
            if before['code'] not in TARGETS:
                self.assertEqual(before, after)
                continue
            self.assertEqual(after['original_extraction_warning'], before['error'])
            self.assertEqual(after['summary_candidate_count'], len(after['candidates']) - 1)
            self.assertEqual(sum(candidate['votes'] for candidate in after['candidates']),
                             after['summary_totals']['valid_candidate_votes'] + after['summary_totals']['nota_votes'])
            ranked = sorted((candidate for candidate in after['candidates'] if not candidate.get('is_nota')),
                            key=lambda candidate: candidate['votes'], reverse=True)
            self.assertEqual(after['summary_result']['winner_votes'], ranked[0]['votes'])
            self.assertEqual(after['summary_result']['runner_votes'], ranked[1]['votes'])
            self.assertEqual(after['summary_result']['margin'], ranked[0]['votes'] - ranked[1]['votes'])

    def test_bundle_has_original_bytes_and_guarded_checksum_import(self):
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
