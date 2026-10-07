"""Corroborate 1996 Tamil Nadu AC declarations with the official report."""

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
EDITION = '82e8650b7962c1d4c78305f3'
NAME = 'pollmedia-ac-1996-tamil-nadu-declared-results-20261007'
PREDECESSOR = 'bf466ac95c9b0a21d3e7a8bc2d6aef82c40c0e2c54594d44479fbabce2692080'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3336-tamil-nadu-1996/'
SOURCE_FILE = '82e8650b7962c1d4c78305f3-7708.pdf'
SOURCE_SHA = 'be3d98d39a829445a6ee69cd5841c6e97dedda9a2bfe2f0b1293fd73bf6fff76'
PENDING = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.'
UNCONTESTED = PENDING + '; Reported elector and voter totals are inconsistent.'


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def section(text: str, start: str, end: str) -> str:
    match = re.search(start + r'\b(.*?)' + end, text, re.I | re.S)
    if match is None:
        raise ValueError(f'Official 1996 Tamil Nadu summary section missing: {start}')
    return match[1]


def total(text: str) -> int:
    line = re.search(r'(?m)^\s*3\. TOTAL[^\n]*$', text)
    values = re.findall(r'\d+', line[0]) if line else []
    if len(values) < 2:
        raise ValueError('Official 1996 Tamil Nadu summary total missing')
    return int(values[-1])


