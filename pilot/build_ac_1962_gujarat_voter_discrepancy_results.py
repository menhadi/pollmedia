"""Corroborate 1962 Gujarat AC declarations from the official ECI report."""

import hashlib
import io
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_pc_1951_multi_seat_declared_members import multi_seat_import_script
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
EDITION = 'f1b2d14d54b2420293125662'
NAME = 'pollmedia-ac-1962-gujarat-26-voter-discrepancy-results-20261005'
PREDECESSOR = '7b32f7bf1d095d57163366a745fe51dadfb53ab64faef63b2ce181cd0ea6ef7c'
PRIOR_RELEASE = 'pollmedia-ac-1962-gujarat-124-reconciled-results-20261005'
PRIOR_RELEASE_SHA = 'a6f4484a733d5aa35d34e357b4e9e014184a70853402715962f16ebc4cffe157'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3828-gujarat-1962/'
SOURCE_FILE = f'{EDITION}-9021.pdf'
SOURCE_SHA = '272ad70d1d7cb3b4d65e5ec2f80888108eaae45c3f08c1079048c865ece6b469'
PREVIOUS_ERROR = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.'
TARGET_CODES = {1, 2, 4, 5, 46, 48, 49, 50, 54, 60, 61, 62, 63, 95, 96,
                106, 110, 121, 126, 129, 133, 134, 135, 137, 140, 146}
WITHHELD_CODES = {3, 111, 116, 144}


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def norm(name: str) -> str:
    return re.sub(r'[^a-z0-9]', '', name.casefold())


def section(text: str, start: str, end: str) -> str:
    match = re.search(start + r'\b(.*?)' + end, text, re.I | re.S)
    if match is None:
        raise ValueError(f'Official 1962 Gujarat summary section missing: {start}')
    return match[1]


def total(text: str, ordinal: int = 3) -> int:
    line = re.search(r'(?m)^[ \t]*' + str(ordinal) + r'\. TOTAL[^\n]*$', text, re.I)
    values = re.findall(r'\d+', line[0]) if line else []
    if len(values) < 2:
        raise ValueError('Official 1962 Gujarat summary total missing')
    return int(values[-1])


def summary_pages(pdf: fitz.Document) -> dict[int, tuple[int, str, str]]:
    pages = {}
    for index in range(14, 168):
        text = pdf[index].get_text(sort=True)
        identity = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text, re.I)
        if ('CONSTITUENCY DATA - SUMMARY' not in text
                or 'LEGISLATIVE ASSEMBLY OF GUJARAT' not in text.upper()
                or identity is None or int(identity[1]) in pages):
            raise ValueError(f'Official 1962 Gujarat summary identity differs: {index + 1}')
        pages[int(identity[1])] = (index + 1, text, identity[2].strip())
    if set(pages) != set(range(1, 155)):
        raise ValueError('Official 1962 Gujarat 154-summary coverage differs')
    return pages


