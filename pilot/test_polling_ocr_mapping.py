import unittest

from polling_ocr_mapping import propose_rows


def word(text, x, y, confidence=96):
    return {'text': str(text), 'left': x, 'top': y, 'width': 26, 'height': 14,
            'confidence': confidence, 'block': 1, 'paragraph': 1, 'line': 1}


def sample_page():
    words = [word('Station', 120, 100), word('Valid', 700, 100),
             word('Rejected', 900, 100), word('NOTA', 1100, 100),
             word('Total', 1300, 100), word('Alice', 270, 125),
             word('Kumar', 285, 145), word('Bob', 470, 125),
             word('Singh', 485, 145)]
    for y, station, alice, bob, valid, rejected, nota, total in [
        (180, 1, 30, 20, 50, 1, 2, 53),
        (220, 2, 15, 10, 25, 0, 1, 26),
        (260, 3, 10, 10, 20, 1, 1, 22),
    ]:
        words.extend(word(value, x, y) for x, value in [
            (200, station), (300, alice), (500, bob), (700, valid),
            (900, rejected), (1100, nota), (1300, total)])
    return {'page': 1, 'source_sha256': 'a' * 64, 'source_url': 'https://example.gov.in/form20.pdf',
            'quality': 'unverified_ocr', 'ocr_error': None, 'words': words}


class StrictOcrMappingTest(unittest.TestCase):
    def test_uses_rightmost_valid_total_heading_before_rejected(self):
        page = sample_page()
        page['words'].insert(0, word('Valid', 300, 65, 99))
        self.assertEqual([row['polling_station'] for row in propose_rows(page)], ['1', '2', '3'])

    def test_uses_result_column_total_with_multiline_station_header(self):
        page = sample_page()
        page['words'].append(word('Total', 70, 25, 99))
        next(w for w in page['words'] if w['text'] == 'Station')['top'] = 45
        self.assertEqual([row['polling_station'] for row in propose_rows(page)], ['1', '2', '3'])
        page['words'] = [w for w in page['words'] if not (w['text'] == 'Total' and w['left'] == 1300)]
        self.assertEqual(propose_rows(page), [])

    def test_maps_only_explicit_reconciled_cells_with_evidence(self):
        rows = propose_rows(sample_page())
        self.assertEqual(len(rows), 3)
        self.assertEqual(rows[0]['candidate_votes'], [
            {'name': 'Alice Kumar', 'votes': 30}, {'name': 'Bob Singh', 'votes': 20}])
        self.assertEqual(rows[1]['rejected_votes'], 0)
        self.assertEqual(rows[0]['total_votes'], 53)
        self.assertEqual(len(rows[0]['ocr_word_boxes']), 7)
        self.assertEqual(rows[0]['quality'], 'unverified_ocr')

    def test_does_not_infer_missing_zero_or_repair_discrepancies(self):
        page = sample_page()
        page['words'] = [w for w in page['words'] if not (w['top'] == 220 and w['left'] == 900)]
        page['words'] = [w for w in page['words'] if not (w['top'] == 260 and w['left'] == 500)]
        rows = propose_rows(page)
        self.assertEqual([r['polling_station'] for r in rows], ['1'])

    def test_rejects_unverified_names_and_bad_totals(self):
        page = sample_page()
        page['words'] = [w for w in page['words'] if w['text'] != 'Kumar']
        self.assertEqual(propose_rows(page), [])
        page = sample_page()
        next(w for w in page['words'] if w['left'] == 1300 and w['top'] == 180)['text'] = '54'
        self.assertEqual([r['polling_station'] for r in propose_rows(page)], ['2', '3'])

    def test_rejects_low_quality_page_or_duplicate_cell(self):
        page = sample_page()
        page['quality'] = 'needs_visual_review'
        self.assertEqual(propose_rows(page), [])
        page = sample_page()
        page['words'].append(word(30, 305, 180))
        self.assertEqual([r['polling_station'] for r in propose_rows(page)], ['2', '3'])


if __name__ == '__main__':
    unittest.main()
