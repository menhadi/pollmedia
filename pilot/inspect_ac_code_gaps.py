"""Locate internal AC number gaps in the corresponding preserved PDF text."""
import json
from pathlib import Path
import re
import subprocess

from audit_pc_ac_gaps import summarize


def inspect(root):
    archive = root / 'application/storage/app/private/election-archive'
    for gap in summarize(root)['internal_ac_code_gaps']:
        folder = archive / gap['edition_id']
        data = json.loads((folder / 'extraction.json').read_text(encoding='utf-8'))
        source = folder / data['source_file']
        if source.suffix.lower() != '.pdf' or len(gap['codes']) > 17:
            continue
        body = subprocess.check_output(['pdftotext', '-layout', str(source), '-'],
                                       stderr=subprocess.DEVNULL, timeout=120).decode(errors='replace')
        pages = body.split('\f')
        for code in gap['codes']:
            found = []
            pattern = re.compile(r'(?im)^\s*(?:CONSTITUENCY\s*:\s*|AC\s*[-.]?\s*)0*'
                                 + str(code) + r'\s*[-.:]\s*([^\r\n]{1,90})')
            for page_number, page in enumerate(pages, 1):
                for match in pattern.finditer(page):
                    found.append({'page': page_number, 'heading': match.group(0).strip()[:130],
                                  'uncontested': 'uncontested' in page.lower()})
            yield {'state': gap['state'], 'year': gap['year'], 'code': code,
                   'edition_id': gap['edition_id'], 'matches': found[:3]}


if __name__ == '__main__':
    for row in inspect(Path(__file__).resolve().parents[1]):
        if row['matches']:
            print(json.dumps(row, ensure_ascii=False))
