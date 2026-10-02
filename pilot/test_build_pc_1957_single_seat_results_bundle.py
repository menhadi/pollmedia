"""Check the 1957 single-seat PC corrections against both official PDFs."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

import fitz

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_pc_1957_single_seat_results_bundle import (
    DETAIL_FILE, EDITION, NAME, SUMMARY_FILE, TARGET_CODES, verified_summary,
)


ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / 'application/storage/app/private/election-archive' / EDITION


class Pc1957SingleSeatResultTests(unittest.TestCase):
    def test_three_single_seat_results_match_both_official_pdfs(self):
        old = json.loads((SOURCE / 'extraction.json').read_bytes())
        matched = set()
        with fitz.open(SOURCE / DETAIL_FILE) as detail, fitz.open(SOURCE / SUMMARY_FILE) as summary:
            for record in old['records']:
                if record['code'] not in TARGET_CODES:
                    continue
                _, result = verified_summary(summary[record['summary_page'] - 1].get_text(sort=True),
                                             detail[record['detail_page'] - 1].get_text(sort=True), record)
                self.assertGreater(result['margin'], 0)
                matched.add(record['code'])
        self.assertEqual(matched, TARGET_CODES)

    def test_bundle_preserves_previous_bytes_and_every_other_record(self):
        path = ROOT / 'exports' / (NAME + '.zip')
        self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(),
                         path.with_suffix('.sha256').read_text(encoding='ascii').split()[0])
        with zipfile.ZipFile(path) as outer:
            audit = json.loads(outer.read('AUDIT.json'))
            self.assertEqual({row['code'] for row in audit['records']}, TARGET_CODES)
            for line in outer.read('SHA256SUMS').decode('ascii').splitlines():
                digest, name = line.split(None, 1)
                self.assertEqual(hashlib.sha256(outer.read(name)).hexdigest(), digest)
            with zipfile.ZipFile(io.BytesIO(outer.read('snapshot-' + EDITION + '.zip'))) as snapshot:
                old_body = snapshot.read(f'election-archive/{EDITION}/extraction-{audit["previous_sha256"]}.json')
            with zipfile.ZipFile(io.BytesIO(outer.read('correction-' + EDITION + '.zip'))) as correction:
                new_body = correction.read(f'election-archive/{EDITION}/extraction.json')
                manifest = json.loads(correction.read('manifest.json'))['files'][0]
        self.assertEqual(old_body, (SOURCE / 'extraction.json').read_bytes())
        self.assertEqual(manifest['replaces_sha256'], audit['previous_sha256'])
        self.assertEqual(hashlib.sha256(new_body).hexdigest(), audit['new_sha256'])
        old, revised = json.loads(old_body), json.loads(new_body)
        self.assertEqual(len(old['records']), len(revised['records']))
        for before, after in zip(old['records'], revised['records']):
            self.assertEqual(before['code'], after['code'])
            for key in ('candidates', 'electors', 'votes_polled', 'valid_candidate_votes',
                        'status', 'error', 'summary_totals'):
                self.assertEqual(before.get(key), after.get(key))
            if before['code'] in TARGET_CODES:
                self.assertEqual(after['original_extraction_warning'], before['error'])
                self.assertEqual(after['source_warning_code'], 'official_pc_summary_reconciled_detail_warning')
                self.assertEqual(after['summary_result']['winner_votes'] -
                                 after['summary_result']['runner_votes'], after['summary_result']['margin'])
            else:
                self.assertEqual(before, after)


if __name__ == '__main__':
    unittest.main()
