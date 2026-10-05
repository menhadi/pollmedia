"""Corroborate 1977 Rajasthan AC declarations with the official report."""

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
EDITION = '4a607a2256a9395204c4842a'
NAME = 'pollmedia-ac-1977-rajasthan-reviewed-results-20261006'
PREDECESSOR = '9d99dea73f8ef57b04aaad3f43740d34775bac2f8ae7c0bda6de18dcc93f7724'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3385-rajasthan-1977/'
SOURCE_FILE = f'{EDITION}-7828.pdf'
SOURCE_SHA = '964d33308871d3a74f70c64e278084cec3c27f372d6dd4285de2f98e523e6f11'
PENDING = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.'
UNCONTESTED = PENDING + '; Reported elector and voter totals are inconsistent.'


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def section(text: str, start: str, end: str) -> str:
    match = re.search(start + r'\b(.*?)' + end, text, re.I | re.S)
    if match is None:
        raise ValueError(f'Official 1977 Rajasthan summary section missing: {start}')
    return match[1]


def total(text: str) -> int:
    line = re.search(r'(?m)^\s*3\. TOTAL[^\n]*$', text)
    values = re.findall(r'\d+', line[0]) if line else []
    if len(values) < 2:
        raise ValueError('Official 1977 Rajasthan summary total missing')
    return int(values[-1])


