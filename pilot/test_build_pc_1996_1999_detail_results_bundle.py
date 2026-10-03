"""Verify the guarded PC detailed-result correction package before upload."""

import hashlib
import io
import json
from pathlib import Path
import unittest
import zipfile

from audit_pc_1996_1999_detail_gaps import EDITIONS, source_check
from build_pc_1996_1999_detail_results_bundle import NAME, NOTE, ROOT


class DetailedPcResultBundleTest(unittest.TestCase):
    def test_source_pages_and_all_revised_rows(self):
        bundle = ROOT / 'exports' / (NAME + '.zip')
        expected = bundle.with_suffix('.sha256').read_text(encoding='ascii').split()[0]
        self.assertEqual(expected, hashlib.sha256(bundle.read_bytes()).hexdigest())
        with zipfile.ZipFile(bundle) as outer:
            audit = json.loads(outer.read('AUDIT.json'))
            self.assertEqual(3, len(audit['editions']))
            self.assertIn('10485760', outer.read('IMPORT.sh').decode())
            self.assertIn('--allow-revision', outer.read('IMPORT.sh').decode())
            for year, edition in EDITIONS.items():
                detail = next(item for item in audit['editions'] if item['edition'] == edition)
                original = (ROOT / 'application/storage/app/private/election-archive' / edition / 'extraction.json').read_bytes()
                self.assertEqual(detail['previous_sha256'], hashlib.sha256(original).hexdigest())
                evidence = {row['code']: row for row in source_check(year, edition)}
                self.assertEqual(15, len(evidence))
                snapshot_name = f'snapshot-{edition}.zip'
                correction_name = f'correction-{edition}.zip'
                for filename in (snapshot_name, correction_name):
                    with zipfile.ZipFile(io.BytesIO(outer.read(filename))) as inner:
                        self.assertEqual(1, len(json.loads(inner.read('manifest.json'))['files']))
                with zipfile.ZipFile(io.BytesIO(outer.read(snapshot_name))) as snapshot:
                    self.assertEqual(original, snapshot.read(f'election-archive/{edition}/extraction-{detail["previous_sha256"]}.json'))
                with zipfile.ZipFile(io.BytesIO(outer.read(correction_name))) as correction:
                    manifest = json.loads(correction.read('manifest.json'))['files'][0]
                    self.assertEqual(detail['previous_sha256'], manifest['replaces_sha256'])
                    revised = correction.read(f'election-archive/{edition}/extraction.json')
                    self.assertEqual(detail['new_sha256'], hashlib.sha256(revised).hexdigest())
                    old_records = json.loads(original)['records']
                    new_records = json.loads(revised)['records']
                    self.assertEqual(len(old_records), len(new_records))
                    for old, new in zip(old_records, new_records):
                        if old['code'] not in evidence:
                            self.assertEqual(old, new)
                            continue
                        for field in ('code', 'official_pc_code', 'name', 'state_name', 'constituency_name',
                                      'number_of_seats', 'status', 'candidates', 'electors', 'votes_polled',
                                      'valid_candidate_votes', 'detail_page'):
                            self.assertEqual(old.get(field), new.get(field), (year, old['code'], field))
                        self.assertEqual(old['error'], new['original_extraction_warning'])
                        self.assertEqual(NOTE, new['error'])
                        self.assertEqual('needs_review', new['status'])
                        self.assertEqual(evidence[old['code']]['result'], new['detail_verified_result'])
                        for field in ('electors', 'votes_polled', 'valid_candidate_votes', 'source_page'):
                            self.assertEqual(evidence[old['code']][field], new['detail_verified_totals'][field])


if __name__ == '__main__':
    unittest.main()
