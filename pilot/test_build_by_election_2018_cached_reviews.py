import copy
import json
from pathlib import Path
import shutil
import tempfile
import unittest
import zipfile

import build_by_election_2018_cached_reviews as builder


class CachedReviewTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.folder = self.root / 'application/storage/app/private'
        real = builder.ROOT / 'application/storage/app/private'
        index = json.loads((real / (builder.PREFIX + 'index.json')).read_bytes())
        paths = [builder.PREFIX + 'index.json', 'election-by-elections/' + builder.EDITION + '/' + builder.SOURCE_SHA + '.xlsx']
        paths += [builder.PREFIX + r['file'] for r in index['records'] if r['id'] in builder.TARGETS]
        for path in paths:
            dest = self.folder / path
            dest.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(real / path, dest)
        (self.root / 'exports').mkdir()

    def test_reviews_preserve_originals_and_unrelated_index_entries(self):
        old, new, originals, additions, reviews = builder.revised_files(self.root)
        before, after = json.loads(old), json.loads(new)
        self.assertEqual(len(before['records']), len(after['records']))
        for first, second in zip(before['records'], after['records']):
            if first['id'] not in builder.TARGETS:
                self.assertEqual(first, second)
            else:
                other = copy.deepcopy(second)
                other.update(file=first['file'], sha256=first['sha256'])
                self.assertEqual(first, other)
        for review in reviews:
            prior = json.loads(originals[review['prior_path']])
            revised = json.loads(additions[review['path']])
            checked = revised.pop('source_review')
            self.assertEqual(prior, revised)
            self.assertTrue(any(c['votes'] is None for c in revised['candidates']))
            self.assertEqual(sum(c['votes'] for c in checked['candidates']), checked['totals']['valid_candidate_votes'])
            if review['id'].startswith('d57'):
                self.assertIn('source dates conflict', ' '.join(checked['notes']))

    def test_conflicting_index_is_refused(self):
        path = self.folder / (builder.PREFIX + 'index.json')
        path.write_bytes(path.read_bytes() + b' ')
        with self.assertRaisesRegex(ValueError, 'index differs'):
            builder.revised_files(self.root)

    def test_changed_workbook_is_refused(self):
        path = self.folder / 'election-by-elections' / builder.EDITION / (builder.SOURCE_SHA + '.xlsx')
        path.write_bytes(path.read_bytes() + b' ')
        with self.assertRaisesRegex(ValueError, 'workbook checksum'):
            builder.revised_files(self.root)

    def test_bundle_checksums_snapshot_and_import_order(self):
        result = builder.build(self.root)
        path = Path(result['bundle'])
        self.assertEqual(builder.digest(path.read_bytes()), result['sha256'])
        with zipfile.ZipFile(path) as bundle:
            for line in bundle.read('SHA256SUMS').decode().splitlines():
                sha, name = line.split('  ')
                self.assertEqual(builder.digest(bundle.read(name)), sha)
            for name in bundle.namelist():
                if not name.endswith('.zip'):
                    continue
                from io import BytesIO
                with zipfile.ZipFile(BytesIO(bundle.read(name))) as inner:
                    manifest = json.loads(inner.read('manifest.json'))
                    for entry in manifest['files']:
                        self.assertEqual(builder.digest(inner.read(entry['path'])), entry['sha256'])
                        if name == 'correction-index.zip':
                            self.assertEqual(entry['replaces_sha256'], builder.INDEX_SHA)
                            self.assertTrue(entry['previous_path'].endswith(builder.INDEX_SHA + '.json'))
            script = bundle.read('IMPORT.sh').decode()
            self.assertLess(script.index('snapshot-*.zip addition-*.zip'), script.index('file=correction-index.zip'))
            for guard in ['flock -n', '10551296', 'pgrep -af', 'PREFLIGHT.php', '--allow-revision']:
                self.assertIn(guard, script)
        with self.assertRaises(FileExistsError):
            builder.build(self.root)


if __name__ == '__main__':
    unittest.main()
