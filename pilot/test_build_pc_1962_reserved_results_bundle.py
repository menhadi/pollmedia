"""Verify the 97 guarded 1962 PC results and their official PDF evidence."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

import fitz

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_pc_1962_reserved_results_bundle import (DETAIL_FILE, EDITION, NAME, SUMMARY_FILE,
                                                   TARGET_CODES, summary_name)
from build_pc_1989_summary_result_bundle import verified_summary


ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / 'application/storage/app/private/election-archive' / EDITION


class Pc1962ReservedResultsTests(unittest.TestCase):
    def test_all_results_match_original_detailed_and_summary_pdfs(self):
        data = json.loads((SOURCE / 'extraction.json').read_bytes())
        found = set()
        with fitz.open(SOURCE / DETAIL_FILE) as detail, fitz.open(SOURCE / SUMMARY_FILE) as summary:
            for record in data['records']:
                if record['code'] not in TARGET_CODES:
                    continue
                detail_text = detail[record['detail_page'] - 1].get_text(sort=True)
                summary_text = summary[record['summary_page'] - 1].get_text(sort=True)
                name = summary_name(summary_text, record)
                result = verified_summary(summary_text, detail_text, record,
                                          (record['state_name'], record['constituency_name'],
                                           name, record['error']))
                self.assertGreater(result['margin'], 0)
                found.add(record['code'])
        self.assertEqual(found, TARGET_CODES)

    def test_bundle_preserves_every_raw_candidate_vote_and_prior_bytes(self):
        path = ROOT / 'exports' / (NAME + '.zip')
        self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(),
                         path.with_suffix('.sha256').read_text(encoding='ascii').split()[0])
        with zipfile.ZipFile(path) as outer:
            audit = json.loads(outer.read('AUDIT.json'))
            self.assertEqual({row['code'] for row in audit['records']}, TARGET_CODES)
            self.assertEqual(len(audit['records']), 97)
            for line in outer.read('SHA256SUMS').decode('ascii').splitlines():
                digest, name = line.split(None, 1)
                self.assertEqual(hashlib.sha256(outer.read(name)).hexdigest(), digest)
            with zipfile.ZipFile(io.BytesIO(outer.read('snapshot-' + EDITION + '.zip'))) as snapshot:
                old_body = snapshot.read(f'election-archive/{EDITION}/extraction-{audit["previous_sha256"]}.json')
            with zipfile.ZipFile(io.BytesIO(outer.read('correction-' + EDITION + '.zip'))) as correction:
                new_body = correction.read(f'election-archive/{EDITION}/extraction.json')
                manifest = json.loads(correction.read('manifest.json'))['files'][0]
        self.assertEqual(old_body, (SOURCE / 'extraction.json').read_bytes())
        self.assertEqual(hashlib.sha256(old_body).hexdigest(), audit['previous_sha256'])
        self.assertEqual(hashlib.sha256(new_body).hexdigest(), audit['new_sha256'])
        self.assertEqual(manifest['replaces_sha256'], audit['previous_sha256'])
        old = json.loads(old_body)
        revised = json.loads(new_body)
        for before, after in zip(old['records'], revised['records']):
            for key in ('code', 'name', 'candidates', 'electors', 'votes_polled',
                        'valid_candidate_votes', 'detail_page', 'summary_page', 'status', 'error'):
                self.assertEqual(before.get(key), after.get(key))
            if before['code'] in TARGET_CODES:
                self.assertEqual(after['original_extraction_warning'], before['error'])
                self.assertEqual(after['source_warning_code'], 'official_pc_summary_reconciled_detail_warning')
            else:
                self.assertEqual(before, after)


if __name__ == '__main__':
    unittest.main()
