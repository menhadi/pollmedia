import unittest
from pathlib import Path
from tempfile import TemporaryDirectory

import cv2
import numpy as np

from cell_ocr_form20 import extract_page, grid_lines, reconciled_rows


class Form20CellAdapterTest(unittest.TestCase):
    def test_refuses_changed_original_pdf(self):
        with TemporaryDirectory() as directory:
            pdf = Path(directory) / 'source.pdf'
            pdf.write_bytes(b'changed source')
            with self.assertRaisesRegex(ValueError, 'checksum changed'):
                extract_page(pdf, 1, 'https://example.gov.in/form20.pdf', '0' * 64)

    def test_detects_ruled_grid_with_tall_header(self):
        image = np.full((600, 800), 255, dtype=np.uint8)
        xs = list(range(50, 751, 50))
        ys = [100, 200, 225, 250, 275, 300, 325, 350]
        for x in xs:
            cv2.line(image, (x, ys[0]), (x, ys[-1]), 0, 2)
        for y in ys:
            cv2.line(image, (xs[0], y), (xs[-1], y), 0, 2)
        grid = grid_lines(image)
        self.assertIsNotNone(grid)
        self.assertEqual(grid[1:3], (100, 200))
        self.assertEqual(len(grid[3]), 6)
        self.assertEqual(len(grid[0]), len(xs))

    def test_reconciles_only_complete_rows_and_keeps_headers_unverified(self):
        rows = [(200, 225), (225, 250), (250, 275)]
        values = {
            (0, 0): 1, (0, 1): 1, (0, 2): 30, (0, 3): 20, (0, 4): 50,
            (0, 5): 1, (0, 6): 2, (0, 7): 53, (0, 8): 0,
            (1, 0): 2, (1, 1): 2, (1, 2): 15, (1, 3): 10, (1, 4): 25,
            (1, 5): 0, (1, 6): 1, (1, 7): 26, (1, 8): 0,
            (2, 0): 3, (2, 1): 3, (2, 2): 10, (2, 3): 10, (2, 4): 20,
            (2, 5): 1, (2, 6): 1, (2, 7): 23, (2, 8): 0,
        }
        proposals = reconciled_rows(values, rows, 4, ['Alice Kumar', 'Bob Singh'], 1,
                                    'a' * 64, 'https://example.gov.in/form20.pdf', list(range(0, 500, 50)))
        self.assertEqual([row['polling_station'] for row in proposals], ['1', '2'])
        self.assertEqual(proposals[0]['candidate_columns'][0],
                         {'column': 2, 'ocr_header': 'Alice Kumar', 'votes': 30})
        self.assertNotIn('candidate_votes', proposals[0])
        self.assertEqual(len(proposals[0]['source_cells']), 9)
        self.assertEqual(proposals[0]['quality'], 'unverified_cell_ocr')
        values[1, 1] = 9
        self.assertEqual([row['polling_station'] for row in reconciled_rows(
            values, rows, 4, ['Alice Kumar', 'Bob Singh'], 1,
            'a' * 64, 'https://example.gov.in/form20.pdf', list(range(0, 500, 50)))], ['1'])
        values[1, 1] = 2
        del values[1, 5]
        self.assertEqual(len(reconciled_rows(values, rows, 4, ['Alice Kumar', 'Bob Singh'],
                                             1, 'a' * 64, 'https://example.gov.in/form20.pdf',
                                             list(range(0, 500, 50)))), 1)


if __name__ == '__main__':
    unittest.main()
