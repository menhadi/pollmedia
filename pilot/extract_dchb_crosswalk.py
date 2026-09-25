"""Preserve explicit 2001/2011 village-code pairs from DCHB alphabetical lists.

Candidates remain unverified text evidence, not accepted LGD or boundary joins.
"""
import argparse
import hashlib
import json
from pathlib import Path
import re
from collections import Counter

ROW = re.compile(r'\s*(\d+)\s+(.+?)\s{2,}(\d{6})\s+(\d{8})\s*')

def page_rows(page):
    text = page['text']
    required = ['Alphabetical list of Villages', '2011 CENSUS LOCATION', '2001 CENSUS LOCATION']
    if not all(term in text for term in required):
        return []
    block = re.search(r'^Name of CD BLOCK:\s*(.+)$', text, re.MULTILINE)
    district = re.search(r'^Name of District:\s*(.+)$', text, re.MULTILINE)
    if not block or not district:
        return []
    result = []
    for line_number, line in enumerate(text.splitlines(), 1):
        match = ROW.fullmatch(line)
        if match:
            serial, name, code2011, code2001 = match.groups()
            result.append({'page': page['page'], 'line_number': line_number,
                           'printed_serial': serial, 'name_as_printed': name.strip(),
                           'district_as_printed': district.group(1).strip(),
                           'cd_block_as_printed': block.group(1).strip(),
                           'census_2011_code': code2011, 'census_2001_code': code2001,
                           'source_line': line, 'page_text_sha256': page['text_sha256'],
                           'review_state': 'unverified_explicit_source_code_pair'})
    return result


def continuation_rows(page, previous):
    if not previous or page['page'] != previous['page'] + 1:
        return []
    result = []
    expected = int(previous['printed_serial']) + 1
    for number, line in enumerate(page['text'].splitlines(), 1):
        if not line.strip() or re.fullmatch(r'\s*\d+\s*', line):
            continue  # Printed page number is the only non-row text permitted.
        match = ROW.fullmatch(line)
        if not match or int(match[1]) != expected:
            return []
        row = {key: previous[key] for key in ('district_as_printed', 'cd_block_as_printed')}
        row.update(page=page['page'], line_number=number, printed_serial=match[1],
                   name_as_printed=match[2].strip(), census_2011_code=match[3], census_2001_code=match[4],
                   source_line=line, page_text_sha256=page['text_sha256'],
                   scope_header_page=previous.get('scope_header_page', previous['page']),
                   review_state='unverified_explicit_source_code_pair',
                   warning='Scope carried from adjacent alphabetical-list page with consecutive printed serials.')
        result.append(row)
        expected += 1
    return result if len(result) >= 2 else []


def digest(path):
    h = hashlib.sha256()
    with path.open('rb') as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b''):
            h.update(block)
    return h.hexdigest()


def extract(pages, manifest, destination):
    meta = json.loads(manifest.read_text(encoding='utf-8'))
    if digest(pages) != meta['pages_jsonl_sha256']:
        raise ValueError('Page evidence checksum differs')
    if destination.exists():
        raise FileExistsError('Choose a new evidence filename')
    count, selected_pages = 0, []
    previous = None
    continued = 0
    codes = Counter()
    temporary = destination.with_suffix('.partial')
    try:
        with pages.open(encoding='utf-8') as source, temporary.open('w', encoding='utf-8') as target:
            for line in source:
                page = json.loads(line)
                if hashlib.sha256(page['text'].encode()).hexdigest() != page['text_sha256']:
                    raise ValueError('Page text checksum differs')
                rows = page_rows(page)
                if not rows:
                    rows = continuation_rows(page, previous)
                    continued += len(rows)
                previous = rows[-1] if rows else None
                if rows:
                    selected_pages.append(page['page'])
                for row in rows:
                    row.update(source_url=meta['source_url'], original_sha256=meta['original_sha256'])
                    target.write(json.dumps(row, ensure_ascii=False) + '\n')
                    count += 1
                    codes[row['census_2011_code']] += 1
        temporary.replace(destination)
    finally:
        temporary.unlink(missing_ok=True)
    summary = {'rows': count, 'pages': selected_pages, 'unique_2011_codes': len(codes),
               'continuation_rows': continued,
               'repeated_2011_codes': {c:n for c,n in codes.items() if n > 1},
               'sha256': digest(destination), 'review_state': 'unverified_explicit_source_code_pairs',
               'scope_note': 'Explicit alphabetical-list headers establish district/CD-block scope. Adjacent row-only pages require consecutive serials and retain a carried-scope warning. No inferred codes, current LGD joins or unchanged-boundary claims.'}
    destination.with_suffix('.summary.json').write_text(json.dumps(summary, indent=2), encoding='utf-8')
    return summary


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('pages', type=Path)
    parser.add_argument('manifest', type=Path)
    parser.add_argument('destination', type=Path)
    args = parser.parse_args()
    print(json.dumps(extract(args.pages, args.manifest, args.destination)))
