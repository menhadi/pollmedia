"""Corroborate 1974 Uttar Pradesh AC declarations with the official report."""

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
EDITION = '0568bae81d96e55877d1807e'
NAME = 'pollmedia-ac-1974-uttar-pradesh-32-summary-zero-count-results-20261005'
PREDECESSOR = 'b461b7bf4eae97dcbcc0893c349eefb666a3e70a2f5365bbc030a40230b4bb88'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3249-uttar-pradesh-1974/'
SOURCE_FILE = f'{EDITION}-7480.pdf'
SOURCE_SHA = '537e663ab2e60759a6a3b0fe6815a10eadd2f9a0dc5afd3c2faf10107b5cedff'
PENDING = 'Candidate count differs from summary'
REVIEW_CODES = (15, 18, 30, 51, 66, 68, 70, 76, 78, 86, 94, 102, 103, 112,
                154, 160, 167, 188, 201, 232, 243, 263, 267, 270, 314, 319,
                360, 371, 378, 388, 395, 396)


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def section(text: str, start: str, end: str) -> str:
    match = re.search(start + r'\b(.*?)' + end, text, re.I | re.S)
    if match is None:
        raise ValueError(f'Official 1974 Uttar Pradesh summary section missing: {start}')
    return match[1]


def total(text: str) -> int:
    line = re.search(r'(?m)^\s*3\. TOTAL[^\n]*$', text)
    values = re.findall(r'\d+', line[0]) if line else []
    if len(values) < 2:
        raise ValueError('Official 1974 Uttar Pradesh summary total missing')
    return int(values[-1])


