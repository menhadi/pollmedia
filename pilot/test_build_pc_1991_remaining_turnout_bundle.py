"""Checks five official 1991 PC summary-turnout revisions."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_pc_1991_remaining_turnout_bundle import EDITION, NAME, TARGETS


ROOT = Path(__file__).resolve().parents[1]


class Pc1991RemainingTurnoutTests(unittest.TestCase):
    def test_five_source_totals_only_and_prior_bytes_guarded(self):
        path = ROOT / 'exports' / (NAME + '.zip')
        self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(),
                         path.with_suffix('.sha256').read_text(encoding='ascii').split()[0])
        with zipfile.ZipFile(path) as outer:
            audit = json.loads(outer.read('AUDIT.json'))
            self.assertEqual({row['code'] for row in audit['records']}, set(TARGETS))
            for line in outer.read('SHA256SUMS').decode('ascii').splitlines():
                digest, name = line.split(None, 1)
                self.assertEqual(hashlib.sha256(outer.read(name)).hexdigest(), digest)
            with zipfile.ZipFile(io.BytesIO(outer.read('snapshot-' + EDITION + '.zip'))) as snapshot:
                old_body = snapshot.read(f'election-archive/{EDITION}/extraction-{audit["previous_sha256"]}.json')
            with zipfile.ZipFile(io.BytesIO(outer.read('correction-' + EDITION + '.zip'))) as correction:
                new_body = correction.read(f'election-archive/{EDITION}/extraction.json')
                manifest = json.loads(correction.read('manifest.json'))['files'][0]
        self.assertEqual(hashlib.sha256(old_body).hexdigest(), audit['previous_sha256'])
        self.assertEqual(hashlib.sha256(new_body).hexdigest(), audit['new_sha256'])
        self.assertEqual(manifest['replaces_sha256'], audit['previous_sha256'])
        old = json.loads(old_body)
        new = json.loads(new_body)
        self.assertEqual(len(old['records']), len(new['records']))
        changed = []
        for before, after in zip(old['records'], new['records']):
            if before == after:
                continue
            changed.append(before['code'])
            self.assertIn(before['code'], TARGETS)
            self.assertEqual(set(after) - set(before),
                             {'summary_totals', 'summary_source_file', 'summary_source_sha256'})
            self.assertEqual({key: value for key, value in after.items()
                              if key not in ('summary_totals', 'summary_source_file', 'summary_source_sha256')}, before)
            self.assertEqual(after['summary_totals'], {field: before[field]
                                                       for field in ('electors', 'votes_polled', 'valid_candidate_votes')})
            self.assertEqual(after['error'], before['error'])
        self.assertEqual(set(changed), set(TARGETS))


if __name__ == '__main__':
    unittest.main()
