import unittest
from extract_mgnrega_html import parse


class EvidenceTests(unittest.TestCase):
    def test_headers_spans_entities_and_raw_strings_have_exact_locators(self):
        source = '<table>\n<tr><th rowspan="2">District</th><th colspan="2">Work</th></tr><tr><th>HH</th><th>Days</th></tr><tr><td>A &amp; B</td><td>0012</td><td>0<br>NA</td></tr></table>'
        cells = parse(source)
        self.assertEqual([c['text'] for c in cells], ['District','Work','HH','Days','A & B','0012','0\nNA'])
        self.assertEqual(cells[0]['attributes'], [('rowspan','2')])
        self.assertEqual(cells[-1]['row'], 3)
        for cell in cells:
            self.assertEqual(source[cell['start_character']:cell['end_character']],cell['raw_html'])

    def test_nested_table_has_distinct_locators_and_preserved_parent_markup(self):
        cells = parse('<table><tr><td>Outer<table><tr><td>Inner</td></tr></table>End</td></tr></table>')
        self.assertEqual([c['table'] for c in cells], [1,2])
        self.assertEqual(cells[0]['text'], 'OuterEnd')
        self.assertIn('<td>Inner</td>', cells[0]['raw_html'])

    def test_malformed_markup_fails_instead_of_inventing_cells(self):
        for source in ('<table><tr><td>Missing close</table>', '<table><td>No row</td></table>', '<table></table>'):
            with self.assertRaises(ValueError): parse(source)


if __name__ == '__main__': unittest.main()
