"""Corroborate 1999 Karnataka AC declarations with the official report."""

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
EDITION = 'b555a7c298cefcfc6b8e3c61'
NAME = 'pollmedia-ac-1999-karnataka-declared-results-20261008'
PREDECESSOR = '6c1762b13277e513a8719343eccd4ddd4b005ff80c6a8a2fa19e843ee8d5936f'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3781-karnataka-1999/'
SOURCE_FILE = 'b555a7c298cefcfc6b8e3c61-8904.pdf'
SOURCE_SHA = 'faacd2185c5d19937da123cdc7b6bca9eba3a12b585b8a789fc31a90fe08b275'
PENDING = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.'
UNCONTESTED = PENDING + '; Reported elector and voter totals are inconsistent.'


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def section(text: str, start: str, end: str) -> str:
    match = re.search(start + r'\b(.*?)' + end, text, re.I | re.S)
    if match is None:
        raise ValueError(f'Official 1999 Karnataka summary section missing: {start}')
    return match[1]


def total(text: str) -> int:
    line = re.search(r'(?m)^\s*3\. TOTAL[^\n]*$', text)
    values = re.findall(r'\d+', line[0]) if line else []
    if len(values) < 2:
        raise ValueError('Official 1999 Karnataka summary total missing')
    return int(values[-1])


