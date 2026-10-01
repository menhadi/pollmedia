"""Guard the four live-revision election bridges against data loss."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_pc_ac_turnout_bridge_20261001 import CASES, NAME, TARGET_PACKAGE, checked_change, verified_payload


ROOT = Path(__file__).resolve().parents[1]


class TurnoutBridgeTests(unittest.TestCase):
    def test_each_observed_live_revision_only_fills_blank_turnout(self):
        for edition, package, prefix, live_sha, count in CASES:
            with self.subTest(edition=edition):
                prior, _ = verified_payload(ROOT, package, prefix, edition)
                target, _ = verified_payload(ROOT, TARGET_PACKAGE, 'correction', edition)
                self.assertEqual(hashlib.sha256(prior).hexdigest(), live_sha)
                self.assertEqual(len(checked_change(prior, target, count)), count)

    def test_nonblank_turnout_and_candidate_changes_are_rejected(self):
        edition, package, prefix, _, count = CASES[0]
        prior, _ = verified_payload(ROOT, package, prefix, edition)
        target, _ = verified_payload(ROOT, TARGET_PACKAGE, 'correction', edition)
        data = json.loads(prior)
        data['records'][0]['votes_polled'] = 1
        with self.assertRaisesRegex(ValueError, 'nonblank total'):
            checked_change(json.dumps(data).encode(), target, count)
        data = json.loads(target)
        data['records'][0]['candidates'][0]['votes'] = 1
        with self.assertRaisesRegex(ValueError, 'candidate'):
            checked_change(prior, json.dumps(data).encode(), count)

    def test_built_bundle_preserves_exact_live_bytes_and_target(self):
        bundle = ROOT / 'exports' / (NAME + '.zip')
        expected_sha = bundle.with_suffix('.sha256').read_text(encoding='ascii').split()[0]
        self.assertEqual(hashlib.sha256(bundle.read_bytes()).hexdigest(), expected_sha)
        with zipfile.ZipFile(bundle) as outer:
            for line in outer.read('SHA256SUMS').decode('ascii').splitlines():
                digest, name = line.split(None, 1)
                self.assertEqual(hashlib.sha256(outer.read(name)).hexdigest(), digest)
            for edition, package, prefix, live_sha, count in CASES:
                for kind, expected in (('snapshot', verified_payload(ROOT, package, prefix, edition)[0]),
                                       ('correction', verified_payload(ROOT, TARGET_PACKAGE, 'correction', edition)[0])):
                    with zipfile.ZipFile(io.BytesIO(outer.read(f'{kind}-{edition}.zip'))) as inner:
                        manifest = json.loads(inner.read('manifest.json'))['files'][0]
                        self.assertEqual(inner.read(manifest['path']), expected)
                        if kind == 'correction':
                            self.assertEqual(manifest['replaces_sha256'], live_sha)


if __name__ == '__main__':
    unittest.main()
