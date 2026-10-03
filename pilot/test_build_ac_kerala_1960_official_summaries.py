"""Source and preservation checks for Kerala's one- and two-seat 1960 tables."""

import hashlib
import io
import json
import unittest
import zipfile

from build_ac_kerala_1960_official_summaries import NAME, ROOT, revised_edition


class Kerala1960SummariesTest(unittest.TestCase):
    def test_official_summaries_preserve_candidates_and_exclude_multi_seat_turnout(self):
        before_body, after_body, audit = revised_edition()
        before, after = json.loads(before_body), json.loads(after_body)
        self.assertEqual(audit['previous_sha256'], hashlib.sha256(before_body).hexdigest())
        self.assertEqual(audit['new_sha256'], hashlib.sha256(after_body).hexdigest())
        self.assertEqual(114, len(after['records']))
        self.assertEqual(102, sum(record['number_of_seats'] == 1 for record in after['records']))
        self.assertEqual(12, len(audit['two_seat_constituencies']))
        self.assertEqual(before['source_url'], after['source_url'])
        for old, new in zip(before['records'], after['records']):
            self.assertEqual(old['code'], new['code'])
            self.assertEqual(old['candidates'], new['candidates'])
            self.assertEqual(old['error'], new['original_extraction_warning'])
            self.assertEqual(old['votes_polled'], new['votes_polled'])
            if new['number_of_seats'] == 2:
                self.assertIn(new['code'], audit['two_seat_constituencies'])
                self.assertGreater(new['votes_polled'], new['electors'])
                self.assertEqual(2, len(new['official_multi_seat_winners']))
                self.assertNotIn('summary_result', new)
            else:
                self.assertNotIn(new['code'], audit['two_seat_constituencies'])
                self.assertLessEqual(new['votes_polled'], new['electors'])
                self.assertEqual(new['summary_result']['winner_votes'] - new['summary_result']['runner_votes'],
                                 new['summary_result']['margin'])

    def test_bundle_contains_previous_bytes_and_guarded_import(self):
        path = ROOT / 'exports' / (NAME + '.zip')
        expected = path.with_suffix('.sha256').read_text(encoding='ascii').split()[0]
        self.assertEqual(expected, hashlib.sha256(path.read_bytes()).hexdigest())
        with zipfile.ZipFile(path) as bundle:
            audit = json.loads(bundle.read('AUDIT.json'))
            self.assertIn('flock', bundle.read('IMPORT.sh').decode())
            self.assertIn('10485760', bundle.read('IMPORT.sh').decode())
            for kind, digest in (('snapshot', audit['previous_sha256']), ('correction', audit['new_sha256'])):
                filename = f'{kind}-c7c15b329cbd2123a34e5b3e.zip'
                with zipfile.ZipFile(io.BytesIO(bundle.read(filename))) as inner:
                    candidates = [name for name in inner.namelist() if name.endswith('.json') and name.startswith('election-archive/')]
                    self.assertEqual(1, len(candidates))
                    self.assertEqual(digest, hashlib.sha256(inner.read(candidates[0])).hexdigest())
            self.assertEqual(audit['previous_sha256'], '483c4fda8b3401c88e0d23eb84e9333e633f6d9faa30e3228b80f4751ce609f2')


if __name__ == '__main__':
    unittest.main()
