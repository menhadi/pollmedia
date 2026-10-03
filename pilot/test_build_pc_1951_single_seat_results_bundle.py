"""Check all recovered 1951 PC single-seat results and the guarded archive revision."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

import fitz

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_pc_1951_single_seat_results_bundle import (
    DETAIL_FILE, EDITION, EXPECTED_SINGLE_SEAT, NAME, SUMMARY_FILE, audited_codes,
    verified_summary,
)


ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / 'application/storage/app/private/election-archive' / EDITION


class Pc1951SingleSeatResultTests(unittest.TestCase):
    def test_all_88_single_seat_results_match_both_official_pdfs(self):
        old = json.loads((SOURCE / 'extraction.json').read_bytes())
        _, hidden_codes = audited_codes(ROOT / 'exports/pc-ac-display-audit-after-bdd282d.csv')
        selected = set()
        with fitz.open(SOURCE / DETAIL_FILE) as detail, fitz.open(SOURCE / SUMMARY_FILE) as summary:
            for record in old['records']:
                if record['code'] not in hidden_codes or record['number_of_seats'] != 1:
                    continue
                verified_summary(summary[record['summary_page'] - 1].get_text(sort=True),
                                 detail[record['detail_page'] - 1].get_text(sort=True), record,
                                 minimum_summary_prefix=13, allow_wrapped_detail_name=True)
                selected.add(record['code'])
            first = next(record for record in old['records'] if record['code'] == 2)
            altered = summary[first['summary_page'] - 1].get_text(sort=True).replace('59326', '59327')
            with self.assertRaisesRegex(ValueError, 'winner or runner-up differs'):
                verified_summary(altered, detail[first['detail_page'] - 1].get_text(sort=True), first,
                                 minimum_summary_prefix=13, allow_wrapped_detail_name=True)
        self.assertEqual(len(selected), EXPECTED_SINGLE_SEAT)

    def test_bundle_preserves_prior_bytes_and_other_constituencies(self):
        path = ROOT / 'exports' / (NAME + '.zip')
        self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(),
                         path.with_suffix('.sha256').read_text(encoding='ascii').split()[0])
        with zipfile.ZipFile(path) as outer:
            audit = json.loads(outer.read('AUDIT.json'))
            selected = {row['code'] for row in audit['records']}
            self.assertEqual(len(selected), EXPECTED_SINGLE_SEAT)
            self.assertEqual(audit['multi_member_unchanged'], 87)
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
            if before['code'] in selected:
                self.assertEqual(after['original_extraction_warning'], before['error'])
                self.assertEqual(after['source_warning_code'], 'official_pc_summary_reconciled_detail_warning')
                self.assertEqual(after['summary_result']['winner_votes'] -
                                 after['summary_result']['runner_votes'], after['summary_result']['margin'])
            else:
                self.assertEqual(before, after)


if __name__ == '__main__':
    unittest.main()
