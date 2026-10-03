"""Check the four Bombay declarations against the archived official report."""

import hashlib
import io
import json
import unittest
import zipfile

from build_ac_bombay_1951_four_single_seat_results import NAME, PAGES, ROOT, revised_edition


class Bombay1951SingleSeatResultsTest(unittest.TestCase):
    def test_only_four_source_declarations_change(self):
        before_body, after_body, audit = revised_edition()
        before, after = json.loads(before_body), json.loads(after_body)
        self.assertEqual(hashlib.sha256(before_body).hexdigest(), audit['previous_sha256'])
        self.assertEqual(hashlib.sha256(after_body).hexdigest(), audit['new_sha256'])
        self.assertEqual(before['source_url'], after['source_url'])
        for old, new in zip(before['records'], after['records']):
            if old['code'] not in PAGES:
                self.assertEqual(old, new)
                continue
            for key in ('candidates', 'electors', 'votes_polled', 'valid_candidate_votes', 'name'):
                self.assertEqual(old[key], new[key])
            self.assertEqual(old['error'], new['original_extraction_warning'])
            self.assertEqual(PAGES[old['code']], new['summary_page'])
            self.assertEqual(new['summary_result']['winner_votes'] - new['summary_result']['runner_votes'],
                             new['summary_result']['margin'])
            self.assertEqual(new['summary_totals']['votes_polled'], new['votes_polled'])
            self.assertEqual(new['summary_totals']['electors'], new['electors'])
        code245 = next(record for record in after['records'] if record['code'] == 245)
        self.assertEqual(code245['summary_totals']['valid_candidate_votes'],
                         sum(row['votes'] for row in code245['candidates'])
                         + code245['summary_result']['winner_votes'])

    def test_guarded_bundle_keeps_previous_bytes(self):
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
