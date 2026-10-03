"""Check Bihar 1951 corrections against the official PDF and preserved archive."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

import fitz

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_ac_1951_bihar_summary_reconciliation import (
    EDITION, EXPECTED, NAME, OLD_SHA256, SOURCE_FILE, audited_codes,
    detail_sections, verified_record,
)


ROOT = Path(__file__).resolve().parents[1]
FOLDER = ROOT / 'application/storage/app/private/election-archive' / EDITION


class Bihar1951SummaryReconciliationTests(unittest.TestCase):
    def test_all_revised_results_match_both_official_tables(self):
        old = json.loads((FOLDER / 'extraction.json').read_bytes())
        audited = audited_codes(ROOT)
        with fitz.open(FOLDER / SOURCE_FILE) as pdf:
            details = detail_sections(pdf)
            checked = []
            for record in old['records']:
                if record['code'] not in audited or record['number_of_seats'] != 1 or record['code'] == 202:
                    continue
                code = record['code']
                result = verified_record(record, pdf[code + 15].get_text(sort=True),
                                         details[code][1], details[code][0])
                self.assertGreater(result['margin'], 0)
                checked.append(code)
        self.assertEqual(len(checked), EXPECTED)
        self.assertEqual(len(set(checked)), EXPECTED)

    def test_mismatched_source_total_is_rejected(self):
        record = json.loads((FOLDER / 'extraction.json').read_bytes())['records'][0]
        with fitz.open(FOLDER / SOURCE_FILE) as pdf:
            details = detail_sections(pdf)
            summary = pdf[16].get_text(sort=True).replace('43429', '43428', 1)
        with self.assertRaisesRegex(ValueError, 'summary totals differ'):
            verified_record(record, summary, details[1][1], details[1][0])

    def test_bundle_preserves_unreconciled_rows_and_original_bytes(self):
        path = ROOT / 'exports' / (NAME + '.zip')
        self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(),
                         path.with_suffix('.sha256').read_text(encoding='ascii').split()[0])
        with zipfile.ZipFile(path) as outer:
            audit = json.loads(outer.read('AUDIT.json'))
            selected = {row['code'] for row in audit['records']}
            self.assertEqual(len(selected), EXPECTED)
            for line in outer.read('SHA256SUMS').decode('ascii').splitlines():
                checksum, filename = line.split(None, 1)
                self.assertEqual(hashlib.sha256(outer.read(filename)).hexdigest(), checksum)
            with zipfile.ZipFile(io.BytesIO(outer.read('snapshot-' + EDITION + '.zip'))) as snapshot:
                old_body = snapshot.read(f'election-archive/{EDITION}/extraction-{OLD_SHA256}.json')
            with zipfile.ZipFile(io.BytesIO(outer.read('correction-' + EDITION + '.zip'))) as correction:
                new_body = correction.read(f'election-archive/{EDITION}/extraction.json')
                manifest = json.loads(correction.read('manifest.json'))['files'][0]
        self.assertEqual(old_body, (FOLDER / 'extraction.json').read_bytes())
        self.assertEqual(manifest['replaces_sha256'], OLD_SHA256)
        self.assertEqual(hashlib.sha256(new_body).hexdigest(), audit['new_sha256'])
        before, after = json.loads(old_body), json.loads(new_body)
        for original, revised in zip(before['records'], after['records']):
            self.assertEqual(original['code'], revised['code'])
            for field in ('candidates', 'electors', 'votes_polled', 'valid_candidate_votes',
                          'status', 'detail_page', 'winner', 'margin'):
                self.assertEqual(original.get(field), revised.get(field))
            if original['code'] in selected:
                self.assertEqual(revised['original_extraction_warning'], original['error'])
                self.assertEqual(revised['summary_totals']['votes_polled'], original['votes_polled'])
            else:
                self.assertEqual(original, revised)
        self.assertNotIn(202, selected)
        self.assertEqual(sum(row['number_of_seats'] == 2 for row in after['records']), 54)


if __name__ == '__main__':
    unittest.main()
