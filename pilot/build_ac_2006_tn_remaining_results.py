"""Corroborate 2006 Tamil Nadu AC declarations with the official report."""

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
EDITION = '1bec8e5043378d3d7ee107b2'
NAME = 'pollmedia-ac-2006-tn-declared-results-20261008'
PREDECESSOR = '314c45b51c6bdad654cecf065b98bfb6882a2653f09c49613c5f3b5f5a300692'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3339-tamil-nadu-2006/'
SOURCE_FILE = '1bec8e5043378d3d7ee107b2-7714.pdf'
SOURCE_SHA = 'f6e57759f46d1b87c3dcda5d90716cc874763e0bff8e4f98a71ebc5387da9340'
EXPECTED_EVM = {8: 576, 14: 101, 28: 7, 30: 136, 32: 33, 40: 5, 51: 942, 52: 754, 60: 51, 66: 20, 102: 61, 103: 823, 104: 6, 107: 686, 112: 690, 115: 6, 124: 270, 168: 177, 171: 1, 183: 4, 186: 5, 195: 300, 210: 17, 216: 554, 217: 395, 221: 1}
PRESERVED_CODES = [5, 16, 18, 21, 26, 45, 58, 62, 82, 98, 140, 145, 169, 191, 218]
HELD_REVIEW_CODES = [6, 9, 11, 49, 61, 94, 180, 221]
PENDING = 'Candidate rows transcribed from the detailed PDF; summary totals reconcile; publication review pending.'
UNCONTESTED = PENDING + '; Reported elector and voter totals are inconsistent.'


