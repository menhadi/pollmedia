"""Preserve OCR words for the 182 official Gujarat 2012 constituency summaries.

This only collects evidence. It does not change extraction.json or publish results.
"""

import hashlib
import json
import os
from pathlib import Path
import shutil
import time

import fitz
from PIL import Image
import psutil
import pytesseract


EDITION = '503135d3e838d38c93d3bce7'
SOURCE_SHA256 = '5c01eb6fdc9a01f445526932c0f5f4e4e3743b52fcf30f1b0854749c25fd4ee3'
ROOT = Path(__file__).resolve().parents[1]
FOLDER = ROOT / 'application/storage/app/private/election-archive' / EDITION
PAGE_FOLDER = FOLDER / 'summary-result-ocr-pages-v1'
OUTPUT = FOLDER / 'summary-result-ocr-words-v1.json'
MIN_RAM = 2 * 1024**3
MIN_DISK = 10 * 1024**3


def digest_file(path: Path) -> str:
    with path.open('rb') as source:
        return hashlib.file_digest(source, 'sha256').hexdigest()


def source_identity() -> tuple[Path, bytes]:
    manifest = json.loads((FOLDER / 'manifest.json').read_text(encoding='utf-8'))
    original = (FOLDER / 'extraction.json').read_bytes()
    data = json.loads(original)
    source = FOLDER / data['source_file']
    if (manifest['url'] != data['source_url'] or data['kind'] != 'ac' or data['year'] != 2012
            or len(data['records']) != 182 or data['source_sha256'] != SOURCE_SHA256
            or digest_file(source) != SOURCE_SHA256):
        raise ValueError('Official Gujarat 2012 source or edition identity differs')
    return source, original


def wait_for_memory() -> None:
    while psutil.virtual_memory().available < MIN_RAM:
        print('Waiting for 2 GiB free RAM before next official summary page', flush=True)
        time.sleep(30)


def read_page(pdf: fitz.Document, code: int, engine: str) -> dict:
    page_number = code + 21
    page = pdf[page_number - 1]
    pix = page.get_pixmap(matrix=fitz.Matrix(2, 2), alpha=False)
    bitmap = Image.frombytes('RGB', (pix.width, pix.height), pix.samples)
    data = pytesseract.image_to_data(bitmap, config='--psm 6', lang='eng',
                                    output_type=pytesseract.Output.DICT, timeout=120)
    words = []
    lines = {}
    for index, value in enumerate(data['text']):
        value = value.strip()
        if not value:
            continue
        x, y = data['left'][index] / 2, data['top'][index] / 2
        words.append([x, y, x + data['width'][index] / 2, y + data['height'][index] / 2,
                      value, float(data['conf'][index])])
        key = (data['block_num'][index], data['par_num'][index], data['line_num'][index])
        lines.setdefault(key, []).append(value)
    return {'source_sha256': SOURCE_SHA256, 'code': code, 'page': page_number,
            'width': page.rect.width, 'height': page.rect.height, 'scale': 2,
            'engine': engine, 'text': '\n'.join(' '.join(line) for line in lines.values()),
            'words': words}


def run() -> dict:
    os.environ['OMP_THREAD_LIMIT'] = '1'
    source, original = source_identity()
    if OUTPUT.exists():
        raise FileExistsError(OUTPUT)
    if shutil.disk_usage(FOLDER).free < MIN_DISK:
        raise RuntimeError('Less than 10 GiB free disk; evidence OCR stopped')
    executable = shutil.which('tesseract') or 'C:/Program Files/Tesseract-OCR/tesseract.exe'
    pytesseract.pytesseract.tesseract_cmd = executable
    engine = str(pytesseract.get_tesseract_version())
    PAGE_FOLDER.mkdir(exist_ok=True)
    with fitz.open(source) as pdf:
        if len(pdf) != 281:
            raise ValueError('Official Gujarat PDF page count differs')
        for code in range(1, 183):
            page_path = PAGE_FOLDER / f'{code:03d}.json'
            if page_path.exists():
                saved = json.loads(page_path.read_text(encoding='utf-8'))
                if (saved['source_sha256'] != SOURCE_SHA256 or saved['code'] != code
                        or saved['page'] != code + 21 or saved['engine'] != engine
                        or not saved['words']):
                    raise ValueError(f'Existing OCR page differs: {code}')
                continue
            wait_for_memory()
            page = read_page(pdf, code, engine)
            if not page['words']:
                raise ValueError(f'Empty official summary OCR: {code}')
            temporary = page_path.with_suffix('.json.partial')
            temporary.write_text(json.dumps(page, ensure_ascii=False), encoding='utf-8')
            temporary.replace(page_path)
            if code == 1 or code % 20 == 0:
                print(f'Preserved Gujarat 2012 summary OCR {code}/182', flush=True)
    pages = [json.loads((PAGE_FOLDER / f'{code:03d}.json').read_text(encoding='utf-8'))
             for code in range(1, 183)]
    if [page['page'] for page in pages] != list(range(22, 204)):
        raise ValueError('Summary page sequence differs')
    if (FOLDER / 'extraction.json').read_bytes() != original or digest_file(source) != SOURCE_SHA256:
        raise RuntimeError('Official source or archived extraction changed during OCR')
    document = {'source_sha256': SOURCE_SHA256, 'engine': engine,
                'method': 'Tesseract --psm 6 at 2x PDF resolution; words in PDF coordinates',
                'pages': pages}
    temporary = OUTPUT.with_suffix('.json.partial')
    temporary.write_text(json.dumps(document, ensure_ascii=False), encoding='utf-8')
    temporary.replace(OUTPUT)
    result = {'ocr_file': str(OUTPUT), 'sha256': digest_file(OUTPUT), 'pages': len(pages)}
    print(json.dumps(result), flush=True)
    return result


if __name__ == '__main__':
    run()
