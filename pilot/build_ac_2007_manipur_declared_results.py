"""Corroborate 2007 Manipur AC declarations with the official report."""

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
EDITION = '630a18596b67255629d299e0'
NAME = 'pollmedia-ac-2007-manipur-declared-results-20261008'
PREDECESSOR = '2b3262c5188bf763e0567d8144537bb65a51291d2fc1f2c87a8f377800b2af3c'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3711-manipur-2007/'
SOURCE_FILE = '630a18596b67255629d299e0-8721.pdf'
SOURCE_SHA = 'eb7ac5d77c19c4050578df6decc32f156f7ac75cc290b38e3936fd175da09ed0'
EXPECTED_EVM = {28: 66}
PRESERVED_CODES = []
HELD_REVIEW_CODES = []
PENDING = 'Candidate rows transcribed from the detailed PDF; summary totals reconcile; publication review pending.'
UNCONTESTED = PENDING + '; Reported elector and voter totals are inconsistent.'


PARTY_DIFFERENCES = {}


def normalized(value: str) -> str:
    return re.sub(r'\s+', ' ', value).strip().upper()


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def section(text: str, start: str, end: str) -> str:
    match = re.search(start + r'\b(.*?)' + end, text, re.I | re.S)
    if match is None:
        raise ValueError(f'Official 2007 Manipur summary section missing: {start}')
    return match[1]


def total(text: str) -> int:
    line = re.search(r'(?m)^\s*[34]\. TOTAL[^\n]*$', text)
    values = re.findall(r'\d+', line[0]) if line else []
    if len(values) < 2:
        raise ValueError('Official 2007 Manipur summary total missing')
    return int(values[-1])


