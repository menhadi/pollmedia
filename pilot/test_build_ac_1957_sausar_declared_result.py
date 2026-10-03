"""Verify Sausar's official result while retaining the printed conflicting totals."""

import hashlib
import io
import json
import unittest
import zipfile

from build_ac_1957_sausar_declared_result import CODE, NAME, ROOT, revised_edition


class Sausar1957DeclaredResultTest(unittest.TestCase):
    def test_only_source_declared_result_changes(self):
        before_body, after_body, audit = revised_edition()
        before, after = json.loads(before_body), json.loads(after_body)
        self.assertEqual(hashlib.sha256(before_body).hexdigest(), audit['previous_sha256'])
        self.assertEqual(hashlib.sha256(after_body).hexdigest(), audit['new_sha256'])
        self.assertEqual(before['source_url'], after['source_url'])
        for old, new in zip(before['records'], after['records']):
            self.assertEqual(old['candidates'], new['candidates'])
            self.assertEqual(old['electors'], new['electors'])
            self.assertEqual(old['votes_polled'], new['votes_polled'])
            if old['code'] == CODE:
                self.assertGreater(new['votes_polled'], new['electors'])
                self.assertEqual(old['error'], new['original_extraction_warning'])
                self.assertEqual(1263, new['summary_result']['margin'])
            else:
                self.assertEqual(old, new)

    def test_guarded_bundle_preserves_prior_bytes(self):
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
