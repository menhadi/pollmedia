"""Preserve bounded page OCR as unverified evidence; never derive accepted values."""
import csv
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile


def selected_pages(values, total):
    if not isinstance(values, list) or not values or len(values) > 25:
        raise ValueError('Queue between one and 25 explicit pages')
    if any(type(p) is not int or p < 1 or p > total for p in values) or len(set(values)) != len(values):
        raise ValueError('Invalid or duplicate PDF page')
    return sorted(values)


def extract(root, pdf, expected, job, digest, resource_check, progress):
    if digest(pdf) != expected:
        raise ValueError('Original PDF checksum differs')
    url = job['source_url']
    if not url.startswith('https://censusindia.gov.in/nada/'):
        raise ValueError('Expected official Census source URL')
    info = subprocess.run(['pdfinfo', str(pdf)], check=True, capture_output=True, text=True, timeout=30).stdout
    match = re.search(r'^Pages:\s*(\d+)$', info, re.MULTILINE)
    if not match:
        raise ValueError('PDF page count unavailable')
    pages = selected_pages(job['pages'], int(match[1]))
    folder = root / 'source-evidence' / ('ocr-' + expected)
    folder.mkdir(parents=True, exist_ok=True)
    for page in pages:
        destination = folder / str(page)
        if destination.exists():
            receipt = json.loads((destination / 'receipt.json').read_text())
            if receipt['original_sha256'] != expected or receipt['page'] != page or any(
                    digest(destination / name) != h for name,h in receipt['outputs'].items()):
                raise ValueError('Saved OCR evidence checksum differs')
            continue
        if not resource_check(root):
            raise RuntimeError('Waiting for disk/RAM reserve')
        progress(root, state='ocr_unverified_pages', source=pdf.name, page=page, queued_pages=len(pages))
        with tempfile.TemporaryDirectory(prefix='page-', dir=folder) as temporary:
            work = Path(temporary)
            subprocess.run(['pdftoppm','-f',str(page),'-l',str(page),'-singlefile','-r','180','-png',str(pdf),str(work/'render')],
                           check=True, capture_output=True, timeout=180)
            environment = dict(os.environ, OMP_THREAD_LIMIT='1')
            subprocess.run(['tesseract',str(work/'render.png'),str(work/'ocr'),'-l','eng','tsv','txt'],
                           check=True, capture_output=True, timeout=180, env=environment)
            with (work/'ocr.tsv').open(encoding='utf-8') as stream:
                words = sum(row['level']=='5' and bool(row['text'].strip()) for row in csv.DictReader(stream, delimiter='\t'))
            outputs = {name:digest(work/name) for name in ['render.png','ocr.tsv','ocr.txt']}
            receipt = {'original_sha256':expected,'source_url':url,'page':page,'render_dpi':180,
                       'language':'eng','word_count':words,'outputs':outputs,
                       'coordinate_basis':'pixels of preserved render.png; TSV contains word boxes and confidence',
                       'review_state':'unverified_ocr_evidence',
                       'warning':'OCR may misread text or contain no usable words; values and page meaning are not inferred.'}
            (work/'receipt.json').write_text(json.dumps(receipt,indent=2))
            # A complete page appears atomically; interrupted temporary folders are never reused.
            work.replace(destination)
