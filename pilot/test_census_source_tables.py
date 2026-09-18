import hashlib
import io
import json
from pathlib import Path
import tempfile
import unittest
import zipfile

from build_census_source_tables import build, prepare_sheet, bundle


class CensusSourceTablesTest(unittest.TestCase):
    def test_pages_and_districts_preserve_original_cells_flags_and_row_numbers(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            rows = [{'source_row': 1, 'cells': ['District _Name', 'NAME', 'COUNT']}]
            rows += [{'source_row': i + 7, 'cells': ['A' if i < 100 else 'B', "'001", None if i == 0 else 0], 'flags': ['Review value'] if i == 0 else []} for i in range(101)]
            stream = io.BytesIO()
            result = prepare_sheet(stream, 0, {'name': 'PCA', 'rows': rows})
            self.assertEqual(101, result['row_count'])
            self.assertEqual(2, len(result['pages']))
            self.assertEqual([100, 1], [d['row_count'] for d in result['districts']])
            descriptor = result['pages'][0]
            page = json.loads(stream.getvalue()[descriptor['offset']:descriptor['offset'] + descriptor['length']])
            self.assertEqual(rows[1], page[0])
            self.assertEqual([None, 0], [r['cells'][2] for r in page[:2]])

    def test_build_retains_pending_sources_and_rejects_modified_inputs(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp); archive = root / 'archive'; output = root / 'output'; archive.mkdir()
            entry = {'year': 1991, 'name': 'Test source', 'landing': 'https://censusindia.gov.in/nada/index.php/catalog/12345', 'area_as_recorded': 'Area', 'population_group': 'Rural'}
            pending = dict(entry, landing=entry['landing'].replace('12345', '12346'))
            (archive / 'catalogue.json').write_text(json.dumps([entry, pending]), encoding='utf-8')
            folder = archive / '12345'; folder.mkdir(); raw = b'preserved source'
            (folder / 'source.xlsx').write_bytes(raw); digest = hashlib.sha256(raw).hexdigest()
            data = {'source_sha256': digest, 'landing': entry['landing'], 'scope_note': 'Original cells', 'sheets': [{'name': 'Data', 'rows': [{'source_row': 1, 'cells': ['NAME']}, {'source_row': 4, 'cells': ['Example'], 'flags': []}]}]}
            payload = json.dumps(data).encode(); (folder / 'source.json').write_bytes(payload)
            manifest = dict(entry, retrieved_at='2026-09-18', files=[{'file': 'source.xlsx', 'sha256': digest, 'extraction': 'source.json', 'extraction_sha256': hashlib.sha256(payload).hexdigest(), 'url': entry['landing']+'/download/source.xlsx'}])
            (folder / 'manifest.json').write_text(json.dumps(manifest), encoding='utf-8')
            index = build(archive, output)
            self.assertEqual([pending], index['pending'])
            self.assertEqual(1, len(index['sources']))
            self.assertEqual(index, build(archive, output))
            exported = root / 'prepared.zip'
            bundle(output, exported)
            self.assertNotIn(b'\r', exported.with_suffix('.sha256').read_bytes())
            with zipfile.ZipFile(exported) as packed:
                self.assertIsNone(packed.testzip())
                self.assertEqual(1, json.loads(packed.read('manifest.json'))['pending'])
            with self.assertRaisesRegex(ValueError, 'new bundle'):
                bundle(output, exported)
            before = (output / 'index.json').read_bytes()
            (folder / 'source.xlsx').write_bytes(b'changed')
            with self.assertRaisesRegex(ValueError, 'checksum'):
                build(archive, output)
            self.assertEqual(before, (output / 'index.json').read_bytes())


if __name__ == '__main__':
    unittest.main()
