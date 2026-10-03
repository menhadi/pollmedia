"""Check Arunachal 2004 source-backed results and missing turnout preservation."""

import hashlib
import io
import json
import unittest
import zipfile

from build_ac_arunachal_2004_official_results import NAME, ROOT, revised_edition


class Arunachal2004ResultsTest(unittest.TestCase):
    def test_contested_results_preserve_candidate_rows_and_missing_voter_totals(self):
        before_body, after_body, audit = revised_edition()
        before, after = json.loads(before_body), json.loads(after_body)
        self.assertEqual(57, audit['declared_result_count'])
        self.assertEqual([22, 24, 33, 46], audit['missing_voter_total_codes'])
        self.assertEqual([3, 4, 15], audit['uncontested_codes'])
        self.assertEqual(before['source_url'], after['source_url'])
        self.assertEqual(hashlib.sha256(before_body).hexdigest(), audit['previous_sha256'])
        self.assertEqual(hashlib.sha256(after_body).hexdigest(), audit['new_sha256'])
        for old, new in zip(before['records'], after['records']):
            self.assertEqual(old['code'], new['code'])
            self.assertEqual(old['candidates'], new['candidates'])
            self.assertEqual(old.get('votes_polled'), new.get('votes_polled'))
            if old['code'] in audit['uncontested_codes']:
                self.assertEqual(old, new)
            else:
                result = new['summary_result']
                self.assertEqual(result['winner_votes'] - result['runner_votes'], result['margin'])
                self.assertEqual(old['error'], new['previous_review_note'])
                if old['code'] in audit['missing_voter_total_codes']:
                    self.assertIsNone(new.get('votes_polled'))
                    self.assertIsNone(new['summary_totals']['votes_polled'])
                    self.assertEqual('official_summary_result_without_turnout', new['source_warning_code'])

    def test_bundle_snapshots_exact_previous_bytes_and_checks_guard(self):
        path = ROOT / 'exports' / (NAME + '.zip')
        self.assertEqual(path.with_suffix('.sha256').read_text(encoding='ascii').split()[0],
                         hashlib.sha256(path.read_bytes()).hexdigest())
        with zipfile.ZipFile(path) as bundle:
            audit = json.loads(bundle.read('AUDIT.json'))
            script = bundle.read('IMPORT.sh').decode()
            self.assertIn('flock', script)
            self.assertIn('10485760', script)
            for kind, digest in (('snapshot', audit['previous_sha256']), ('correction', audit['new_sha256'])):
                with zipfile.ZipFile(io.BytesIO(bundle.read(f'{kind}-{audit["edition"]}.zip'))) as inner:
                    paths = [name for name in inner.namelist()
                             if name.startswith('election-archive/') and name.endswith('.json')]
                    self.assertEqual(1, len(paths))
                    self.assertEqual(digest, hashlib.sha256(inner.read(paths[0])).hexdigest())


if __name__ == '__main__':
    unittest.main()
