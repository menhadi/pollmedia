"""Check all three observed-live 2012 election revisions and evidence trails."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_ac_2012_live_rebased_results_20261004 import EDITIONS, revised_edition


ROOT = Path(__file__).resolve().parents[1]


class LiveRebased2012ResultsTests(unittest.TestCase):
    def test_revisions_retain_live_candidates_and_original_warnings(self):
        for config in EDITIONS:
            with self.subTest(state=config.state):
                live_body, new_body, audit = revised_edition(config)
                live, revised = json.loads(live_body), json.loads(new_body)
                self.assertEqual(config.live_sha, hashlib.sha256(live_body).hexdigest())
                self.assertEqual(audit['new_sha256'], hashlib.sha256(new_body).hexdigest())
                self.assertEqual(live['source_url'], revised['source_url'])
                self.assertEqual(live['source_sha256'], revised['source_sha256'])
                self.assertEqual(config.seats, len(revised['records']))
                before = {row['code']: row for row in live['records']}
                after = {row['code']: row for row in revised['records']}
                self.assertEqual(before.keys(), after.keys())
                for code in before:
                    self.assertEqual(before[code]['candidates'], after[code]['candidates'])
                    self.assertEqual(before[code].get('original_extraction_warning'),
                                     after[code].get('original_extraction_warning'))
                    self.assertIsNotNone(after[code].get('summary_result'))
                    if config.state != 'Gujarat':
                        self.assertEqual(before[code].get('votes_polled'),
                                         after[code].get('votes_polled'))
                for code in config.overlaps:
                    self.assertTrue(after[code].get('error'))
                    if config.state == 'Gujarat':
                        prior, result = before[code], after[code]
                        self.assertEqual(prior['turnout_totals'], result['turnout_totals'])
                        self.assertEqual(prior['error'], result['previous_turnout_review_note'])
                        self.assertEqual(result['votes_polled'],
                                         result['summary_totals']['votes_polled'])
                        self.assertEqual(prior['votes_polled'],
                                         result['summary_totals']['valid_candidate_votes'])
                        self.assertEqual(result['turnout_detail_discrepancy']['detail_page'],
                                         prior['turnout_source_page'])
                        self.assertIn('Review both pages', result['error'])
                    else:
                        self.assertIn(before[code]['error'], after[code]['error'])
                        self.assertIn('Review the linked source', after[code]['error'])

    def test_bundles_guard_exact_live_bytes_and_checksums(self):
        for config in EDITIONS:
            with self.subTest(state=config.state):
                path = ROOT / 'exports' / (config.output + '.zip')
                expected = hashlib.sha256(path.read_bytes()).hexdigest()
                self.assertEqual(path.with_suffix('.sha256').read_bytes(),
                                 f'{expected}  {path.name}\n'.encode('ascii'))
                with zipfile.ZipFile(path) as bundle:
                    audit = json.loads(bundle.read('AUDIT.json'))
                    self.assertEqual(audit['previous_sha256'], config.live_sha)
                    for guard in (b'--allow-revision', b'check_disk', b'flock',
                                  b'archive:index-constituencies --check'):
                        self.assertIn(guard, bundle.read('IMPORT.sh'))
                    for line in bundle.read('SHA256SUMS').decode('ascii').splitlines():
                        checksum, inner_name = line.split(None, 1)
                        self.assertEqual(hashlib.sha256(bundle.read(inner_name)).hexdigest(),
                                         checksum)
                    with zipfile.ZipFile(io.BytesIO(bundle.read(
                            f'snapshot-{config.edition}.zip'))) as snapshot:
                        old_body = snapshot.read(
                            f'election-archive/{config.edition}/extraction-{config.live_sha}.json')
                    with zipfile.ZipFile(io.BytesIO(bundle.read(
                            f'correction-{config.edition}.zip'))) as correction:
                        revised = correction.read(
                            f'election-archive/{config.edition}/extraction.json')
                        manifest = json.loads(correction.read('manifest.json'))['files'][0]
                expected_old, expected_revised, _ = revised_edition(config)
                self.assertEqual(old_body, expected_old)
                self.assertEqual(revised, expected_revised)
                self.assertEqual(manifest['replaces_sha256'], config.live_sha)
                self.assertEqual(manifest['sha256'], audit['new_sha256'])


if __name__ == '__main__':
    unittest.main()
