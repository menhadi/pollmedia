"""Independently preserve winner, runner and margin number crops for Gujarat 2012."""

import hashlib
import io
import json
import os
from pathlib import Path
import re
import shutil
import time

import fitz
from PIL import Image
import psutil
import pytesseract


EDITION = '503135d3e838d38c93d3bce7'
SOURCE_SHA256 = '5c01eb6fdc9a01f445526932c0f5f4e4e3743b52fcf30f1b0854749c25fd4ee3'
OCR_SHA256 = '06232b79d125d89b4d5725f241ce8dd42326da5c77309173ecba29b4b01988c3'
FOLDER = Path(__file__).resolve().parents[1] / 'application/storage/app/private/election-archive' / EDITION
PAGE_FOLDER = FOLDER / 'summary-result-number-pages-v1'
OUTPUT = FOLDER / 'summary-result-ocr-numbers-v1.json'


def digest(path: Path) -> str:
    with path.open('rb') as source:
        return hashlib.file_digest(source, 'sha256').hexdigest()


def read_cell(pdf: fitz.Document, page: dict, field: str) -> dict:
    label = {'winner_votes': 'WINNER', 'runner_votes': 'RUNNER-UP', 'margin': 'MARGIN'}[field]
    anchors = [word for word in page['words'] if word[0] < 100 and word[1] > 660
               and word[4].upper().startswith(label)]
    if len(anchors) != 1:
        raise ValueError(f'Official result row label differs: {page["code"]} / {field}')
    y = anchors[0][1]
    left, right = (445, 515) if field != 'margin' else (93, 165)
    crop = fitz.Rect(left, y - 9, right, y + 12)
    pix = pdf[page['page'] - 1].get_pixmap(matrix=fitz.Matrix(4, 4), clip=crop, alpha=False)
    bitmap = Image.open(io.BytesIO(pix.tobytes('png')))
    data = pytesseract.image_to_data(bitmap, lang='eng',
        config='--psm 7 -c tessedit_char_whitelist=0123456789',
        output_type=pytesseract.Output.DICT, timeout=120)
    words = [{'text': text.strip(), 'confidence': float(data['conf'][i]),
              'left': crop.x0 + data['left'][i] / 4, 'top': crop.y0 + data['top'][i] / 4,
              'width': data['width'][i] / 4, 'height': data['height'][i] / 4}
             for i, text in enumerate(data['text']) if text.strip()]
    return {'field': field, 'crop_pdf_rect': list(crop), 'scale': 4, 'words': words}


def run() -> dict:
    os.environ['OMP_THREAD_LIMIT'] = '1'
    source = FOLDER / f'{EDITION}-9045.pdf'
    base_file = FOLDER / 'summary-result-ocr-words-v1.json'
    if OUTPUT.exists() or digest(source) != SOURCE_SHA256 or digest(base_file) != OCR_SHA256:
        raise ValueError('Existing result numbers or official source/OCR checksum differs')
    if shutil.disk_usage(FOLDER).free < 10 * 1024**3:
        raise RuntimeError('Less than 10 GiB free disk; result OCR stopped')
    base = json.loads(base_file.read_text(encoding='utf-8'))
    if base['source_sha256'] != SOURCE_SHA256 or len(base['pages']) != 182:
        raise ValueError('Saved Gujarat summary coverage differs')
    pytesseract.pytesseract.tesseract_cmd = shutil.which('tesseract') or 'C:/Program Files/Tesseract-OCR/tesseract.exe'
    engine = str(pytesseract.get_tesseract_version())
    PAGE_FOLDER.mkdir(exist_ok=True)
    with fitz.open(source) as pdf:
        if len(pdf) != 281:
            raise ValueError('Official Gujarat PDF page count differs')
        for page in base['pages']:
            code = page['code']
            if page['page'] != code + 21:
                raise ValueError(f'Official result page mapping differs: {code}')
            output_page = PAGE_FOLDER / f'{code:03d}.json'
            if output_page.exists():
                saved = json.loads(output_page.read_text(encoding='utf-8'))
                if (saved['source_sha256'] != SOURCE_SHA256 or saved['code'] != code
                        or saved['page'] != page['page'] or saved['engine'] != engine
                        or len(saved['cells']) != 3):
                    raise ValueError(f'Existing result number OCR differs: {code}')
                continue
            while psutil.virtual_memory().available < 2 * 1024**3:
                print('Waiting for 2 GiB free RAM before next result page', flush=True)
                time.sleep(30)
            cells = [read_cell(pdf, page, field)
                     for field in ('winner_votes', 'runner_votes', 'margin')]
            saved = {'source_sha256': SOURCE_SHA256, 'code': code, 'page': page['page'],
                     'engine': engine, 'cells': cells}
            temporary = output_page.with_suffix('.json.partial')
            temporary.write_text(json.dumps(saved, ensure_ascii=False), encoding='utf-8')
            temporary.replace(output_page)
            if code == 1 or code % 20 == 0:
                print(f'Preserved Gujarat 2012 result numbers {code}/182', flush=True)
    pages = [json.loads((PAGE_FOLDER / f'{code:03d}.json').read_text(encoding='utf-8'))
             for code in range(1, 183)]
    document = {'source_sha256': SOURCE_SHA256, 'base_ocr_sha256': OCR_SHA256,
                'engine': engine, 'method': 'Tesseract --psm 7 numeric row crops at 4x PDF resolution',
                'pages': pages}
    temporary = OUTPUT.with_suffix('.json.partial')
    temporary.write_text(json.dumps(document, ensure_ascii=False), encoding='utf-8')
    temporary.replace(OUTPUT)
    result = {'file': str(OUTPUT), 'sha256': digest(OUTPUT), 'pages': len(pages)}
    print(json.dumps(result), flush=True)
    return result


if __name__ == '__main__':
    run()
