import hashlib
import json
from pathlib import Path
import tempfile
import unittest

from reportlab.pdfgen import canvas

from extract_dchb_pdf import extract, iter_pages, supported_official_pdf_url


class DchbPdfTests(unittest.TestCase):
    def test_supported_existing_publishers(self):
        for url in [
            'https://censusindia.gov.in/nada/index.php/catalog/30470/download/33651/24040_1961_GPET.pdf',
            'https://nhm.gov.in/New-Update-2024-26/CRM/16th_CRM_Report_2024.pdf',
            'https://dashboard.udiseplus.gov.in/report2026/static/media/UDISE+2022_23_Booklet_existing.d209e7c7516d77939da9.pdf',
            'https://dashboard.udiseplus.gov.in/report2026/static/media/UDISE+2025_26_Booklet_nep.94ceae1e8c2210549d21.pdf',
        ]:
            with self.subTest(url=url):
                self.assertTrue(supported_official_pdf_url(url))

    def test_rejects_unapproved_urls_and_lookalikes(self):
        base = 'https://dashboard.udiseplus.gov.in/report2026/static/media/UDISE+2022_23_Booklet_existing.abc123.pdf'
        for url in [
            base.replace('https:', 'http:'), base+'?redirect=1', base+'#page=2',
            base.replace('dashboard.udiseplus.gov.in', 'dashboard.udiseplus.gov.in.evil.test'),
            base.replace('dashboard.udiseplus.gov.in', 'user@dashboard.udiseplus.gov.in'),
            base.replace('dashboard.udiseplus.gov.in', 'dashboard.udiseplus.gov.in:443'),
            base.replace('/report2026/', '/other/'), base.replace('existing.', 'unknown.'),
            'https://nhm.gov.in/another.pdf', 'https://censusindia.gov.in.evil.test/nada/file.pdf',
            'https://user@censusindia.gov.in/nada/file.pdf', 'https://example.org/report.pdf',
        ]:
            with self.subTest(url=url):
                self.assertFalse(supported_official_pdf_url(url))

    def test_page_stream_keeps_page_boundaries(self):
        import io
        self.assertEqual(list(iter_pages(io.BytesIO(b'first\x0c\x0cthird\x0c'))),
                         ['first', '', 'third'])

    def test_extracts_approved_education_and_health_pdfs_without_changing_url(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            pdf = root / 'source.pdf'
            document = canvas.Canvas(str(pdf))
            document.drawString(72, 700, 'Official source evidence')
            document.save()
            original_hash = hashlib.sha256(pdf.read_bytes()).hexdigest()
            urls = [
                'https://nhm.gov.in/New-Update-2024-26/CRM/16th_CRM_Report_2024.pdf',
                'https://dashboard.udiseplus.gov.in/report2026/static/media/UDISE+2022_23_Booklet_existing.d209e7c7516d77939da9.pdf',
            ]
            for index, url in enumerate(urls):
                result = extract(pdf, original_hash, url, root / ('review'+str(index)))
                self.assertEqual(result['source_url'], url)
                self.assertEqual(result['original_sha256'], original_hash)
                self.assertEqual(result['pages'], 1)
                self.assertEqual(result['review_state'], 'unverified_page_text')

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
