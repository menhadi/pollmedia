import hashlib
import json
from contextlib import closing
from pathlib import Path
import sqlite3
import tempfile
import unittest

from build_census_staging_db import build


class CensusStagingTest(unittest.TestCase):
    def source(self, root, bad_page=False):
        identity = '1991-123-' + 'a' * 16
        folder = root / identity
        folder.mkdir()
        rows = [{'source_row': 2, 'cells': ['A', 3], 'flags': [],
                 'formula_columns': [], 'error_columns': []}]
        body = json.dumps(rows).encode()
        (folder / 'pages.jsonl').write_bytes(body + b'\n')
        metadata = {'id': identity, 'year': 1991, 'population_group': 'Rural',
                    'area_as_recorded': 'TEST', 'source_url': 'https://censusindia.gov.in/source',
                    'source_sha256': '1' * 64, 'extraction_sha256': '2' * 64,
                    'scope_note': 'Historical source cells', 'pages_sha256': hashlib.sha256(body + b'\n').hexdigest(),
                    'sheets': [{'name': 'PCA', 'headers': ['area', 'population'], 'row_count': 1,
                                'pages': [{'offset': 0, 'length': len(body),
                                           'sha256': ('0' * 64 if bad_page else hashlib.sha256(body).hexdigest())}]}]}
        manifest = json.dumps(metadata).encode()
        (folder / 'manifest.json').write_bytes(manifest)
        (root / 'index.json').write_text(json.dumps({'sources': [{'id': identity,
            'manifest_sha256': hashlib.sha256(manifest).hexdigest()}], 'pending': []}))
        return identity

    def test_builds_isolated_verified_rows(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory) / 'tables'
            root.mkdir()
            identity = self.source(root)
            output = Path(directory) / 'staging.sqlite'
            self.assertEqual(build(root, output), {'sources': 1, 'rows': 1,
                                                   'pending': 0, 'integrity': 'ok'})
            with closing(sqlite3.connect(output)) as db:
                self.assertEqual(db.execute('SELECT source_url FROM sources').fetchone()[0],
                                 'https://censusindia.gov.in/source')
                self.assertEqual(db.execute('SELECT cells_json FROM source_rows').fetchone()[0], '["A",3]')
            with self.assertRaises(FileExistsError):
                build(root, output)

    def test_rejects_page_checksum_mismatch_without_database(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory) / 'tables'
            root.mkdir()
            self.source(root, bad_page=True)
            output = Path(directory) / 'staging.sqlite'
            with self.assertRaises(ValueError):
                build(root, output)
            self.assertFalse(output.exists())


if __name__ == '__main__':
    unittest.main()
