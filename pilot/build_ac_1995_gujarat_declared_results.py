"""Corroborate 1995 Gujarat AC declarations with the official report."""

import io
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
EDITION = 'b089f1ea6668b55e759ee45b'
NAME = 'pollmedia-ac-1995-gujarat-declared-results-20261007'
PREDECESSOR = '21047354f55206ccbf71aec8abd38f616395eb798bb9785cdbd84a559202b88e'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3836-gujarat-1995/'
SOURCE_FILE = 'b089f1ea6668b55e759ee45b-9037.pdf'
SOURCE_SHA = '51502855200e137d67cca72bd036629cc6fa9b2bc8a9a1e9cc90758134d51c45'
PENDING = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.'
UNCONTESTED = PENDING + '; Reported elector and voter totals are inconsistent.'


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def section(text: str, start: str, end: str) -> str:
    match = re.search(start + r'\b(.*?)' + end, text, re.I | re.S)
    if match is None:
        raise ValueError(f'Official 1995 Gujarat summary section missing: {start}')
    return match[1]


def total(text: str) -> int:
    line = re.search(r'(?m)^\s*3\. TOTAL[^\n]*$', text)
    values = re.findall(r'\d+', line[0]) if line else []
    if len(values) < 2:
        raise ValueError('Official 1995 Gujarat summary total missing')
    return int(values[-1])


