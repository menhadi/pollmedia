import hashlib
import json
import tempfile
import unittest
import zipfile
from pathlib import Path

from verify_original_pca_package import verify


class OriginalPcaPackageTest(unittest.TestCase):
    def setUp(self):
        self.package = Path(__file__).resolve().parents[1] / 'exports/historical-1961-20261001/kerala-original-pca-evidence-1961.zip'
        if not self.package.exists():
            self.skipTest('Original PCA evidence package unavailable')

    def test_preserves_six_original_source_rows(self):
        manifest, rows = verify(self.package, hashlib.sha256(self.package.read_bytes()).hexdigest())
        self.assertEqual(6, len(rows))
        self.assertEqual(1961, manifest['year'])
        self.assertEqual('CANNANORE DISTRICT', rows[3]['original_name'])
        self.assertEqual(7919220, rows[0]['values']['P_LIT'])

    def test_rejects_changed_member_even_with_new_outer_checksum(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / 'tampered.zip'
            with zipfile.ZipFile(self.package) as source, zipfile.ZipFile(target, 'w') as destination:
                for name in source.namelist():
                    raw = source.read(name)
                    if name.endswith('pca-mapping-audit-20261001T1901.json'):
                        raw = raw.replace(b'7919220', b'7919221')
                    destination.writestr(name, raw)
            with self.assertRaisesRegex(ValueError, 'Member checksum mismatch'):
                verify(target, hashlib.sha256(target.read_bytes()).hexdigest())

    def test_rejects_wrong_outer_checksum(self):
        with self.assertRaisesRegex(ValueError, 'Package checksum mismatch'):
            verify(self.package, '0' * 64)

    def test_nine_district_package_retains_original_discrepancy(self):
        path = self.package.with_name('kerala-original-pca-evidence-1961-v7.zip')
        if not path.exists():
            self.skipTest('Nine district evidence unavailable')
        _, rows = verify(path, hashlib.sha256(path.read_bytes()).hexdigest())
        self.assertEqual(30, len(rows))
        self.assertEqual(1428, sum(len(row['values']) for row in rows))
        self.assertEqual('I', rows[3]['original_serial'])
        self.assertEqual(932007, rows[22]['values']['NON_WORK_P'])
        self.assertEqual(982007, rows[22]['values']['NON_WORK_M'] + rows[22]['values']['NON_WORK_F'])
        self.assertTrue(any('Source discrepancy' in note for note in rows[22]['flags']))
        with zipfile.ZipFile(path) as source:
            original = {name: source.read(name) for name in source.namelist()}
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / 'changed.zip'
            for case in ['warning', 'crop']:
                members = dict(original)
                member = 'evidence/pca-mapping-audit-20261001T1901.json' if case == 'warning' else 'evidence/kerala-pca-alleppey-full-verified-candidates.json'
                evidence = json.loads(members[member])
                if case == 'warning':
                    evidence['rows'][22]['flags'] = [note for note in evidence['rows'][22]['flags'] if 'Source discrepancy' not in note]
                else:
                    evidence['digit_review_render']['sha256'] = '0' * 64
                members[member] = json.dumps(evidence).encode()
                manifest = json.loads(members['manifest.json'])
                manifest['files'][member] = hashlib.sha256(members[member]).hexdigest()
                members['manifest.json'] = json.dumps(manifest).encode()
                with zipfile.ZipFile(target, 'w', compression=zipfile.ZIP_DEFLATED) as archive:
                    for name, raw in members.items():
                        archive.writestr(name, raw)
                with self.assertRaises(ValueError):
                    verify(target, hashlib.sha256(target.read_bytes()).hexdigest())

    def test_expanded_rows_keep_exact_acreage_and_missing_measures(self):
        path = self.package.with_name('kerala-original-pca-evidence-1961-v6-candidate.zip')
        if not path.exists():
            self.skipTest('Expanded PCA evidence unavailable')
        manifest, rows = verify(path, hashlib.sha256(path.read_bytes()).hexdigest())
        self.assertEqual(24, len(rows))
        self.assertEqual('TRICHUR DISTRICT', rows[12]['original_name'])
        self.assertEqual('727694.88', rows[12]['values']['AREA_ACRES'])
        self.assertEqual(18989, rows[16]['values']['WORK_CATEGORY_IV_M'])
        self.assertNotIn('NON_WORK_P', rows[18]['values'])
        self.assertEqual(978, sum(len(r['values']) for r in rows))
        self.assertEqual('acres (original source)', manifest['measure_units']['AREA_ACRES'])

    def test_expanded_candidates_reject_semantic_drift_after_checksum_refresh(self):
        source_path = self.package.with_name('kerala-original-pca-evidence-1961-v6-candidate.zip')
        if not source_path.exists():
            self.skipTest('Expanded PCA evidence unavailable')
        with zipfile.ZipFile(source_path) as source:
            original = {name: source.read(name) for name in source.namelist()}
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / 'changed.zip'
            for case in ['identity', 'page', 'cell', 'unit', 'render']:
                with self.subTest(case=case):
                    members = dict(original)
                    manifest = json.loads(members['manifest.json'])
                    member = 'evidence/pca-mapping-audit-20261001T1901.json' if case == 'cell' else 'evidence/kerala-pca-trichur-full-verified-candidates.json'
                    evidence = json.loads(members[member])
                    if case == 'identity':
                        evidence['rows'][0]['original_serial'] = '5'
                    elif case == 'page':
                        evidence['physical_pages'][0] = 188
                    elif case == 'cell':
                        evidence['rows'][12]['values']['SC_P'] += 1
                    elif case == 'unit':
                        manifest['measure_units']['AREA_ACRES'] = 'square kilometres'
                    else:
                        evidence['render_hashes']['182'] = '0' * 64
                    members[member] = json.dumps(evidence).encode()
                    manifest['files'][member] = hashlib.sha256(members[member]).hexdigest()
                    members['manifest.json'] = json.dumps(manifest).encode()
                    with zipfile.ZipFile(target, 'w', compression=zipfile.ZIP_DEFLATED) as archive:
                        for name, raw in members.items():
                            archive.writestr(name, raw)
                    with self.assertRaises(ValueError):
                        verify(target, hashlib.sha256(target.read_bytes()).hexdigest())

    def test_discrepant_reported_value_requires_note_and_is_preserved(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / 'discrepancy.zip'
            with zipfile.ZipFile(self.package) as source:
                members = {name: source.read(name) for name in source.namelist()}
            name = 'evidence/pca-mapping-audit-20261001T1901.json'
            audit = json.loads(members[name])
            audit['rows'][0]['values']['P_LIT'] += 1
            for noted in [False, True]:
                if noted:
                    audit['rows'][0]['flags'].append('Source discrepancy: P_LIT differs from M_LIT+F_LIT; reported values preserved.')
                members[name] = json.dumps(audit).encode()
                manifest = json.loads(members['manifest.json'])
                manifest['files'][name] = hashlib.sha256(members[name]).hexdigest()
                members['manifest.json'] = json.dumps(manifest).encode()
                with zipfile.ZipFile(target, 'w') as archive:
                    for member, raw in members.items():
                        archive.writestr(member, raw)
                digest = hashlib.sha256(target.read_bytes()).hexdigest()
                if noted:
                    _, rows = verify(target, digest)
                    self.assertEqual(7919221, rows[0]['values']['P_LIT'])
                else:
                    with self.assertRaisesRegex(ValueError, 'Unnoted source discrepancy'):
                        verify(target, digest)


if __name__ == '__main__':
    unittest.main()
