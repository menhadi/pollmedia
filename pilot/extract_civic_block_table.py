"""Parse the six-column Bareilly 2011 urban-block appendix into review rows.

Never treats printed dashes as zero or joins blocks to current geography.
"""
import hashlib
import json
import re
from pathlib import Path

ORIGINAL = '4a15246b6203c69a1e3d7a70321949d7ccf4b70d8fea0dfa02bf22395d2fd34a'
PROFILE = 'bareilly_2011_urban_block_v1'
SHAHJAHANPUR = '4b536b63aaa7906aac6b5a1e412a8140261d883772d42561810994501249b829'
SHAH_PROFILE = 'shahjahanpur_2011_urban_block_v1'


def profile_for(original):
    if original == ORIGINAL:
        return PROFILE
    if original == SHAHJAHANPUR:
        return SHAH_PROFILE
    raise ValueError('Unsupported original PDF profile')


def parse_page(text, profile=PROFILE):
    required = ['APPENDIX TO DISTRICT PRIMARY CENSUS ABSTRACT',
                'POPULATION - URBAN BLOCK WISE', 'Name of Town', 'Name of Ward',
                'Population', 'Castes']
    if not all(s in text for s in required):
        raise ValueError('Unsupported table header')
    if profile not in (PROFILE, SHAH_PROFILE):
        raise ValueError('Unsupported table profile')
    if profile == SHAH_PROFILE and ('Tribes' not in text or not re.search(r'1\s+2\s+3\s+4\s+5\s+6\s+7', text)):
        raise ValueError('Expected seven-column header')
    rows, rejected = [], []
    for number, line in enumerate(text.splitlines(), 1):
        if not re.match(r'^\s*\d{6}\s', line):
            continue
        cells = re.split(r'\s{2,}', line.strip())
        if profile == SHAH_PROFILE:
            match = re.fullmatch(r'\s*(\d{6})\s+(.+?)\s+(WARD No\.-\d+)\s+(EB No\.-\d+(?: SUB-EB No\.\d+)?)\s+(\d+|-)\s+(\d+|-)\s+(\d+|-)\s*', line)
            cells = list(match.groups()) if match else []
        width = 7 if profile == SHAH_PROFILE else 6
        if (len(cells) != width or not re.fullmatch(r'\d{6}', cells[0])
                or not cells[2].startswith('WARD No.') or not cells[3].startswith('EB No.')
                or not all(re.fullmatch(r'\d+|-', c) for c in cells[4:])):
            rejected.append({'line': number, 'raw_line': line, 'reason': 'unsupported_row_shape'})
            continue
        total, sc = [int(c) if c != '-' else None for c in cells[4:6]]
        warnings = ['unverified_pdf_table', 'source_era_geography_not_joined']
        if profile == PROFILE:
            warnings.append('scheduled_tribes_not_extracted_by_this_profile')
        if '-' in cells[4:]:
            warnings.append('printed_dash_preserved_not_zero')
        if total is not None and sc is not None and sc > total:
            warnings.append('scheduled_castes_exceeds_total')
        rows.append(dict(line=number, raw_line=line, raw_cells=cells,
                         town_code=cells[0], town_name=cells[1], ward=cells[2], block=cells[3],
                         total_population=total, scheduled_castes_population=sc,
                         census_edition=2011, review_state='unverified', warnings=warnings))
        if profile == SHAH_PROFILE:
            st = int(cells[6]) if cells[6] != '-' else None
            rows[-1]['scheduled_tribes_population'] = st
            if total is not None and st is not None and st > total:
                warnings.append('scheduled_tribes_exceeds_total')
    if not rows and not rejected:
        raise ValueError('No supported data rows on page')
    return rows, rejected


def extract(root, package, expected, job, digest, resources_ok):
    from ocr_civic_pdf_pages import ResourceWait
    profile = profile_for(expected)
    if digest(package) != expected:
        raise ValueError('Original PDF checksum/profile differs')
    pages = job['pages']
    if (not pages or len(pages) > 25 or len(set(pages)) != len(pages)
            or any(type(p) is not int or p < 1 for p in pages)):
        raise ValueError('Expected 1-25 distinct positive page numbers')
    evidence = root / 'source-evidence'
    prefix = evidence / ('pdf-' + expected)
    manifest = json.loads(prefix.with_suffix('.manifest.json').read_text())
    source = prefix.with_suffix('.pages.jsonl')
    if manifest['original_sha256'] != expected or digest(source) != manifest['pages_jsonl_sha256']:
        raise ValueError('Preserved page evidence checksum differs')
    key = hashlib.sha256(json.dumps([profile, expected, pages]).encode()).hexdigest()
    output = evidence / ('table-' + key + '.jsonl')
    report = evidence / ('table-' + key + '.report.json')
    if report.exists():
        prior = json.loads(report.read_text())
        if digest(output) != prior['rows_sha256']:
            raise ValueError('Structured row receipt checksum differs')
        return prior
    partial = output.with_suffix('.partial')
    count = 0; found = []; rejected = []
    try:
        with source.open(encoding='utf-8') as stream, partial.open('w', encoding='utf-8') as target:
            for raw in stream:
                page = json.loads(raw)
                if page['page'] not in pages:
                    continue
                if not resources_ok(root):
                    raise ResourceWait('Waiting for resources')
                if hashlib.sha256(page['text'].encode()).hexdigest() != page['text_sha256']:
                    raise ValueError('Page text checksum differs')
                rows, failures = parse_page(page['text'], profile); found.append(page['page'])
                rejected.extend(dict(page=page['page'], **r) for r in failures)
                for row in rows:
                    row.update(page=page['page'], page_text_sha256=page['text_sha256'],
                               original_sha256=expected, source_url=manifest['source_url'], profile=profile)
                    target.write(json.dumps(row, ensure_ascii=False) + '\n'); count += 1
        if set(found) != set(pages):
            raise ValueError('Requested pages missing')
        result = dict(profile=profile, original_sha256=expected, pages=found, structured_pdf_rows=count,
                      rejected_rows=rejected, review_state='unverified', rows_sha256=digest(partial),
                      validation='Original/page hashes checked; row shapes checked; not visually or geographically reviewed')
        partial.replace(output)
        temp = report.with_suffix('.partial'); temp.write_text(json.dumps(result, indent=2)+'\n')
        temp.replace(report)
        return result
    finally:
        partial.unlink(missing_ok=True)
