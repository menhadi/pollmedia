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
