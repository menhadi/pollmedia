"""Corroborate 2006 Kerala AC declarations with the official report."""

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
EDITION = 'fb76cebe74b1c87526369724'
NAME = 'pollmedia-ac-2006-kerala-declared-results-20261008'
PREDECESSOR = '144017b2ae53a3955f3473d87ff6491cad9a0da3f02e3d5d9e8ac653e3254830'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3762-kerala-2006/'
SOURCE_FILE = 'fb76cebe74b1c87526369724-8845.pdf'
SOURCE_SHA = 'bae58505ed29beb58f4df2941119074b6bc1a83f7ede776f3a557bdd9c8d8d93'
EXPECTED_EVM = {1: 846, 2: 801, 3: 10, 4: 1, 6: 577, 7: 1, 8: 807, 10: 12, 14: 18, 16: 9, 18: 8, 20: 3, 21: 13, 22: 25, 23: 7, 26: 10, 27: 16, 32: 5, 33: 493, 34: 4, 39: 4, 40: 7, 43: 11, 44: 5, 45: 15, 50: 9, 52: 2, 53: 8, 56: 11, 60: 722, 64: 7, 73: 8, 79: 2, 81: 9, 93: 7, 96: 3, 105: 3, 109: 11, 111: 3, 129: 12}
PRESERVED_CODES = [48, 69, 72]
HELD_REVIEW_CODES = [43, 68, 82, 83, 86]
PENDING = 'Candidate rows transcribed from the detailed PDF; summary totals reconcile; publication review pending.'
UNCONTESTED = PENDING + '; Reported elector and voter totals are inconsistent.'


PARTY_DIFFERENCES = {(1, 0): ('CPM', 'CPI(M)'), (3, 0): ('CPM', 'CPI(M)'), (5, 0): ('CPM', 'CPI(M)'), (6, 1): ('CPM', 'CPI(M)'), (7, 0): ('CPM', 'CPI(M)'), (8, 0): ('CPM', 'CPI(M)'), (9, 0): ('CPM', 'CPI(M)'), (10, 1): ('CPM', 'CPI(M)'), (12, 0): ('CPM', 'CPI(M)'), (14, 0): ('CPM', 'CPI(M)'), (15, 0): ('CPM', 'CPI(M)'), (16, 0): ('CPM', 'CPI(M)'), (19, 0): ('CPM', 'CPI(M)'), (20, 0): ('CPM', 'CPI(M)'), (21, 0): ('CPM', 'CPI(M)'), (24, 0): ('CPM', 'CPI(M)'), (26, 0): ('CPM', 'CPI(M)'), (27, 1): ('CPM', 'CPI(M)'), (28, 0): ('CPM', 'CPI(M)'), (30, 0): ('CPM', 'CPI(M)'), (31, 1): ('CPM', 'CPI(M)'), (32, 1): ('CPM', 'CPI(M)'), (35, 1): ('CPM', 'CPI(M)'), (38, 0): ('CPM', 'CPI(M)'), (39, 0): ('CPM', 'CPI(M)'), (42, 0): ('CPM', 'CPI(M)'), (45, 0): ('CPM', 'CPI(M)'), (46, 0): ('CPM', 'CPI(M)'), (49, 0): ('CPM', 'CPI(M)'), (51, 0): ('CPM', 'CPI(M)'), (52, 0): ('CPM', 'CPI(M)'), (53, 0): ('CPM', 'CPI(M)'), (54, 0): ('CPM', 'CPI(M)'), (55, 0): ('CPM', 'CPI(M)'), (56, 0): ('CPM', 'CPI(M)'), (58, 1): ('CPM', 'CPI(M)'), (60, 0): ('CPM', 'CPI(M)'), (61, 0): ('CPM', 'CPI(M)'), (63, 1): ('CPM', 'CPI(M)'), (64, 0): ('CPM', 'CPI(M)'), (65, 0): ('CPM', 'CPI(M)'), (71, 0): ('CPM', 'CPI(M)'), (73, 1): ('CPM', 'CPI(M)'), (74, 0): ('CPM', 'CPI(M)'), (75, 1): ('CPM', 'CPI(M)'), (76, 0): ('CPM', 'CPI(M)'), (77, 0): ('CPM', 'CPI(M)'), (78, 0): ('CPM', 'CPI(M)'), (79, 0): ('CPM', 'CPI(M)'), (84, 1): ('CPM', 'CPI(M)'), (85, 0): ('CPM', 'CPI(M)'), (89, 1): ('CPM', 'CPI(M)'), (90, 0): ('CPM', 'CPI(M)'), (91, 1): ('CPM', 'CPI(M)'), (92, 1): ('CPM', 'CPI(M)'), (97, 0): ('CPM', 'CPI(M)'), (99, 0): ('CPM', 'CPI(M)'), (101, 0): ('CPM', 'CPI(M)'), (103, 1): ('CPM', 'CPI(M)'), (104, 0): ('CPM', 'CPI(M)'), (107, 0): ('CPM', 'CPI(M)'), (108, 1): ('CPM', 'CPI(M)'), (109, 1): ('CPM', 'CPI(M)'), (110, 1): ('CPM', 'CPI(M)'), (111, 0): ('CPM', 'CPI(M)'), (112, 1): ('CPM', 'CPI(M)'), (113, 1): ('CPM', 'CPI(M)'), (117, 0): ('CPM', 'CPI(M)'), (118, 0): ('CPM', 'CPI(M)'), (123, 0): ('CPM', 'CPI(M)'), (124, 0): ('CPM', 'CPI(M)'), (127, 1): ('CPM', 'CPI(M)'), (128, 0): ('CPM', 'CPI(M)'), (130, 0): ('CPM', 'CPI(M)'), (133, 1): ('CPM', 'CPI(M)'), (134, 0): ('CPM', 'CPI(M)'), (136, 0): ('CPM', 'CPI(M)'), (137, 1): ('CPM', 'CPI(M)'), (139, 0): ('CPM', 'CPI(M)'), (140, 0): ('CPM', 'CPI(M)')}


