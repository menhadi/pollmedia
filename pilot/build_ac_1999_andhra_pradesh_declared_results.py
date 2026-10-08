"""Corroborate 1999 Andhra Pradesh AC declarations with the official report."""

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
EDITION = '306035ee8fe2218e11a1fefa'
NAME = 'pollmedia-ac-1999-andhra-pradesh-declared-results-20261008'
PREDECESSOR = '51f15a21055446e192e773ca7e9fb81cf893c3867e614c3d1fc90aec3b21f7ae'
SOURCE_URL = 'https://old.eci.gov.in/files/file/4052-andhra-pradesh-1999/'
SOURCE_FILE = '306035ee8fe2218e11a1fefa-9608.pdf'
SOURCE_SHA = 'ee0cf004d81796a462a4aadaa05c1f3946d61a78ae5232db48f5ec465a78cab4'
PENDING = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.'
UNCONTESTED = PENDING + '; Reported elector and voter totals are inconsistent.'


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def section(text: str, start: str, end: str) -> str:
    match = re.search(start + r'\b(.*?)' + end, text, re.I | re.S)
    if match is None:
        raise ValueError(f'Official 1999 Andhra Pradesh summary section missing: {start}')
    return match[1]


def total(text: str) -> int:
    line = re.search(r'(?m)^\s*3\. TOTAL[^\n]*$', text)
    values = re.findall(r'\d+', line[0]) if line else []
    if len(values) < 2:
        raise ValueError('Official 1999 Andhra Pradesh summary total missing')
    return int(values[-1])