def reconcile(row: dict, page: int, text: str) -> str:
    code = row['code']
    identity = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text, re.I)
    if (identity is None or int(identity[1]) != code
            or re.sub(r'\s+', ' ', identity[2]).strip() != re.sub(r'\s+', ' ', row['name']).strip()
            or page != code + 20 or 'CONSTITUENCY DATA - SUMMARY' not in text
            or 'LEGISLATIVE ASSEMBLY OF KARNATAKA' not in text.upper()
            or row['state_name'] != 'Karnataka' or row['number_of_seats'] != 1
            or row['status'] != 'needs_review' or row.get('summary_page') is not None
            or row.get('source_warning_code') is not None):
        raise ValueError(f'Official 1999 Karnataka identity differs: {code}')
    electors = total(section(text, r'II\. ELECTORS', r'III\. ELECTORS WHO VOTED'))
    voters_section = section(text, r'III\. ELECTORS WHO VOTED', r'IV\. VOTES')
    votes_section = section(text, r'IV\. VOTES', r'V\. POLLING STATIONS')
    results = section(text, r'VII\. RESULT', r'rptConstituencySummary')
    if electors != row['electors'] or len(row['candidates']) < 1:
        raise ValueError(f'Official 1999 Karnataka electors differ: {code}')
    row['previous_review_note'] = row['error']
    row['original_extraction_warning'] = row['error']
    row['summary_page'] = page
    row['summary_source_file'] = SOURCE_FILE
    row['summary_source_sha256'] = SOURCE_SHA
    row['detail_source_file'] = SOURCE_FILE
    row['detail_source_sha256'] = SOURCE_SHA
    row['official_source_url'] = SOURCE_URL
    row['official_summary_constituency_name'] = identity[2].strip()
    row['official_summary_state'] = 'Karnataka'
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
            raise ValueError(f'Official 1999 Karnataka uncontested result differs: {code}')
        row['error'] = (f'Official 1999 Karnataka summary declares {candidate["candidate_name"]} '
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
        raise ValueError(f'Official 1999 Karnataka contested state differs: {code}')
    voters = total(voters_section)
    printed = {}
    for ordinal, label in ((1, 'POLLED'), (2, 'VALID'), (3, 'REJECTED'), (4, 'MISSING')):
        match = re.search(r'(?m)^\s*' + str(ordinal) + r'\. ' + label + r'[ \t]+(\d+)', votes_section)
        if match is None:
            raise ValueError(f'Official 1999 Karnataka {label} missing: {code}')
        printed[label] = int(match[1])
    if (voters < 1 or voters > electors or voters != printed['POLLED']
            or (voters, printed['VALID']) != (row['votes_polled'], row['valid_candidate_votes'])
            or printed['VALID'] + printed['REJECTED'] + printed['MISSING'] != voters or printed['MISSING'] != {1: 67, 3: 13, 5: 217, 6: 12, 9: 42, 10: 164, 11: 128, 12: 45, 13: 18, 14: 30, 15: 135, 16: 12, 20: 31, 21: 144, 22: 87, 23: 11, 24: 2, 25: 61, 26: 25, 27: 46, 30: 23, 31: 49, 32: 32, 33: 68, 35: 33, 36: 36, 37: 41, 38: 86, 39: 119, 40: 167, 41: 136, 42: 192, 43: 47, 44: 352, 45: 38, 46: 46, 47: 39, 48: 32, 49: 154, 50: 94, 51: 27, 52: 85, 53: 106, 56: 105, 57: 173, 58: 61, 59: 138, 61: 3, 62: 35, 63: 121, 64: 49, 65: 245, 66: 70, 67: 55, 68: 583, 69: 7, 70: 71, 71: 796, 72: 75, 73: 27, 75: 55, 80: 668, 85: 24, 87: 16, 88: 163, 91: 96, 92: 3, 93: 79, 94: 42, 96: 140, 97: 63, 99: 108, 101: 101, 102: 67, 103: 41, 104: 82, 105: 28, 108: 38, 109: 81, 110: 17, 111: 184, 112: 36, 113: 33, 114: 14, 116: 130, 117: 13, 118: 36, 119: 41, 120: 35, 123: 107, 127: 18, 128: 80, 129: 70, 130: 106, 131: 20, 132: 49, 133: 76, 134: 89, 135: 58, 136: 49, 140: 34, 142: 15, 145: 11, 146: 46, 147: 22, 148: 50, 149: 65, 150: 9, 152: 87, 157: 75, 158: 211, 159: 106, 161: 72, 162: 75, 163: 77, 164: 140, 165: 42, 166: 1, 167: 5, 168: 2, 169: 11, 170: 8, 171: 2, 172: 41, 173: 15, 174: 66, 175: 56, 176: 7, 177: 135, 180: 13, 182: 5, 183: 13, 184: 17, 185: 67, 186: 5, 187: 36, 188: 77, 189: 85, 190: 14, 191: 39, 192: 100, 194: 18, 195: 36, 197: 21, 198: 74, 199: 19, 200: 32, 201: 38, 203: 33, 205: 10, 206: 84, 207: 17, 208: 84, 210: 210, 211: 40, 212: 91, 213: 68, 214: 14, 215: 24, 216: 327, 217: 70, 219: 26, 220: 54, 221: 7, 222: 29, 223: 39, 224: 6}.get(code, 0)):
        raise ValueError(f'Official 1999 Karnataka totals differ: {code}')
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
        raise ValueError(f'Official 1999 Karnataka result differs: {code}')
    for source, candidate in zip(declared, ranked[:2], strict=True):
        if (source[2].strip(), source[1], int(source[3])) != (
                candidate['candidate_name'], candidate['party_at_election'], candidate['votes']):
            raise ValueError(f'Official 1999 Karnataka candidate differs: {code}')
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
        raise ValueError('1999 Karnataka official source differs')
    old_body = original.read_bytes()
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    before = json.loads(old_body)
    after = json.loads(old_body)
    if (digest(old_body) != PREDECESSOR or manifest['url'] != SOURCE_URL
            or next(file for file in manifest['files'] if file['file'] == SOURCE_FILE)['sha256'] != SOURCE_SHA
            or before['kind'] != 'ac' or before['year'] != 1999
            or before['source_url'] != SOURCE_URL or before['source_file'] != SOURCE_FILE
            or before['source_sha256'] != SOURCE_SHA or len(before['records']) != 224
            or [row['code'] for row in before['records']] != list(range(1, 225))):
        raise ValueError('1999 Karnataka extraction provenance differs')
    kinds = {'contested': [], 'uncontested': []}
    with fitz.open(source) as pdf:
        if len(pdf) != 281:
            raise ValueError('1999 Karnataka PDF page coverage differs')
        for row in after['records']:
            code = row['code']
            kind = reconcile(row, code + 20, pdf[code + 19].get_text(sort=True))
            kinds[kind].append(code)
    if len(kinds['contested']) != 224 or kinds['uncontested'] != []:
        raise ValueError('1999 Karnataka contest coverage differs')
    common = {'previous_review_note', 'original_extraction_warning', 'summary_page',
              'summary_source_file', 'summary_source_sha256', 'detail_source_file',
              'detail_source_sha256', 'official_source_url', 'official_summary_constituency_name',
              'official_summary_state', 'error', 'source_warning_code', 'summary_totals'}
    for old, new in zip(before['records'], after['records'], strict=True):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        expected = common | ({'summary_result'} if old['code'] in kinds['contested'] else {'summary_source_rows'})
        if (old['code'] != new['code'] or old['candidates'] != new['candidates'] or changed != expected):
            raise ValueError(f'Unrelated 1999 Karnataka evidence changed: {old["code"]}')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{PREDECESSOR}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='ac-1999-karnataka-', dir=root / 'exports') as temporary:
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
                'scope': '224 contested 1999 Karnataka AC declarations',
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
