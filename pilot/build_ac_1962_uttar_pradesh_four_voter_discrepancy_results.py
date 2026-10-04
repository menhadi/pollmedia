"""Corroborate four 1962 Uttar Pradesh AC results with printed missing votes."""

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
EDITION = '0e914ee54c0367b069fc4586'
NAME = 'pollmedia-ac-1962-uttar-pradesh-four-voter-discrepancy-results-20261005'
PREDECESSOR = 'a1af49b19d2b98b58ff4113ffe4549ca7a58303c514c16dc7b16e7600ad3cbe6'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3244-uttar-pradesh-1962/'
SOURCE_FILE = f'{EDITION}-7467.pdf'
SOURCE_SHA = '7013ca979c8cd3dbef804d37da2f84571af543f644dae0bd103dc6679c22d1c7'
TARGET_PAGES = {35: 58, 102: 125, 146: 169, 373: 396}
DETAIL_PAGES = {35: 460, 102: 473, 146: 481, 373: 526}


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def total(section: str) -> int:
    match = re.search(r'(?m)^\s*3\. TOTAL[^\n]*$', section)
    if match is None:
        raise ValueError('Official summary total missing')
    values = re.findall(r'\d+', match[0])
    if len(values) < 2:
        raise ValueError('Official summary total invalid')
    return int(values[-1])


def section(text: str, start: str, end: str) -> str:
    match = re.search(start + r'\b(.*?)' + end, text, re.I | re.S)
    if match is None:
        raise ValueError(f'Official summary section missing: {start}')
    return match[1]


