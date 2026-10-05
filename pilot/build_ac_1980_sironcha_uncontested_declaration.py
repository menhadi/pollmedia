"""Corroborate 1980 Maharashtra AC declarations with the official report."""

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
EDITION = '9025b24a06b55158d799c648'
NAME = 'pollmedia-ac-1980-sironcha-uncontested-declaration-20261006'
PRIOR = 'pollmedia-ac-1980-maharashtra-declared-results-20261006'
PRIOR_SHA = '69b5f4d22a04162c93f856e5fb5ef3495336cdaeb547dc07349f8e7a34c65f9f'
PREDECESSOR = 'b275bd0554cfdff95b491d48e10bd1caff15173311a1bd9fc3391a26ca30c655'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3718-maharashtra-1980/'
SOURCE_FILE = f'{EDITION}-8752.pdf'
SOURCE_SHA = 'c0bf375fbeeab77b668ea51a16fd1000d4f8e0f77f3a8044a8077a8371dc4020'
PENDING = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.'
UNCONTESTED = (PENDING + '; Detailed totals are missing or use an unsupported layout.; The source reports an uncontested candidate without vote totals. Candidate rows recovered from the same archived source; earlier extraction note: ' + PENDING + '; Detailed totals are missing or use an unsupported layout.; Some candidate text could not be parsed; see the original PDF.')


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def section(text: str, start: str, end: str) -> str:
    match = re.search(start + r'\b(.*?)' + end, text, re.I | re.S)
    if match is None:
        raise ValueError(f'Official 1980 Maharashtra summary section missing: {start}')
    return match[1]


def total(text: str) -> int:
    line = re.search(r'(?m)^\s*3\. TOTAL[^\n]*$', text)
    values = re.findall(r'\d+', line[0]) if line else []
    if len(values) < 2:
        raise ValueError('Official 1980 Maharashtra summary total missing')
    return int(values[-1])


