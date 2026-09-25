import unittest
from extract_dchb_crosswalk import page_rows


class DchbCrosswalkTest(unittest.TestCase):
    def page(self, row):
        return {'page': 150, 'text_sha256':'a'*64, 'text':
                'Alphabetical list of Villages (CD Block Wise)\nName of District: Pilibhit\nName of CD BLOCK: Lalaurikhera\n2011 CENSUS LOCATION    2001 CENSUS LOCATION\n' + row}

    def test_preserves_codes_names_and_source_location(self):
        rows = page_rows(self.page('  1   Aaraji Mathu Dandi       131593       02440600'))
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0]['census_2001_code'], '02440600')
        self.assertEqual(rows[0]['name_as_printed'], 'Aaraji Mathu Dandi')
        self.assertEqual(rows[0]['page'], 150)

    def test_rejects_missing_code_and_unscoped_page(self):
        self.assertEqual(page_rows(self.page('  1   Village       131593       -')), [])
        page = self.page('  1   Village       131593       02440600')
        page['text'] = page['text'].replace('2001 CENSUS LOCATION', 'Amenities in 2009')
        self.assertEqual(page_rows(page), [])


if __name__ == '__main__':
    unittest.main()
