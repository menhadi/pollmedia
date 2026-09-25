"""Preserve page text from one official District Census Handbook PDF for review.

This does not turn extracted text into Census observations or OCR blank pages.
"""
import argparse
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import re
import shutil
import subprocess


def digest(path):
    h = hashlib.sha256()
    with Path(path).open('rb') as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b''):
            h.update(block)
    return h.hexdigest()


def iter_pages(stream):
    pending = b''
    while block := stream.read(65536):
        pending += block
        while b'\x0c' in pending:
            page, pending = pending.split(b'\x0c', 1)
            yield page.decode('utf-8')
    if pending.strip():
        yield pending.decode('utf-8')


def extract(pdf, expected_sha256, source_url, destination):
    pdf, destination = Path(pdf), Path(destination)
    if not re.fullmatch('[a-f0-9]{64}', expected_sha256) or digest(pdf) != expected_sha256:
        raise ValueError('Original PDF checksum differs')
    if not source_url.startswith('https://censusindia.gov.in/'):
        raise ValueError('Expected an official Census source URL')
    if shutil.disk_usage(destination.parent).free < 10 * 1024**3:
        raise RuntimeError('Insufficient disk reserve')
    info = subprocess.run(['pdfinfo', str(pdf)], check=True, capture_output=True, text=True).stdout
    match = re.search(r'^Pages:\s*(\d+)$', info, re.MULTILINE)
    if not match:
        raise ValueError('PDF page count unavailable')
    expected_pages = int(match.group(1))
    raw_text = destination.with_suffix('.raw.txt.partial')
    pages_file = destination.with_suffix('.pages.jsonl.partial')
    manifest_file = destination.with_suffix('.manifest.json.partial')
    try:
        subprocess.run(['pdftotext', '-layout', '-enc', 'UTF-8', str(pdf), str(raw_text)], check=True)
        count = text_pages = 0
        with raw_text.open('rb') as stream, pages_file.open('w', encoding='utf-8', newline='\n') as target:
            for count, page in enumerate(iter_pages(stream), 1):
                meaningful = bool(page.strip())
                text_pages += meaningful
                target.write(json.dumps({'page': count, 'text': page,
                                         'text_sha256': hashlib.sha256(page.encode('utf-8')).hexdigest(),
                                         'has_text': meaningful}, ensure_ascii=False) + '\n')
        if count != expected_pages:
            raise ValueError(f'PDF text/page count mismatch: {count} != {expected_pages}')
        manifest = {'source_url': source_url, 'url_scope': 'exact_download',
                    'original_file': pdf.name, 'original_sha256': expected_sha256,
                    'original_bytes': pdf.stat().st_size, 'pages': count,
                    'text_pages': text_pages, 'pages_without_text': count - text_pages,
                    'raw_text_sha256': digest(raw_text), 'pages_jsonl_sha256': digest(pages_file),
                    'extractor': 'pdftotext -layout -enc UTF-8',
                    'extracted_at': datetime.now(timezone.utc).isoformat(),
                    'review_state': 'unverified_page_text'}
        manifest_file.write_text(json.dumps(manifest, indent=2) + '\n', encoding='utf-8')
        raw_text.replace(destination.with_suffix('.raw.txt'))
        pages_file.replace(destination.with_suffix('.pages.jsonl'))
        manifest_file.replace(destination.with_suffix('.manifest.json'))
        return manifest
    finally:
        for path in (raw_text, pages_file, manifest_file):
            path.unlink(missing_ok=True)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('pdf', type=Path)
    parser.add_argument('expected_sha256')
    parser.add_argument('source_url')
    parser.add_argument('destination', type=Path)
    arguments = parser.parse_args()
    print(json.dumps(extract(arguments.pdf, arguments.expected_sha256,
                             arguments.source_url, arguments.destination)))
