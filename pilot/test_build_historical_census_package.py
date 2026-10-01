import hashlib
import json
from pathlib import Path
import tempfile
import unittest
import zipfile

import openpyxl
from build_historical_census_package import build
from extract_historical_a02 import extract


class HistoricalPackageTest(unittest.TestCase):
    def test_checksums_and_rejects_extraction_tampering(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            original = root/'43334.xlsx'
            book = openpyxl.Workbook()
            sheet = book.active
            sheet.title = 'A-2'
            sheet.append(['A-02'])
            sheet.append(['State Code', 'District Code', 'State/District', 'Census Year', 'Persons'])
            sheet.append([None]); sheet.append([None])
            sheet.append(['01', '000', 'Printed state', 1901, 10, None, None, 5, 5])
            sheet.append([None, None, None, 1911, 12, None, None, 6, 6])
            book.save(original)
            manifest = dict(sha256=hashlib.sha256(original.read_bytes()).hexdigest(), boundary_basis='2011 jurisdictions',
                            url='https://censusindia.gov.in/nada/index.php/catalog/43334/download/1/test.xlsx',
                            landing='https://censusindia.gov.in/nada/index.php/catalog/43334')
            (root/'43334.manifest.json').write_text(json.dumps(manifest))
            data = extract(original, manifest)
            payload = root/'43334.1901-1911.json'
            payload.write_text(json.dumps(data))
            result = build(root, root/'package.zip')
            self.assertEqual(result['source_records'], 2)
            with zipfile.ZipFile(root/'package.zip') as archive:
                metadata = json.loads(archive.read('manifest.json'))
                for member, digest in metadata['files'].items():
                    self.assertEqual(hashlib.sha256(archive.read(member)).hexdigest(), digest)
            data['records'][0]['persons'] = 999
            payload.write_text(json.dumps(data))
            with self.assertRaisesRegex(ValueError, 'differs'):
                build(root, root/'tampered.zip')


if __name__ == '__main__':
    unittest.main()