PARTY_DIFFERENCES = {(1, 0): ('ADMK', 'AIADMK'), (3, 0): ('ADMK', 'AIADMK'), (4, 0): ('ADMK', 'AIADMK'), (10, 1): ('ADMK', 'AIADMK'), (12, 0): ('ADMK', 'AIADMK'), (13, 0): ('ADMK', 'AIADMK'), (14, 0): ('ADMK', 'AIADMK'), (15, 0): ('ADMK', 'AIADMK'), (17, 1): ('ADMK', 'AIADMK'), (19, 1): ('ADMK', 'AIADMK'), (22, 1): ('ADMK', 'AIADMK'), (23, 1): ('ADMK', 'AIADMK'), (24, 1): ('ADMK', 'AIADMK'), (25, 1): ('ADMK', 'AIADMK'), (29, 1): ('ADMK', 'AIADMK'), (30, 0): ('ADMK', 'AIADMK'), (31, 1): ('ADMK', 'AIADMK'), (32, 1): ('ADMK', 'AIADMK'), (33, 1): ('ADMK', 'AIADMK'), (34, 1): ('ADMK', 'AIADMK'), (35, 1): ('ADMK', 'AIADMK'), (36, 1): ('ADMK', 'AIADMK'), (37, 0): ('CPM', 'CPI(M)'), (37, 1): ('ADMK', 'AIADMK'), (38, 1): ('ADMK', 'AIADMK'), (39, 1): ('ADMK', 'AIADMK'), (40, 1): ('ADMK', 'AIADMK'), (43, 1): ('ADMK', 'AIADMK'), (44, 1): ('ADMK', 'AIADMK'), (46, 1): ('ADMK', 'AIADMK'), (47, 0): ('ADMK', 'AIADMK'), (50, 1): ('ADMK', 'AIADMK'), (51, 1): ('ADMK', 'AIADMK'), (52, 1): ('ADMK', 'AIADMK'), (53, 1): ('ADMK', 'AIADMK'), (55, 0): ('ADMK', 'AIADMK'), (56, 0): ('ADMK', 'AIADMK'), (57, 1): ('ADMK', 'AIADMK'), (60, 0): ('ADMK', 'AIADMK'), (63, 1): ('ADMK', 'AIADMK'), (64, 1): ('ADMK', 'AIADMK'), (66, 0): ('ADMK', 'AIADMK'), (68, 0): ('ADMK', 'AIADMK'), (68, 1): ('CPM', 'CPI(M)'), (71, 1): ('ADMK', 'AIADMK'), (72, 1): ('ADMK', 'AIADMK'), (73, 1): ('ADMK', 'AIADMK'), (74, 1): ('ADMK', 'AIADMK'), (76, 1): ('ADMK', 'AIADMK'), (77, 1): ('ADMK', 'AIADMK'), (78, 0): ('ADMK', 'AIADMK'), (79, 0): ('CPM', 'CPI(M)'), (80, 1): ('ADMK', 'AIADMK'), (81, 0): ('ADMK', 'AIADMK'), (83, 1): ('ADMK', 'AIADMK'), (84, 1): ('ADMK', 'AIADMK'), (86, 1): ('ADMK', 'AIADMK'), (87, 1): ('ADMK', 'AIADMK'), (88, 0): ('ADMK', 'AIADMK'), (89, 1): ('ADMK', 'AIADMK'), (90, 1): ('ADMK', 'AIADMK'), (91, 1): ('ADMK', 'AIADMK'), (92, 1): ('ADMK', 'AIADMK'), (93, 1): ('ADMK', 'AIADMK'), (95, 1): ('ADMK', 'AIADMK'), (96, 1): ('ADMK', 'AIADMK'), (99, 1): ('ADMK', 'AIADMK'), (100, 1): ('ADMK', 'AIADMK'), (101, 0): ('ADMK', 'AIADMK'), (102, 0): ('ADMK', 'AIADMK'), (104, 0): ('ADMK', 'AIADMK'), (104, 1): ('CPM', 'CPI(M)'), (105, 0): ('ADMK', 'AIADMK'), (106, 1): ('ADMK', 'AIADMK'), (107, 0): ('ADMK', 'AIADMK'), (108, 0): ('ADMK', 'AIADMK'), (109, 0): ('ADMK', 'AIADMK'), (111, 0): ('ADMK', 'AIADMK'), (112, 1): ('ADMK', 'AIADMK'), (114, 1): ('ADMK', 'AIADMK'), (115, 0): ('ADMK', 'AIADMK'), (116, 0): ('CPM', 'CPI(M)'), (117, 1): ('ADMK', 'AIADMK'), (118, 1): ('ADMK', 'AIADMK'), (119, 0): ('ADMK', 'AIADMK'), (120, 1): ('ADMK', 'AIADMK'), (121, 1): ('ADMK', 'AIADMK'), (122, 1): ('ADMK', 'AIADMK'), (123, 0): ('ADMK', 'AIADMK'), (124, 1): ('ADMK', 'AIADMK'), (126, 1): ('ADMK', 'AIADMK'), (127, 1): ('ADMK', 'AIADMK'), (128, 1): ('ADMK', 'AIADMK'), (129, 1): ('ADMK', 'AIADMK'), (130, 1): ('ADMK', 'AIADMK'), (131, 0): ('ADMK', 'AIADMK'), (132, 0): ('ADMK', 'AIADMK'), (133, 1): ('ADMK', 'AIADMK'), (135, 0): ('ADMK', 'AIADMK'), (136, 0): ('ADMK', 'AIADMK'), (138, 0): ('ADMK', 'AIADMK'), (139, 0): ('ADMK', 'AIADMK'), (141, 0): ('ADMK', 'AIADMK'), (141, 1): ('CPM', 'CPI(M)'), (142, 0): ('ADMK', 'AIADMK'), (143, 1): ('ADMK', 'AIADMK'), (144, 0): ('CPM', 'CPI(M)'), (146, 0): ('ADMK', 'AIADMK'), (147, 0): ('ADMK', 'AIADMK'), (148, 0): ('CPM', 'CPI(M)'), (149, 1): ('ADMK', 'AIADMK'), (150, 1): ('ADMK', 'AIADMK'), (152, 0): ('ADMK', 'AIADMK'), (153, 1): ('ADMK', 'AIADMK'), (154, 0): ('ADMK', 'AIADMK'), (155, 1): ('ADMK', 'AIADMK'), (157, 1): ('ADMK', 'AIADMK'), (158, 1): ('ADMK', 'AIADMK'), (159, 1): ('ADMK', 'AIADMK'), (160, 1): ('ADMK', 'AIADMK'), (161, 0): ('ADMK', 'AIADMK'), (162, 1): ('ADMK', 'AIADMK'), (163, 1): ('ADMK', 'AIADMK'), (164, 0): ('ADMK', 'AIADMK'), (165, 0): ('ADMK', 'AIADMK'), (167, 1): ('ADMK', 'AIADMK'), (168, 1): ('ADMK', 'AIADMK'), (170, 1): ('ADMK', 'AIADMK'), (172, 1): ('ADMK', 'AIADMK'), (173, 1): ('ADMK', 'AIADMK'), (174, 1): ('ADMK', 'AIADMK'), (175, 0): ('CPM', 'CPI(M)'), (175, 1): ('ADMK', 'AIADMK'), (176, 1): ('ADMK', 'AIADMK'), (177, 1): ('ADMK', 'AIADMK'), (178, 1): ('ADMK', 'AIADMK'), (181, 0): ('ADMK', 'AIADMK'), (182, 1): ('ADMK', 'AIADMK'), (183, 1): ('ADMK', 'AIADMK'), (184, 1): ('ADMK', 'AIADMK'), (185, 0): ('ADMK', 'AIADMK'), (186, 0): ('ADMK', 'AIADMK'), (187, 1): ('ADMK', 'AIADMK'), (188, 0): ('ADMK', 'AIADMK'), (189, 1): ('ADMK', 'AIADMK'), (190, 0): ('ADMK', 'AIADMK'), (192, 1): ('ADMK', 'AIADMK'), (193, 1): ('ADMK', 'AIADMK'), (194, 1): ('ADMK', 'AIADMK'), (195, 1): ('ADMK', 'AIADMK'), (196, 1): ('ADMK', 'AIADMK'), (197, 1): ('ADMK', 'AIADMK'), (199, 0): ('ADMK', 'AIADMK'), (200, 1): ('ADMK', 'AIADMK'), (202, 1): ('ADMK', 'AIADMK'), (203, 1): ('ADMK', 'AIADMK'), (204, 1): ('ADMK', 'AIADMK'), (205, 1): ('ADMK', 'AIADMK'), (208, 1): ('ADMK', 'AIADMK'), (209, 0): ('ADMK', 'AIADMK'), (210, 0): ('ADMK', 'AIADMK'), (211, 0): ('ADMK', 'AIADMK'), (212, 0): ('ADMK', 'AIADMK'), (213, 0): ('ADMK', 'AIADMK'), (214, 1): ('CPM', 'CPI(M)'), (215, 1): ('ADMK', 'AIADMK'), (217, 1): ('ADMK', 'AIADMK'), (219, 1): ('ADMK', 'AIADMK'), (220, 1): ('ADMK', 'AIADMK'), (222, 1): ('ADMK', 'AIADMK'), (223, 1): ('ADMK', 'AIADMK'), (225, 0): ('ADMK', 'AIADMK'), (226, 1): ('ADMK', 'AIADMK'), (227, 1): ('ADMK', 'AIADMK'), (228, 1): ('ADMK', 'AIADMK'), (231, 1): ('ADMK', 'AIADMK'), (232, 0): ('CPM', 'CPI(M)'), (233, 0): ('CPM', 'CPI(M)'), (233, 1): ('ADMK', 'AIADMK')}


