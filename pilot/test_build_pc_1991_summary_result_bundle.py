"""Checks for the source-matched 1991 PC result correction."""

import csv
import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

import fitz

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_pc_1991_summary_result_bundle import EDITION, NAME, SUMMARY_FILE, TARGET_CODES
from build_pc_1992_summary_result_bundle import verified_summary


ROOT = Path(__file__).resolve().parents[1]
ARCHIVE = ROOT / 'application/storage/app/private/election-archive' / EDITION


class Pc1991SummaryResultTests(unittest.TestCase):
    def test_every_target_has_official_winner_margin_and_matching_candidate_votes(self):
        data = json.loads((ARCHIVE / 'extraction.json').read_text(encoding='utf-8'))
        found = {}
        with fitz.open(ARCHIVE / SUMMARY_FILE) as pdf:
            for record in data['records']:
                if record['code'] in TARGET_CODES:
                    found[record['code']] = verified_summary(pdf[record['summary_page'] - 1].get_text(sort=True), record)
        self.assertEqual(set(found), TARGET_CODES)
        self.assertEqual(found[292]['winner'], 'PURNO A. SANGMA')
        self.assertEqual(found[473]['margin'], 1820)

    def test_bundle_preserves_every_source_value_and_guards_prior_live_checksum(self):
        path = ROOT / 'exports' / (NAME + '.zip')
        self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(),
                         path.with_suffix('.sha256').read_text(encoding='ascii').split()[0])
        with zipfile.ZipFile(path) as outer:
            audit = json.loads(outer.read('AUDIT.json'))
            self.assertEqual({row['code'] for row in audit['records']}, TARGET_CODES)
            for line in outer.read('SHA256SUMS').decode('ascii').splitlines():
                digest, inner_name = line.split(None, 1)
                self.assertEqual(hashlib.sha256(outer.read(inner_name)).hexdigest(), digest)
            with zipfile.ZipFile(io.BytesIO(outer.read('snapshot-' + EDITION + '.zip'))) as snapshot:
                before_body = snapshot.read(f'election-archive/{EDITION}/extraction-{audit["previous_sha256"]}.json')
            with zipfile.ZipFile(io.BytesIO(outer.read('correction-' + EDITION + '.zip'))) as correction:
                after_body = correction.read(f'election-archive/{EDITION}/extraction.json')
                manifest = json.loads(correction.read('manifest.json'))['files'][0]
        with (ROOT / 'exports/pc-ac-display-audit-after-bdd282d.csv').open(encoding='utf-8-sig', newline='') as live_csv:
            live_rows = [row for row in csv.DictReader(live_csv)
                         if row['edition_id'] == EDITION and row['issue'] == 'winner_hidden_with_candidate_votes']
        self.assertEqual({row['extraction_sha256'] for row in live_rows}, {audit['previous_sha256']})
        self.assertEqual(hashlib.sha256(before_body).hexdigest(), audit['previous_sha256'])
        self.assertEqual(hashlib.sha256(after_body).hexdigest(), audit['new_sha256'])
        self.assertEqual(manifest['replaces_sha256'], audit['previous_sha256'])
        before = json.loads(before_body)
        after = json.loads(after_body)
        self.assertEqual(len(before['records']), len(after['records']))
        for old, new in zip(before['records'], after['records']):
            for key in ('code', 'name', 'candidates', 'electors', 'votes_polled',
                        'valid_candidate_votes', 'detail_page', 'summary_page', 'status'):
                self.assertEqual(old.get(key), new.get(key))
            if old['code'] in TARGET_CODES:
                self.assertEqual(new['original_extraction_warning'], old['error'])
                self.assertEqual(new['source_warning_code'], 'official_pc_summary_reconciled_serial_gap')
            else:
                self.assertEqual(old, new)


if __name__ == '__main__':
    unittest.main()
