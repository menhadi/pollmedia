"""Corroborate 1962 Andhra Pradesh AC declarations from the official ECI report."""

import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_pc_1951_multi_seat_declared_members import multi_seat_import_script
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
EDITION = '67cbc63183f169509782123b'
NAME = 'pollmedia-ac-1962-andhra-pradesh-288-reconciled-results-20261005'
PREDECESSOR = '62f7c42a8af02aeb62e42eb0beebf1ffa80701b5813629055400fa2de832836d'
SOURCE_URL = 'https://old.eci.gov.in/files/file/4044-andhra-pradesh-1962/'
SOURCE_FILE = f'{EDITION}-9592.pdf'
SOURCE_SHA = '41651d1c51c279be933521c7fc16f36a95b614adb0260144335f73ca72aac375'
PREVIOUS_ERROR = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.'
RESULT_NOTE = ('Official 1962 Andhra Pradesh summary corroborates turnout, named winner, '
               'runner-up and margin; original detailed-PDF extraction warning remains for review.')
EXCEPTION_CODES = {6, 13, 18, 19, 57, 58, 59, 179, 190, 199, 226, 244}


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def norm(name: str) -> str:
    return re.sub(r'[^a-z0-9]', '', name.casefold())


def section(text: str, start: str, end: str) -> str:
    match = re.search(start + r'\b(.*?)' + end, text, re.I | re.S)
    if match is None:
        raise ValueError(f'Official 1962 Andhra Pradesh summary section missing: {start}')
    return match[1]


def total(text: str, ordinal: int = 3) -> int:
    line = re.search(r'(?m)^[ \t]*' + str(ordinal) + r'\. TOTAL[^\n]*$', text, re.I)
    values = re.findall(r'\d+', line[0]) if line else []
    if len(values) < 2:
        raise ValueError('Official 1962 Andhra Pradesh summary total missing')
    return int(values[-1])


def summary_pages(pdf: fitz.Document) -> dict[int, tuple[int, str, str]]:
    pages = {}
    for index in range(18, 318):
        text = pdf[index].get_text(sort=True)
        identity = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text, re.I)
        if ('CONSTITUENCY DATA - SUMMARY' not in text
                or 'LEGISLATIVE ASSEMBLY OF ANDHRA PRADESH' not in text.upper()
                or identity is None or int(identity[1]) in pages):
            raise ValueError(f'Official 1962 Andhra Pradesh summary identity differs: {index + 1}')
        pages[int(identity[1])] = (index + 1, text, identity[2].strip())
    if set(pages) != set(range(1, 301)):
        raise ValueError('Official 1962 Andhra Pradesh 300-summary coverage differs')
    return pages


