"""Reconcile ordinary 1971 Tamil Nadu AC results from the official ECI report."""

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
EDITION = '7a130d7480f6fd17a797d5aa'
NAME = 'pollmedia-ac-1971-tamil-nadu-232-reconciled-results-20261005'
PRIOR_NAME = 'pollmedia-ac-tamil-nadu-1971-two-invalid-turnout-results-20261004'
PRIOR_ZIP_SHA = '1e94ebb7a35281ce2f27be8f058afdb058fef4171350e4900affad1b1ad34a7c'
PREDECESSOR = '597fd3a4de818b0d4e136c7c43c05f96090ca8005ae9cf0879e4b39d537aa037'
ORIGINAL_SHA = '5294d1dfb330585d9e5b3b1077d81cf207cf391cedde69a1585d08ef1c32a64a'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3326-tamil-nadu-1971/'
SOURCE_FILE = f'{EDITION}-7685.pdf'
SOURCE_SHA = '9c57d16fc1e05bd43aa0896e80fe6fc946960eb115a360b6b8099fcd404a6dcd'
PENDING = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.'
WITHHELD = {152, 195}


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def section(text: str, start: str, end: str) -> str:
    match = re.search(start + r'\b(.*?)' + end, text, re.I | re.S)
    if match is None:
        raise ValueError(f'Official 1971 Tamil Nadu summary section missing: {start}')
    return match[1]


def official_total(text: str, ordinal: int, label: str) -> int:
    match = re.search(r'(?m)^\s*' + str(ordinal) + r'\.\s+' + label + r'\s+(.+)$', text, re.I)
    if match is None:
        raise ValueError(f'Official 1971 Tamil Nadu {label} missing')
    digits = re.findall(r'\d+', match[1])
    if not digits:
        raise ValueError(f'Official 1971 Tamil Nadu {label} total missing')
    return int(digits[-1] if label == 'TOTAL' else digits[0])