def reconcile(row: dict, page: int, text: str, detail: str) -> str:
    code = row['code']
    identity = re.search(r'CONSTITUENCY\s+(\d+)\s*-\s*([^\n]+)', text, re.I)
    if (identity is None or int(identity[1]) != code
            or normalized(identity[2]).replace(' (', '(') != normalized(row['name']).replace(' (', '(')
            or page != code + 13 or 'CONSTITUENCY DATA - SUMMARY' not in text
            or 'LEGISLATIVE ASSEMBLY OF MANIPUR' not in normalized(text) or '2007' not in text
            or row['state_name'] != 'Manipur' or row['number_of_seats'] != 1
            or row['status'] != 'needs_review' or row.get('summary_page') != page
            or row.get('source_warning_code') is not None):
        raise ValueError(f'Official 2007 Manipur identity differs: {code}')
    electors = total(section(text, r'II\.\s+ELECTORS', r'III\. VOTERS'))
    voters_section = section(text, r'III\. VOTERS', r'IV\. VOTES')
    votes_section = section(text, r'IV\. VOTES', r'V\. POLLING STATIONS')
    results = text.split('VII. RESULT', 1)[1]
    if electors != row['electors'] or len(row['candidates']) < 1:
        raise ValueError(f'Official 2007 Manipur electors differ: {code}')
    row['previous_review_note'] = row['error']
    # Preserve the original extraction warning already carried by the prior correction.
    row['summary_page'] = page
    row['summary_source_file'] = SOURCE_FILE
    row['summary_source_sha256'] = SOURCE_SHA
    row['detail_source_file'] = SOURCE_FILE
    row['detail_source_sha256'] = SOURCE_SHA
    row['official_source_url'] = SOURCE_URL
    row['official_summary_constituency_name'] = identity[2].strip()
    row['official_summary_state'] = 'Manipur'
    if 'Uncontested' in text:
        raise ValueError('Unexpected uncontested declaration in pinned 2007 Manipur edition')
    if row['error'] != PENDING or len(row['candidates']) < 2:
        raise ValueError(f'Official 2007 Manipur contested state differs: {code}')
    voters = total(voters_section)
    printed = {'POLLED': voters}
    for ordinal, label, key in ((1, 'REJECTED VOTES (POSTAL)', 'REJECTED'), (2, 'VOTES NOT RETREIVED FROM EVM', 'VOTES NOT RETREIVED'), (3, 'TOTAL VALID VOTES POLLED', 'VALID')):
        match = re.search(r'(?m)^[ \t]*' + str(ordinal) + r'\. ' + re.escape(label) + r'[ \t]+(\d+)', votes_section)
        if match is None:
            raise ValueError(f'Official 2007 Manipur {label} missing: {code}')
        printed[key] = int(match[1])
    if (voters < 1 or voters > electors or voters != printed['POLLED']
            or (voters, printed['VALID']) != (row['votes_polled'], row['valid_candidate_votes'])
            or printed['VALID'] + printed['REJECTED'] + printed['VOTES NOT RETREIVED'] != voters or printed['VOTES NOT RETREIVED'] != EXPECTED_EVM.get(code, 0)):
        raise ValueError(f'Official 2007 Manipur totals differ: {code}')
    declared = []
    for label, stop in (('WINNER', 'RUNNER-UP'), ('RUNNER-UP', 'MARGIN')):
        block = re.search(r'^\s*' + label + r'\b(.*?)^\s*' + stop + r'\b', results, re.M | re.S)
        if block is None:
            raise ValueError(f'Official result block differs: {code}')
        parsed = re.fullmatch(r'\s*(\S+)[ \t]+([^\n]+?)[ \t]+(\d+)[ \t]*(?:\n(.*))?', block[1], re.S)
        if parsed is None or re.search(r'\d', parsed[4] or ''):
            raise ValueError(f'Official declaration continuation differs: {code}')
        name = normalized(parsed[2] + ' ' + (parsed[4] or ''))
        declared.append((label, parsed[1], name, parsed[3]))
    margin = re.search(r'^\s*MARGIN\s+(\d+)', results, re.M)
    ranked = sorted(row['candidates'], key=lambda candidate: candidate['votes'], reverse=True)
    if (len(declared) != 2 or margin is None or sum(candidate['votes'] for candidate in ranked) != printed['VALID']
            or ranked[0]['votes'] <= ranked[1]['votes']
            or int(margin[1]) != ranked[0]['votes'] - ranked[1]['votes']
            or len({(candidate['candidate_name'], candidate['party_at_election'], candidate['votes'])
                    for candidate in ranked}) != len(ranked)):
        raise ValueError(f'Official 2007 Manipur result differs: {code}')
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
        raise ValueError('2007 Manipur official source differs')
    prior = root / 'exports/pollmedia-ac-summary-corrections-20261001-v7.zip'
    with zipfile.ZipFile(prior) as outer:
        entry = next(row for row in json.loads(outer.read('AUDIT.json'))['editions'] if row['edition'] == EDITION)
        with zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{EDITION}.zip'))) as inner:
            old_body = inner.read(f'election-archive/{EDITION}/extraction.json')
    if entry['new_sha256'] != PREDECESSOR:
        raise ValueError('Prior correction audit checksum differs')
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    before = json.loads(old_body)
    after = json.loads(old_body)
    if (digest(old_body) != PREDECESSOR or manifest['url'] != SOURCE_URL
            or next(file for file in manifest['files'] if file['file'] == SOURCE_FILE)['sha256'] != SOURCE_SHA
            or before['kind'] != 'ac' or before['year'] != 2007
            or before['source_url'] != SOURCE_URL or before['source_file'] != SOURCE_FILE
            or before['source_sha256'] != SOURCE_SHA or len(before['records']) != 60
            or [row['code'] for row in before['records']] != list(range(1, 61))):
        raise ValueError('2007 Manipur extraction provenance differs')
    kinds = {'contested': [], 'uncontested': []}
    with fitz.open(source) as pdf:
        if len(pdf) != 133:
            raise ValueError('2007 Manipur PDF page coverage differs')
        for row in after['records']:
            code = row['code']
            if code in HELD_REVIEW_CODES:
                # Official winner/margin or extracted name differs; preserve full pending records.
                continue
            if code in PRESERVED_CODES:
                if row.get('source_warning_code') != 'official_summary_turnout_only' or not row.get('summary_result'):
                    raise ValueError(f'Prior declared-result evidence missing: {code}')
                continue
            kind = reconcile(row, code + 13, pdf[code + 12].get_text(sort=True), pdf[row['detail_page'] - 1].get_text(sort=True))
            kinds[kind].append(code)
    if len(kinds['contested']) != 60 or kinds['uncontested'] != []:
        raise ValueError('2007 Manipur contest coverage differs')
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
            raise ValueError(f'Unrelated 2007 Manipur evidence changed: {old["code"]}')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{PREDECESSOR}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='ac-2007-manipur-', dir=root / 'exports') as temporary:
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
                'scope': '60 contested 2007 Manipur AC declarations',
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
