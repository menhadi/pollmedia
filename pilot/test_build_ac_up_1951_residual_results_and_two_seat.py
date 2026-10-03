"""Verify three declared results and four historical two-seat constituencies."""

import hashlib
import io
import json
import unittest
import zipfile

from build_ac_up_1951_residual_results_and_two_seat import (NAME, RESULT_CODES, ROOT,
                                                              TWO_SEAT_CODES, revised_edition)


class Up1951ResidualResultsTest(unittest.TestCase):
    def test_only_seven_official_rows_change(self):
        old_body, new_body, audit = revised_edition()
        old, new = json.loads(old_body), json.loads(new_body)
        self.assertEqual(hashlib.sha256(old_body).hexdigest(), audit['previous_sha256'])
        self.assertEqual(hashlib.sha256(new_body).hexdigest(), audit['new_sha256'])
        for before, after in zip(old['records'], new['records']):
            if before['code'] not in RESULT_CODES + TWO_SEAT_CODES:
                self.assertEqual(before, after)
                continue
            self.assertEqual(before['candidates'], after['candidates'])
            self.assertEqual(before['electors'], after['electors'])
            self.assertEqual(before['votes_polled'], after['votes_polled'])
            self.assertEqual(before['error'], after['previous_review_note'])
            self.assertEqual(before['source_warning_code'], after['previous_source_warning_code'])
            self.assertEqual(sum(candidate['votes'] for candidate in before['candidates']),
                             after['valid_candidate_votes'])
            if before['code'] in RESULT_CODES:
                result = after['summary_result']
                self.assertEqual(1, after['number_of_seats'])
                self.assertEqual(result['winner_votes'] - result['runner_votes'], result['margin'])
            else:
                self.assertEqual(1, after['original_extracted_number_of_seats'])
                self.assertEqual(2, after['number_of_seats'])
                self.assertEqual(2, len(after['official_summary_winners']))
                self.assertNotIn('summary_result', after)

    def test_guarded_bundle_keeps_prior_bytes(self):
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
                    paths = [name for name in inner.namelist()
                             if name.startswith('election-archive/') and name.endswith('.json')]
                    self.assertEqual(1, len(paths))
                    self.assertEqual(digest, hashlib.sha256(inner.read(paths[0])).hexdigest())


if __name__ == '__main__':
    unittest.main()