def reconcile(row: dict, page: int, text: str) -> None:
    code = row['code']
    identity = re.search(r'Field7:CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text, re.I)
    if (identity is None or int(identity[1]) != code
            or re.sub(r'\s+', ' ', identity[2]).strip() != re.sub(r'\s+', ' ', row['name']).strip()
            or page != code + 14
            or 'CONSTITUENCY DATA - SUMMARY' not in text
            or 'LEGISLATIVE ASSEMBLY OF TAMIL NAIDU' not in text.upper()
            or row['state_name'] != 'Tamil Nadu' or row['number_of_seats'] != 1
            or row['status'] != 'needs_review' or row['error'] != PENDING
            or row.get('summary_page') is not None or row.get('source_warning_code') is not None
            or len(row['candidates']) < 2):
        raise ValueError(f'Official 1971 Tamil Nadu identity differs: {code}')
    electors = official_total(section(text, r'II\. ELECTORS', r'III\. ELECTORS WHO VOTED'), 3, 'TOTAL')
    voters_section = section(text, r'III\. ELECTORS WHO VOTED', r'IV\. VOTES')
    votes_section = section(text, r'IV\. VOTES', r'V\. POLLING STATIONS')
    result_section = section(text, r'VII\. RESULT', r'rptConstituencySummary')
    voters = official_total(voters_section, 3, 'TOTAL')
    printed = {label: official_total(votes_section, ordinal, label)
               for ordinal, label in ((1, 'POLLED'), (2, 'VALID'), (3, 'REJECTED'), (4, 'MISSING'))}
    if (electors != row['electors'] or voters < 1 or voters > electors
            or voters != printed['POLLED'] or printed['VALID'] + printed['REJECTED'] != voters
            or printed['MISSING'] != 0
            or (voters, printed['VALID']) != (row['votes_polled'], row['valid_candidate_votes'])):
        raise ValueError(f'Official 1971 Tamil Nadu totals differ: {code}')
    declared = re.findall(r'^\s*(Winner|Runner up)\s*:\s*(\S+)\s+(.+?)\s+(\d+)\s*$',
                          result_section, re.I | re.M)
    margin = re.search(r'MARGIN\s*:\s*(\d+)', result_section, re.I)
    ranked = sorted(row['candidates'], key=lambda candidate: candidate['votes'], reverse=True)
    if (len(declared) != 2 or margin is None
            or sum(candidate['votes'] for candidate in ranked) != printed['VALID']
            or ranked[0]['votes'] <= ranked[1]['votes']
            or int(margin[1]) != ranked[0]['votes'] - ranked[1]['votes']
            or len({(candidate['candidate_name'], candidate['party_at_election'], candidate['votes'])
                    for candidate in ranked}) != len(ranked)):
        raise ValueError(f'Official 1971 Tamil Nadu result differs: {code}')
    for source, candidate in zip(declared, ranked[:2], strict=True):
        if (source[2].strip(), source[1], int(source[3])) != (
                candidate['candidate_name'], candidate['party_at_election'], candidate['votes']):
            raise ValueError(f'Official 1971 Tamil Nadu candidate differs: {code}')
    row['previous_review_note'] = row['error']
    row['original_extraction_warning'] = row['error']
    row['summary_page'] = page
    row['summary_source_file'] = SOURCE_FILE
    row['summary_source_sha256'] = SOURCE_SHA
    row['detail_source_file'] = SOURCE_FILE
    row['detail_source_sha256'] = SOURCE_SHA
    row['official_source_url'] = SOURCE_URL
    row['official_summary_constituency_name'] = identity[2].strip()
    row['official_summary_state'] = 'Tamil Naidu'
    row['error'] = ('Official 1971 Tamil Nadu summary corroborates the detailed electors, voters, '
                    'valid votes, declared winner and margin; archived review warning retained. '
                    'The source header prints Tamil Naidu.')
    row['source_warning_code'] = 'official_summary_turnout_only'
    row['summary_totals'] = {'electors': electors, 'votes_polled': voters,
                             'valid_candidate_votes': printed['VALID']}
    row['summary_result'] = {'winner': ranked[0]['candidate_name'],
                             'winner_party': ranked[0]['party_at_election'],
                             'winner_votes': ranked[0]['votes'],
                             'runner': ranked[1]['candidate_name'],
                             'runner_party': ranked[1]['party_at_election'],
                             'runner_votes': ranked[1]['votes'], 'margin': int(margin[1])}


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    source = folder / SOURCE_FILE
    original = folder / 'extraction.json'
    prior_zip = root / 'exports' / (PRIOR_NAME + '.zip')
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    if (digest(source.read_bytes()) != SOURCE_SHA or digest(original.read_bytes()) != ORIGINAL_SHA
            or digest(prior_zip.read_bytes()) != PRIOR_ZIP_SHA
            or manifest['url'] != SOURCE_URL
            or next(file for file in manifest['files'] if file['file'] == SOURCE_FILE)['sha256'] != SOURCE_SHA):
        raise ValueError('1971 Tamil Nadu source/predecessor provenance differs')
    with zipfile.ZipFile(prior_zip) as release:
        with zipfile.ZipFile(io.BytesIO(release.read(f'correction-{EDITION}.zip'))) as inner:
            old_body = inner.read(f'election-archive/{EDITION}/extraction.json')
    if digest(old_body) != PREDECESSOR:
        raise ValueError('1971 Tamil Nadu corrected predecessor differs')
    before = json.loads(old_body)
    after = json.loads(old_body)
    if (before['kind'] != 'ac' or before['year'] != 1971
            or before['source_url'] != SOURCE_URL or before['source_file'] != SOURCE_FILE
            or before['source_sha256'] != SOURCE_SHA or len(before['records']) != 234
            or [row['code'] for row in before['records']] != list(range(1, 235))):
        raise ValueError('1971 Tamil Nadu predecessor shape differs')
    with fitz.open(source) as pdf:
        if len(pdf) != 277:
            raise ValueError('1971 Tamil Nadu PDF page coverage differs')
        for row in after['records']:
            code = row['code']
            if code not in WITHHELD:
                reconcile(row, code + 14, pdf[code + 13].get_text(sort=True))
    common = {'previous_review_note', 'original_extraction_warning', 'summary_page',
              'summary_source_file', 'summary_source_sha256', 'detail_source_file',
              'detail_source_sha256', 'official_source_url', 'official_summary_constituency_name',
              'official_summary_state', 'error', 'source_warning_code', 'summary_totals', 'summary_result'}
    for old, new in zip(before['records'], after['records'], strict=True):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        expected = set() if old['code'] in WITHHELD else common
        if (old['code'] != new['code'] or old['candidates'] != new['candidates'] or changed != expected):
            raise ValueError(f'Unrelated 1971 Tamil Nadu evidence changed: {old["code"]}')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{PREDECESSOR}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='ac-1971-tamil-nadu-', dir=root / 'exports') as temporary:
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
                'scope': '232 ordinary contested 1971 Tamil Nadu AC declarations',
                'edition': EDITION, 'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
                'source_sha256': SOURCE_SHA, 'previous_sha256': PREDECESSOR,
                'new_sha256': digest(new_body),
                'reconciled_codes': [code for code in range(1, 235) if code not in WITHHELD],
                'withheld_invalid_turnout_codes': sorted(WITHHELD),
            }, indent=2))
            release.writestr('IMPORT.sh', multi_seat_import_script())
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'previous_sha256': PREDECESSOR,
            'new_sha256': digest(new_body), 'reconciled': 232,
            'withheld_invalid_turnout': sorted(WITHHELD)}


if __name__ == '__main__':
    print(json.dumps(build()))