def reconcile(row: dict, page: int, text: str) -> str:
    code = row['code']
    identity = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text, re.I)
    if (identity is None or int(identity[1]) != code
            or re.sub(r'\s+', ' ', identity[2]).strip() != re.sub(r'\s+', ' ', row['name']).strip()
            or page != code + 15 or 'CONSTITUENCY DATA - SUMMARY' not in text
            or 'LEGISLATIVE ASSEMBLY OF RAJASTHAN' not in text.upper()
            or row['state_name'] != 'Rajasthan' or row['number_of_seats'] != 1
            or row['status'] != 'needs_review' or row.get('summary_page') is not None
            or row.get('source_warning_code') is not None):
        raise ValueError(f'Official 1977 Rajasthan identity differs: {code}')
    electors = total(section(text, r'II\. ELECTORS', r'III\. ELECTORS WHO VOTED'))
    voters_section = section(text, r'III\. ELECTORS WHO VOTED', r'IV\. VOTES')
    votes_section = section(text, r'IV\. VOTES', r'V\. POLLING STATIONS')
    results = section(text, r'VII\. RESULT', r'rptConstituencySummary')
    if electors != row['electors'] or len(row['candidates']) < 1:
        raise ValueError(f'Official 1977 Rajasthan electors differ: {code}')
    row['previous_review_note'] = row['error']
    row['original_extraction_warning'] = row['error']
    row['summary_page'] = page
    row['summary_source_file'] = SOURCE_FILE
    row['summary_source_sha256'] = SOURCE_SHA
    row['detail_source_file'] = SOURCE_FILE
    row['detail_source_sha256'] = SOURCE_SHA
    row['official_source_url'] = SOURCE_URL
    row['official_summary_constituency_name'] = identity[2].strip()
    row['official_summary_state'] = 'Rajasthan'
    if 'Uncontested' in text:
        declaration = re.search(r'Winner\s*:?\s*(\S+)\s+(.+?)\s+Returned\s+Uncontested',
                                results, re.I | re.S)
        candidate = row['candidates'][0]
        if (row['error'] != UNCONTESTED or len(row['candidates']) != 1
                or row['votes_polled'] != 0 or row['valid_candidate_votes'] != 0
                or candidate['votes'] != 0 or declaration is None
                or (declaration[1], re.sub(r'\s+', ' ', declaration[2]).strip()) !=
                (candidate['party_at_election'], re.sub(r'\s+', ' ', candidate['candidate_name']).strip())
                or 'Uncontested' not in voters_section or 'Uncontested' not in votes_section
                or re.search(r'(?m)^[ \t]*3\. TOTAL[ \t]+\d+', voters_section)
                or re.search(r'(?m)^[ \t]*(1\. POLLED|2\. VALID)[ \t]+\d+', votes_section)
                or re.search(r'Runner up|MARGIN\s*:', results, re.I)):
            raise ValueError(f'Official 1977 Rajasthan uncontested result differs: {code}')
        row['error'] = (f'Official 1977 Rajasthan summary declares {candidate["candidate_name"]} '
                        f'({candidate["party_at_election"]}) returned uncontested. No voter, valid-vote '
                        'or margin total is reported; archived zero candidate/voter fields are extraction '
                        'placeholders, not measured turnout.')
        row['source_warning_code'] = 'official_uncontested_summary'
        row['summary_source_rows'] = [f'Winner {candidate["party_at_election"]} '
                                      f'{candidate["candidate_name"]} Returned Uncontested']
        row['summary_totals'] = {'electors': electors, 'votes_polled': None,
                                 'valid_candidate_votes': None}
        return 'uncontested'
    if row['error'] != PENDING or len(row['candidates']) < 2:
        raise ValueError(f'Official 1977 Rajasthan contested state differs: {code}')
    voters = total(voters_section)
    printed = {}
    for ordinal, label in ((1, 'POLLED'), (2, 'VALID'), (3, 'REJECTED'), (4, 'MISSING')):
        match = re.search(r'(?m)^\s*' + str(ordinal) + r'\. ' + label + r'\s+(\d+)', votes_section)
        if match is None:
            raise ValueError(f'Official 1977 Rajasthan {label} missing: {code}')
        printed[label] = int(match[1])
    conflict = {
        76: (80116, 52209, 51209, 51423, 786, 231),
        193: (82035, 46205, 47205, 45226, 979, 248),
    }.get(code)
    if conflict:
        if ((electors, row['votes_polled'], voters, printed['VALID'], printed['REJECTED'], row['detail_page']) != conflict
                or printed['POLLED'] != voters or printed['MISSING'] != 0
                or row['valid_candidate_votes'] != printed['VALID']
                or printed['VALID'] + printed['REJECTED'] != row['votes_polled']):
            raise ValueError(f'Official 1977 Rajasthan conflict differs: {code}')
    elif (voters < 1 or voters > electors or voters != printed['POLLED']
            or (voters, printed['VALID']) != (row['votes_polled'], row['valid_candidate_votes'])
            or printed['VALID'] + printed['REJECTED'] != voters or printed['MISSING'] != 0):
        raise ValueError(f'Official 1977 Rajasthan totals differ: {code}')
    declared = re.findall(r'^\s*(Winner|Runner up)\s*:?\s+(\S+)\s+(.+?)\s+(\d+)\s*$',
                          results, re.I | re.M)
    margin = re.search(r'MARGIN\s*:\s*(\d+)', results, re.I)
    ranked = sorted(row['candidates'], key=lambda candidate: candidate['votes'], reverse=True)
    if (len(declared) != 2 or margin is None or sum(candidate['votes'] for candidate in ranked) != printed['VALID']
            or ranked[0]['votes'] <= ranked[1]['votes']
            or int(margin[1]) != ranked[0]['votes'] - ranked[1]['votes']
            or len({(candidate['candidate_name'], candidate['party_at_election'], candidate['votes'])
                    for candidate in ranked}) != len(ranked)):
        raise ValueError(f'Official 1977 Rajasthan result differs: {code}')
    for source, candidate in zip(declared, ranked[:2], strict=True):
        if (source[2].strip(), source[1], int(source[3])) != (
                candidate['candidate_name'], candidate['party_at_election'], candidate['votes']):
            raise ValueError(f'Official 1977 Rajasthan candidate differs: {code}')
    row['error'] = ('Official summary corroborates the detailed electors, voters, valid votes, '
                    'declared winner and margin; archived review warning retained.')
    row['source_warning_code'] = 'official_summary_turnout_only'
    row['summary_totals'] = {'electors': electors, 'votes_polled': voters,
                             'valid_candidate_votes': printed['VALID']}
    row['summary_result'] = {'winner': ranked[0]['candidate_name'],
                             'winner_party': ranked[0]['party_at_election'],
                             'winner_votes': ranked[0]['votes'],
                             'runner': ranked[1]['candidate_name'],
                             'runner_party': ranked[1]['party_at_election'],
                             'runner_votes': ranked[1]['votes'], 'margin': int(margin[1])}
    if conflict:
        row['source_warning_code'] = 'official_ac_voter_total_conflict'
        row['source_discrepancy'] = {'field': 'votes_polled', 'detail_value': row['votes_polled'],
                                    'summary_value': voters, 'valid_votes': printed['VALID'],
                                    'rejected_votes': printed['REJECTED']}
        row['error'] = (f'Official summary reports {voters:,} voters; detailed table reports '
                        f'{row["votes_polled"]:,}. Valid {printed["VALID"]:,} plus rejected '
                        f'{printed["REJECTED"]:,} equals the detailed voter total. Turnout is withheld. '
                        f'Official winner {ranked[0]["candidate_name"]} ({ranked[0]["party_at_election"]}), '
                        f'{ranked[0]["votes"]:,} votes; runner-up {ranked[1]["candidate_name"]} '
                        f'({ranked[1]["party_at_election"]}), {ranked[1]["votes"]:,}; margin {int(margin[1]):,}. '
                        'Declaration retained for review; aggregate result projection is withheld.')
        return 'discrepancy'
    return 'contested'


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    source = folder / SOURCE_FILE
    original = folder / 'extraction.json'
    if (source.is_symlink() or original.is_symlink() or digest(source.read_bytes()) != SOURCE_SHA):
        raise ValueError('1977 Rajasthan official source differs')
    old_body = original.read_bytes()
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    before = json.loads(old_body)
    after = json.loads(old_body)
    if (digest(old_body) != PREDECESSOR or manifest['url'] != SOURCE_URL
            or next(file for file in manifest['files'] if file['file'] == SOURCE_FILE)['sha256'] != SOURCE_SHA
            or before['kind'] != 'ac' or before['year'] != 1977
            or before['source_url'] != SOURCE_URL or before['source_file'] != SOURCE_FILE
            or before['source_sha256'] != SOURCE_SHA or len(before['records']) != 200
            or [row['code'] for row in before['records']] != list(range(1, 201))):
        raise ValueError('1977 Rajasthan extraction provenance differs')
    kinds = {'contested': [], 'uncontested': [], 'discrepancy': []}
    with fitz.open(source) as pdf:
        if len(pdf) != 249:
            raise ValueError('1977 Rajasthan PDF page coverage differs')
        for row in after['records']:
            code = row['code']
            if code in (76, 193):
                detail = pdf[230].get_text(sort=True) if code == 76 else pdf[247].get_text(sort=True) + pdf[248].get_text(sort=True)
                expected = (80116, 52209, 51423) if code == 76 else (82035, 46205, 45226)
                block = re.search(r'Constituency\s*:\s*' + str(code) + r'\s*\.\s*' + row['name'] + r'(.*?)(?=Constituency\s*:|$)', detail, re.S)
                totals = re.search(r'ELECTORS\s*:\s*(\d+)\s+VOTERS\s*:\s*(\d+).*?VALID VOTES\s*:\s*(\d+)', block[1] if block else '', re.S)
                if totals is None or tuple(map(int, totals.groups())) != expected:
                    raise ValueError(f'Rajasthan detailed conflict evidence differs: {code}')
                if code == 193 and not re.search(r'HANUMAN\s+M\s+IND\s+770\b', block[1]):
                    raise ValueError('Ladnu continuation candidate evidence missing')
            kind = reconcile(row, code + 15, pdf[code + 14].get_text(sort=True))
            kinds[kind].append(code)
    if len(kinds['contested']) != 198 or len(kinds['uncontested']) != 0 or kinds['discrepancy'] != [76, 193]:
        raise ValueError('1977 Rajasthan contest coverage differs')
    common = {'previous_review_note', 'original_extraction_warning', 'summary_page',
              'summary_source_file', 'summary_source_sha256', 'detail_source_file',
              'detail_source_sha256', 'official_source_url', 'official_summary_constituency_name',
              'official_summary_state', 'error', 'source_warning_code', 'summary_totals'}
    for old, new in zip(before['records'], after['records'], strict=True):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        expected = common | ({'summary_result'} if old['code'] in kinds['contested'] + kinds['discrepancy'] else {'summary_source_rows'})
        if old['code'] in kinds['discrepancy']:
            expected |= {'source_discrepancy'}
        if (old['code'] != new['code'] or old['candidates'] != new['candidates'] or changed != expected):
            raise ValueError(f'Unrelated 1977 Rajasthan evidence changed: {old["code"]}')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{PREDECESSOR}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='ac-1977-rajasthan-', dir=root / 'exports') as temporary:
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
                'scope': '198 ordinary 1977 Rajasthan AC declarations and two voter discrepancies',
                'edition': EDITION, 'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
                'source_sha256': SOURCE_SHA, 'previous_sha256': PREDECESSOR,
                'new_sha256': digest(new_body), 'contested_codes': kinds['contested'],
                'uncontested_codes': kinds['uncontested'], 'discrepancy_codes': kinds['discrepancy'],
            }, indent=2))
            release.writestr('IMPORT.sh', multi_seat_import_script())
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'previous_sha256': PREDECESSOR,
            'new_sha256': digest(new_body),
            'contested': len(kinds['contested']), 'uncontested': len(kinds['uncontested'])}


if __name__ == '__main__':
    print(json.dumps(build()))
