"""Verify 28 truncated Uttar Pradesh headings against official summaries."""

import hashlib
import io
import json
import unittest
import zipfile

from build_ac_up_1951_28_summary_results import CODES, NAME, ROOT, revised_edition


class Up1951SummaryResultsTest(unittest.TestCase):
    def test_28_results_keep_candidate_rows_and_previous_warnings(self):
        old_body, new_body, audit = revised_edition()
        old, new = json.loads(old_body), json.loads(new_body)
        self.assertEqual(28, len(CODES))
        self.assertEqual(hashlib.sha256(old_body).hexdigest(), audit['previous_sha256'])
        self.assertEqual(hashlib.sha256(new_body).hexdigest(), audit['new_sha256'])
        for before, after in zip(old['records'], new['records']):
            if before['code'] not in CODES:
                self.assertEqual(before, after)
                continue
            self.assertEqual(before['candidates'], after['candidates'])
            self.assertEqual(before['error'], after['original_extraction_warning'])
            self.assertEqual(before['summary_page'], after['summary_page'])
            for field in ('electors', 'votes_polled', 'valid_candidate_votes'):
                self.assertEqual(before[field], after[field])
            result = after['summary_result']
            self.assertEqual(result['winner_votes'] - result['runner_votes'], result['margin'])
            self.assertEqual(before['valid_candidate_votes'], sum(row['votes'] for row in before['candidates']))

    def test_bundle_guards_prior_bytes_and_server_disk_reserve(self):
        path = ROOT / 'exports' / (NAME + '.zip')
        self.assertEqual(path.with_suffix('.sha256').read_text(encoding='ascii').split()[0],
                         hashlib.sha256(path.read_bytes()).hexdigest())
        with zipfile.ZipFile(path) as bundle:
            audit = json.loads(bundle.read('AUDIT.json'))
            script = bundle.read('IMPORT.sh').decode()
            self.assertIn('flock', script)
            self.assertIn('10485760', script)
            for kind, digest in (('snapshot', audit['previous_sha256']),
                                 ('correction', audit['new_sha256'])):
                with zipfile.ZipFile(io.BytesIO(bundle.read(f'{kind}-{audit["edition"]}.zip'))) as inner:
                    files = [name for name in inner.namelist()
                             if name.startswith('election-archive/') and name.endswith('.json')]
                    self.assertEqual(1, len(files))
                    self.assertEqual(digest, hashlib.sha256(inner.read(files[0])).hexdigest())


if __name__ == '__main__':
    unittest.main()
