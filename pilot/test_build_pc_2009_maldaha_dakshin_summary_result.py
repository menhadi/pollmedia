"""Check that the official result is added without changing the disputed detail."""

import json
import sys
import tempfile
from pathlib import Path
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
import build_pc_2009_maldaha_dakshin_summary_result as builder


class MaldahaDakshinSummaryTests(unittest.TestCase):
    def test_summary_evidence_preserves_candidate_rows_and_discrepancy(self):
        old, new, audit = builder.revised_edition()
        before = json.loads(old)
        after = json.loads(new)
        original = next(row for row in before['records'] if row['code'] == 466)
        revised = next(row for row in after['records'] if row['code'] == 466)
        self.assertEqual([r for r in before['records'] if r['code'] != 466],
                         [r for r in after['records'] if r['code'] != 466])
        for field in ('candidates', 'electors', 'votes_polled', 'valid_candidate_votes',
                      'summary_totals', 'status', 'detail_page', 'summary_page'):
            self.assertEqual(revised[field], original[field])
        self.assertEqual(revised['summary_result'], builder.RESULT)
        self.assertEqual(revised['source_warning_code'], 'official_summary_turnout_only')
        self.assertEqual(revised['summary_source_sha256'], builder.SUMMARY_SHA)
        self.assertIn('conflict', revised['error'])
        self.assertEqual(audit['previous_sha256'], builder.sha(old))
        self.assertEqual(audit['new_sha256'], builder.sha(new))

    def test_bundle_retains_prior_bytes_and_guards_revision(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = builder.ROOT / 'application/storage/app/private/election-archive' / builder.EDITION
            target = root / 'application/storage/app/private/election-archive' / builder.EDITION
            target.mkdir(parents=True)
            for name in ('extraction.json', 'manifest.json', builder.SUMMARY_FILE, builder.DETAIL_FILE):
                (target / name).write_bytes((source / name).read_bytes())
            (root / 'exports').mkdir()
            audit = builder.build(root)
            with zipfile.ZipFile(audit['bundle']) as outer:
                script = outer.read('IMPORT.sh')
                self.assertIn(b'--allow-revision', script)
                self.assertIn(b'check_disk', script)
                self.assertIn(b'sha256sum -c', script)
                self.assertEqual(outer.read('ARCHIVES'), (builder.EDITION + '\n').encode())
                with zipfile.ZipFile(outer.open(f'snapshot-{builder.EDITION}.zip')) as snapshot:
                    path = f'election-archive/{builder.EDITION}/extraction-{builder.OLD_SHA}.json'
                    self.assertEqual(builder.sha(snapshot.read(path)), builder.OLD_SHA)
                with zipfile.ZipFile(outer.open(f'correction-{builder.EDITION}.zip')) as correction:
                    path = f'election-archive/{builder.EDITION}/extraction.json'
                    self.assertEqual(builder.sha(correction.read(path)), audit['new_sha256'])


if __name__ == '__main__':
    unittest.main()
