import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
from extract_assembly_legacy import extract, parse_body, run

ROW = '. ONE\nM\nAAA\n60\n60.00%\n1\n. TWO\nF\nBBB\n40\n40.00%\n2\n'
TOTAL = 'ELECTORS :\n150\n66.67%\nVALID VOTES :\n100\nVOTERS :\n100\nPOLL PERCENTAGE :'

class Page:
    def __init__(self, text): self.text = text
    def get_text(self): return self.text

class Document(list):
    def __enter__(self): return self
    def __exit__(self, *args): pass

class LegacyAssemblyTest(unittest.TestCase):
    def test_preserves_votes_without_inventing_components_or_validation(self):
        record = parse_body(ROW + TOTAL)
        self.assertEqual([c['votes'] for c in record['candidates']], [60,40])
        self.assertIsNone(record['candidates'][0]['postal_votes'])
        self.assertEqual(record['status'], 'needs_review')
        self.assertNotIn('winner', record)

    def test_missing_totals_and_partial_rows_stay_visible(self):
        record = parse_body(ROW + '. BROKEN ROW')
        self.assertEqual(len(record['candidates']), 2)
        self.assertNotIn('electors', record)
        self.assertIn('could not be parsed', record['error'])

    def test_multi_member_and_discrepancy_have_notes(self):
        record = parse_body('NUMBER OF SEATS 2\n' + ROW + TOTAL.replace('100\nVOTERS', '101\nVOTERS'))
        self.assertIn('Multi-member', record['error'])
        self.assertIn('do not match', record['error'])
        self.assertNotIn('margin', record)

    def test_cross_page_rows_keep_original_page_and_flag_page_gap(self):
        pages = Document([Page('DETAILED RESULTS\nConstituency :\n1 . SAMPLE\n' + ROW + 'rptDetailedResults - Page 1 of 3'), Page(TOTAL + '\nrptDetailedResults - Page 3 of 3')])
        with patch('extract_assembly_legacy.fitz.open', return_value=pages):
            record = extract('report.pdf', 'Example')[0]
        self.assertEqual(record['detail_page'], 1)
        self.assertEqual(record['valid_candidate_votes'], 100)
        self.assertIn('pages are missing', record['error'])

    def test_other_layout_rejected(self):
        with patch('extract_assembly_legacy.fitz.open', return_value=Document([Page('VALID VOTES POLLED\nrptDetailedResults - Page 1 of 1')])):
            with self.assertRaisesRegex(ValueError, 'Different'):
                extract('report.pdf', 'Example')

    def test_existing_extraction_never_overwritten(self):
        import hashlib
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory); folder = root/hashlib.sha256(b'https://example.test').hexdigest()[:24]; folder.mkdir()
            target = folder/'extraction.json'; target.write_text('original')
            self.assertIsNone(run({'url':'https://example.test'},root))
            self.assertEqual(target.read_text(),'original')

if __name__ == '__main__': unittest.main()
