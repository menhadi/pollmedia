"""Verify 2007 Punjab declared-result edits and guarded package."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_ac_2007_punjab_declared_results import EDITION, NAME, SOURCE_NOTE, revised_edition


ROOT = Path(__file__).resolve().parents[1]


class Punjab2007DeclaredResultsTests(unittest.TestCase):
    def test_only_declared_result_and_review_note_change(self):
        old_body, new_body, detail = revised_edition(ROOT)
        old = json.loads(old_body)
        new = json.loads(new_body)
        codes = set(detail['codes'])
        self.assertEqual(len(codes), 11)
        self.assertEqual(len(old['records']), 116)
        self.assertEqual(len(new['records']), 117)
        after_by_code = {row['code']: row for row in new['records']}
        self.assertEqual(len(after_by_code), 117)
        for before in old['records']:
            after = after_by_code[before['code']]
            for key in ('candidates', 'electors', 'votes_polled', 'valid_candidate_votes', 'summary_totals',
                        'status', 'number_of_seats', 'original_extraction_warning', 'summary_page', 'detail_page',
                        'source_warning_code', 'summary_source_sha256', 'official_source_url'):
                self.assertEqual(before.get(key), after.get(key), (before['code'], key))
            if before['code'] in codes:
                result = after['summary_result']
                self.assertEqual(result['margin'], result['winner_votes'] - result['runner_votes'])
                self.assertGreater(result['margin'], 0)
                self.assertTrue(after['error'].startswith(before['error']))
                self.assertTrue(before['error'].startswith(SOURCE_NOTE))
            else:
                self.assertEqual(before, after)
        beas = after_by_code[12]
        self.assertEqual(beas['name'], 'BEAS')
        self.assertEqual(beas['state_name'], 'Punjab')
        self.assertEqual(beas['candidates'], [])
        self.assertEqual(beas['summary_page'], 29)
        self.assertEqual(beas['summary_source_sha256'], new['source_sha256'])
        self.assertEqual(beas['summary_result']['margin'], 4179)
        self.assertEqual(beas['summary_result']['winner_votes'] - beas['summary_result']['runner_votes'], 4179)
        self.assertEqual(beas['summary_totals']['votes_polled'], 109229)
        self.assertEqual(detail['new_sha256'], hashlib.sha256(new_body).hexdigest())

    def test_package_retains_prior_bytes_and_conflict_guard(self):
        path = ROOT / 'exports' / (NAME + '.zip')
        self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(),
                         path.with_suffix('.sha256').read_text(encoding='ascii').split()[0])
        self.assertNotIn(b'\r', path.with_suffix('.sha256').read_bytes())
        with zipfile.ZipFile(path) as outer:
            self.assertIn(b'check_disk', outer.read('IMPORT.sh'))
            self.assertIn(b'--allow-revision', outer.read('IMPORT.sh'))
            for line in outer.read('SHA256SUMS').decode('ascii').splitlines():
                checksum, filename = line.split(None, 1)
                self.assertEqual(hashlib.sha256(outer.read(filename)).hexdigest(), checksum)
            detail = json.loads(outer.read('AUDIT.json'))
            old_sha = detail['previous_sha256']
            with zipfile.ZipFile(io.BytesIO(outer.read(f'snapshot-{EDITION}.zip'))) as inner:
                old_body = inner.read(f'election-archive/{EDITION}/extraction-{old_sha}.json')
            with zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{EDITION}.zip'))) as inner:
                new_body = inner.read(f'election-archive/{EDITION}/extraction.json')
                manifest = json.loads(inner.read('manifest.json'))['files'][0]
        expected_old, expected_new, _ = revised_edition(ROOT)
        self.assertEqual(old_body, expected_old)
        self.assertEqual(new_body, expected_new)
        self.assertEqual(manifest['replaces_sha256'], old_sha)
        self.assertEqual(hashlib.sha256(new_body).hexdigest(), manifest['sha256'])


if __name__ == '__main__':
    unittest.main()
