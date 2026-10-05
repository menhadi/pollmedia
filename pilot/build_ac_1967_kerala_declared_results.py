"""Corroborate 1967 Kerala AC declarations with the official report."""

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
EDITION = 'a8b487805aa7b866bf9cf0a7'
NAME = 'pollmedia-ac-1967-kerala-declared-results-20261005'
PREDECESSOR = '6eb7d6279313b57635bebae9ad60e65c47e1506cd4505150f15bf0fc4749bc48'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3751-kerala-1967/'
SOURCE_FILE = f'{EDITION}-8822.pdf'
SOURCE_SHA = '7920f44bce75050dd37c60af7f5b2a6b2843ac0581e86b23b5d5500022bf3d83'
PENDING = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.'
UNCONTESTED = PENDING + '; Reported elector and voter totals are inconsistent.'
NAME_SPACING = {
    91: {'field': 'winner', 'printed': 'K  . R . G . THOMAS',
         'detail': 'K . R . G . THOMAS'},
    118: {'field': 'runner', 'printed': 'K  . K . KRISHNAN',
          'detail': 'K . K . KRISHNAN'},
}


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def section(text: str, start: str, end: str) -> str:
    match = re.search(start + r'\b(.*?)' + end, text, re.I | re.S)
    if match is None:
        raise ValueError(f'Official 1967 Kerala summary section missing: {start}')
    return match[1]


def total(text: str) -> int:
    line = re.search(r'(?m)^\s*3\. TOTAL[^\n]*$', text)
    values = re.findall(r'\d+', line[0]) if line else []
    if len(values) < 2:
        raise ValueError('Official 1967 Kerala summary total missing')
    return int(values[-1])


