"""Verify the Bihar 1985 AC correction against source pages and preserved JSON."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

import fitz

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_ac_1985_bihar_summary_reconciliation import (
    EDITION, EXPECTED_TARGETS, NAME, SOURCE_FILE, audited_targets, detail_sections,
    previous_body, verified_result,
)
from extract_assembly_summary_totals import corroborates, read_summary_pages


ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / 'application/storage/app/private/election-archive' / EDITION


class Bihar1985SummaryReconciliationTests(unittest.TestCase):
    def test_source_summary_and_detailed_pages_agree_for_all_323_rows(self):
        old = json.loads(previous_body(ROOT))
        _, selected = audited_targets(ROOT)
        summaries = read_summary_pages(SOURCE / SOURCE_FILE)
        self.assertEqual(len(summaries), 324)
        self.assertEqual(len(selected), EXPECTED_TARGETS)
        with fitz.open(SOURCE / SOURCE_FILE) as pdf:
            sections = detail_sections(pdf)
            for record in old['records']:
                if record['code'] not in selected:
                    continue
                code = record['code']
                self.assertEqual(record['detail_page'], sections[code][0])
                self.assertTrue(corroborates(record, summaries[code]))
                result = verified_result(pdf[summaries[code]['summary_page'] - 1].get_text(sort=True),
                                         sections[code][2], record)
                self.assertGreater(result['margin'], 0)

    def test_bundle_preserves_old_bytes_candidate_rows_and_final_incomplete_record(self):
        path = ROOT / 'exports' / (NAME + '.zip')
        self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(),
                         path.with_suffix('.sha256').read_text(encoding='ascii').split()[0])
        with zipfile.ZipFile(path) as outer:
            audit = json.loads(outer.read('AUDIT.json'))
            selected = {row['code'] for row in audit['records']}
            self.assertEqual(len(selected), EXPECTED_TARGETS)
            for line in outer.read('SHA256SUMS').decode('ascii').splitlines():
                digest, name = line.split(None, 1)
                self.assertEqual(hashlib.sha256(outer.read(name)).hexdigest(), digest)
            with zipfile.ZipFile(io.BytesIO(outer.read('snapshot-' + EDITION + '.zip'))) as snapshot:
                old_body = snapshot.read(f'election-archive/{EDITION}/extraction-{audit["previous_sha256"]}.json')
            with zipfile.ZipFile(io.BytesIO(outer.read('correction-' + EDITION + '.zip'))) as correction:
                new_body = correction.read(f'election-archive/{EDITION}/extraction.json')
                manifest = json.loads(correction.read('manifest.json'))['files'][0]
        self.assertEqual(old_body, previous_body(ROOT))
        self.assertEqual(manifest['replaces_sha256'], audit['previous_sha256'])
        self.assertEqual(hashlib.sha256(new_body).hexdigest(), audit['new_sha256'])
        old, revised = json.loads(old_body), json.loads(new_body)
        for before, after in zip(old['records'], revised['records']):
            self.assertEqual(before['code'], after['code'])
            for key in ('name', 'candidates', 'electors', 'votes_polled', 'valid_candidate_votes',
                        'status', 'detail_page', 'winner', 'margin'):
                self.assertEqual(before.get(key), after.get(key))
            if before['code'] in selected:
                self.assertEqual(after['original_extraction_warning'], before['error'])
                self.assertEqual(after['summary_totals']['votes_polled'], before['votes_polled'])
                self.assertEqual(after['summary_result']['winner_votes'] -
                                 after['summary_result']['runner_votes'], after['summary_result']['margin'])
            else:
                self.assertEqual(before, after)
        self.assertNotIn(324, selected)


if __name__ == '__main__':
    unittest.main()
