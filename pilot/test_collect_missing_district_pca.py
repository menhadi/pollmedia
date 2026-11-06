import hashlib
import json
import ssl
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import openpyxl
from collect_missing_district_pca import GEO, stage


class DistrictPcaStagingTest(unittest.TestCase):
    def prepare(self, root, rows):
        folder = root / '6337'
        folder.mkdir()
        (folder / 'related-materials.html').write_text('<a href="https://censusindia.gov.in/nada/index.php/catalog/6337/download/9414" data-filename="DDW_PCA0915_2011_MDDS with UI.xlsx">Download</a>')
        workbook = openpyxl.Workbook()
        sheet = workbook.active
        sheet.title = 'EB-0915'
        sheet.append(GEO + ['No_HH', 'TOT_P', 'TOT_M', 'TOT_F', 'P_06', 'P_LIT'])
        for row in rows:
            sheet.append(row)
        workbook.save(folder / 'original.xlsx')
        workbook.close()
        (folder / 'retrieved-at.txt').write_text('2026-10-13 00:00:00')
        return {'landing': 'https://censusindia.gov.in/nada/index.php/catalog/6337', 'title': 'Agra 2011'}

    def row(self):
        return ['09', '146', '00765', '124462', '0000', '000000', 'VILLAGE', 'Sorai', 'Rural', 10, 50, 25, 25, 5, 30]

    def test_preserves_geography_original_and_reuses_checksums(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            entry = self.prepare(root, [self.row()])
            raw = (root / '6337/original.xlsx').read_bytes()
            with patch('urllib.request.urlopen', side_effect=AssertionError('Existing original must be reused')):
                item = stage(entry, root, ssl.create_default_context())
                self.assertEqual(item, stage(entry, root, ssl.create_default_context()))
            self.assertEqual(item['sha256'], hashlib.sha256(raw).hexdigest())
            data = json.loads((root / '6337/extracted.json').read_text())
            self.assertEqual(data['rows'][0]['Town/Village'], '124462')
            self.assertEqual(data['rows'][0]['State'], '09')
            self.assertEqual(item['village_rows'], 1)
            (root / '6337/original.xlsx').write_bytes(b'changed')
            with self.assertRaisesRegex(ValueError, 'checksum changed'):
                stage(entry, root, ssl.create_default_context())

    def test_rejects_duplicate_complete_identity(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            entry = self.prepare(root, [self.row(), self.row()])
            with self.assertRaisesRegex(ValueError, 'Duplicate complete'):
                stage(entry, root, ssl.create_default_context())

    def test_rejects_wrong_state_identity(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            row = self.row()
            row[0] = '08'
            entry = self.prepare(root, [row])
            with self.assertRaisesRegex(ValueError, 'Workbook state differs'):
                stage(entry, root, ssl.create_default_context())

    def test_rejects_download_from_another_origin(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            entry = self.prepare(root, [self.row()])
            page = root / '6337/related-materials.html'
            page.write_text(page.read_text().replace('censusindia.gov.in/nada/index.php/catalog/6337/download', 'example.org/download'))
            with self.assertRaisesRegex(ValueError, 'Download origin'):
                stage(entry, root, ssl.create_default_context())


if __name__ == '__main__':
    unittest.main()
