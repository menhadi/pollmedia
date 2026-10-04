"""Verify all 288 official summaries and the guarded archive revision."""

import json
from pathlib import Path
import sys
import tempfile
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
import build_ac_maharashtra_2009_official_summaries as builder


class Maharashtra2009SummariesTests(unittest.TestCase):
    def test_source_turnout_and_results_keep_all_original_candidate_rows(self):
        old_body, new_body, audit = builder.revised_edition()
        old, revised = json.loads(old_body), json.loads(new_body)
        self.assertEqual(len(old['records']), 288)
        self.assertEqual(len(revised['records']), 288)
        self.assertEqual(sum('summary_result' in row for row in revised['records']), 286)
        self.assertEqual(sum('summary_winner_only' in row for row in revised['records']), 2)
        for before, after in zip(old['records'], revised['records']):
            self.assertEqual(before['candidates'], after['candidates'])
            self.assertEqual(before['status'], after['status'])
            self.assertEqual(before['electors'], after['electors'])
            self.assertTrue(0 < after['valid_candidate_votes'] <= after['votes_polled'] <= after['electors'])
            self.assertEqual(after['source_warning_code'], 'official_summary_turnout_only')
            self.assertEqual(after['summary_source_sha256'], audit['source_sha256'])
        for code in (134, 178):
            record = revised['records'][code - 1]
            self.assertNotIn('summary_result', record)
            result = record['summary_winner_only']
            self.assertNotEqual(result['margin'], result['winner_votes'] - result['runner_votes'])
            self.assertIn('margin is withheld', record['error'])
        self.assertEqual(builder.digest(old_body), audit['previous_sha256'])
        self.assertEqual(builder.digest(new_body), audit['new_sha256'])

    def test_bundle_preserves_prior_bytes_and_checksum_guard(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = builder.ROOT / 'application/storage/app/private/election-archive' / builder.EDITION
            target = root / 'application/storage/app/private/election-archive' / builder.EDITION
            target.mkdir(parents=True)
            for filename in ('extraction.json', 'manifest.json', builder.SOURCE_FILE):
                (target / filename).write_bytes((source / filename).read_bytes())
            (root / 'exports').mkdir()
            audit = builder.build(root)
            with zipfile.ZipFile(audit['bundle']) as outer:
                script = outer.read('IMPORT.sh')
                for guard in (b'--allow-revision', b'check_disk', b'sha256sum -c'):
                    self.assertIn(guard, script)
                with zipfile.ZipFile(outer.open(f'snapshot-{builder.EDITION}.zip')) as snapshot:
                    path = f'election-archive/{builder.EDITION}/extraction-{audit["previous_sha256"]}.json'
                    self.assertEqual(builder.digest(snapshot.read(path)), audit['previous_sha256'])
                with zipfile.ZipFile(outer.open(f'correction-{builder.EDITION}.zip')) as correction:
                    path = f'election-archive/{builder.EDITION}/extraction.json'
                    self.assertEqual(builder.digest(correction.read(path)), audit['new_sha256'])


if __name__ == '__main__':
    unittest.main()