def reconcile(row: dict, page: int, text: str) -> str:
    code = row['code']
    identity = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text, re.I)
    if (identity is None or int(identity[1]) != code
            or re.sub(r'\s+', ' ', identity[2]).strip() != re.sub(r'\s+', ' ', row['name']).strip()
            or page != code + 25 or 'CONSTITUENCY DATA - SUMMARY' not in text
            or 'LEGISLATIVE ASSEMBLY OF ANDHRA PRADESH' not in text.upper()
            or row['state_name'] != 'Andhra Pradesh' or row['number_of_seats'] != 1
            or row['status'] != 'needs_review' or row.get('summary_page') is not None
            or row.get('source_warning_code') is not None):
        raise ValueError(f'Official 1999 Andhra Pradesh identity differs: {code}')
    electors = total(section(text, r'II\. ELECTORS', r'III\. ELECTORS WHO VOTED'))
    voters_section = section(text, r'III\. ELECTORS WHO VOTED', r'IV\. VOTES')
    votes_section = section(text, r'IV\. VOTES', r'V\. POLLING STATIONS')
    results = section(text, r'VII\. RESULT', r'rptConstituencySummary')
    if electors != row['electors'] or len(row['candidates']) < 1:
        raise ValueError(f'Official 1999 Andhra Pradesh electors differ: {code}')
    row['previous_review_note'] = row['error']
    row['original_extraction_warning'] = row['error']
    row['summary_page'] = page
    row['summary_source_file'] = SOURCE_FILE
    row['summary_source_sha256'] = SOURCE_SHA
    row['detail_source_file'] = SOURCE_FILE
    row['detail_source_sha256'] = SOURCE_SHA
    row['official_source_url'] = SOURCE_URL
    row['official_summary_constituency_name'] = identity[2].strip()
    row['official_summary_state'] = 'Andhra Pradesh'
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
            raise ValueError(f'Official 1999 Andhra Pradesh uncontested result differs: {code}')
        row['error'] = (f'Official 1999 Andhra Pradesh summary declares {candidate["candidate_name"]} '
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
        raise ValueError(f'Official 1999 Andhra Pradesh contested state differs: {code}')
    voters = total(voters_section)
    printed = {}
    for ordinal, label in ((1, 'POLLED'), (2, 'VALID'), (3, 'REJECTED'), (4, 'MISSING')):
        match = re.search(r'(?m)^\s*' + str(ordinal) + r'\. ' + label + r'[ \t]+(\d+)', votes_section)
        if match is None:
            raise ValueError(f'Official 1999 Andhra Pradesh {label} missing: {code}')
        printed[label] = int(match[1])
    if (voters < 1 or voters > electors or voters != printed['POLLED']
            or (voters, printed['VALID']) != (row['votes_polled'], row['valid_candidate_votes'])
            or printed['VALID'] + printed['REJECTED'] + printed['MISSING'] != voters or printed['MISSING'] != {1: 85, 2: 69, 5: 10, 8: 5, 9: 8, 10: 24, 13: 8, 14: 23, 15: 70, 16: 7, 18: 23, 21: 12, 24: 24, 26: 39, 31: 30, 34: 60, 41: 65, 42: 55, 44: 26, 45: 104, 46: 7, 47: 114, 48: 26, 49: 21, 51: 7, 52: 219, 53: 9, 54: 8, 56: 101, 57: 9, 58: 11, 59: 210, 61: 45, 62: 139, 63: 39, 65: 14, 68: 30, 69: 155, 70: 61, 73: 57, 74: 27, 76: 11, 78: 18, 79: 57, 80: 74, 87: 27, 91: 27, 92: 57, 93: 21, 94: 69, 98: 2, 100: 29, 101: 2, 103: 4, 105: 50, 110: 7, 112: 7, 113: 47, 115: 204, 116: 615, 117: 41, 119: 114, 125: 19, 126: 18, 127: 1, 128: 531, 129: 10, 130: 38, 131: 34, 132: 7, 133: 24, 134: 54, 135: 35, 136: 18, 139: 19, 141: 20, 142: 33, 144: 15, 145: 136, 146: 5, 147: 21, 148: 25, 150: 53, 153: 1, 154: 34, 155: 195, 156: 20, 158: 7, 159: 27, 162: 25, 163: 121, 165: 201, 166: 190, 167: 286, 169: 169, 170: 66, 171: 31, 173: 36, 176: 159, 178: 22, 179: 12, 183: 27, 184: 10, 185: 105, 186: 11, 189: 76, 193: 129, 194: 40, 196: 113, 198: 815, 200: 14, 203: 57, 211: 13, 212: 1719, 219: 282, 222: 156, 224: 207, 225: 24, 227: 51, 228: 57, 229: 73, 231: 11, 232: 29, 235: 44, 236: 7, 237: 6, 238: 3, 240: 37, 241: 19, 243: 18, 244: 72, 245: 41, 247: 183, 250: 12, 251: 18, 255: 32, 256: 21, 257: 2, 259: 642, 263: 16, 264: 1072, 265: 32, 267: 35, 268: 60, 271: 64, 273: 26, 274: 5, 276: 28, 277: 37, 278: 84, 280: 8, 281: 193, 286: 24, 287: 10, 288: 43, 289: 66, 290: 25, 294: 17}.get(code, 0)):
        raise ValueError(f'Official 1999 Andhra Pradesh totals differ: {code}')
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
        raise ValueError(f'Official 1999 Andhra Pradesh result differs: {code}')
    for source, candidate in zip(declared, ranked[:2], strict=True):
        if (source[2].strip(), source[1], int(source[3])) != (
                candidate['candidate_name'], candidate['party_at_election'], candidate['votes']):
            raise ValueError(f'Official 1999 Andhra Pradesh candidate differs: {code}')
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
        raise ValueError('1999 Andhra Pradesh official source differs')
    old_body = original.read_bytes()
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    before = json.loads(old_body)
    after = json.loads(old_body)
    if (digest(old_body) != PREDECESSOR or manifest['url'] != SOURCE_URL
            or next(file for file in manifest['files'] if file['file'] == SOURCE_FILE)['sha256'] != SOURCE_SHA
            or before['kind'] != 'ac' or before['year'] != 1999
            or before['source_url'] != SOURCE_URL or before['source_file'] != SOURCE_FILE
            or before['source_sha256'] != SOURCE_SHA or len(before['records']) != 294
            or [row['code'] for row in before['records']] != list(range(1, 295))):
        raise ValueError('1999 Andhra Pradesh extraction provenance differs')
    kinds = {'contested': [], 'uncontested': []}
    with fitz.open(source) as pdf:
        if len(pdf) != 373:
            raise ValueError('1999 Andhra Pradesh PDF page coverage differs')
        for row in after['records']:
            code = row['code']
            kind = reconcile(row, code + 25, pdf[code + 24].get_text(sort=True))
            kinds[kind].append(code)
    if len(kinds['contested']) != 294 or kinds['uncontested'] != []:
        raise ValueError('1999 Andhra Pradesh contest coverage differs')
    common = {'previous_review_note', 'original_extraction_warning', 'summary_page',
              'summary_source_file', 'summary_source_sha256', 'detail_source_file',
              'detail_source_sha256', 'official_source_url', 'official_summary_constituency_name',
              'official_summary_state', 'error', 'source_warning_code', 'summary_totals'}
    for old, new in zip(before['records'], after['records'], strict=True):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        expected = common | ({'summary_result'} if old['code'] in kinds['contested'] else {'summary_source_rows'})
        if (old['code'] != new['code'] or old['candidates'] != new['candidates'] or changed != expected):
            raise ValueError(f'Unrelated 1999 Andhra Pradesh evidence changed: {old["code"]}')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{PREDECESSOR}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='ac-1999-andhra-pradesh-', dir=root / 'exports') as temporary:
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
                'scope': '294 contested 1999 Andhra Pradesh AC declarations',
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