def reconcile(row: dict, page: int, text: str, pdf) -> str:
    code = row['code']
    identity = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text)
    if (identity is None or int(identity[1]) != code or identity[2].strip() != row['name']
            or page != code + 25 or row['state_name'] != 'Tamil Nadu'
            or row['number_of_seats'] != 1 or row['status'] != 'needs_review'
            or row['error'] != PENDING or row.get('summary_page') is not None
            or 'LEGISLATIVE ASSEMBLY OF TAMIL NADU' not in text.upper()):
        raise ValueError(f'Summary identity or prior state differs: {code}')
    electors = total(section(text, r'II\. ELECTORS', r'III\. ELECTORS WHO VOTED'))
    voters = section(text, r'III\. ELECTORS WHO VOTED', r'IV\. VOTES')
    general = int(re.findall(r'\d+', re.search(r'(?m)^\s*1\. GENERAL[^\n]*', voters)[0])[-1])
    postal = int(re.findall(r'\d+', re.search(r'(?m)^\s*2\. POSTAL[^\n]*', voters)[0])[-1])
    polled = total(voters)
    votes = section(text, r'IV\. VOTES', r'V\. POLLING STATIONS')
    printed = {}
    for n, label in enumerate(('POLLED', 'VALID', 'REJECTED', 'MISSING', 'TENDERED'), 1):
        match = re.search(r'(?m)^[ \t]*' + str(n) + r'\. ' + label + r'[ \t]+(\d+)', votes)
        if match is None:
            raise ValueError(f'Explicit source value missing: {code} {label}')
        printed[label] = int(match[1])
    detail_page = row['detail_page']
    detail = pdf[detail_page - 1].get_text(sort=True)
    identity_detail = re.search(r'Constituency\s*:\s*' + str(code) + r'\s*\.\s*([^\n]+)', detail)
    if identity_detail is None or identity_detail[1].strip() != row['name']:
        raise ValueError(f'Detail identity differs: {code}')
    block = re.split(r'Constituency\s*:', detail[identity_detail.end():], maxsplit=1)[0]
    pattern = r'ELECTORS\s*:\s*(\d+)\s+VOTERS\s*:\s*(\d+).*?VALID VOTES\s*:\s*(\d+)'
    dt = re.search(pattern, block, re.S)
    pages = [detail_page]
    if dt is None:
        block = re.split(r'Constituency\s*:', detail[identity_detail.end():] + '\n' + pdf[detail_page].get_text(sort=True), maxsplit=1)[0]
        dt = re.search(pattern, block, re.S)
        pages.append(detail_page + 1)
    ranked = sorted(row['candidates'], key=lambda c: -c['votes'])
    candidate_sum = sum(c['votes'] for c in ranked)
    if (dt is None or tuple(map(int, dt.groups())) != (row['electors'], row['votes_polled'], row['valid_candidate_votes'])
            or electors != row['electors'] or general != row['votes_polled']
            or polled != general + postal or polled != printed['POLLED'] or not 0 < polled <= electors
            or candidate_sum != row['valid_candidate_votes']
            or candidate_sum + printed['REJECTED'] + printed['MISSING'] != polled
            or candidate_sum - printed['VALID'] != postal + printed['TENDERED']
            or len({(c['candidate_name'], c['party_at_election'], c['votes']) for c in ranked}) != len(ranked)):
        raise ValueError(f'Source detail/summary relationships differ: {code}')
    result = {}
    for label, key, candidate in zip(('Winner', 'Runner up'), ('winner', 'runner'), ranked[:2], strict=True):
        match = re.search(r'(?m)^[ \t]*' + label + r'\s*:\s*(\S+)[ \t]+(.+?)[ \t]+(\d+)[ \t]*$', text)
        if match is None or (match[2].strip(), match[1], int(match[3])) != (candidate['candidate_name'], candidate['party_at_election'], candidate['votes']):
            raise ValueError(f'Declaration differs: {code}')
        result.update({key: candidate['candidate_name'], key + '_party': candidate['party_at_election'], key + '_votes': candidate['votes']})
    margin = int(re.search(r'MARGIN\s*:\s*(\d+)', text)[1])
    if margin <= 0 or margin != ranked[0]['votes'] - ranked[1]['votes']:
        raise ValueError(f'Margin differs: {code}')
    result['margin'] = margin
    row['original_detail_totals'] = {k: row[k] for k in ('electors', 'votes_polled', 'valid_candidate_votes')}
    row['original_detail_totals']['candidate_sum'] = candidate_sum
    row['previous_review_note'] = row['error']
    row['original_extraction_warning'] = row['error']
    row['error'] = (f'Official summary declares the winner and margin and reports {polled:,} total voters '
                    f'({general:,} general and {postal:,} postal); the detail table reports {general:,} voters. '
                    f'Summary valid votes are {printed["VALID"]:,}; detailed candidates total {candidate_sum:,}. '
                    'Both printed totals and original candidate rows are retained; the difference remains under review.')
    row['votes_polled'] = polled
    row['valid_candidate_votes'] = printed['VALID']
    row['summary_totals'] = {'electors': electors, 'votes_polled': polled, 'valid_candidate_votes': printed['VALID']}
    row['candidate_source_discrepancy'] = {'candidate_sum': candidate_sum, 'printed_valid_votes': printed['VALID'], 'difference': candidate_sum - printed['VALID']}
    row['summary_result'] = result
    row['source_warning_code'] = 'official_summary_turnout_only'
    row['summary_page'] = page
    row['summary_source_file'] = SOURCE_FILE
    row['summary_source_sha256'] = SOURCE_SHA
    row['official_source_url'] = SOURCE_URL
    row['detail_verified_pages'] = pages
    return 'contested'


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    source = folder / SOURCE_FILE
    original = folder / 'extraction.json'
    if (source.is_symlink() or original.is_symlink() or digest(source.read_bytes()) != SOURCE_SHA):
        raise ValueError('1996 Tamil Nadu official source differs')
    prior = root / 'exports/pollmedia-ac-tamil-nadu-1996-modakurichi-summary-result-20261004.zip'
    if digest(prior.read_bytes()) != '10600d2c67b9bfaa52472a31028cdda641d49e7410427599f51b2a9b193aad5a':
        raise ValueError('Prior Tamil Nadu summary bundle differs')
    with zipfile.ZipFile(prior) as outer:
        with zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{EDITION}.zip'))) as inner:
            old_body = inner.read(f'election-archive/{EDITION}/extraction.json')
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    before = json.loads(old_body)
    after = json.loads(old_body)
    if (digest(old_body) != PREDECESSOR or manifest['url'] != SOURCE_URL
            or next(file for file in manifest['files'] if file['file'] == SOURCE_FILE)['sha256'] != SOURCE_SHA
            or before['kind'] != 'ac' or before['year'] != 1996
            or before['source_url'] != SOURCE_URL or before['source_file'] != SOURCE_FILE
            or before['source_sha256'] != SOURCE_SHA or len(before['records']) != 234
            or [row['code'] for row in before['records']] != list(range(1, 235))):
        raise ValueError('1996 Tamil Nadu extraction provenance differs')
    kinds = {'contested': [], 'uncontested': []}
    with fitz.open(source) as pdf:
        if len(pdf) != 381:
            raise ValueError('1996 Tamil Nadu PDF page coverage differs')
        for row in after['records']:
            code = row['code']
            if code == 118:
                continue
            kind = reconcile(row, code + 25, pdf[code + 24].get_text(sort=True), pdf)
            kinds[kind].append(code)
    if len(kinds['contested']) != 233 or kinds['uncontested'] != []:
        raise ValueError('1996 Tamil Nadu contest coverage differs')
    common = {'previous_review_note', 'original_extraction_warning', 'summary_page',
              'summary_source_file', 'summary_source_sha256', 'official_source_url',
              'error', 'source_warning_code', 'summary_totals', 'summary_result',
              'candidate_source_discrepancy', 'original_detail_totals', 'detail_verified_pages'}
    for old, new in zip(before['records'], after['records'], strict=True):
        if old['code'] == 118:
            if old != new:
                raise ValueError('Prior Tamil Nadu Modakurichi review changed')
            continue
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        expected = common | {k for k in ('votes_polled', 'valid_candidate_votes') if old[k] != new[k]}
        if (old['code'] != new['code'] or old['candidates'] != new['candidates'] or changed != expected):
            raise ValueError(f'Unrelated 1996 Tamil Nadu evidence changed: {old["code"]}')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{PREDECESSOR}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='ac-1996-tamil-nadu-', dir=root / 'exports') as temporary:
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
                'scope': '233 corroborated; prior record118 retained in234 1996 Tamil Nadu AC declarations',
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
