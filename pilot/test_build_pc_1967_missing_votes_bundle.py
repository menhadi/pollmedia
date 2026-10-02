"""Verify the 1967 PC turnout revision and preserved prior archive."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

import fitz

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_pc_1967_missing_votes_bundle import (DETAIL_FILE, EDITION, NAME, PREVIOUS_SHA256,
                                                 SUMMARY_FILE, TARGET_CODES, previous_body)
from build_pc_1962_remaining_bundle import detailed_section, official_turnout


ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / 'application/storage/app/private/election-archive' / EDITION


class Pc1967MissingVotesTests(unittest.TestCase):
    def test_all_17_missing_vote_differences_match_both_official_pdfs(self):
        old = json.loads(previous_body(ROOT / 'exports'))
        matched = set()
        with fitz.open(SOURCE / DETAIL_FILE) as detail, fitz.open(SOURCE / SUMMARY_FILE) as summary:
            for record in old['records']:
                if record['code'] not in TARGET_CODES:
                    continue
                totals = official_turnout(summary[record['summary_page'] - 1].get_text(sort=True),
                                          detailed_section(detail, record), record)
                self.assertEqual(totals['detail_votes_polled'] + totals['summary_missing_votes'],
                                 totals['summary_votes_polled'])
                matched.add(record['code'])
        self.assertEqual(matched, TARGET_CODES)

    def test_bundle_preserves_old_bytes_and_unrelated_constituencies(self):
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
                old_body = snapshot.read(f'election-archive/{EDITION}/extraction-{PREVIOUS_SHA256}.json')
            with zipfile.ZipFile(io.BytesIO(outer.read('correction-' + EDITION + '.zip'))) as correction:
                new_body = correction.read(f'election-archive/{EDITION}/extraction.json')
                entry = json.loads(correction.read('manifest.json'))['files'][0]
        self.assertEqual(old_body, previous_body(ROOT / 'exports'))
        self.assertEqual(entry['replaces_sha256'], PREVIOUS_SHA256)
        self.assertEqual(hashlib.sha256(new_body).hexdigest(), audit['new_sha256'])
        old = json.loads(old_body)
        new = json.loads(new_body)
        for before, after in zip(old['records'], new['records']):
            self.assertEqual(before['code'], after['code'])
            self.assertEqual(before['candidates'], after['candidates'])
            self.assertEqual(before['electors'], after['electors'])
            self.assertEqual(before.get('valid_candidate_votes'), after.get('valid_candidate_votes'))
            self.assertEqual(before['status'], after['status'])
            if before['code'] in TARGET_CODES:
                self.assertEqual(after['detail_votes_polled'], before['votes_polled'])
                self.assertEqual(after['votes_polled'], before['summary_totals']['votes_polled'])
                self.assertEqual(after['original_extraction_warning'], before['error'])
            else:
                self.assertEqual(before, after)


if __name__ == '__main__':
    unittest.main()
