"""Verify the 1999 PC name-variant revision and its prior-checksum chain."""

import hashlib
import io
import json
import unittest
import zipfile

from audit_pc_1999_name_mismatches import EDITION, ROOT, audit
from build_pc_1999_name_variant_results_bundle import NAME, PRIOR_PACKAGE, TRUNCATION_NOTE


class Pc1999NameVariantBundleTest(unittest.TestCase):
    def test_only_118_reviewed_results_change_after_detail_correction(self):
        path = ROOT / 'exports' / (NAME + '.zip')
        self.assertEqual(path.with_suffix('.sha256').read_text(encoding='ascii').split()[0],
                         hashlib.sha256(path.read_bytes()).hexdigest())
        with zipfile.ZipFile(ROOT / 'exports' / PRIOR_PACKAGE) as prior_outer:
            with zipfile.ZipFile(io.BytesIO(prior_outer.read(f'correction-{EDITION}.zip'))) as prior_inner:
                prior = prior_inner.read(f'election-archive/{EDITION}/extraction.json')
        with zipfile.ZipFile(path) as outer:
            meta = json.loads(outer.read('AUDIT.json'))
            self.assertEqual(meta['prior_package'], PRIOR_PACKAGE)
            self.assertEqual(meta['previous_sha256'], hashlib.sha256(prior).hexdigest())
            self.assertIn('--allow-revision', outer.read('IMPORT.sh').decode())
            self.assertIn('10485760', outer.read('IMPORT.sh').decode())
            with zipfile.ZipFile(io.BytesIO(outer.read(f'snapshot-{EDITION}.zip'))) as snapshot:
                self.assertEqual(prior, snapshot.read(
                    f'election-archive/{EDITION}/extraction-{meta["previous_sha256"]}.json'))
            with zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{EDITION}.zip'))) as correction:
                manifest = json.loads(correction.read('manifest.json'))['files'][0]
                self.assertEqual(meta['previous_sha256'], manifest['replaces_sha256'])
                revised = correction.read(f'election-archive/{EDITION}/extraction.json')
                self.assertEqual(meta['new_sha256'], hashlib.sha256(revised).hexdigest())
        evidence = {row['code']: row for row in audit()}
        self.assertEqual(118, len(evidence))
        before = json.loads(prior)['records']
        after = json.loads(revised)['records']
        self.assertEqual(543, len(after))
        for old, new in zip(before, after):
            if old['code'] not in evidence:
                self.assertEqual(old, new)
                continue
            for key in ('code', 'name', 'state_name', 'constituency_name', 'candidates',
                        'electors', 'votes_polled', 'valid_candidate_votes', 'status',
                        'detail_page', 'summary_page', 'summary_totals'):
                self.assertEqual(old.get(key), new.get(key), (old['code'], key))
            self.assertEqual(old['error'], new['original_extraction_warning'])
            self.assertEqual(evidence[old['code']]['result'], new['summary_result'])
            self.assertEqual(evidence[old['code']]['summary'], new['official_summary_constituency_name'])
            self.assertEqual('needs_review', new['status'])
            if old['code'] == 253:
                self.assertEqual(TRUNCATION_NOTE, new['error'])
            else:
                self.assertEqual(old['error'], new['error'])


if __name__ == '__main__':
    unittest.main()
