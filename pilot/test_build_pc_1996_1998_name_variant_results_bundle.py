"""Verify both name-variant revisions depend on the prior detailed-result bytes."""

import hashlib
import io
import json
import unittest
import zipfile

from audit_pc_1996_1998_name_mismatches import EDITIONS, ROOT, audit
from build_pc_1996_1998_name_variant_results_bundle import NAME, PRIOR_PACKAGE, TRUNCATION_NOTE


class PcOldNameVariantBundleTest(unittest.TestCase):
    def test_revisions_preserve_all_candidate_rows_and_prior_hashes(self):
        bundle = ROOT / 'exports' / (NAME + '.zip')
        self.assertEqual(bundle.with_suffix('.sha256').read_text(encoding='ascii').split()[0],
                         hashlib.sha256(bundle.read_bytes()).hexdigest())
        evidence = {(row['edition'], row['code']): row for row in audit()}
        self.assertEqual(4, len(evidence))
        with zipfile.ZipFile(ROOT / 'exports' / PRIOR_PACKAGE) as prior_outer, zipfile.ZipFile(bundle) as outer:
            meta = json.loads(outer.read('AUDIT.json'))
            self.assertEqual(PRIOR_PACKAGE, meta['prior_package'])
            self.assertEqual(2, len(meta['editions']))
            self.assertIn('--allow-revision', outer.read('IMPORT.sh').decode())
            self.assertIn('10485760', outer.read('IMPORT.sh').decode())
            for year, (edition, _, _) in EDITIONS.items():
                detail = next(item for item in meta['editions'] if item['edition'] == edition)
                with zipfile.ZipFile(io.BytesIO(prior_outer.read(f'correction-{edition}.zip'))) as prior_inner:
                    previous = prior_inner.read(f'election-archive/{edition}/extraction.json')
                self.assertEqual(detail['previous_sha256'], hashlib.sha256(previous).hexdigest())
                with zipfile.ZipFile(io.BytesIO(outer.read(f'snapshot-{edition}.zip'))) as snapshot:
                    self.assertEqual(previous, snapshot.read(
                        f'election-archive/{edition}/extraction-{detail["previous_sha256"]}.json'))
                with zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{edition}.zip'))) as correction:
                    manifest = json.loads(correction.read('manifest.json'))['files'][0]
                    self.assertEqual(detail['previous_sha256'], manifest['replaces_sha256'])
                    revised = correction.read(f'election-archive/{edition}/extraction.json')
                    self.assertEqual(detail['new_sha256'], hashlib.sha256(revised).hexdigest())
                before = json.loads(previous)['records']
                after = json.loads(revised)['records']
                self.assertEqual(543, len(after))
                for old, new in zip(before, after):
                    source = evidence.get((edition, old['code']))
                    if source is None:
                        self.assertEqual(old, new)
                        continue
                    for field in ('code', 'name', 'state_name', 'candidates', 'electors',
                                  'votes_polled', 'valid_candidate_votes', 'status',
                                  'summary_totals', 'summary_page', 'detail_page'):
                        self.assertEqual(old.get(field), new.get(field), (year, old['code'], field))
                    self.assertEqual(source['result'], new['summary_result'])
                    self.assertEqual(source['summary'], new['official_summary_constituency_name'])
                    self.assertEqual(old['error'], new['original_extraction_warning'])
                    self.assertEqual(TRUNCATION_NOTE if old['code'] == 253 else old['error'], new['error'])


if __name__ == '__main__':
    unittest.main()