def normalized(value: str) -> str:
    return re.sub(r'\s+', ' ', value).strip().upper()


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def section(text: str, start: str, end: str) -> str:
    match = re.search(start + r'\b(.*?)' + end, text, re.I | re.S)
    if match is None:
        raise ValueError(f'Official 2006 Kerala summary section missing: {start}')
    return match[1]


def total(text: str) -> int:
    line = re.search(r'(?m)^\s*[34]\. TOTAL[^\n]*$', text)
    values = re.findall(r'\d+', line[0]) if line else []
    if len(values) < 2:
        raise ValueError('Official 2006 Kerala summary total missing')
    return int(values[-1])


def reconcile(row: dict, page: int, text: str, detail: str) -> str:
    code = row['code']
    identity = re.search(r'CONSTITUENCY\s+(\d+)\s*-\s*([^\n]+)', text, re.I)
    if (identity is None or int(identity[1]) != code
            or normalized(identity[2]).replace(' (', '(') != normalized(row['name']).replace(' (', '(')
            or page != code + 18 or 'CONSTITUENCY DATA - SUMMARY' not in text
            or 'LEGISLATIVE ASSEMBLY OF KERALA' not in normalized(text) or '2006' not in text
            or row['state_name'] != 'Kerala' or row['number_of_seats'] != 1
            or row['status'] != 'needs_review' or row.get('summary_page') != page
            or row.get('source_warning_code') is not None):
        raise ValueError(f'Official 2006 Kerala identity differs: {code}')
    electors = total(section(text, r'II\.\s+ELECTORS', r'III\. VOTERS'))
    voters_section = section(text, r'III\. VOTERS', r'IV\. VOTES')
    votes_section = section(text, r'IV\. VOTES', r'V\. POLLING STATIONS')
    results = text.split('VII. RESULT', 1)[1]
    if electors != row['electors'] or len(row['candidates']) < 1:
        raise ValueError(f'Official 2006 Kerala electors differ: {code}')
    row['previous_review_note'] = row['error']
    # Preserve the original extraction warning already carried by the prior correction.
    row['summary_page'] = page
    row['summary_source_file'] = SOURCE_FILE
    row['summary_source_sha256'] = SOURCE_SHA
    row['detail_source_file'] = SOURCE_FILE
    row['detail_source_sha256'] = SOURCE_SHA
    row['official_source_url'] = SOURCE_URL
    row['official_summary_constituency_name'] = identity[2].strip()
    row['official_summary_state'] = 'Kerala'
    if 'Uncontested' in text:
        raise ValueError('Unexpected uncontested declaration in pinned 2006 Kerala edition')
    if row['error'] != PENDING or len(row['candidates']) < 2:
        raise ValueError(f'Official 2006 Kerala contested state differs: {code}')
    voters = total(voters_section)
    printed = {'POLLED': voters}
    for ordinal, label, key in ((1, 'REJECTED VOTES (POSTAL)', 'REJECTED'), (2, 'VOTES NOT RETREIVED FROM EVM', 'VOTES NOT RETREIVED'), (3, 'TOTAL VALID VOTES POLLED', 'VALID')):
        match = re.search(r'(?m)^[ \t]*' + str(ordinal) + r'\. ' + re.escape(label) + r'[ \t]+(\d+)', votes_section)
        if match is None:
            raise ValueError(f'Official 2006 Kerala {label} missing: {code}')
        printed[key] = int(match[1])
    if (voters < 1 or voters > electors or voters != printed['POLLED']
            or (voters, printed['VALID']) != (row['votes_polled'], row['valid_candidate_votes'])
            or printed['VALID'] + printed['REJECTED'] + printed['VOTES NOT RETREIVED'] != voters or printed['VOTES NOT RETREIVED'] != EXPECTED_EVM.get(code, 0)):
        raise ValueError(f'Official 2006 Kerala totals differ: {code}')
    declared = []
    for label in ('WINNER', 'RUNNER-UP'):
        parsed = re.search(r'^\s*' + label + r'\s+(\S+)\s+(.+?)\s+(\d+)\s*$', results, re.M)
        if parsed is None:
            raise ValueError(f'Official declaration layout differs: {code}')
        declared.append((label, parsed[1], parsed[2], parsed[3]))
    margin = re.search(r'^\s*MARGIN\s+(\d+)', results, re.M)
    ranked = sorted(row['candidates'], key=lambda candidate: candidate['votes'], reverse=True)
    if (len(declared) != 2 or margin is None or sum(candidate['votes'] for candidate in ranked) != printed['VALID']
            or ranked[0]['votes'] <= ranked[1]['votes']
            or int(margin[1]) != ranked[0]['votes'] - ranked[1]['votes']
            or len({(candidate['candidate_name'], candidate['party_at_election'], candidate['votes'])
                    for candidate in ranked}) != len(ranked)):
        raise ValueError(f'Official 2006 Kerala result differs: {code}')
    differences = []
    for rank, (source, candidate) in enumerate(zip(declared, ranked[:2], strict=True)):
        expected = PARTY_DIFFERENCES.get((code, rank))
        if (normalized(source[2]), int(source[3])) != (normalized(candidate['candidate_name']), candidate['votes']):
            raise ValueError(f'Official candidate differs: {code}')
        if expected is None:
            if source[1] != candidate['party_at_election']:
                raise ValueError(f'Unexpected party difference: {code}')
        else:
            if (source[1], candidate['party_at_election']) != expected:
                raise ValueError(f'Pinned party difference changed: {code}')
            # Verify the detailed report prints the archived party and the same vote total.
            pattern = re.escape(expected[1]) + r'\s+(\d+)\s+(\d+)\s+' + str(candidate['votes']) + r'\s*$'
            matches = re.findall(pattern, detail, re.M)
            if len(matches) != 1 or sum(map(int, matches[0])) != candidate['votes']:
                raise ValueError(f'Detailed party evidence differs: {code}')
            differences.append(f"summary {expected[0]}, detailed report {expected[1]}")
    row['error'] = ('Official summary corroborates the detailed electors, voters, valid votes, '
                    'declared winner and margin; archived review warning retained.')
    if differences:
        row['error'] += ' Official report party labels differ (' + '; '.join(differences) + '); detailed party labels retained.'
    if printed['VOTES NOT RETREIVED']:
        row['error'] += f" Official summary includes {printed['VOTES NOT RETREIVED']} votes not retrieved from EVM/set apart in the polled total."
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
        raise ValueError('2006 Kerala official source differs')
    prior = root / 'exports/pollmedia-ac-2006-kerala-declared-results-20261003.zip'
    with zipfile.ZipFile(prior) as outer:
        entry = json.loads(outer.read('AUDIT.json'))
        with zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{EDITION}.zip'))) as inner:
            old_body = inner.read(f'election-archive/{EDITION}/extraction.json')
    if entry['new_sha256'] != PREDECESSOR:
        raise ValueError('Prior correction audit checksum differs')
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    before = json.loads(old_body)
    after = json.loads(old_body)
    if (digest(old_body) != PREDECESSOR or manifest['url'] != SOURCE_URL
            or next(file for file in manifest['files'] if file['file'] == SOURCE_FILE)['sha256'] != SOURCE_SHA
            or before['kind'] != 'ac' or before['year'] != 2006
            or before['source_url'] != SOURCE_URL or before['source_file'] != SOURCE_FILE
            or before['source_sha256'] != SOURCE_SHA or len(before['records']) != 140
            or [row['code'] for row in before['records']] != list(range(1, 141))):
        raise ValueError('2006 Kerala extraction provenance differs')
    kinds = {'contested': [], 'uncontested': []}
    with fitz.open(source) as pdf:
        if len(pdf) != 298:
            raise ValueError('2006 Kerala PDF page coverage differs')
        for row in after['records']:
            code = row['code']
            if code in HELD_REVIEW_CODES:
                # Official winner/margin or extracted name differs; preserve full pending records.
                continue
            if code in PRESERVED_CODES:
                if row.get('source_warning_code') != 'official_summary_turnout_only' or not row.get('summary_result'):
                    raise ValueError(f'Prior declared-result evidence missing: {code}')
                continue
            kind = reconcile(row, code + 18, pdf[code + 17].get_text(sort=True), pdf[row['detail_page'] - 1].get_text(sort=True))
            kinds[kind].append(code)
    if len(kinds['contested']) != 132 or kinds['uncontested'] != []:
        raise ValueError('2006 Kerala contest coverage differs')
    common = {'previous_review_note',
              'summary_source_file', 'summary_source_sha256', 'detail_source_file',
              'detail_source_sha256', 'official_source_url', 'official_summary_constituency_name',
              'official_summary_state', 'error', 'source_warning_code'}
    for old, new in zip(before['records'], after['records'], strict=True):
        if old['code'] in PRESERVED_CODES + HELD_REVIEW_CODES:
            if old != new:
                raise ValueError('Previously corrected record changed')
            continue
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        expected = common | ({'summary_result'} if old['code'] in kinds['contested'] else {'summary_source_rows'})
        if (old['code'] != new['code'] or old['candidates'] != new['candidates'] or changed != expected
                or old['original_extraction_warning'] != new['original_extraction_warning']):
            raise ValueError(f'Unrelated 2006 Kerala evidence changed: {old["code"]}')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{PREDECESSOR}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='ac-2006-kerala-', dir=root / 'exports') as temporary:
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
                'scope': '132 contested 2006 Kerala AC declarations; 3 prior corrected records and 5 result/name discrepancies preserved',
                'edition': EDITION, 'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
                'source_sha256': SOURCE_SHA, 'previous_sha256': PREDECESSOR,
                'new_sha256': digest(new_body), 'contested_codes': kinds['contested'],
                'uncontested_codes': kinds['uncontested'], 'preserved_codes': PRESERVED_CODES + HELD_REVIEW_CODES,
                'held_review_codes': HELD_REVIEW_CODES,
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
