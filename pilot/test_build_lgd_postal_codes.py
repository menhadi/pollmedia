import json
import pathlib
import sqlite3
import tempfile
import unittest
from contextlib import closing

from build_lgd_postal_codes import build


class PostalBuildTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = pathlib.Path(self.temp.name)
        self.rural = [dict(stateCode=9, stateNameEnglish='Uttar Pradesh', districtCode=173,
                           districtNameEnglish='Pilibhit', subdistrictCode=1,
                           subdistrictNameEnglish='Example', villageCode=100,
                           villageNameEnglish='Example', pincode=262001)]
        self.urban = [dict(stateCode=9, stateNameEnglish='Uttar Pradesh', localBodyCode=100,
                           localBodyNameEnglish='Example Municipality', pincode=262002)]

    def tearDown(self):
        self.temp.cleanup()

    def page(self, prefix, rows, total=None, offset=0):
        (self.root / f'{prefix}-{offset}.json').write_text(json.dumps(dict(
            total=len(rows) if total is None else total, records=rows, updated_date='2026-11-01T00:00:00Z')))

    def test_preserves_multiple_pins_and_unusable_source_values(self):
        self.rural += [dict(self.rural[0], pincode=262003), dict(self.rural[0], villageCode=101, pincode=0),
                       dict(self.rural[0], villageCode=102, pincode='12345')]
        self.page('rural', self.rural)
        self.page('urban', self.urban)
        path = self.root / 'links.sqlite'
        build(self.root, path)
        with closing(sqlite3.connect(path)) as db:
            self.assertEqual(db.execute("SELECT pin FROM places WHERE kind='village' AND code='100' ORDER BY pin").fetchall(), [('262001',), ('262003',)])
            self.assertEqual(db.execute("SELECT status,raw_pin,pin FROM places WHERE code='101'").fetchone(), ('missing', '0', None))
            self.assertEqual(db.execute("SELECT status FROM places WHERE code='102'").fetchone()[0], 'invalid')
            self.assertEqual(db.execute("SELECT pin FROM places WHERE kind='local-body' AND code='100'").fetchone()[0], '262002')

    def test_incomplete_release_is_not_published(self):
        self.page('rural', self.rural, total=2)
        path = self.root / 'links.sqlite'
        with self.assertRaises(FileNotFoundError):
            build(self.root, path)
        self.assertFalse(path.exists())

    def test_unstable_page_order_is_rejected(self):
        self.page('rural', self.rural, total=2)
        self.page('rural', [dict(self.rural[0], villageCode=99)], total=2, offset=1)
        path = self.root / 'links.sqlite'
        with self.assertRaisesRegex(ValueError, 'unstable page order'):
            build(self.root, path)
        self.assertFalse(path.exists())


if __name__ == '__main__':
    unittest.main()