def reconcile(row: dict, summary: str, detail: str) -> None:
    code = row['code']
    identity = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', summary, re.I)
    name = identity[2].strip() if identity else None
    expected_error = (f'Summary and detailed totals differ: votes_polled: detail {row["votes_polled"]}, '
                      f'summary {row["summary_totals"]["votes_polled"]}')
    if (identity is None or int(identity[1]) != code or name != row['name']
            or 'LEGISLATIVE ASSEMBLY OF UTTAR PRADESH' not in summary.upper()
            or row['status'] != 'needs_review' or row['number_of_seats'] != 1
            or row['summary_page'] != TARGET_PAGES[code] or row['detail_page'] != DETAIL_PAGES[code]
            or row.get('error') != expected_error or row.get('winner') is not None
            or row.get('margin') is not None):
        raise ValueError(f'1962 Uttar Pradesh extraction identity differs: {code}')
    electors = total(section(summary, r'II\. ELECTORS', r'III\. ELECTORS WHO VOTED'))
    voters = total(section(summary, r'III\. ELECTORS WHO VOTED', r'IV\. VOTES'))
    votes = section(summary, r'IV\. VOTES', r'V\. POLLING STATIONS')
    printed = {}
    for ordinal, label in ((1, 'POLLED'), (2, 'VALID'), (3, 'REJECTED'), (4, 'MISSING')):
        match = re.search(r'(?m)^\s*' + str(ordinal) + r'\. ' + label + r'\s+(\d+)', votes)
        if match is None:
            raise ValueError(f'1962 Uttar Pradesh {label} missing: {code}')
        printed[label] = int(match[1])
    if (electors != row['electors'] or voters != printed['POLLED']
            or printed['VALID'] != row['valid_candidate_votes']
            or printed['VALID'] + printed['REJECTED'] != row['votes_polled']
            or printed['MISSING'] < 1 or row['votes_polled'] + printed['MISSING'] != voters
            or row['summary_totals'] != {'electors': electors, 'votes_polled': voters,
                                          'valid_candidate_votes': printed['VALID']}):
        raise ValueError(f'1962 Uttar Pradesh printed totals differ: {code}')
    detail_match = re.search(r'Constituency\s+' + str(code) + r'\s+' + re.escape(name)
                             + r'\b(.*?)(?=Constituency\s+\d+\s+|\Z)', detail, re.I | re.S)
    if detail_match is None:
        raise ValueError(f'1962 Uttar Pradesh detail identity missing: {code}')
    detail_totals = re.search(r'ELECTORS\s*:\s*(\d+)\s+VOTERS\s*:\s*(\d+).*?VALID VOTES\s+(\d+)',
                              detail_match[1], re.I | re.S)
    if (detail_totals is None or tuple(map(int, detail_totals.groups())) !=
            (electors, row['votes_polled'], printed['VALID'])):
        raise ValueError(f'1962 Uttar Pradesh detail totals differ: {code}')
    results = section(summary, r'VII\. RESULT', r'rptConstituencySummary')
    declared = re.findall(r'^\s*(Winner|Runner up)\s*:\s*(\S+)\s+(.+?)\s+(\d+)\s*$',
                          results, re.I | re.M)
    margin_match = re.search(r'MARGIN\s*:\s*(\d+)', results, re.I)
    ranked = sorted(row['candidates'], key=lambda candidate: candidate['votes'], reverse=True)
    if (len(declared) != 2 or margin_match is None or len(ranked) < 2
            or sum(candidate['votes'] for candidate in ranked) != printed['VALID']
            or ranked[0]['votes'] <= ranked[1]['votes']
            or int(margin_match[1]) != ranked[0]['votes'] - ranked[1]['votes']):
        raise ValueError(f'1962 Uttar Pradesh declared result differs: {code}')
    for source, candidate in zip(declared, ranked[:2], strict=True):
        if (source[2].strip(), source[1], int(source[3])) != (
                candidate['candidate_name'], candidate['party_at_election'], candidate['votes']):
            raise ValueError(f'1962 Uttar Pradesh summary/detail candidate differs: {code}')
    old_error = row['error']
    row['previous_review_note'] = old_error
    row['original_extraction_warning'] = old_error
    row['summary_source_file'] = SOURCE_FILE
    row['summary_source_sha256'] = SOURCE_SHA
    row['detail_source_file'] = SOURCE_FILE
    row['detail_source_sha256'] = SOURCE_SHA
    row['official_source_url'] = SOURCE_URL
    row['official_summary_constituency_name'] = name
    row['official_summary_state'] = 'Uttar Pradesh'
    row['error'] = (f'{old_error}. The difference is {printed["MISSING"]}, printed as MISSING votes '
                    'in the official summary. Winner and margin agree; review both voter figures.')
    row['source_discrepancy'] = {'field': 'votes_polled', 'detail_value': row['votes_polled'],
                                 'summary_value': voters, 'missing_votes': printed['MISSING']}
    row['summary_result'] = {'winner': ranked[0]['candidate_name'],
                             'winner_party': ranked[0]['party_at_election'],
                             'winner_votes': ranked[0]['votes'],
                             'runner': ranked[1]['candidate_name'],
                             'runner_party': ranked[1]['party_at_election'],
                             'runner_votes': ranked[1]['votes'], 'margin': int(margin_match[1])}
    row['winner'] = ranked[0]['candidate_name']
    row['margin'] = int(margin_match[1])


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    source = folder / SOURCE_FILE
    original = folder / 'extraction.json'
    if (source.is_symlink() or original.is_symlink() or digest(source.read_bytes()) != SOURCE_SHA):
        raise ValueError('1962 Uttar Pradesh official source differs')
    old_body = original.read_bytes()
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    before = json.loads(old_body)
    after = json.loads(old_body)
    if (digest(old_body) != PREDECESSOR or manifest['url'] != SOURCE_URL
            or next(file for file in manifest['files'] if file['file'] == SOURCE_FILE)['sha256'] != SOURCE_SHA
            or before['kind'] != 'ac' or before['year'] != 1962
            or before['source_url'] != SOURCE_URL or before['source_file'] != SOURCE_FILE
            or before['source_sha256'] != SOURCE_SHA or len(before['records']) != 430):
        raise ValueError('1962 Uttar Pradesh extraction provenance differs')
    seen = []
    with fitz.open(source) as pdf:
        for row in after['records']:
            code = row['code']
            if code not in TARGET_PAGES:
                continue
            reconcile(row, pdf[TARGET_PAGES[code] - 1].get_text(sort=True),
                      pdf[DETAIL_PAGES[code] - 1].get_text(sort=True))
            seen.append({'code': code, 'summary_page': TARGET_PAGES[code],
                         'detail_page': DETAIL_PAGES[code], 'missing_votes': row['source_discrepancy']['missing_votes']})
    if [item['code'] for item in seen] != sorted(TARGET_PAGES):
        raise ValueError('1962 Uttar Pradesh discrepancy coverage differs')
    expected = {'previous_review_note', 'original_extraction_warning', 'summary_source_file',
                'summary_source_sha256', 'detail_source_file', 'detail_source_sha256',
                'official_source_url', 'official_summary_constituency_name',
                'official_summary_state', 'error', 'source_discrepancy', 'summary_result',
                'winner', 'margin'}
    for old, new in zip(before['records'], after['records'], strict=True):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        if (old['code'] != new['code'] or old['candidates'] != new['candidates']
                or changed != (expected if old['code'] in TARGET_PAGES else set())):
            raise ValueError(f'Unrelated 1962 Uttar Pradesh evidence changed: {old["code"]}')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{PREDECESSOR}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='ac-1962-uttar-pradesh-', dir=root / 'exports') as temporary:
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
                'scope': 'four printed-voter-discrepancy 1962 Uttar Pradesh AC one-seat declarations',
                'edition': EDITION, 'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
                'source_sha256': SOURCE_SHA, 'previous_sha256': PREDECESSOR,
                'new_sha256': digest(new_body), 'results': seen,
            }, indent=2))
            release.writestr('IMPORT.sh', multi_seat_import_script())
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'previous_sha256': PREDECESSOR,
            'new_sha256': digest(new_body), 'discrepancies': len(seen)}


if __name__ == '__main__':
    print(json.dumps(build()))
