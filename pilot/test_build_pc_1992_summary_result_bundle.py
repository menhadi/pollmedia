"""Checks for the source-matched 1992 Punjab PC result correction."""

import copy
import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

import fitz

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_pc_1992_summary_result_bundle import EDITION, NAME, TARGET_CODES, verified_summary


ROOT = Path(__file__).resolve().parents[1]
ARCHIVE = ROOT / 'application/storage/app/private/election-archive' / EDITION


class Pc1992SummaryResultTests(unittest.TestCase):
    def test_seven_results_match_official_summary_and_reject_changed_votes(self):
        data = json.loads((ARCHIVE / 'extraction.json').read_text(encoding='utf-8'))
        found = {}
        with fitz.open(ARCHIVE / (EDITION + '-9768.pdf')) as pdf:
            for record in data['records']:
                if record['code'] not in TARGET_CODES:
                    continue
                text = pdf[record['summary_page'] - 1].get_text(sort=True)
                found[record['code']] = verified_summary(text, record)
                altered = copy.deepcopy(record)
                altered['candidates'][0]['votes'] += 1
                with self.assertRaisesRegex(ValueError, 'candidate votes do not reconcile'):
                    verified_summary(text, altered)
        self.assertEqual(set(found), TARGET_CODES)
        self.assertEqual(found[9]['winner'], 'GURCHARAN SINGH GALIB')
        self.assertEqual(found[9]['margin'], 53448)
        self.assertEqual(found[5]['margin'], 5784)

    def test_bundle_keeps_raw_tables_and_guards_exact_prior_bytes(self):
        bundle = ROOT / 'exports' / (NAME + '.zip')
        self.assertEqual(hashlib.sha256(bundle.read_bytes()).hexdigest(),
                         bundle.with_suffix('.sha256').read_text(encoding='ascii').split()[0])
        with zipfile.ZipFile(bundle) as outer:
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
                if before['code'] not in TARGET_CODES:
                    self.assertEqual(before, after)
                else:
                    self.assertEqual(after['source_warning_code'], 'official_pc_summary_reconciled_serial_gap')
                    self.assertEqual(after['original_extraction_warning'], before['error'])


if __name__ == '__main__':
    unittest.main()
