"""Verify 2007 HimachalPradesh declared-result edits and guarded package."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_ac_2007_himachal_pradesh_declared_results import EDITION, NAME, SOURCE_NOTES, revised_edition


ROOT = Path(__file__).resolve().parents[1]


class HimachalPradesh2007DeclaredResultsTests(unittest.TestCase):
    def test_only_declared_result_and_review_note_change(self):
        old_body, new_body, detail = revised_edition(ROOT)
        old = json.loads(old_body)
        new = json.loads(new_body)
        codes = set(detail['codes'])
        self.assertEqual(len(codes), 68)
        self.assertEqual(len(old['records']), len(new['records']))
        for before, after in zip(old['records'], new['records']):
            for key in ('candidates', 'electors', 'votes_polled', 'valid_candidate_votes', 'summary_totals',
                        'status', 'number_of_seats', 'original_extraction_warning', 'summary_page', 'detail_page',
                        'source_warning_code', 'official_source_url'):
                self.assertEqual(before.get(key), after.get(key), (before['code'], key))
            if before['code'] in codes:
                result = after['summary_result']
                self.assertEqual(result['margin'], result['winner_votes'] - result['runner_votes'])
                self.assertGreater(result['margin'], 0)
                self.assertTrue(after['error'].startswith(before['error']))
                self.assertTrue(any(before['error'].startswith(note) for note in SOURCE_NOTES))
                if before['source_warning_code'] == 'summary_turnout_with_detail_warnings':
                    self.assertIsNone(before.get('summary_source_sha256'))
                    self.assertEqual(after['summary_source_sha256'], new['source_sha256'])
                    self.assertEqual(after['summary_source_file'], new['source_file'])
                else:
                    self.assertEqual(before.get('summary_source_sha256'), after.get('summary_source_sha256'))
                if before['code'] in {41, 67}:
                    self.assertEqual(after['summary_general_vote_line']['winner'], before['candidates'][0]['general_votes'])
                    self.assertEqual(after['summary_general_vote_line']['runner'], before['candidates'][1]['general_votes'])
                    self.assertEqual(result['winner_votes'], before['candidates'][0]['votes'])
                    self.assertEqual(result['runner_votes'], before['candidates'][1]['votes'])
                    self.assertIn('postal votes', after['error'])
            else:
                self.assertEqual(before, after)
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
