"""Re-read unclear numeric cells from saved 2012 Gujarat summary pages.

Keep the first-pass OCR intact; this companion file records every crop and word.
"""

import hashlib
import io
import json
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
ROWS = {'electors': 288, 'voters': 386, 'valid_candidate_votes': 478}


def digest(path: Path) -> str:
    with path.open('rb') as source:
        return hashlib.file_digest(source, 'sha256').hexdigest()


def first_pass_value(words: list, y: int) -> int | None:
    matches = [word for word in words if abs(word[1] - y) < 6 and 480 < word[0] < 550
               and re.fullmatch(r'\d{3,7}', word[4]) and word[5] >= 85]
    return int(matches[0][4]) if len(matches) == 1 else None


def run() -> dict:
    source = FOLDER / f'{EDITION}-9045.pdf'
    ocr_path = FOLDER / 'summary-result-ocr-words-v1.json'
    output = FOLDER / 'summary-result-ocr-cells-v1.json'
    if output.exists() or digest(source) != SOURCE_SHA256 or digest(ocr_path) != OCR_SHA256:
        raise ValueError('Existing companion or official source/OCR checksum differs')
    base = json.loads(ocr_path.read_text(encoding='utf-8'))
    if base['source_sha256'] != SOURCE_SHA256 or len(base['pages']) != 182:
        raise ValueError('Saved Gujarat summary coverage differs')
    pytesseract.pytesseract.tesseract_cmd = shutil.which('tesseract') or 'C:/Program Files/Tesseract-OCR/tesseract.exe'
    engine = str(pytesseract.get_tesseract_version())
    cells = []
    with fitz.open(source) as pdf:
        if len(pdf) != 281:
            raise ValueError('Official Gujarat PDF page count differs')
        for page in base['pages']:
            code = page['code']
            if page['page'] != code + 21:
                raise ValueError(f'Summary page identity differs: {code}')
            for field, y in ROWS.items():
                if first_pass_value(page['words'], y) is not None:
                    continue
                while psutil.virtual_memory().available < 2 * 1024**3:
                    time.sleep(30)
                crop = fitz.Rect(488, y - 9, 544, y + 12)
                pix = pdf[page['page'] - 1].get_pixmap(matrix=fitz.Matrix(4, 4), clip=crop, alpha=False)
                bitmap = Image.open(io.BytesIO(pix.tobytes('png')))
                data = pytesseract.image_to_data(bitmap, lang='eng',
                    config='--psm 7 -c tessedit_char_whitelist=0123456789',
                    output_type=pytesseract.Output.DICT, timeout=120)
                words = [{'text': text.strip(), 'confidence': float(data['conf'][i]),
                          'left': crop.x0 + data['left'][i] / 4, 'top': crop.y0 + data['top'][i] / 4,
                          'width': data['width'][i] / 4, 'height': data['height'][i] / 4}
                         for i, text in enumerate(data['text']) if text.strip()]
                cells.append({'code': code, 'page': page['page'], 'field': field,
                              'crop_pdf_rect': list(crop), 'scale': 4,
                              'words': words})
    document = {'source_sha256': SOURCE_SHA256, 'base_ocr_sha256': OCR_SHA256,
                'engine': engine, 'method': 'Tesseract --psm 7 numeric crop at 4x PDF resolution',
                'cells': cells}
    temporary = output.with_suffix('.json.partial')
    temporary.write_text(json.dumps(document, ensure_ascii=False), encoding='utf-8')
    temporary.replace(output)
    result = {'file': str(output), 'sha256': digest(output), 'cells': len(cells),
              'codes': len({cell['code'] for cell in cells})}
    print(json.dumps(result))
    return result


if __name__ == '__main__':
    run()
