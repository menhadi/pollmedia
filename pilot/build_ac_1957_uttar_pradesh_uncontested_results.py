"""Confirm four 1957 Uttar Pradesh AC one-seat uncontested declarations."""

import hashlib
import io
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_ac_1957_uttar_pradesh_multi_seat_declared_members import norm, source_total, summary_pages
from build_pc_1951_multi_seat_declared_members import multi_seat_import_script
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
EDITION = 'd31cb3f180e44ec4b9e59209'
NAME = 'pollmedia-ac-1957-uttar-pradesh-four-uncontested-results-20261005'
PREDECESSOR = 'd3011f7a20a88e6a557ac892bad642a9922fbb6112f067cb022952e78fa90c5f'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3242-uttar-pradesh-1957/'
SOURCE_FILE = EDITION + '-7464.pdf'
SOURCE_SHA = '7e0ea66649df0c20d8e381018ce33e049bc16c139c3facf09b04d935019da98c'
CODES = (11, 12, 13, 60)
PREVIOUS_ERROR = 'Report gives zero votes and no usable vote percentage; uncontested status requires source review'
NOTE = ('Official 1957 Uttar Pradesh summary declares the named candidate returned uncontested. '
        'It gives no voter or vote total; archived zero fields are retained as extraction values, '
        'not interpreted as measured votes or turnout.')


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def predecessor_bytes(root: Path) -> bytes:
    release = root / 'exports' / 'pollmedia-ac-1957-uttar-pradesh-source-state-and-multi-seat-20261004.zip'
    with zipfile.ZipFile(release) as outer:
        with zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{EDITION}.zip'))) as inner:
            body = inner.read(f'election-archive/{EDITION}/extraction.json')
    if digest(body) != PREDECESSOR:
        raise ValueError('Queued 1957 Uttar Pradesh predecessor differs')
    return body


