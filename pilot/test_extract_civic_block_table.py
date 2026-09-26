import unittest
import hashlib
import json
import tempfile
from pathlib import Path
from unittest.mock import patch
from extract_civic_block_table import parse_page, extract

HEADER = '''APPENDIX TO DISTRICT PRIMARY CENSUS ABSTRACT
POPULATION - URBAN BLOCK WISE
Name of Town  Name of Ward  Population  Castes
'''

class BlockTableTests(unittest.TestCase):
    def test_preserves_codes_and_dash(self):
        rows, rejected = parse_page(HEADER+'800863  Aonla (NPP)  WARD No.-0001  EB No.-000100  598  -')
        self.assertFalse(rejected)
        self.assertEqual(rows[0]['total_population'], 598)
        self.assertIsNone(rows[0]['scheduled_castes_population'])
        self.assertEqual(rows[0]['block'], 'EB No.-000100')
        self.assertEqual(rows[0]['raw_cells'][-1], '-')

    def test_extra_column_is_rejected_not_shifted(self):
        rows, rejected = parse_page(HEADER+'800863  Aonla  WARD No.-0001  EB No.-000100  598  2  3')
        self.assertFalse(rows)
        self.assertEqual(len(rejected), 1)

    def test_wrong_table_fails(self):
        with self.assertRaises(ValueError): parse_page('800863  Aonla  598  2')

    def test_impossible_subtotal_retained_with_warning(self):
        rows, _ = parse_page(HEADER+'800863  Aonla  WARD No.-0001  EB No.-000100  5  12')
        self.assertIn('scheduled_castes_exceeds_total', rows[0]['warnings'])

    def test_hash_verified_output_receipt_and_tamper_detection(self):
        digest = lambda p: hashlib.sha256(Path(p).read_bytes()).hexdigest()
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder); evidence = root/'source-evidence'; evidence.mkdir()
            pdf = root/'source.pdf'; pdf.write_bytes(b'test original'); sha = digest(pdf)
            text = HEADER+'800863  Aonla  WARD No.-0001  EB No.-000100  5  -'
            source = evidence/('pdf-'+sha+'.pages.jsonl')
            source.write_text(json.dumps(dict(page=1,text=text,text_sha256=hashlib.sha256(text.encode()).hexdigest()))+'\n')
            (evidence/('pdf-'+sha+'.manifest.json')).write_text(json.dumps(dict(
                original_sha256=sha,pages_jsonl_sha256=digest(source),source_url='https://censusindia.gov.in/test')))
            with patch('extract_civic_block_table.ORIGINAL',sha):
                result = extract(root,pdf,sha,{'pages':[1]},digest,lambda r:True)
                self.assertEqual(result['structured_pdf_rows'],1)
                self.assertEqual(extract(root,pdf,sha,{'pages':[1]},digest,lambda r:True),result)
                next(evidence.glob('table-*.jsonl')).write_text('altered')
                with self.assertRaisesRegex(ValueError,'receipt checksum'):
                    extract(root,pdf,sha,{'pages':[1]},digest,lambda r:True)

if __name__ == '__main__': unittest.main()
