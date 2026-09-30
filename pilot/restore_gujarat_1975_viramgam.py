"""Preserve the omitted uncontested AC 63 summary without inventing a winner."""
import hashlib
import json
from pathlib import Path
import subprocess


EDITION = '2660ede4254e7cee1c5810b4'


def restore(root):
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    extraction = folder / 'extraction.json'
    old = extraction.read_bytes()
    old_sha256 = hashlib.sha256(old).hexdigest()
    data = json.loads(old)
    if data['kind'] != 'ac' or data['year'] != 1975 or data['source_url'] != 'https://old.eci.gov.in/files/file/3831-gujarat-1975/':
        raise ValueError('Unexpected Gujarat 1975 source identity')
    pdf = folder / data['source_file']
    with pdf.open('rb') as stream:
        if hashlib.file_digest(stream, 'sha256').hexdigest() != data['source_sha256']:
            raise ValueError('Official report checksum differs')
    summary = subprocess.check_output(['pdftotext', '-f', '77', '-l', '77', '-layout', str(pdf), '-'],
                                      timeout=30).decode('utf-8', errors='replace')
    if 'CONSTITUENCY : 63 - VIRAMGAM' not in summary or summary.count('Uncontested') < 2:
        raise ValueError('Official page 77 does not confirm uncontested Viramgam')
    codes = {record['code'] for record in data['records']}
    if 63 in codes or 62 not in codes or 64 not in codes:
        raise ValueError('Seat numbers have changed since the audit')
    record = {
        'number_of_seats': 1, 'candidates': [], 'electors': None,
        'status': 'needs_review',
        'error': 'Official summary labels this seat uncontested but leaves the winner, party and vote cells blank. No candidate or votes were inferred.',
        'code': 63, 'name': 'VIRAMGAM', 'state_name': 'Gujarat',
        'summary_page': 77, 'source_locator': 'Official PDF page 77, constituency data summary, printed page 63 of 182.',
    }
    data['records'].append(record)
    data['records'].sort(key=lambda item: item['code'])
    snapshot = folder / ('extraction-' + old_sha256 + '.json')
    if snapshot.exists():
        if snapshot.read_bytes() != old:
            raise ValueError('Prior extraction snapshot differs')
    else:
        snapshot.write_bytes(old)
    replacement = json.dumps(data, indent=2, ensure_ascii=False).encode('utf-8')
    temporary = folder / 'extraction.json.partial'
    if temporary.exists():
        raise FileExistsError('Extraction temporary file already exists')
    temporary.write_bytes(replacement)
    temporary.replace(extraction)
    return {'old_sha256': old_sha256, 'new_sha256': hashlib.sha256(replacement).hexdigest(),
            'snapshot': snapshot.as_posix(), 'added_records': 1}


if __name__ == '__main__':
    print(json.dumps(restore(Path(__file__).resolve().parents[1])))