def reconcile(row: dict, page: int, text: str) -> str:
    code = row['code']
    identity = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text, re.I)
    if (identity is None or int(identity[1]) != code
            or re.sub(r'\s+', ' ', identity[2]).strip() != re.sub(r'\s+', ' ', row['name']).strip()
            or page != code + (20 if code <= 70 else 21) or 'CONSTITUENCY DATA - SUMMARY' not in text
            or 'LEGISLATIVE ASSEMBLY OF GUJARAT' not in text.upper()
            or row['state_name'] != 'Gujarat' or row['number_of_seats'] != 1
            or row['status'] != 'needs_review' or row.get('summary_page') is not None
            or row.get('source_warning_code') is not None):
        raise ValueError(f'Official 1995 Gujarat identity differs: {code}')
    electors = total(section(text, r'II\. ELECTORS', r'III\. ELECTORS WHO VOTED'))
    voters_section = section(text, r'III\. ELECTORS WHO VOTED', r'IV\. VOTES')
    votes_section = section(text, r'IV\. VOTES', r'V\. POLLING STATIONS')
    results = section(text, r'VII\. RESULT', r'rptConstituencySummary')
    if electors != row['electors'] or len(row['candidates']) < 1:
        raise ValueError(f'Official 1995 Gujarat electors differ: {code}')
    row['previous_review_note'] = row['error']
    row['original_extraction_warning'] = row['error']
    row['summary_page'] = page
    row['summary_source_file'] = SOURCE_FILE
    row['summary_source_sha256'] = SOURCE_SHA
    row['detail_source_file'] = SOURCE_FILE
    row['detail_source_sha256'] = SOURCE_SHA
    row['official_source_url'] = SOURCE_URL
    row['official_summary_constituency_name'] = identity[2].strip()
    row['official_summary_state'] = 'Gujarat'
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
            raise ValueError(f'Official 1995 Gujarat uncontested result differs: {code}')
        row['error'] = (f'Official 1995 Gujarat summary declares {candidate["candidate_name"]} '
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
        raise ValueError(f'Official 1995 Gujarat contested state differs: {code}')
    voters = total(voters_section)
    printed = {}
    for ordinal, label in ((1, 'POLLED'), (2, 'VALID'), (3, 'REJECTED'), (4, 'MISSING')):
        match = re.search(r'(?m)^\s*' + str(ordinal) + r'\. ' + label + r'\s+(\d+)', votes_section)
        if match is None:
            raise ValueError(f'Official 1995 Gujarat {label} missing: {code}')
        printed[label] = int(match[1])
    if (voters < 1 or voters > electors or voters != printed['POLLED']
            or (voters, printed['VALID']) != (row['votes_polled'], row['valid_candidate_votes'])
            or printed['VALID'] + printed['REJECTED'] + printed['MISSING'] != voters or printed['MISSING'] != {1: 12, 2: 9, 4: 11, 5: 21, 6: 24, 7: 14, 8: 30, 9: 1, 10: 19, 11: 30, 12: 101, 13: 4, 14: 20, 16: 27, 19: 22, 20: 30, 22: 15, 23: 26, 24: 35, 25: 47, 26: 31, 27: 6, 28: 10, 30: 55, 31: 23, 32: 37, 33: 21, 34: 3, 35: 15, 36: 17, 37: 183, 38: 36, 39: 76, 40: 28, 41: 41, 43: 62, 44: 29, 46: 45, 48: 22, 49: 40, 50: 15, 51: 29, 52: 37, 53: 9, 54: 20, 55: 17, 56: 19, 57: 15, 58: 54, 59: 16, 61: 131, 62: 13, 63: 8, 64: 242, 66: 8, 67: 747, 68: 92, 70: 63, 71: 86, 72: 61, 73: 56, 74: 19, 75: 23, 76: 35, 78: 231, 80: 62, 81: 134, 82: 24, 83: 4, 84: 211, 85: 36, 86: 1, 87: 98, 88: 38, 89: 123, 91: 39, 92: 10, 93: 7, 94: 85, 95: 31, 96: 9, 98: 36, 99: 33, 100: 62, 101: 26, 102: 100, 103: 78, 104: 34, 106: 22, 107: 21, 108: 18, 110: 12, 111: 17, 112: 30, 113: 11, 114: 39, 116: 6, 117: 8, 118: 80, 119: 25, 120: 24, 122: 41, 124: 24, 126: 47, 127: 29, 130: 25, 132: 17, 133: 96, 134: 31, 135: 88, 136: 19, 137: 132, 138: 68, 139: 106, 140: 21, 141: 68, 142: 71, 143: 25, 144: 15, 145: 79, 147: 20, 148: 48, 149: 35, 151: 24, 152: 75, 153: 40, 154: 67, 155: 23, 156: 88, 157: 49, 158: 14, 159: 15, 160: 4, 161: 8, 162: 3, 164: 3, 165: 36, 166: 5, 169: 86, 170: 59, 171: 5, 172: 142, 173: 43, 174: 15, 176: 9, 179: 50, 182: 16}.get(code, 0)):
        raise ValueError(f'Official 1995 Gujarat totals differ: {code}')
    declared = []
    for label, stop in (('Winner', 'Runner up'), ('Runner up', 'MARGIN')):
        block_match = re.search(re.escape(label) + r'\s*:?(.*?)' + re.escape(stop), results, re.S)
        if block_match is None:
            raise ValueError(f'Official declaration missing: {code} {label}')
        block = block_match[1]
        parsed = re.fullmatch(r'\s*(\S+)[ \t]+([^\n]+?)[ \t]+(\d+)[ \t]*(?:\n(.*))?', block, re.S)
        if parsed is None:
            raise ValueError(f'Official declaration layout differs: {code} {label}')
        continuation = re.sub(r'\s+', ' ', parsed[4] or '').strip()
        if re.search(r'\d', continuation):
            raise ValueError(f'Unexpected numeric continuation: {code}')
        name = re.sub(r'\s+', ' ', parsed[2] + ' ' + continuation).strip()
        declared.append((label, parsed[1], name, parsed[3]))
    margin = re.search(r'MARGIN\s*:\s*(\d+)', results, re.I)
    ranked = sorted(row['candidates'], key=lambda candidate: candidate['votes'], reverse=True)
    if (len(declared) != 2 or margin is None or sum(candidate['votes'] for candidate in ranked) != printed['VALID']
            or ranked[0]['votes'] <= ranked[1]['votes']
            or int(margin[1]) != ranked[0]['votes'] - ranked[1]['votes']
            or len({(candidate['candidate_name'], candidate['party_at_election'], candidate['votes'])
                    for candidate in ranked}) != len(ranked)):
        raise ValueError(f'Official 1995 Gujarat result differs: {code}')
    for source, candidate in zip(declared, ranked[:2], strict=True):
        if (source[2].strip(), source[1], int(source[3])) != (
                candidate['candidate_name'], candidate['party_at_election'], candidate['votes']):
            raise ValueError(f'Official 1995 Gujarat candidate differs: {code}')
    row['error'] = ('Official summary corroborates the detailed electors, voters, valid votes, '
                    'declared winner and margin; archived review warning retained.')
    if printed['MISSING']:
        row['error'] += f" Official summary includes {printed['MISSING']} missing ballots in the polled total."
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
        raise ValueError('1995 Gujarat official source differs')
    prior = root / 'exports/pollmedia-election-corrections-20261003-resume-v1.zip'
    if digest(prior.read_bytes()) != '27d0dcbf6bfce60b9e628f595edfa6d1e1fee1c11e61b8d97c08c2629d85f089':
        raise ValueError('Prior Gujarat summary bundle differs')
    with zipfile.ZipFile(prior) as outer:
        with zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{EDITION}.zip'))) as inner:
            old_body = inner.read(f'election-archive/{EDITION}/extraction.json')
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    before = json.loads(old_body)
    after = json.loads(old_body)
    if (digest(old_body) != PREDECESSOR or manifest['url'] != SOURCE_URL
            or next(file for file in manifest['files'] if file['file'] == SOURCE_FILE)['sha256'] != SOURCE_SHA
            or before['kind'] != 'ac' or before['year'] != 1995
            or before['source_url'] != SOURCE_URL or before['source_file'] != SOURCE_FILE
            or before['source_sha256'] != SOURCE_SHA or len(before['records']) != 182
            or [row['code'] for row in before['records']] != list(range(1, 183))):
        raise ValueError('1995 Gujarat extraction provenance differs')
    kinds = {'contested': [], 'uncontested': []}
    with fitz.open(source) as pdf:
        if len(pdf) != 276:
            raise ValueError('1995 Gujarat PDF page coverage differs')
        for row in after['records']:
            code = row['code']
            if code == 169:
                continue
            page = code + (20 if code <= 70 else 21)
            text = pdf[page - 1].get_text(sort=True)
            if code == 70:
                continuation = pdf[90].get_text(sort=True)
                if (page != 90 or 'MARGIN' in text or 'CONSTITUENCY DATA - SUMMARY' not in continuation
                        or 'LEGISLATIVE ASSEMBLY OF GUJARAT' not in continuation.upper()
                        or re.search(r'CONSTITUENCY\s*:', continuation)
                        or not re.search(r'MARGIN\s*:\s*10843\s*\(', continuation)
                        or 'rptConstituencySummary - Page 71 of 183' not in continuation):
                    raise ValueError('Gujarat 70 continuation differs')
                text = text.split('rptConstituencySummary')[0] + continuation[continuation.index('MARGIN'):]
            kind = reconcile(row, page, text)
            if code == 70:
                row['summary_continuation_pages'] = [91]
                row['error'] += ' Official margin is printed on continuation PDF page 91.'
            kinds[kind].append(code)
    if len(kinds['contested']) != 181 or kinds['uncontested'] != []:
        raise ValueError('1995 Gujarat contest coverage differs')
    common = {'previous_review_note', 'original_extraction_warning', 'summary_page',
              'summary_source_file', 'summary_source_sha256', 'detail_source_file',
              'detail_source_sha256', 'official_source_url', 'official_summary_constituency_name',
              'official_summary_state', 'error', 'source_warning_code', 'summary_totals'}
    for old, new in zip(before['records'], after['records'], strict=True):
        if old['code'] == 169:
            if old != new:
                raise ValueError('Prior Gujarat 169 review changed')
            continue
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        expected = common | ({'summary_result'} if old['code'] in kinds['contested'] else {'summary_source_rows'})
        if old['code'] == 70:
            expected.add('summary_continuation_pages')
        if (old['code'] != new['code'] or old['candidates'] != new['candidates'] or changed != expected):
            raise ValueError(f'Unrelated 1995 Gujarat evidence changed: {old["code"]}')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{PREDECESSOR}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='ac-1995-gujarat-', dir=root / 'exports') as temporary:
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
                'scope': '181 corroborated; prior record 169 retained in 182 1995 Gujarat AC declarations',
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