def reconcile(record: dict, page: int, text: str, source_name: str) -> str:
    code = record['code']
    if (record['number_of_seats'] != 1 or record['state_name'] != 'Andhra Pradesh'
            or record['status'] != 'needs_review' or record.get('summary_page') is not None
            or record.get('source_warning_code') is not None or norm(source_name) != norm(record['name'])):
        raise ValueError(f'1962 Andhra Pradesh one-seat identity differs: {code}')
    electors = total(section(text, r'II\. ELECTORS', r'III\. ELECTORS WHO VOTED'))
    if electors != record['electors']:
        raise ValueError(f'1962 Andhra Pradesh electors differ: {code}')
    voter_section = section(text, r'III\. ELECTORS WHO VOTED', r'IV\. VOTES')
    vote_section = section(text, r'IV\. VOTES', r'V\. POLLING STATIONS')
    result_section = section(text, r'VII\. RESULT', r'rptConstituencySummary')
    record['previous_review_note'] = record['error']
    record['original_extraction_warning'] = record['error']
    record['summary_page'] = page
    record['summary_source_file'] = SOURCE_FILE
    record['summary_source_sha256'] = SOURCE_SHA
    record['detail_source_file'] = SOURCE_FILE
    record['detail_source_sha256'] = SOURCE_SHA
    record['official_source_url'] = SOURCE_URL
    record['official_summary_constituency_name'] = source_name
    record['official_summary_state'] = 'Andhra Pradesh'

    if record['error'] != PREVIOUS_ERROR or len(record['candidates']) < 2 or 'Uncontested' in text:
        raise ValueError(f'1962 Andhra Pradesh contested extraction differs: {code}')
    voters = total(voter_section)
    polled = re.search(r'(?m)^[ \t]*1\. POLLED[ \t]+(\d+)', vote_section)
    valid = re.search(r'(?m)^[ \t]*2\. VALID[ \t]+(\d+)', vote_section)
    rejected = re.search(r'(?m)^[ \t]*3\. REJECTED[ \t]+(\d+)', vote_section)
    missing = re.search(r'(?m)^[ \t]*4\. MISSING[ \t]+(\d+)', vote_section)
    if (polled is None or valid is None or rejected is None or missing is None
            or voters < 1 or voters > electors
            or (voters, int(polled[1]), int(valid[1])) !=
            (record['votes_polled'], record['votes_polled'], record['valid_candidate_votes'])
            or int(valid[1]) + int(rejected[1]) != voters or int(missing[1]) != 0):
        raise ValueError(f'1962 Andhra Pradesh contested totals differ: {code}')
    declared = re.findall(r'^[ \t]*(Winner|Runner up)[ \t]*:?[ \t]+(\S+)[ \t]+(.+?)[ \t]+(\d+)[ \t]*$',
                          result_section, re.I | re.M)
    margin = re.search(r'MARGIN\s*:\s*(\d+)', result_section, re.I)
    if (len(declared) != 2 or [row[0].lower() for row in declared] != ['winner', 'runner up']
            or margin is None or int(declared[0][3]) <= int(declared[1][3])
            or int(declared[0][3]) - int(declared[1][3]) != int(margin[1])):
        raise ValueError(f'1962 Andhra Pradesh declared result differs: {code}')
    ranked = sorted(record['candidates'], key=lambda row: row['votes'], reverse=True)
    if (sum(row['votes'] for row in ranked) != int(valid[1])
            or ranked[0]['votes'] <= ranked[1]['votes']
            or len({(row['candidate_name'], row['party_at_election'], row['votes']) for row in ranked}) != len(ranked)):
        raise ValueError(f'1962 Andhra Pradesh candidate rows differ: {code}')
    for source, candidate in zip(declared, ranked[:2], strict=True):
        if (source[2].strip(), source[1], int(source[3])) != (
                candidate['candidate_name'], candidate['party_at_election'], candidate['votes']):
            raise ValueError(f'1962 Andhra Pradesh summary/detail conflict: {code}')
    record['error'] = RESULT_NOTE
    record['source_warning_code'] = 'official_summary_turnout_only'
    record['summary_totals'] = {'electors': electors, 'votes_polled': voters,
                                'valid_candidate_votes': int(valid[1])}
    record['summary_result'] = {'winner': ranked[0]['candidate_name'],
                                'winner_party': ranked[0]['party_at_election'],
                                'winner_votes': ranked[0]['votes'],
                                'runner': ranked[1]['candidate_name'],
                                'runner_party': ranked[1]['party_at_election'],
                                'runner_votes': ranked[1]['votes'], 'margin': int(margin[1])}
    return 'contested'


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    source = folder / SOURCE_FILE
    old_body = (folder / 'extraction.json').read_bytes()
    if digest(old_body) != PREDECESSOR or source.is_symlink() or digest(source.read_bytes()) != SOURCE_SHA:
        raise ValueError('1962 Andhra Pradesh live precursor or source PDF differs')
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    before = json.loads(old_body)
    after = json.loads(old_body)
    if (manifest['url'] != SOURCE_URL
            or next(file for file in manifest['files'] if file['file'] == SOURCE_FILE)['sha256'] != SOURCE_SHA
            or before['kind'] != 'ac' or before['year'] != 1962
            or before['source_url'] != SOURCE_URL or before['source_file'] != SOURCE_FILE
            or before['source_sha256'] != SOURCE_SHA or len(before['records']) != 300):
        raise ValueError('1962 Andhra Pradesh archive provenance differs')
    seen = []
    with fitz.open(source) as pdf:
        if len(pdf) != 357:
            raise ValueError('Official 1962 Andhra Pradesh PDF page count differs')
        pages = summary_pages(pdf)
        for record in after['records']:
            code = record['code']
            if code in EXCEPTION_CODES:
                continue
            page, text, source_name = pages[code]
            if reconcile(record, page, text, source_name) != 'contested':
                raise ValueError(f'1962 Andhra Pradesh result kind differs: {code}')
            seen.append({'code': code, 'summary_page': page})
    if len(seen) != 288 or [item['code'] for item in seen] != [code for code in range(1, 301) if code not in EXCEPTION_CODES]:
        raise ValueError('1962 Andhra Pradesh one-seat coverage differs')
    common = {'previous_review_note', 'original_extraction_warning', 'summary_page',
              'summary_source_file', 'summary_source_sha256', 'detail_source_file',
              'detail_source_sha256', 'official_source_url',
              'official_summary_constituency_name', 'official_summary_state',
              'error', 'source_warning_code', 'summary_totals'}
    for old, new in zip(before['records'], after['records'], strict=True):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        expected = common | {'summary_result'} if old['code'] not in EXCEPTION_CODES else set()
        if old['code'] != new['code'] or old['candidates'] != new['candidates'] or changed != expected:
            raise ValueError(f'Unrelated 1962 Andhra Pradesh evidence changed: {old["code"]}')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{PREDECESSOR}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='ac-1962-andhra-', dir=root / 'exports') as temporary:
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
                'scope': '288 source-reconciled contested 1962 Andhra Pradesh AC one-seat declarations',
                'edition': EDITION, 'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
                'source_sha256': SOURCE_SHA, 'previous_sha256': PREDECESSOR,
                'new_sha256': digest(new_body), 'results': seen,
            }, indent=2))
            release.writestr('IMPORT.sh', multi_seat_import_script())
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'previous_sha256': PREDECESSOR,
            'new_sha256': digest(new_body), 'contested': 288}


if __name__ == '__main__':
    print(json.dumps(build()))
