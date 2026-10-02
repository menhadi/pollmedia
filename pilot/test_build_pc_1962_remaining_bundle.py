"""Check the 1962 follow-up against its prior import and official PDFs."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

import fitz

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_pc_1962_remaining_bundle import (DETAIL_FILE, EDITION, NAME, PREVIOUS_SHA256,
                                             RESULT_CODES, SUMMARY_FILE, audit_codes,
                                             detailed_section, official_turnout,
                                             previous_body, source_seat)
from build_pc_1989_summary_result_bundle import verified_summary


ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / 'application/storage/app/private/election-archive' / EDITION


class Pc1962RemainingTests(unittest.TestCase):
    def test_official_pages_reconcile_all_remaining_results_and_turnout(self):
        old = json.loads(previous_body(ROOT / 'exports'))
        turnout_codes = audit_codes(ROOT / 'exports/pc-ac-display-audit-after-bdd282d.csv')
        seen_results = set()
        seen_turnout = set()
        with fitz.open(SOURCE / DETAIL_FILE) as detail, fitz.open(SOURCE / SUMMARY_FILE) as summary:
            for record in old['records']:
                code = record['code']
                if code not in turnout_codes | RESULT_CODES:
                    continue
                st = summary[record['summary_page'] - 1].get_text(sort=True)
                dt = detailed_section(detail, record)
                name = source_seat(st, record)
                if code in turnout_codes:
                    totals = official_turnout(st, dt, record)
                    self.assertEqual(totals['detail_votes_polled'] + totals['summary_missing_votes'],
                                     totals['summary_votes_polled'])
                    record['votes_polled'] = totals['summary_votes_polled']
                    seen_turnout.add(code)
                if code in RESULT_CODES:
                    result = verified_summary(st, dt, record,
                                              (record['state_name'], record['constituency_name'],
                                               name, record['error']))
                    self.assertGreater(result['margin'], 0)
                    seen_results.add(code)
        self.assertEqual(seen_results, RESULT_CODES)
        self.assertEqual(seen_turnout, turnout_codes)

    def test_guarded_bundle_changes_only_verified_fields(self):
        path = ROOT / 'exports' / (NAME + '.zip')
        self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(),
                         path.with_suffix('.sha256').read_text(encoding='ascii').split()[0])
        with zipfile.ZipFile(path) as outer:
            audit = json.loads(outer.read('AUDIT.json'))
            self.assertEqual(audit['previous_sha256'], PREVIOUS_SHA256)
            self.assertEqual({row['code'] for row in audit['results']}, RESULT_CODES)
            self.assertEqual(len(audit['turnout']), 37)
            for line in outer.read('SHA256SUMS').decode('ascii').splitlines():
                digest, filename = line.split(None, 1)
                self.assertEqual(hashlib.sha256(outer.read(filename)).hexdigest(), digest)
            with zipfile.ZipFile(io.BytesIO(outer.read('snapshot-' + EDITION + '.zip'))) as snapshot:
                prior = snapshot.read(f'election-archive/{EDITION}/extraction-{PREVIOUS_SHA256}.json')
            with zipfile.ZipFile(io.BytesIO(outer.read('correction-' + EDITION + '.zip'))) as correction:
                revised = correction.read(f'election-archive/{EDITION}/extraction.json')
                manifest = json.loads(correction.read('manifest.json'))['files'][0]
        self.assertEqual(prior, previous_body(ROOT / 'exports'))
        self.assertEqual(hashlib.sha256(revised).hexdigest(), audit['new_sha256'])
        self.assertEqual(manifest['replaces_sha256'], PREVIOUS_SHA256)
        before = json.loads(prior)
        after = json.loads(revised)
        turnout_codes = {row['code'] for row in audit['turnout']}
        for original, changed in zip(before['records'], after['records']):
            self.assertEqual(original['code'], changed['code'])
            self.assertEqual(original['candidates'], changed['candidates'])
            self.assertEqual(original['electors'], changed['electors'])
            self.assertEqual(original['valid_candidate_votes'], changed['valid_candidate_votes'])
            self.assertEqual(original['status'], changed['status'])
            if original['code'] in turnout_codes:
                self.assertEqual(changed['detail_votes_polled'], original['votes_polled'])
                self.assertEqual(changed['votes_polled'], original['summary_totals']['votes_polled'])
            else:
                self.assertEqual(changed['votes_polled'], original['votes_polled'])
            if original['code'] in RESULT_CODES:
                self.assertEqual(changed['source_warning_code'],
                                 'official_pc_summary_reconciled_detail_warning')
                self.assertEqual(changed['summary_result']['margin'],
                                 next(row['margin'] for row in audit['results'] if row['code'] == original['code']))
            elif original['code'] not in turnout_codes:
                self.assertEqual(original, changed)


if __name__ == '__main__':
    unittest.main()
