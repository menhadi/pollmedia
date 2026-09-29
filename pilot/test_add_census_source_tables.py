import json
from pathlib import Path
import tempfile
import unittest
from add_census_source_tables import digest, install


class AddTablesTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        root = Path(self.tmp.name)
        self.src, self.dst, self.backup = root/'source', root/'live', root/'backup.json'
        self.src.mkdir(); self.dst.mkdir()
        self.identity = '2011-1184-0123456789abcdef'
        folder = self.src/self.identity; folder.mkdir()
        (folder/'pages.jsonl').write_text('[]\n')
        (folder/'manifest.json').write_text(json.dumps({'id': self.identity, 'pages_sha256': digest(folder/'pages.jsonl')}))
        self.entry = {'id': self.identity, 'manifest_sha256': digest(folder/'manifest.json')}
        (self.src/'index.json').write_text(json.dumps({'sources': [self.entry]}))
        self.old = {'sources': [{'id': '1991-1-aaaaaaaaaaaaaaaa'}], 'pending': [], 'scope_note': 'preserve'}
        (self.dst/'index.json').write_text(json.dumps(self.old))
        self.sha = digest(self.dst/'index.json')

    def test_addition_preserves_prior_index_and_backup(self):
        result = install(self.src, self.dst, self.backup, self.sha)
        current = json.loads((self.dst/'index.json').read_text())
        self.assertEqual(current['sources'][0], self.old['sources'][0])
        self.assertEqual(current['scope_note'], 'preserve')
        self.assertEqual(digest(self.backup), self.sha)
        self.assertEqual(result['new_count'], 2)

    def test_stale_index_rejected(self):
        with self.assertRaisesRegex(ValueError, 'index changed'):
            install(self.src, self.dst, self.backup, '0'*64)
        self.assertEqual(digest(self.dst/'index.json'), self.sha)

    def test_collision_rejected(self):
        (self.dst/self.identity).mkdir()
        with self.assertRaisesRegex(ValueError, 'collision'):
            install(self.src, self.dst, self.backup, self.sha)
        self.assertEqual(digest(self.dst/'index.json'), self.sha)

    def test_corruption_rejected(self):
        (self.src/self.identity/'pages.jsonl').write_text('corrupt')
        with self.assertRaisesRegex(ValueError, 'checksum'):
            install(self.src, self.dst, self.backup, self.sha)
        self.assertEqual(digest(self.dst/'index.json'), self.sha)


if __name__ == '__main__':
    unittest.main()
