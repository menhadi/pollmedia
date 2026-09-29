import io
import json
import hashlib
import sqlite3
import unittest

from prepare_amenity_pages import prepare_sheet, prepare_preamble


class AmenityPagesTest(unittest.TestCase):
    def setUp(self):
        self.db = sqlite3.connect(':memory:')
        self.addCleanup(self.db.close)
        self.db.execute('CREATE TABLE raw_rows (workbook_sha256, sheet, source_row, cells_json, cell_types_json)')

    def put(self, number, cells, types):
        self.db.execute('INSERT INTO raw_rows VALUES (?,?,?,?,?)',
                        ('hash', 'Hamlet_Test', number, json.dumps(cells), json.dumps(types)))

    def test_sparse_typed_rows_notes_and_page_roundtrip(self):
        self.put(1, ['Title'], ['s'])
        self.put(2, ['Code', 'Value'], ['s', 's'])
        self.put(4, ['001', None], ['s', 'n'])
        self.put(8, ['001', 0, '0'], ['s', 'n', 's'])
        self.put(9, ['002', '=A1', '#VALUE!'], ['s', 'f', 'e'])
        stream = io.BytesIO()
        sheet, notes = prepare_sheet(self.db, stream, 'hash', 'Hamlet_Test', 2, page_size=2)
        self.assertEqual(sheet['headers'], ['Code', 'Value', None])
        self.assertEqual(sheet['row_count'], 3)
        self.assertEqual(len(sheet['pages']), 2)
        rows = []
        for page in sheet['pages']:
            body = stream.getvalue()[page['offset']:page['offset'] + page['length']]
            self.assertEqual(hashlib.sha256(body).hexdigest(), page['sha256'])
            rows.extend(json.loads(body))
        self.assertEqual([r['source_row'] for r in rows], [4, 8, 9])
        self.assertEqual(rows[1]['cells'], ['001', 0, '0'])
        self.assertIsNone(rows[0]['cells'][1])
        self.assertEqual(rows[2]['formula_columns'], [1])
        self.assertEqual(rows[2]['error_columns'], [2])
        visible = prepare_preamble(stream, 'Hamlet_Test', notes)
        self.assertEqual(visible['row_count'], 1)
        self.assertEqual(visible['original_worksheet'], 'Hamlet_Test')

    def test_missing_header_and_misaligned_types_rejected(self):
        self.put(3, ['data'], ['s'])
        with self.assertRaisesRegex(ValueError, 'header row missing'):
            prepare_sheet(self.db, io.BytesIO(), 'hash', 'Hamlet_Test', 2)
        self.db.execute('DELETE FROM raw_rows')
        self.put(1, ['header'], [])
        with self.assertRaisesRegex(ValueError, 'alignment'):
            prepare_sheet(self.db, io.BytesIO(), 'hash', 'Hamlet_Test', 1)

    def test_duplicate_source_locator_rejected(self):
        self.put(1, ['header'], ['s'])
        self.put(2, ['a'], ['s'])
        self.put(2, ['b'], ['s'])
        with self.assertRaisesRegex(ValueError, 'Duplicate'):
            prepare_sheet(self.db, io.BytesIO(), 'hash', 'Hamlet_Test', 1)


if __name__ == '__main__':
    unittest.main()