def reconcile(row: dict, text: str) -> None:
    code = row['code']
    identity = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text, re.I)
    if (identity is None or int(identity[1]) != code
            or re.sub(r'\s+', ' ', identity[2]).strip() != re.sub(r'\s+', ' ', row['name']).strip()
            or 'CONSTITUENCY DATA - SUMMARY' not in text
            or 'LEGISLATIVE ASSEMBLY OF UTTAR PRADESH' not in text.upper()
            or row['number_of_seats'] != 1 or row['status'] != 'needs_review'
            or row['error'] != PENDING or row.get('source_warning_code') is not None
            or row['reported_candidate_count'] != 0 or row['detail_page'] <= row['summary_page']):
        raise ValueError(f'Official 1974 Uttar Pradesh identity differs: {code}')
    electors = total(section(text, r'II\. ELECTORS', r'III\. ELECTORS WHO VOTED'))
    voters_section = section(text, r'III\. ELECTORS WHO VOTED', r'IV\. VOTES')
    votes_section = section(text, r'IV\. VOTES', r'V\. POLLING STATIONS')
    results = section(text, r'VII\. RESULT', r'rptConstituencySummary')
    contested_line = re.search(r'(?m)^\s*4\. CONTESTED\s+(\d+)\s+(\d+)\s+(\d+)\s*$', text)
    if (electors != row['electors'] or len(row['candidates']) < 2
            or contested_line is None or tuple(map(int, contested_line.groups())) != (0, 0, 0)
            or 'Uncontested' in text):
        raise ValueError(f'Official 1974 Uttar Pradesh electors differ: {code}')
    row['previous_review_note'] = row['error']
    row['original_extraction_warning'] = row['error']
    row['summary_source_file'] = SOURCE_FILE
    row['summary_source_sha256'] = SOURCE_SHA
    row['detail_source_file'] = SOURCE_FILE
    row['detail_source_sha256'] = SOURCE_SHA
    row['official_source_url'] = SOURCE_URL
    row['official_summary_constituency_name'] = identity[2].strip()
    row['official_summary_state'] = 'Uttar Pradesh'
    row['official_summary_candidate_count'] = 0
    row['official_candidate_count_discrepancy'] = {
        'summary_value': 0, 'detailed_rows': len(row['candidates'])}
    voters = total(voters_section)
    printed = {}
    for ordinal, label in ((1, 'POLLED'), (2, 'VALID'), (3, 'REJECTED'), (4, 'MISSING')):
        match = re.search(r'(?m)^\s*' + str(ordinal) + r'\. ' + label + r'\s+(\d+)', votes_section)
        if match is None:
            raise ValueError(f'Official 1974 Uttar Pradesh {label} missing: {code}')
        printed[label] = int(match[1])
    if (voters < 1 or voters > electors or voters != printed['POLLED']
            or (voters, printed['VALID']) != (row['votes_polled'], row['valid_candidate_votes'])
            or printed['VALID'] + printed['REJECTED'] != voters or printed['MISSING'] != 0):
        raise ValueError(f'Official 1974 Uttar Pradesh totals differ: {code}')
    declared = re.findall(r'^\s*(Winner|Runner up)\s*:?\s+(\S+)\s+(.+?)\s+(\d+)\s*$',
                          results, re.I | re.M)
    margin = re.search(r'MARGIN\s*:\s*(\d+)', results, re.I)
    ranked = sorted(row['candidates'], key=lambda candidate: candidate['votes'], reverse=True)
    if (len(declared) != 2 or margin is None or sum(candidate['votes'] for candidate in ranked) != printed['VALID']
            or ranked[0]['votes'] <= ranked[1]['votes']
            or int(margin[1]) != ranked[0]['votes'] - ranked[1]['votes']
            or len({(candidate['candidate_name'], candidate['party_at_election'], candidate['votes'])
                    for candidate in ranked}) != len(ranked)):
        raise ValueError(f'Official 1974 Uttar Pradesh result differs: {code}')
    for source, candidate in zip(declared, ranked[:2], strict=True):
        if (source[2].strip(), source[1], int(source[3])) != (
                candidate['candidate_name'], candidate['party_at_election'], candidate['votes']):
            raise ValueError(f'Official 1974 Uttar Pradesh candidate differs: {code}')
    row['error'] = ('Official summary prints zero contested candidates, but the detailed candidate '
                    'rows sum to its valid votes and match its declared winner and margin. '
                    'The candidate-count discrepancy remains for review.')
    row['source_warning_code'] = 'official_summary_turnout_only'
    row['summary_totals'] = {'electors': electors, 'votes_polled': voters,
                             'valid_candidate_votes': printed['VALID']}
    row['summary_result'] = {'winner': ranked[0]['candidate_name'],
                             'winner_party': ranked[0]['party_at_election'],
                             'winner_votes': ranked[0]['votes'],
                             'runner': ranked[1]['candidate_name'],
                             'runner_party': ranked[1]['party_at_election'],
                             'runner_votes': ranked[1]['votes'], 'margin': int(margin[1])}
    return None


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    source = folder / SOURCE_FILE
    original = folder / 'extraction.json'
    if (source.is_symlink() or original.is_symlink() or digest(source.read_bytes()) != SOURCE_SHA):
        raise ValueError('1974 Uttar Pradesh official source differs')
    old_body = original.read_bytes()
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    before = json.loads(old_body)
    after = json.loads(old_body)
    if (digest(old_body) != PREDECESSOR or manifest['url'] != SOURCE_URL
            or next(file for file in manifest['files'] if file['file'] == SOURCE_FILE)['sha256'] != SOURCE_SHA
            or before['kind'] != 'ac' or before['year'] != 1974
            or before['source_url'] != SOURCE_URL or before['source_file'] != SOURCE_FILE
            or before['source_sha256'] != SOURCE_SHA or len(before['records']) != 424
            or [row['code'] for row in before['records']] != [code for code in range(1, 426) if code != 207]
            or [row['code'] for row in before['records'] if row['status'] == 'needs_review']
            != list(REVIEW_CODES)):
        raise ValueError('1974 Uttar Pradesh extraction provenance differs')
    corrected = []
    with fitz.open(source) as pdf:
        if len(pdf) != 551:
            raise ValueError('1974 Uttar Pradesh PDF page coverage differs')
        for row in after['records']:
            code = row['code']
            if code not in REVIEW_CODES:
                if row['status'] != 'validated':
                    raise ValueError(f'Unexpected 1974 Uttar Pradesh status: {code}')
                continue
            reconcile(row, pdf[row['summary_page'] - 1].get_text(sort=True))
            corrected.append(code)
    if corrected != list(REVIEW_CODES):
        raise ValueError('1974 Uttar Pradesh contest coverage differs')
    common = {'previous_review_note', 'original_extraction_warning',
              'summary_source_file', 'summary_source_sha256', 'detail_source_file',
              'detail_source_sha256', 'official_source_url', 'official_summary_constituency_name',
              'official_summary_state', 'official_summary_candidate_count',
              'official_candidate_count_discrepancy', 'error', 'source_warning_code',
              'summary_totals', 'summary_result'}
    for old, new in zip(before['records'], after['records'], strict=True):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        expected = common if old['code'] in REVIEW_CODES else set()
        if (old['code'] != new['code'] or old['candidates'] != new['candidates'] or changed != expected):
            raise ValueError(f'Unrelated 1974 Uttar Pradesh evidence changed: {old["code"]}')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{PREDECESSOR}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='ac-1974-uttar-pradesh-', dir=root / 'exports') as temporary:
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
                'scope': '32 summary-zero candidate-count 1974 Uttar Pradesh AC declarations',
                'edition': EDITION, 'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
                'source_sha256': SOURCE_SHA, 'previous_sha256': PREDECESSOR,
                'new_sha256': digest(new_body), 'corrected_codes': corrected,
                'source_candidate_count': 0, 'review_status_retained': True,
            }, indent=2))
            release.writestr('IMPORT.sh', multi_seat_import_script())
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'previous_sha256': PREDECESSOR,
            'new_sha256': digest(new_body),
            'corrected': len(corrected), 'validated_untouched': 392}


if __name__ == '__main__':
    print(json.dumps(build()))