def reconcile(record: dict, page: int, text: str, source_name: str) -> str:
    code = record['code']
    if (record['number_of_seats'] != 1 or record['state_name'] != 'Gujarat'
            or record['status'] != 'needs_review' or record.get('summary_page') is not None
            or record.get('source_warning_code') is not None or norm(source_name) != norm(record['name'])):
        raise ValueError(f'1962 Gujarat one-seat identity differs: {code}')
    electors = total(section(text, r'II\. ELECTORS', r'III\. ELECTORS WHO VOTED'))
    if electors != record['electors']:
        raise ValueError(f'1962 Gujarat electors differ: {code}')
    voter_section = section(text, r'III\. ELECTORS WHO VOTED', r'IV\. VOTES')
    vote_section = section(text, r'IV\. VOTES', r'V\. POLLING STATIONS')
    result_section = section(text, r'VII\. RESULT', r'rptConstituencySummary')
    if record['error'] != PREVIOUS_ERROR or len(record['candidates']) < 2 or 'Uncontested' in text:
        raise ValueError(f'1962 Gujarat contested extraction differs: {code}')
    voters = total(voter_section)
    polled = re.search(r'(?m)^[ \t]*1\. POLLED[ \t]+(\d+)', vote_section)
    valid = re.search(r'(?m)^[ \t]*2\. VALID[ \t]+(\d+)', vote_section)
    rejected = re.search(r'(?m)^[ \t]*3\. REJECTED[ \t]+(\d+)', vote_section)
    missing = re.search(r'(?m)^[ \t]*4\. MISSING[ \t]+(\d+)', vote_section)
    if (polled is None or valid is None or voters < 1 or voters > electors
            or rejected is None or missing is None
            or int(polled[1]) != voters or int(valid[1]) != record['valid_candidate_votes']
            or int(valid[1]) + int(rejected[1]) != record['votes_polled']
            or int(missing[1]) < 1
            or record['votes_polled'] + int(missing[1]) != voters):
        raise ValueError(f'1962 Gujarat printed voter discrepancy differs: {code}')
    declared = re.findall(r'^[ \t]*(Winner|Runner up)[ \t]*:?[ \t]+(\S+)[ \t]+(.+?)[ \t]+(\d+)[ \t]*$',
                          result_section, re.I | re.M)
    margin = re.search(r'MARGIN\s*:\s*(\d+)', result_section, re.I)
    if (len(declared) != 2 or [row[0].lower() for row in declared] != ['winner', 'runner up']
            or margin is None or int(declared[0][3]) <= int(declared[1][3])
            or int(declared[0][3]) - int(declared[1][3]) != int(margin[1])):
        raise ValueError(f'1962 Gujarat declared result differs: {code}')
    ranked = sorted(record['candidates'], key=lambda row: row['votes'], reverse=True)
    if (sum(row['votes'] for row in ranked) != int(valid[1])
            or ranked[0]['votes'] <= ranked[1]['votes']
            or len({(row['candidate_name'], row['party_at_election'], row['votes']) for row in ranked}) != len(ranked)):
        raise ValueError(f'1962 Gujarat candidate rows differ: {code}')
    for source, candidate in zip(declared, ranked[:2], strict=True):
        if (source[2].strip(), source[1], int(source[3])) != (
                candidate['candidate_name'], candidate['party_at_election'], candidate['votes']):
            raise ValueError(f'1962 Gujarat summary/detail conflict: {code}')
    detail_voters = record['votes_polled']
    record['previous_review_note'] = record['error']
    record['original_extraction_warning'] = record['error']
    record['summary_page'] = page
    record['summary_source_file'] = SOURCE_FILE
    record['summary_source_sha256'] = SOURCE_SHA
    record['detail_source_file'] = SOURCE_FILE
    record['detail_source_sha256'] = SOURCE_SHA
    record['official_source_url'] = SOURCE_URL
    record['official_summary_constituency_name'] = source_name
    record['official_summary_state'] = 'Gujarat'
    record['error'] = (f'Summary and detailed totals differ: votes_polled: detail {detail_voters}, '
                       f'summary {voters}. The difference is {missing[1]}, printed as MISSING votes '
                       'in the official summary. Winner and margin agree; review both voter figures.')
    record['source_discrepancy'] = {'field': 'votes_polled', 'detail_value': detail_voters,
                                    'summary_value': voters, 'missing_votes': int(missing[1])}
    record['summary_totals'] = {'electors': electors, 'votes_polled': voters,
                                'valid_candidate_votes': int(valid[1])}
    record['summary_result'] = {'winner': ranked[0]['candidate_name'],
                                'winner_party': ranked[0]['party_at_election'],
                                'winner_votes': ranked[0]['votes'],
                                'runner': ranked[1]['candidate_name'],
                                'runner_party': ranked[1]['party_at_election'],
                                'runner_votes': ranked[1]['votes'], 'margin': int(margin[1])}
    record['winner'] = ranked[0]['candidate_name']
    record['margin'] = int(margin[1])
    return 'printed-voter-discrepancy'


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    source = folder / SOURCE_FILE
    prior_release = root / 'exports' / (PRIOR_RELEASE + '.zip')
    if (prior_release.is_symlink() or digest(prior_release.read_bytes()) != PRIOR_RELEASE_SHA
            or source.is_symlink() or digest(source.read_bytes()) != SOURCE_SHA):
        raise ValueError('1962 Gujarat queued predecessor or source PDF differs')
    with zipfile.ZipFile(prior_release) as release:
        with zipfile.ZipFile(io.BytesIO(release.read(f'correction-{EDITION}.zip'))) as prior:
            old_body = prior.read(f'election-archive/{EDITION}/extraction.json')
    if digest(old_body) != PREDECESSOR:
        raise ValueError('1962 Gujarat queued extraction bytes differ')
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    before = json.loads(old_body)
    after = json.loads(old_body)
    if (manifest['url'] != SOURCE_URL
            or next(file for file in manifest['files'] if file['file'] == SOURCE_FILE)['sha256'] != SOURCE_SHA
            or before['kind'] != 'ac' or before['year'] != 1962
            or before['source_url'] != SOURCE_URL or before['source_file'] != SOURCE_FILE
            or before['source_sha256'] != SOURCE_SHA or len(before['records']) != 154):
        raise ValueError('1962 Gujarat archive provenance differs')
    seen = []
    with fitz.open(source) as pdf:
        pages = summary_pages(pdf)
        for record in after['records']:
            code = record['code']
            if code not in TARGET_CODES:
                continue
            page, text, source_name = pages[code]
            if reconcile(record, page, text, source_name) != 'printed-voter-discrepancy':
                raise ValueError(f'1962 Gujarat result kind differs: {code}')
            seen.append({'code': code, 'summary_page': page})
    if (len(TARGET_CODES) != 26 or len(WITHHELD_CODES) != 4 or len(seen) != 26
            or TARGET_CODES & WITHHELD_CODES
            or [item['code'] for item in seen] != sorted(TARGET_CODES)):
        raise ValueError('1962 Gujarat one-seat coverage differs')
    common = {'previous_review_note', 'original_extraction_warning', 'summary_page',
              'summary_source_file', 'summary_source_sha256', 'detail_source_file',
              'detail_source_sha256', 'official_source_url',
              'official_summary_constituency_name', 'official_summary_state',
              'error', 'source_discrepancy', 'summary_totals'}
    for old, new in zip(before['records'], after['records'], strict=True):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        expected = common | {'summary_result', 'winner', 'margin'}
        if (old['code'] != new['code'] or old['candidates'] != new['candidates']
                or changed != (expected if old['code'] in TARGET_CODES else set())):
            raise ValueError(f'Unrelated 1962 Gujarat evidence changed: {old["code"]}')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{PREDECESSOR}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='ac-1962-gujarat-', dir=root / 'exports') as temporary:
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
                'scope': '26 printed-voter-discrepancy 1962 Gujarat AC one-seat declarations',
                'edition': EDITION, 'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
                'source_sha256': SOURCE_SHA, 'previous_sha256': PREDECESSOR,
                'new_sha256': digest(new_body), 'results': seen,
                'withheld_review_codes': sorted(WITHHELD_CODES),
            }, indent=2))
            release.writestr('IMPORT.sh', multi_seat_import_script())
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'previous_sha256': PREDECESSOR,
            'new_sha256': digest(new_body), 'discrepancies': 26,
            'withheld_review': len(WITHHELD_CODES)}


if __name__ == '__main__':
    print(json.dumps(build()))