def reconcile(record: dict, page: int, text: str, seat: re.Match) -> dict:
    code = record['code']
    if (code not in CODES or record['number_of_seats'] != 1 or record['state_name'] != 'Uttar Pradesh'
            or record['status'] != 'needs_review' or record['error'] != PREVIOUS_ERROR
            or record['summary_page'] != page or record.get('summary_source_rows') is not None
            or record.get('source_warning_code') is not None or len(record['candidates']) != 1
            or record['votes_polled'] != 0 or record['valid_candidate_votes'] != 0
            or record['candidates'][0]['votes'] != 0 or int(seat[1]) != code or int(seat[3]) != 1
            or norm(seat[2]) != norm(record['name'])
            or 'LEGISLATIVE ASSEMBLY OF UTTAR PRADESH' not in text.upper()):
        raise ValueError(f'1957 Uttar Pradesh uncontested identity differs: {code}')
    electors = source_total(text, r'II\. ELECTORS', r'III\. ELECTORS WHO VOTED')
    if electors != record['electors']:
        raise ValueError(f'1957 Uttar Pradesh elector count differs: {code}')
    voter = re.search(r'III\. ELECTORS WHO VOTED\b(.*?)IV\. VOTES\b', text, re.I | re.S)
    votes = re.search(r'IV\. VOTES\b(.*?)VI\. DATES\b', text, re.I | re.S)
    result = re.search(r'VII\. RESULT\b(.*?)rptConstituencySummary', text, re.I | re.S)
    if voter is None or votes is None or result is None:
        raise ValueError(f'1957 Uttar Pradesh uncontested sections missing: {code}')
    if (re.search(r'(?m)^[ \t]*1\. TOTAL[ \t]+\d+[^\n]*$', voter[1])
            or re.search(r'(?m)^[ \t]*1\. POLLED[ \t]+\d+[^\n]*$', votes[1])
            or re.search(r'(?m)^[ \t]*2\. VALID[ \t]+\d+[^\n]*$', votes[1])
            or re.search(r'(?m)^[ \t]*MARGIN[ \t]*:[ \t]*\d+', result[1])
            or 'Uncontested' not in voter[1] or 'Uncontested' not in votes[1]):
        raise ValueError(f'1957 Uttar Pradesh uncontested page reports numeric votes: {code}')
    declaration = re.search(r'Winner\s+(\S+)\s+(.+?)\s+Returned\s+Uncontested', result[1], re.I | re.S)
    candidate = record['candidates'][0]
    if (declaration is None or (declaration[2].strip(), declaration[1]) !=
            (candidate['candidate_name'], candidate['party_at_election'])
            or not re.search(r'Runner up\s*\n\s*MARGIN\s*:', result[1], re.I)):
        raise ValueError(f'1957 Uttar Pradesh uncontested winner differs: {code}')
    record['previous_review_note'] = record['error']
    record['original_extraction_warning'] = record['error']
    record['error'] = NOTE
    record['source_warning_code'] = 'official_uncontested_summary'
    record['official_source_url'] = SOURCE_URL
    record['summary_source_file'] = SOURCE_FILE
    record['summary_source_sha256'] = SOURCE_SHA
    record['detail_source_file'] = SOURCE_FILE
    record['detail_source_sha256'] = SOURCE_SHA
    record['official_summary_constituency_name'] = seat[2].strip()
    record['summary_source_rows'] = [f"Winner {declaration[1]} {declaration[2].strip()} Returned Uncontested"]
    record['summary_totals'] = {'electors': electors, 'votes_polled': None,
                                'valid_candidate_votes': None}
    return {'code': code, 'summary_page': page, 'winner': candidate['candidate_name'],
            'party': candidate['party_at_election'], 'electors': electors,
            'reported_voters': None, 'reported_votes': None, 'margin': None}


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    source = folder / SOURCE_FILE
    old_body = predecessor_bytes(root)
    if source.is_symlink() or digest(source.read_bytes()) != SOURCE_SHA:
        raise ValueError('Official 1957 Uttar Pradesh PDF differs')
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    before = json.loads(old_body)
    after = json.loads(old_body)
    if (manifest['url'] != SOURCE_URL
            or next(file for file in manifest['files'] if file['file'] == SOURCE_FILE)['sha256'] != SOURCE_SHA
            or before['kind'] != 'ac' or before['year'] != 1957
            or before['source_url'] != SOURCE_URL or before['source_file'] != SOURCE_FILE
            or before['source_sha256'] != SOURCE_SHA or len(before['records']) != 341):
        raise ValueError('1957 Uttar Pradesh archive provenance differs')
    with fitz.open(source) as pdf:
        pages = summary_pages(pdf)
        seen = [reconcile(row, *pages[row['code']]) for row in after['records'] if row['code'] in CODES]
    if [item['code'] for item in seen] != list(CODES):
        raise ValueError('1957 Uttar Pradesh uncontested coverage differs')
    allowed = {'previous_review_note', 'original_extraction_warning', 'error',
               'source_warning_code', 'official_source_url', 'summary_source_file',
               'summary_source_sha256', 'detail_source_file', 'detail_source_sha256',
               'official_summary_constituency_name', 'summary_source_rows', 'summary_totals'}
    for old, new in zip(before['records'], after['records'], strict=True):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        if old['code'] != new['code'] or old['candidates'] != new['candidates'] or changed != (allowed if old['code'] in CODES else set()):
            raise ValueError(f'Unrelated 1957 Uttar Pradesh evidence changed: {old["code"]}')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{PREDECESSOR}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='ac-1957-up-uncontested-', dir=root / 'exports') as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        archives = []
        for kind, relative, body in (('snapshot', snapshot, old_body), ('correction', revision, new_body)):
            path = staged / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(body)
            bucket = hashlib.sha256(relative.encode()).digest()[0] % 8
            archive = packages / f'{kind}-{EDITION}.zip'
            package(staged, archive, 'election-archive', bucket, 8, [relative],
                    PREDECESSOR if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
            archives.append(archive)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as release:
            for archive in archives:
                release.write(archive, archive.name)
            release.writestr('SHA256SUMS', ''.join(f'{digest(archive.read_bytes())}  {archive.name}\n'
                                                for archive in archives))
            release.writestr('ARCHIVES', EDITION + '\n')
            release.writestr('AUDIT.json', json.dumps({
                'scope': 'Four official uncontested 1957 Uttar Pradesh AC one-seat declarations',
                'edition': EDITION, 'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
                'source_sha256': SOURCE_SHA, 'previous_sha256': PREDECESSOR,
                'new_sha256': digest(new_body), 'results': seen,
                'retained_other_one_seat_results': 248, 'retained_multi_seat_records': 89,
            }, indent=2))
            release.writestr('IMPORT.sh', multi_seat_import_script())
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'previous_sha256': PREDECESSOR,
            'new_sha256': digest(new_body), 'uncontested': len(seen)}


if __name__ == '__main__':
    print(json.dumps(build()))
