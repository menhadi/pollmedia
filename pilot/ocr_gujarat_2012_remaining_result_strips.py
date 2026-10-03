"""Preserve independent, high-resolution OCR for unresolved Gujarat 2012 results.

This does not revise election data. Each crop records its PDF coordinates and words.
"""

import hashlib
import io
import json
import os
from pathlib import Path
import shutil
import time

import fitz
from PIL import Image
import psutil
import pytesseract

from audit_ac_2012_gujarat_summary_results import (
    EDITION, ROOT, SOURCE_SHA256, WORDS_SHA256, audit_refined,
)


FOLDER = ROOT / 'application/storage/app/private/election-archive' / EDITION
PAGE_FOLDER = FOLDER / 'summary-result-strip-pages-v1'
OUTPUT = FOLDER / 'summary-result-ocr-strips-v1.json'
TARGET_CODES = [10, 17, 18, 20, 27, 29, 40, 44, 52, 54, 57, 63, 68, 70, 78,
                85, 88, 92, 97, 99, 113, 118, 119, 127, 134, 148, 150,
                153, 174, 180]
# The first-pass numeric fallback already resolved codes 13, 80, 102, 108 and 172.


def digest(path: Path) -> str:
    with path.open('rb') as source:
        return hashlib.file_digest(source, 'sha256').hexdigest()


def crop_page(pdf: fitz.Document, code: int, engine: str) -> dict:
    page_number = code + 21
    crop = fitz.Rect(25, 664, 520, 744)
    pix = pdf[page_number - 1].get_pixmap(matrix=fitz.Matrix(4, 4), clip=crop, alpha=False)
    bitmap = Image.open(io.BytesIO(pix.tobytes('png')))
    data = pytesseract.image_to_data(bitmap, config='--psm 6', lang='eng',
                                    output_type=pytesseract.Output.DICT, timeout=120)
    words = []
    lines = {}
    for index, value in enumerate(data['text']):
        value = value.strip()
        if not value:
            continue
        x, y = crop.x0 + data['left'][index] / 4, crop.y0 + data['top'][index] / 4
        words.append([x, y, x + data['width'][index] / 4,
                      y + data['height'][index] / 4, value, float(data['conf'][index])])
        key = (data['block_num'][index], data['par_num'][index], data['line_num'][index])
        lines.setdefault(key, []).append(value)
    return {'source_sha256': SOURCE_SHA256, 'code': code, 'page': page_number,
            'engine': engine, 'crop_pdf_rect': list(crop), 'scale': 4,
            'text': '\n'.join(' '.join(line) for line in lines.values()),
            'words': words}


def run() -> dict:
    os.environ['OMP_THREAD_LIMIT'] = '1'
    source = FOLDER / f'{EDITION}-9045.pdf'
    original = (FOLDER / 'extraction.json').read_bytes()
    if OUTPUT.exists() or digest(source) != SOURCE_SHA256:
        raise ValueError('Existing strips or official source checksum differs')
    if digest(FOLDER / 'summary-result-ocr-words-v1.json') != WORDS_SHA256:
        raise ValueError('Preserved first-pass OCR checksum differs')
    if shutil.disk_usage(FOLDER).free < 10 * 1024**3:
        raise RuntimeError('Less than 10 GiB free disk; strip OCR stopped')
    target = [row['code'] for row in audit_refined(shifted_pages=True, result_fallback=True)['rows']
              if row['result'] is None]
    if target != TARGET_CODES:
        raise ValueError(f'Unresolved Gujarat target inventory differs: {target}')
    pytesseract.pytesseract.tesseract_cmd = shutil.which('tesseract') or 'C:/Program Files/Tesseract-OCR/tesseract.exe'
    engine = str(pytesseract.get_tesseract_version())
    PAGE_FOLDER.mkdir(exist_ok=True)
    with fitz.open(source) as pdf:
        if len(pdf) != 281:
            raise ValueError('Official Gujarat PDF page count differs')
        for code in TARGET_CODES:
            path = PAGE_FOLDER / f'{code:03d}.json'
            if path.exists():
                saved = json.loads(path.read_text(encoding='utf-8'))
                if (saved['source_sha256'] != SOURCE_SHA256 or saved['code'] != code
                        or saved['page'] != code + 21 or saved['engine'] != engine
                        or not saved['words']):
                    raise ValueError(f'Existing result strip differs: {code}')
                continue
            while psutil.virtual_memory().available < 2 * 1024**3:
                print('Waiting for 2 GiB free RAM before next result strip', flush=True)
                time.sleep(30)
            saved = crop_page(pdf, code, engine)
            if not saved['words']:
                raise ValueError(f'Empty official result strip: {code}')
            temporary = path.with_suffix('.json.partial')
            temporary.write_text(json.dumps(saved, ensure_ascii=False), encoding='utf-8')
            temporary.replace(path)
    pages = [json.loads((PAGE_FOLDER / f'{code:03d}.json').read_text(encoding='utf-8'))
             for code in TARGET_CODES]
    if (FOLDER / 'extraction.json').read_bytes() != original or digest(source) != SOURCE_SHA256:
        raise RuntimeError('Official source or original extraction changed during strip OCR')
    document = {'source_sha256': SOURCE_SHA256, 'base_ocr_sha256': WORDS_SHA256,
                'engine': engine, 'method': 'Tesseract --psm 6, result strips at 4x PDF resolution',
                'pages': pages}
    temporary = OUTPUT.with_suffix('.json.partial')
    temporary.write_text(json.dumps(document, ensure_ascii=False), encoding='utf-8')
    temporary.replace(OUTPUT)
    return {'file': str(OUTPUT), 'sha256': digest(OUTPUT), 'pages': len(pages)}


if __name__ == '__main__':
    print(json.dumps(run()), flush=True)
