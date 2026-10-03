"""Check Punjab 1997 published figures while retaining damaged detailed rows."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

import fitz

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_ac_1997_punjab_summary_result_bundle import (
    EDITION, EXPECTED, NAME, OLD_SHA256, SOURCE_FILE, audited_codes, verified_summary,
)
from extract_assembly_summary_totals import read_summary_pages


ROOT = Path(__file__).resolve().parents[1]
FOLDER = ROOT / 'application/storage/app/private/election-archive' / EDITION


class Punjab1997SummaryResultTests(unittest.TestCase):
    def test_all_45_official_summary_results_reconcile(self):
        old = json.loads((FOLDER / 'extraction.json').read_bytes())
        codes = audited_codes(ROOT)
        summaries = read_summary_pages(FOLDER / SOURCE_FILE)
        self.assertEqual(len(summaries), 117)
        self.assertEqual(len(codes), EXPECTED)
        with fitz.open(FOLDER / SOURCE_FILE) as pdf:
            for record in old['records']:
                if record['code'] not in codes:
                    continue
                summary = summaries[record['code']]
                result = verified_summary(record, summary, pdf[summary['summary_page'] - 1].get_text(sort=True))
                self.assertGreater(result['margin'], 0)

    def test_source_total_tampering_is_rejected(self):
        old = json.loads((FOLDER / 'extraction.json').read_bytes())
        record = old['records'][5]
        summaries = read_summary_pages(FOLDER / SOURCE_FILE)
        with fitz.open(FOLDER / SOURCE_FILE) as pdf:
            text = pdf[summaries[6]['summary_page'] - 1].get_text(sort=True)
        altered = dict(summaries[6], votes_polled=summaries[6]['votes_polled'] - 1)
        with self.assertRaisesRegex(ValueError, 'summary totals differ'):
            verified_summary(record, altered, text)

    def test_import_preserves_old_bytes_and_incomplete_candidate_rows(self):
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
        old, revised = json.loads(old_body), json.loads(new_body)
        for before, after in zip(old['records'], revised['records']):
            self.assertEqual(before['code'], after['code'])
            for field in ('candidates', 'electors', 'votes_polled', 'valid_candidate_votes',
                          'status', 'detail_page', 'winner', 'margin'):
                self.assertEqual(before.get(field), after.get(field))
            if before['code'] in selected:
                self.assertEqual(after['original_extraction_warning'], before['error'])
                self.assertEqual(after['summary_result']['winner_votes'] -
                                 after['summary_result']['runner_votes'], after['summary_result']['margin'])
            else:
                self.assertEqual(before, after)


if __name__ == '__main__':
    unittest.main()
