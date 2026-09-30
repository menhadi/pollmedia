"""Restore AC seats confirmed on preserved official report pages.

Dry run by default. Every write keeps the exact preceding extraction bytes.
"""
import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess

import fitz

from audit_pc_ac_gaps import catalogue
from extract_assembly_legacy import extract as legacy_extract, parse_body


LEGACY = {
    '482b0cfa0689d697b8830cd0': [30],  # Delhi 1951
    'a68e5ff94ea8b19adbf5378b': [13, 14, 15],  # Mysore 1951
    'affdd40634235082132e418b': [1, 29, 30, 37, 38, 39, 44, 45, 46, 47, 48],  # Sourastra 1951
}
TAMIL_1991 = 'f0d9e36a60bcef19312cabc4'
UNCONTESTED = {
    '8bea140010fef922a65fa3e7': [(32, 'BONGAIGAON', 44), (71, 'DHEKIAJULI', 78), (75, 'SOOTEA', 81)],
    'b9dc2765d7ca0d1c746693ea': [(1, 'LUNGLEH (ST)', 13)],
    '60d8a031055599ff3b89eb78': [(25, 'NAGA HILLS NORTH', 36),
                                   (26, 'NAGA HILLS CENTRAL', 37),
                                   (27, 'NAGA HILLS SOUTH', 38)],
}


def tamil_rows(pdf):
    with fitz.open(pdf) as document:
        page = document[292].get_text()
    identity = re.compile(r'Constituency\s*:\s*(13[2-6])\s*\.\s*([^\n]+)', re.I)
    matches = list(identity.finditer(page))
    rows = []
    for position, match in enumerate(matches):
        code = int(match[1])
        if code not in [132, 133, 134, 135]:
            continue
        body = page[match.end():matches[position + 1].start() if position + 1 < len(matches) else len(page)]
        clean = '\n'.join(line.strip() for line in body.splitlines() if line.strip())
        record = parse_body(clean)
        record.update(code=code, name=match[2].strip(), state_name='Tamil Nadu', detail_page=293)
        rows.append(record)
    return rows


def source_page(pdf, page_number):
    return subprocess.check_output(['pdftotext', '-f', str(page_number), '-l', str(page_number),
                                    '-layout', str(pdf), '-'], stderr=subprocess.DEVNULL,
                                   timeout=30).decode('utf-8', errors='replace')


def recover(root, write=False):
    by_id = {hashlib.sha256(entry['url'].encode()).hexdigest()[:24]: entry
             for entry in catalogue(root) if entry['kind'] == 'ac'}
    archive = root / 'application/storage/app/private/election-archive'
    report = []
    for edition_id in dict.fromkeys([*LEGACY, TAMIL_1991, *UNCONTESTED]):
        entry = by_id[edition_id]
        folder = archive / edition_id
        extraction = folder / 'extraction.json'
        original = extraction.read_bytes()
        old_sha256 = hashlib.sha256(original).hexdigest()
        data = json.loads(original)
        if data['source_url'] != entry['url'] or data['kind'] != 'ac' or data['year'] != entry['year']:
            raise ValueError('Edition catalogue identity differs: ' + edition_id)
        pdf = folder / data['source_file']
        with pdf.open('rb') as stream:
            if hashlib.file_digest(stream, 'sha256').hexdigest() != data['source_sha256']:
                raise ValueError('Official report checksum differs: ' + edition_id)
        existing = {record['code'] for record in data['records']}
        additions = []
        if edition_id in LEGACY:
            parsed = {record['code']: record for record in legacy_extract(pdf, entry['state'])}
            for code in LEGACY[edition_id]:
                record = parsed[code]
                if (not record['candidates'] or not record.get('valid_candidate_votes')
                        or any(candidate['votes'] is None for candidate in record['candidates'])
                        or sum(candidate['votes'] for candidate in record['candidates']) != record['valid_candidate_votes']):
                    raise ValueError('Candidate rows do not reconcile: ' + edition_id + '/' + str(code))
                additions.append(record)
        if edition_id == TAMIL_1991:
            additions.extend(tamil_rows(pdf))
            if {record['code'] for record in additions} != {132, 133, 134, 135}:
                raise ValueError('Tamil Nadu 1991 page 293 seat identities differ')
            for record in additions:
                if (not record['candidates'] or any(candidate['votes'] is None for candidate in record['candidates'])
                        or sum(candidate['votes'] for candidate in record['candidates']) != record.get('valid_candidate_votes')):
                    raise ValueError('Tamil Nadu candidate votes do not reconcile: ' + str(record['code']))
        for code, name, page_number in UNCONTESTED.get(edition_id, []):
            page = source_page(pdf, page_number)
            heading = re.search(r'(?im)^\s*CONSTITUENCY\s*:\s*0*' + str(code)
                                + r'\s*-\s*([^\r\n]+)', page)
            if not heading or heading[1].strip() != name or page.lower().count('uncontested') < 1:
                raise ValueError('Uncontested summary does not match: ' + edition_id + '/' + str(code))
            additions.append({'code': code, 'name': name, 'state_name': entry['state'],
                              'candidates': [], 'status': 'needs_review',
                              'error': 'Official summary marks this seat uncontested but leaves candidate and vote cells blank. No winner or votes were inferred.',
                              'summary_page': page_number,
                              'source_locator': f'Official PDF page {page_number}, constituency data summary.'})
        if any(record['code'] in existing for record in additions) or len({record['code'] for record in additions}) != len(additions):
            raise ValueError('Recovery would duplicate a seat: ' + edition_id)
        data['records'].extend(additions)
        data['records'].sort(key=lambda record: record['code'])
        changed = {'edition_id': edition_id, 'state': entry['state'], 'year': entry['year'],
                   'codes': [record['code'] for record in additions],
                   'candidate_rows': sum(len(record['candidates']) for record in additions),
                   'old_sha256': old_sha256}
        if write:
            snapshot = folder / ('extraction-' + old_sha256 + '.json')
            if snapshot.exists():
                if snapshot.read_bytes() != original:
                    raise ValueError('Previous extraction snapshot differs: ' + edition_id)
            else:
                snapshot.write_bytes(original)
            replacement = json.dumps(data, indent=2, ensure_ascii=False).encode('utf-8')
            temporary = folder / 'extraction.json.partial'
            if temporary.exists():
                raise FileExistsError('Extraction temporary file already exists: ' + edition_id)
            temporary.write_bytes(replacement)
            temporary.replace(extraction)
            changed['new_sha256'] = hashlib.sha256(replacement).hexdigest()
        report.append(changed)
    return report


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--apply', action='store_true')
    arguments = parser.parse_args()
    print(json.dumps(recover(Path(__file__).resolve().parents[1], arguments.apply), indent=2))