def reconcile(row: dict, page: int, text: str) -> str:
    code = row['code']
    identity = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text, re.I)
    if (identity is None or int(identity[1]) != code
            or re.sub(r'\s+', ' ', identity[2]).strip() != re.sub(r'\s+', ' ', row['name']).strip()
            or page != code + 17 or 'CONSTITUENCY DATA - SUMMARY' not in text
            or 'LEGISLATIVE ASSEMBLY OF MAHARASHTRA' not in text.upper()
            or row['state_name'] != 'Maharashtra' or row['number_of_seats'] != 1
            or row['status'] != 'needs_review' or row.get('summary_page') is not None
            or row.get('source_warning_code') is not None):
        raise ValueError(f'Official 1980 Maharashtra identity differs: {code}')
    electors = total(section(text, r'II\. ELECTORS', r'III\. ELECTORS WHO VOTED'))
    voters_section = section(text, r'III\. ELECTORS WHO VOTED', r'IV\. VOTES')
    votes_section = section(text, r'IV\. VOTES', r'V\. POLLING STATIONS')
    results = section(text, r'VII\. RESULT', r'rptConstituencySummary')
    if (code != 153 and electors != row.get('electors')) or len(row['candidates']) < 1:
        raise ValueError(f'Official 1980 Maharashtra electors differ: {code}')
    row['previous_review_note'] = row['error']
    row['original_extraction_warning'] = row['error']
    row['summary_page'] = page
    row['summary_source_file'] = SOURCE_FILE
    row['summary_source_sha256'] = SOURCE_SHA
    row['detail_source_file'] = SOURCE_FILE
    row['detail_source_sha256'] = SOURCE_SHA
    row['official_source_url'] = SOURCE_URL
    row['official_summary_constituency_name'] = identity[2].strip()
    row['official_summary_state'] = 'Maharashtra'
    if 'Uncontested' in text:
        declaration = re.search(r'Winner\s*:?\s*(\S+)\s+(.+?)\s+Returned\s+Uncontested',
                                results, re.I | re.S)
        candidate = row['candidates'][0]
        if (row['error'] != UNCONTESTED or len(row['candidates']) != 1
                or (code, electors, row['detail_page'], candidate['candidate_name']) not in [
                    (153, 94931, 331, 'SHRI TALANDI PENTA RAMA')]
                or any(key in row for key in ['electors', 'votes_polled', 'valid_candidate_votes'])
                or candidate['votes'] is not None or candidate['general_votes'] is not None
                or candidate['postal_votes'] is not None or candidate['reported_contest_status'] != 'uncontested'
                or declaration is None
                or (declaration[1], re.sub(r'\s+', ' ', declaration[2]).strip()) !=
                (candidate['party_at_election'], re.sub(r'\s+', ' ', candidate['candidate_name']).strip())
                or 'Uncontested' not in voters_section or 'Uncontested' not in votes_section
                or re.search(r'(?m)^[ \t]*3\. TOTAL[ \t]+\d+', voters_section)
                or re.search(r'(?m)^[ \t]*(1\. POLLED|2\. VALID)[ \t]+\d+', votes_section)
                or re.search(r'Runner up|MARGIN\s*:', results, re.I)):
            raise ValueError(f'Official 1980 Maharashtra uncontested result differs: {code}')
        row['error'] = (f'Official 1980 Maharashtra summary declares {candidate["candidate_name"]} '
                        f'({candidate["party_at_election"]}) returned uncontested. No voter, valid-vote '
                        'or margin total is reported. Original missing totals and null candidate votes are preserved; '
                        f'the summary reports {electors:,} electors. No turnout is inferred.')
        row['source_warning_code'] = 'official_uncontested_summary'
        row['summary_source_rows'] = [f'Winner {candidate["party_at_election"]} '
                                      f'{candidate["candidate_name"]} Returned Uncontested']
        row['summary_totals'] = {'electors': electors, 'votes_polled': None,
                                 'valid_candidate_votes': None}
        return 'uncontested'
    raise ValueError('Sironcha source does not declare an uncontested result')


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    source = folder / SOURCE_FILE
    original = folder / 'extraction.json'
    if (source.is_symlink() or original.is_symlink() or digest(source.read_bytes()) != SOURCE_SHA):
        raise ValueError('1980 Maharashtra official source differs')
    prior = root / 'exports' / (PRIOR + '.zip')
    if digest(prior.read_bytes()) != PRIOR_SHA:
        raise ValueError('Prior release checksum differs')
    with zipfile.ZipFile(prior) as release:
        with zipfile.ZipFile(io.BytesIO(release.read(f'correction-{EDITION}.zip'))) as inner:
            old_body = inner.read(f'election-archive/{EDITION}/extraction.json')
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    before = json.loads(old_body)
    after = json.loads(old_body)
    if (digest(old_body) != PREDECESSOR or manifest['url'] != SOURCE_URL
            or next(file for file in manifest['files'] if file['file'] == SOURCE_FILE)['sha256'] != SOURCE_SHA
            or before['kind'] != 'ac' or before['year'] != 1980
            or before['source_url'] != SOURCE_URL or before['source_file'] != SOURCE_FILE
            or before['source_sha256'] != SOURCE_SHA or len(before['records']) != 288
            or [row['code'] for row in before['records']] != list(range(1, 289))):
        raise ValueError('1980 Maharashtra extraction provenance differs')
    kinds = {'contested': [], 'uncontested': []}
    with fitz.open(source) as pdf:
        if len(pdf) != 352:
            raise ValueError('1980 Maharashtra PDF page coverage differs')
        for row in after['records']:
            code = row['code']
            if code != 153:
                continue
            kind = reconcile(row, code + 17, pdf[code + 16].get_text(sort=True))
            kinds[kind].append(code)
    if len(kinds['contested']) != 0 or kinds['uncontested'] != [153]:
        raise ValueError('1980 Maharashtra contest coverage differs')
    common = {'previous_review_note', 'original_extraction_warning', 'summary_page',
              'summary_source_file', 'summary_source_sha256', 'detail_source_file',
              'detail_source_sha256', 'official_source_url', 'official_summary_constituency_name',
              'official_summary_state', 'error', 'source_warning_code', 'summary_totals'}
    for old, new in zip(before['records'], after['records'], strict=True):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        expected = common | ({'summary_result'} if old['code'] in kinds['contested'] else {'summary_source_rows'})
        if old['code'] != 153:
            expected = set()
        if (old['code'] != new['code'] or old['candidates'] != new['candidates'] or changed != expected):
            raise ValueError(f'Unrelated 1980 Maharashtra evidence changed: {old["code"]}')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{PREDECESSOR}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='ac-1980-maharashtra-', dir=root / 'exports') as temporary:
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
                'scope': 'Sironcha1980 uncontested declaration; other287 records unchanged',
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
