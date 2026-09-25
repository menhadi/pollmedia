import hashlib
import json
from pathlib import Path
import tempfile
import unittest

from reportlab.pdfgen import canvas

from extract_dchb_pdf import extract, iter_pages


class DchbPdfTests(unittest.TestCase):
    def test_page_stream_keeps_page_boundaries(self):
        import io
        self.assertEqual(list(iter_pages(io.BytesIO(b'first\x0c\x0cthird\x0c'))),
                         ['first', '', 'third'])

    def test_preserves_original_and_page_evidence(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            pdf = root / 'source.pdf'
            document = canvas.Canvas(str(pdf))
            document.drawString(72, 700, 'Village Directory 2011')
            document.showPage()
            document.drawString(72, 700, 'Water and education')
            document.save()
            original_hash = hashlib.sha256(pdf.read_bytes()).hexdigest()
            result = extract(pdf, original_hash,
                             'https://censusindia.gov.in/nada/index.php/catalog/example',
                             root / 'review')
            self.assertEqual(result['pages'], 2)
            self.assertEqual(result['text_pages'], 2)
            self.assertEqual(result['original_sha256'], original_hash)
            rows = [json.loads(line) for line in (root / 'review.pages.jsonl').read_text().splitlines()]
            self.assertEqual([row['page'] for row in rows], [1, 2])
            self.assertIn('Village Directory', rows[0]['text'])
            self.assertEqual(hashlib.sha256(pdf.read_bytes()).hexdigest(), original_hash)


if __name__ == '__main__':
    unittest.main()
