"""Verify 1951 Sourastra/Mysore source totals and preserved detailed rows."""

import hashlib
import io
import json
import unittest
import zipfile

from build_ac_1951_sourastra_mysore_summary_results import NAME, ROOT, SOURCES, revised_edition


class SourastraMysore1951SummaryResultsTest(unittest.TestCase):
    def test_official_summary_replaces_only_four_mixed_extraction_totals(self):
        for edition, config in SOURCES.items():
            with self.subTest(edition=edition):
                old_body, new_body, audit = revised_edition(edition)
                old, new = json.loads(old_body), json.loads(new_body)
                self.assertEqual(hashlib.sha256(old_body).hexdigest(), audit['previous_sha256'])
                self.assertEqual(hashlib.sha256(new_body).hexdigest(), audit['new_sha256'])
                self.assertEqual(old['source_url'], new['source_url'])
                for before, after in zip(old['records'], new['records']):
                    if before['code'] not in config['seats']:
                        self.assertEqual(before, after)
                        continue
                    self.assertEqual(before['candidates'], after['candidates'])
                    self.assertEqual(before['error'], after['original_extraction_warning'])
                    self.assertEqual({key: before[key] for key in ('electors', 'votes_polled', 'valid_candidate_votes')},
                                     after['original_extracted_totals'])
                    page, electors, polled, winner, runner, margin = config['seats'][before['code']]
                    self.assertEqual(page, after['summary_page'])
                    self.assertEqual((electors, polled, polled),
                                     (after['electors'], after['votes_polled'], after['valid_candidate_votes']))
                    self.assertEqual((winner, runner, margin),
                                     (after['summary_result']['winner_votes'],
                                      after['summary_result']['runner_votes'], after['summary_result']['margin']))

    def test_guarded_bundle_preserves_both_previous_extractions(self):
        path = ROOT / 'exports' / (NAME + '.zip')
        self.assertEqual(path.with_suffix('.sha256').read_text(encoding='ascii').split()[0],
                         hashlib.sha256(path.read_bytes()).hexdigest())
        with zipfile.ZipFile(path) as bundle:
            audit = json.loads(bundle.read('AUDIT.json'))
            self.assertEqual(set(SOURCES), set(bundle.read('ARCHIVES').decode().splitlines()))
            script = bundle.read('IMPORT.sh').decode()
            self.assertIn('flock', script)
            self.assertIn('10485760', script)
            for row in audit['editions']:
                for kind, digest in (('snapshot', row['previous_sha256']),
                                     ('correction', row['new_sha256'])):
                    with zipfile.ZipFile(io.BytesIO(bundle.read(f'{kind}-{row["edition"]}.zip'))) as inner:
                        paths = [name for name in inner.namelist()
                                 if name.startswith('election-archive/') and name.endswith('.json')]
                        self.assertEqual(1, len(paths))
                        self.assertEqual(digest, hashlib.sha256(inner.read(paths[0])).hexdigest())


if __name__ == '__main__':
    unittest.main()
