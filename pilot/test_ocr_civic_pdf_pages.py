import unittest
from unittest.mock import patch
import hashlib
import tempfile
from pathlib import Path
from types import SimpleNamespace
from ocr_civic_pdf_pages import selected_pages, extract


class PageQueueTests(unittest.TestCase):
    def test_receipts_resume_and_reject_changed_output(self):
        def digest(path):
            return hashlib.sha256(path.read_bytes()).hexdigest()
        def run(command, **kwargs):
            if command[0] == 'pdfinfo':
                return SimpleNamespace(stdout='Pages: 2\n')
            if command[0] == 'pdftoppm':
                Path(command[-1]+'.png').write_bytes(b'synthetic-render')
            if command[0] == 'tesseract':
                Path(command[2]+'.txt').write_text('example')
                Path(command[2]+'.tsv').write_text('level\ttext\n5\texample\n')
            return SimpleNamespace(stdout='')
        with tempfile.TemporaryDirectory() as temporary, patch('ocr_civic_pdf_pages.subprocess.run', side_effect=run):
            root=Path(temporary); pdf=root/'original.pdf'; pdf.write_bytes(b'synthetic-pdf')
            sha=digest(pdf); job={'source_url':'https://censusindia.gov.in/nada/example','pages':[1]}
            args=(root,pdf,sha,job,digest,lambda p:True,lambda *a,**kw:None)
            extract(*args)
            extract(*args)
            (root/'source-evidence'/('ocr-'+sha)/'1/ocr.txt').write_text('changed')
            with self.assertRaises(ValueError):extract(*args)

    def test_explicit_bounded_pages(self):
        self.assertEqual(selected_pages([9,2],10), [2,9])
        for values in ([0],[11],[True],[2,2],[],list(range(1,27))):
            with self.assertRaises(ValueError):
                selected_pages(values,100 if len(values)>25 else 10)


if __name__ == '__main__':
    unittest.main()
