import hashlib,json,tempfile,unittest
from pathlib import Path
from extract_assembly_ocr import run
from test_assembly_symbols import SymbolPage

class OcrExtractionTests(unittest.TestCase):
    def test_ocr_rows_without_recognized_serial_are_preserved_with_notes_and_hash(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory);source=root/'source.pdf';source.write_bytes(b'original scanned report');digest=hashlib.sha256(source.read_bytes()).hexdigest()
            (root/'manifest.json').write_text(json.dumps(dict(year=2012,url='https://example.test/report',files=[dict(file='source.pdf',sha256=digest)])))
            page=SymbolPage();words=[list(w)+[85] for w in page.get_text('words') if not (w[0]==43 and w[1]==769)]
            (root/'ocr-words-v1.json').write_text(json.dumps(dict(source_sha256=digest,pages=[dict(page=1,width=612,height=792,text='DETAILED RESULTS',words=words)])))
            run(root,'Example')
            data=json.loads((root/'extraction.json').read_text());r=data['records'][0]
            self.assertEqual(r['candidates'][0]['votes'],60)
            self.assertIsNone(r['candidates'][0]['source_row'])
            self.assertIn('OCR transcription',r['error'])
            self.assertEqual(data['source_sha256'],digest)
            source.write_bytes(b'changed')
            with self.assertRaisesRegex(ValueError,'checksum'):run(root,'Example',refresh=True)

if __name__=='__main__':unittest.main()