def normalized(value: str) -> str:
    return re.sub(r'\s+', ' ', value).strip().upper()


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def section(text: str, start: str, end: str) -> str:
    match = re.search(start + r'\b(.*?)' + end, text, re.I | re.S)
    if match is None:
        raise ValueError(f'Official 2006 Tamil Nadu summary section missing: {start}')
    return match[1]


def total(text: str) -> int:
    line = re.search(r'(?m)^\s*[34]\. TOTAL[^\n]*$', text)
    values = re.findall(r'\d+', line[0]) if line else []
    if len(values) < 2:
        raise ValueError('Official 2006 Tamil Nadu summary total missing')
    return int(values[-1])


def reconcile(row: dict, page: int, text: str, detail: str) -> str:
    code = row['code']
    identity = re.search(r'CONSTITUENCY\s+(\d+)\s*-\s*([^\n]+)', text, re.I)
    if (identity is None or int(identity[1]) != code
            or normalized(identity[2]).replace(' (', '(') != normalized(row['name']).replace(' (', '(')
            or page != code + (25 if code <= 85 else 28) or 'CONSTITUENCY DATA - SUMMARY' not in text
            or 'LEGISLATIVE ASSEMBLY OF TAMIL NADU' not in normalized(text) or '2006' not in text
            or row['state_name'] != 'Tamil Nadu' or row['number_of_seats'] != 1
            or row['status'] != 'needs_review' or row.get('summary_page') != page
            or row.get('source_warning_code') is not None):
        raise ValueError(f'Official 2006 Tamil Nadu identity differs: {code}')
    electors = total(section(text, r'II\.\s+ELECTORS', r'III\. VOTERS'))
    voters_section = section(text, r'III\. VOTERS', r'IV\. VOTES')
    votes_section = section(text, r'IV\. VOTES', r'V\. POLLING STATIONS')
    results = text.split('VII. RESULT', 1)[1]
    if electors != row['electors'] or len(row['candidates']) < 1:
        raise ValueError(f'Official 2006 Tamil Nadu electors differ: {code}')
    row['previous_review_note'] = row['error']
    # Preserve the original extraction warning already carried by the prior correction.
    row['summary_page'] = page
    row['summary_source_file'] = SOURCE_FILE
    row['summary_source_sha256'] = SOURCE_SHA
    row['detail_source_file'] = SOURCE_FILE
    row['detail_source_sha256'] = SOURCE_SHA
    row['official_source_url'] = SOURCE_URL
    row['official_summary_constituency_name'] = identity[2].strip()
    row['official_summary_state'] = 'Tamil Nadu'
    if 'Uncontested' in text:
        raise ValueError('Unexpected uncontested declaration in pinned 2006 Tamil Nadu edition')
    if row['error'] != PENDING or len(row['candidates']) < 2:
        raise ValueError(f'Official 2006 Tamil Nadu contested state differs: {code}')
    voters = total(voters_section)
    printed = {'POLLED': voters}
    for ordinal, label, key in ((1, 'REJECTED VOTES (POSTAL)', 'REJECTED'), (2, 'VOTES NOT RETREIVED FROM EVM', 'VOTES NOT RETREIVED'), (3, 'TOTAL VALID VOTES POLLED', 'VALID')):
        match = re.search(r'(?m)^[ \t]*' + str(ordinal) + r'\. ' + re.escape(label) + r'[ \t]+(\d+)', votes_section)
        if match is None:
            raise ValueError(f'Official 2006 Tamil Nadu {label} missing: {code}')
        printed[key] = int(match[1])
    if (voters < 1 or voters > electors or voters != printed['POLLED']
            or (voters, printed['VALID']) != (row['votes_polled'], row['valid_candidate_votes'])
            or printed['VALID'] + printed['REJECTED'] + printed['VOTES NOT RETREIVED'] != voters or printed['VOTES NOT RETREIVED'] != EXPECTED_EVM.get(code, 0)):
        raise ValueError(f'Official 2006 Tamil Nadu totals differ: {code}')
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
        raise ValueError(f'Official 2006 Tamil Nadu result differs: {code}')
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
        raise ValueError('2006 Tamil Nadu official source differs')
    prior = root / 'exports/pollmedia-ac-2006-tn-declared-results-20261003.zip'
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
            or before['source_sha256'] != SOURCE_SHA or len(before['records']) != 234
            or [row['code'] for row in before['records']] != list(range(1, 235))):
        raise ValueError('2006 Tamil Nadu extraction provenance differs')
    kinds = {'contested': [], 'uncontested': []}
    with fitz.open(source) as pdf:
        if len(pdf) != 496:
            raise ValueError('2006 Tamil Nadu PDF page coverage differs')
        for row in after['records']:
            code = row['code']
            if code in HELD_REVIEW_CODES:
                # Official winner/margin or extracted name differs; preserve full pending records.
                continue
            if code in PRESERVED_CODES:
                if row.get('source_warning_code') != 'official_summary_turnout_only' or not row.get('summary_result'):
                    raise ValueError(f'Prior declared-result evidence missing: {code}')
                continue
            kind = reconcile(row, code + (25 if code <= 85 else 28), pdf[code + (24 if code <= 85 else 27)].get_text(sort=True), pdf[row['detail_page'] - 1].get_text(sort=True))
            kinds[kind].append(code)
    if len(kinds['contested']) != 211 or kinds['uncontested'] != []:
        raise ValueError('2006 Tamil Nadu contest coverage differs')
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
            raise ValueError(f'Unrelated 2006 Tamil Nadu evidence changed: {old["code"]}')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{PREDECESSOR}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='ac-2006-tn-', dir=root / 'exports') as temporary:
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
                'scope': '211 contested 2006 Tamil Nadu AC declarations; 15 prior corrected records and 8 result/name discrepancies preserved',
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
