"""Checks the source-verified 1980 Eluru result and retained count warning."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

import fitz

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_pc_1980_eluru_result_bundle import (DETAIL_FILE, EDITION, NAME, SUMMARY_FILE,
                                              TARGET_CODE, verified_result)


ROOT = Path(__file__).resolve().parents[1]


class Pc1980EluruResultTests(unittest.TestCase):
    def test_official_pdf_reconciliation_and_guarded_bundle(self):
        folder = ROOT / 'application/storage/app/private/election-archive' / EDITION
        original = json.loads((folder / 'extraction.json').read_text(encoding='utf-8'))
        record = next(row for row in original['records'] if row['code'] == TARGET_CODE)
        with fitz.open(folder / DETAIL_FILE) as detail, fitz.open(folder / SUMMARY_FILE) as summary:
            result, count = verified_result(summary[record['summary_page'] - 1].get_text(sort=True),
                                            detail[record['detail_page'] - 1].get_text(sort=True),
                                            detail[record['detail_page']].get_text(sort=True), record)
        self.assertEqual(count, 8)
        self.assertEqual(len(record['candidates']), 9)
        self.assertEqual(result['winner'], 'CHITTOORI SUBBARAO CHOWDARY')
        self.assertEqual(result['margin'], 183335)

        path = ROOT / 'exports' / (NAME + '.zip')
        self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(),
                         path.with_suffix('.sha256').read_text(encoding='ascii').split()[0])
        with zipfile.ZipFile(path) as outer:
            audit = json.loads(outer.read('AUDIT.json'))
            for line in outer.read('SHA256SUMS').decode('ascii').splitlines():
                digest, name = line.split(None, 1)
                self.assertEqual(hashlib.sha256(outer.read(name)).hexdigest(), digest)
            with zipfile.ZipFile(io.BytesIO(outer.read('snapshot-' + EDITION + '.zip'))) as snapshot:
                old_body = snapshot.read(f'election-archive/{EDITION}/extraction-{audit["previous_sha256"]}.json')
            with zipfile.ZipFile(io.BytesIO(outer.read('correction-' + EDITION + '.zip'))) as correction:
                new_body = correction.read(f'election-archive/{EDITION}/extraction.json')
                manifest = json.loads(correction.read('manifest.json'))['files'][0]
        self.assertEqual(hashlib.sha256(old_body).hexdigest(), audit['previous_sha256'])
        self.assertEqual(hashlib.sha256(new_body).hexdigest(), audit['new_sha256'])
        self.assertEqual(manifest['replaces_sha256'], audit['previous_sha256'])
        old = json.loads(old_body)
        new = json.loads(new_body)
        self.assertEqual(len(old['records']), len(new['records']))
        for before, after in zip(old['records'], new['records']):
            for key in ('code', 'name', 'candidates', 'electors', 'votes_polled',
                        'valid_candidate_votes', 'detail_page', 'summary_page', 'status', 'error'):
                self.assertEqual(before.get(key), after.get(key))
            if before['code'] == TARGET_CODE:
                self.assertEqual(after['original_extraction_warning'], before['error'])
                self.assertEqual(after['source_warning_code'], 'official_pc_summary_reconciled_detail_warning')
                self.assertEqual(after['summary_result'], result)
                self.assertEqual(after['official_summary_candidate_count'], 8)
                self.assertEqual(after['detail_candidate_count'], 9)
            else:
                self.assertEqual(before, after)


if __name__ == '__main__':
    unittest.main()
