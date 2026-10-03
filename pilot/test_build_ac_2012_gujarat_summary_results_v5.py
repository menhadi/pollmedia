"""Verify all Gujarat 2012 summary results remain source-backed and reversible."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
from audit_ac_2012_gujarat_result_strips import STRIPS_SHA256, audit
from audit_ac_2012_gujarat_summary_results import EDITION, ROOT
from build_ac_2012_gujarat_summary_results_v5 import NAME, revised


class Gujarat2012CompleteSummaryTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.old_body, cls.new_body, cls.detail = revised()
        cls.old = json.loads(cls.old_body)
        cls.new = json.loads(cls.new_body)

    def test_independent_ocr_and_detail_review(self):
        evidence = audit()
        self.assertEqual(30, len(evidence['verified']))
        self.assertEqual({}, evidence['unresolved'])
        self.assertEqual(17, sum(row['reason'] == 'official_summary_with_detail_difference'
                                 for row in evidence['verified'].values()))
        source = ROOT / 'application/storage/app/private/election-archive' / EDITION / 'summary-result-ocr-strips-v1.json'
        self.assertEqual(STRIPS_SHA256, hashlib.sha256(source.read_bytes()).hexdigest())
        for row in evidence['verified'].values():
            result = row['result']
            self.assertGreater(result['winner_votes'], result['runner_votes'])
            self.assertEqual(result['margin'], result['winner_votes'] - result['runner_votes'])

    def test_all_constituencies_retain_detail_and_have_summary_result(self):
        self.assertEqual(182, len(self.new['records']))
        self.assertEqual(182, self.detail['results'])
        self.assertEqual(182, self.detail['turnout'])
        for old, new in zip(self.old['records'], self.new['records']):
            self.assertEqual(old['code'], new['code'])
            self.assertEqual(old['candidates'], new['candidates'])
            self.assertEqual(old.get('turnout_totals'), new.get('turnout_totals'))
            self.assertEqual(old['source_heading'], new['source_heading'])
            self.assertEqual(old['status'], new['status'])
            self.assertEqual(old['number_of_seats'], new['number_of_seats'])
            self.assertGreater(new['votes_polled'], 0)
            self.assertIsNotNone(new['summary_result'])
            self.assertLessEqual(new['summary_result']['winner_votes'],
                                 new['summary_totals']['valid_candidate_votes'])
            self.assertEqual(new['summary_page'], new['code'] + 21)

    def test_guarded_bundle_restores_exact_prior_bytes(self):
        path = ROOT / 'exports' / (NAME + '.zip')
        expected, filename = path.with_suffix('.sha256').read_text(encoding='ascii').split()
        self.assertEqual(filename, path.name)
        self.assertEqual(expected, hashlib.sha256(path.read_bytes()).hexdigest())
        with zipfile.ZipFile(path) as outer:
            self.assertEqual(EDITION, outer.read('ARCHIVES').decode().strip())
            checks = [line.split() for line in outer.read('SHA256SUMS').decode().splitlines()]
            self.assertEqual(2, len(checks))
            for sha, name in checks:
                self.assertEqual(sha, hashlib.sha256(outer.read(name)).hexdigest())
            script = outer.read('IMPORT.sh').decode()
            self.assertIn('flock -n', script)
            self.assertIn('10485760', script)
            self.assertIn('--allow-revision', script)
            self.assertIn('archive:index-constituencies --check', script)
            with zipfile.ZipFile(io.BytesIO(outer.read(f'snapshot-{EDITION}.zip'))) as inner:
                old_path = f'election-archive/{EDITION}/extraction-{self.detail["previous_sha256"]}.json'
                self.assertEqual(self.old_body, inner.read(old_path))
            with zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{EDITION}.zip'))) as inner:
                self.assertEqual(self.new_body, inner.read(f'election-archive/{EDITION}/extraction.json'))


if __name__ == '__main__':
    unittest.main()