def reconcile(row: dict, page: int, text: str) -> str:
    code = row['code']
    identity = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text, re.I)
    if (identity is None or int(identity[1]) != code
            or re.sub(r'\s+', ' ', identity[2]).strip() != re.sub(r'\s+', ' ', row['name']).strip()
            or page != code + 13 or 'CONSTITUENCY DATA - SUMMARY' not in text
            or 'LEGISLATIVE ASSEMBLY OF KERALA' not in text.upper()
            or row['state_name'] != 'Kerala' or row['number_of_seats'] != 1
            or row['status'] != 'needs_review' or row.get('summary_page') is not None
            or row.get('source_warning_code') is not None):
        raise ValueError(f'Official 1967 Kerala identity differs: {code}')
    electors = total(section(text, r'II\. ELECTORS', r'III\. ELECTORS WHO VOTED'))
    voters_section = section(text, r'III\. ELECTORS WHO VOTED', r'IV\. VOTES')
    votes_section = section(text, r'IV\. VOTES', r'V\. POLLING STATIONS')
    results = section(text, r'VII\. RESULT', r'rptConstituencySummary')
    if electors != row['electors'] or len(row['candidates']) < 1:
        raise ValueError(f'Official 1967 Kerala electors differ: {code}')
    row['previous_review_note'] = row['error']
    row['original_extraction_warning'] = row['error']
    row['summary_page'] = page
    row['summary_source_file'] = SOURCE_FILE
    row['summary_source_sha256'] = SOURCE_SHA
    row['detail_source_file'] = SOURCE_FILE
    row['detail_source_sha256'] = SOURCE_SHA
    row['official_source_url'] = SOURCE_URL
    row['official_summary_constituency_name'] = identity[2].strip()
    row['official_summary_state'] = 'Kerala'
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
            raise ValueError(f'Official 1967 Kerala uncontested result differs: {code}')
        row['error'] = (f'Official 1967 Kerala summary declares {candidate["candidate_name"]} '
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
        raise ValueError(f'Official 1967 Kerala contested state differs: {code}')
    voters = total(voters_section)
    printed = {}
    for ordinal, label in ((1, 'POLLED'), (2, 'VALID'), (3, 'REJECTED'), (4, 'MISSING')):
        match = re.search(r'(?m)^\s*' + str(ordinal) + r'\. ' + label + r'\s+(\d+)', votes_section)
        if match is None:
            raise ValueError(f'Official 1967 Kerala {label} missing: {code}')
        printed[label] = int(match[1])
    if (voters < 1 or voters > electors or voters != printed['POLLED']
            or (voters, printed['VALID']) != (row['votes_polled'], row['valid_candidate_votes'])
            or printed['VALID'] + printed['REJECTED'] != voters or printed['MISSING'] != 0):
        raise ValueError(f'Official 1967 Kerala totals differ: {code}')
    declared = re.findall(r'^\s*(Winner|Runner up)\s*:?\s+(\S+)\s+(.+?)\s+(\d+)\s*$',
                          results, re.I | re.M)
    margin = re.search(r'MARGIN\s*:\s*(\d+)', results, re.I)
    ranked = sorted(row['candidates'], key=lambda candidate: candidate['votes'], reverse=True)
    if (len(declared) != 2 or margin is None or sum(candidate['votes'] for candidate in ranked) != printed['VALID']
            or ranked[0]['votes'] <= ranked[1]['votes']
            or int(margin[1]) != ranked[0]['votes'] - ranked[1]['votes']
            or len({(candidate['candidate_name'], candidate['party_at_election'], candidate['votes'])
                    for candidate in ranked}) != len(ranked)):
        raise ValueError(f'Official 1967 Kerala result differs: {code}')
    for position, (source, candidate) in enumerate(zip(declared, ranked[:2], strict=True)):
        spacing = NAME_SPACING.get(code)
        expected_name = (spacing['printed'] if spacing and spacing['field'] ==
                         ('winner' if position == 0 else 'runner') else candidate['candidate_name'])
        if (source[2].strip(), source[1], int(source[3])) != (
                expected_name, candidate['party_at_election'], candidate['votes']):
            raise ValueError(f'Official 1967 Kerala candidate differs: {code}')
    spacing = NAME_SPACING.get(code)
    if spacing:
        named = ranked[0 if spacing['field'] == 'winner' else 1]['candidate_name']
        if named != spacing['detail']:
            raise ValueError(f'Official 1967 Kerala detailed name differs: {code}')
        row['official_summary_name_layout'] = spacing
        row['error'] = ('Official summary and detailed candidate name differ only in printed '
                        f'spacing for the {spacing["field"]}; electors, voters, valid votes, '
                        'party, votes and margin agree. Both printed forms remain for review.')
    else:
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
    return 'contested'


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    source = folder / SOURCE_FILE
    original = folder / 'extraction.json'
    if (source.is_symlink() or original.is_symlink() or digest(source.read_bytes()) != SOURCE_SHA):
        raise ValueError('1967 Kerala official source differs')
    old_body = original.read_bytes()
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    before = json.loads(old_body)
    after = json.loads(old_body)
    if (digest(old_body) != PREDECESSOR or manifest['url'] != SOURCE_URL
            or next(file for file in manifest['files'] if file['file'] == SOURCE_FILE)['sha256'] != SOURCE_SHA
            or before['kind'] != 'ac' or before['year'] != 1967
            or before['source_url'] != SOURCE_URL or before['source_file'] != SOURCE_FILE
            or before['source_sha256'] != SOURCE_SHA or len(before['records']) != 133
            or [row['code'] for row in before['records']] != list(range(1, 134))):
        raise ValueError('1967 Kerala extraction provenance differs')
    kinds = {'contested': [], 'uncontested': []}
    with fitz.open(source) as pdf:
        if len(pdf) != 162:
            raise ValueError('1967 Kerala PDF page coverage differs')
        for row in after['records']:
            code = row['code']
            kind = reconcile(row, code + 13, pdf[code + 12].get_text(sort=True))
            kinds[kind].append(code)
    if len(kinds['contested']) != 133 or len(kinds['uncontested']) != 0:
        raise ValueError('1967 Kerala contest coverage differs')
    common = {'previous_review_note', 'original_extraction_warning', 'summary_page',
              'summary_source_file', 'summary_source_sha256', 'detail_source_file',
              'detail_source_sha256', 'official_source_url', 'official_summary_constituency_name',
              'official_summary_state', 'error', 'source_warning_code', 'summary_totals'}
    for old, new in zip(before['records'], after['records'], strict=True):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        expected = common | ({'summary_result'} if old['code'] in kinds['contested'] else {'summary_source_rows'})
        if old['code'] in NAME_SPACING:
            expected.add('official_summary_name_layout')
        if (old['code'] != new['code'] or old['candidates'] != new['candidates'] or changed != expected):
            raise ValueError(f'Unrelated 1967 Kerala evidence changed: {old["code"]}')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{PREDECESSOR}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='ac-1967-kerala-', dir=root / 'exports') as temporary:
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
                'scope': '133 contested 1967 Kerala AC declarations',
                'edition': EDITION, 'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
                'source_sha256': SOURCE_SHA, 'previous_sha256': PREDECESSOR,
                'new_sha256': digest(new_body), 'contested_codes': kinds['contested'],
                'uncontested_codes': kinds['uncontested'],
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
