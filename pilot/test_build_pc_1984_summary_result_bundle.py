"""Checks the source-verified 1984 Kanakapura PC correction."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

import fitz

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_pc_1984_summary_result_bundle import EDITION, NAME, SUMMARY_FILE, TARGET_CODES
from build_pc_1992_summary_result_bundle import verified_summary


ROOT = Path(__file__).resolve().parents[1]


class Pc1984SummaryResultTests(unittest.TestCase):
    def test_official_summary_and_bundle_preserve_candidate_votes(self):
        folder = ROOT / 'application/storage/app/private/election-archive' / EDITION
        old_local = json.loads((folder / 'extraction.json').read_text(encoding='utf-8'))
        record = next(row for row in old_local['records'] if row['code'] == 157)
        with fitz.open(folder / SUMMARY_FILE) as pdf:
            result = verified_summary(pdf[record['summary_page'] - 1].get_text(sort=True), record)
        self.assertEqual(result['margin'], 7026)
        self.assertEqual(result['winner'], 'M. V. CHANDRASHEKARA MURTHY')

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
        self.assertEqual(hashlib.sha256(old_body).hexdigest(), audit['previous_sha256'])
        self.assertEqual(hashlib.sha256(new_body).hexdigest(), audit['new_sha256'])
        self.assertEqual(manifest['replaces_sha256'], audit['previous_sha256'])
        old = json.loads(old_body)
        new = json.loads(new_body)
        self.assertEqual(len(old['records']), len(new['records']))
        for before, after in zip(old['records'], new['records']):
            for key in ('code', 'name', 'candidates', 'electors', 'votes_polled',
                        'valid_candidate_votes', 'detail_page', 'summary_page', 'status'):
                self.assertEqual(before.get(key), after.get(key))
            if before['code'] == 157:
                self.assertEqual(after['original_extraction_warning'], before['error'])
                self.assertEqual(after['summary_result'], result)
            else:
                self.assertEqual(before, after)


if __name__ == '__main__':
    unittest.main()
