"""Verify two 1971 official declarations without treating impossible totals as turnout."""

import hashlib
import io
import json
import unittest
import zipfile

from build_ac_tamil_nadu_1971_invalid_turnout_results import NAME, ROOT, SPECS, revised_edition


class TamilNadu1971InvalidTurnoutTest(unittest.TestCase):
    def test_only_two_declared_results_change(self):
        old_body, new_body, audit = revised_edition()
        old, new = json.loads(old_body), json.loads(new_body)
        self.assertEqual(hashlib.sha256(old_body).hexdigest(), audit['previous_sha256'])
        self.assertEqual(hashlib.sha256(new_body).hexdigest(), audit['new_sha256'])
        for before, after in zip(old['records'], new['records']):
            if before['code'] not in SPECS:
                self.assertEqual(before, after)
                continue
            self.assertEqual(before['candidates'], after['candidates'])
            self.assertEqual(before['error'], after['original_extraction_warning'])
            for key in ('electors', 'votes_polled', 'valid_candidate_votes'):
                self.assertEqual(before[key], after[key])
            self.assertGreater(after['votes_polled'], after['electors'])
            self.assertEqual(SPECS[before['code']][-1], after['summary_result']['margin'])

    def test_guarded_bundle_keeps_previous_bytes(self):
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
