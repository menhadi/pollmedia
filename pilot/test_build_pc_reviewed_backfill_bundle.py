"""Checks the combined PC import preserves all source-verified child packages."""

import hashlib
import json
from pathlib import Path
import sys
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_pc_reviewed_backfill_bundle import CHILDREN, NAME


ROOT = Path(__file__).resolve().parents[1]


class PcReviewedBackfillTests(unittest.TestCase):
    def test_combined_import_contains_exact_four_guarded_revisions(self):
        path = ROOT / 'exports' / (NAME + '.zip')
        self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(),
                         path.with_suffix('.sha256').read_text(encoding='ascii').split()[0])
        with zipfile.ZipFile(path) as combined:
            audit = json.loads(combined.read('AUDIT.json'))
            self.assertEqual([row['name'] for row in audit['revisions']], list(CHILDREN))
            editions = combined.read('ARCHIVES').decode('ascii').splitlines()
            self.assertEqual(len(editions), 4)
            self.assertEqual(len(set(editions)), 4)
            self.assertEqual([row['edition'] for row in audit['revisions']], editions)
            checksums = {}
            for line in combined.read('SHA256SUMS').decode('ascii').splitlines():
                digest, filename = line.split(None, 1)
                checksums[filename] = digest
                self.assertEqual(hashlib.sha256(combined.read(filename)).hexdigest(), digest)
            self.assertEqual(len(checksums), 8)
            script = combined.read('IMPORT.sh').decode('utf-8')
            self.assertIn('Less than 10 GiB free on server', script)
            self.assertIn('archive:import-json', script)
            self.assertEqual(script.count('archive:index-constituencies --check'), 1)
            self.assertEqual(script.count('archive:index-constituencies\n'), 1)
            for row in audit['revisions']:
                edition = row['edition']
                with zipfile.ZipFile(ROOT / 'exports' / (row['name'] + '.zip')) as child:
                    for kind in ('snapshot', 'correction'):
                        filename = f'{kind}-{edition}.zip'
                        self.assertEqual(combined.read(filename), child.read(filename))


if __name__ == '__main__':
    unittest.main()
