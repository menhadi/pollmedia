"""Corroborate 1962 Andhra Pradesh AC declarations from the official ECI report."""

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
EDITION = '67cbc63183f169509782123b'
NAME = 'pollmedia-ac-1962-andhra-pradesh-six-uncontested-results-20261005'
PREDECESSOR = '4f7a9b5cebbd7bcb5caaef1dd2d1a6b4b853c1f9e0d98c887fab57948c9028bb'
PRIOR_RELEASE = 'pollmedia-ac-1962-andhra-pradesh-runner-name-review-20261005'
PRIOR_RELEASE_SHA = '893ce3e89f56e4d64ca77edadf621c0f8b348cb20e6c424b828c0483b42c6e4b'
SOURCE_URL = 'https://old.eci.gov.in/files/file/4044-andhra-pradesh-1962/'
SOURCE_FILE = f'{EDITION}-9592.pdf'
SOURCE_SHA = '41651d1c51c279be933521c7fc16f36a95b614adb0260144335f73ca72aac375'
PREVIOUS_ERROR = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.'
UNCONTESTED_ERROR = PREVIOUS_ERROR + '; Reported elector and voter totals are inconsistent.'
DECLARATIONS = {
    19: ('PONDURU (SC)', 53070, 'KOTAPALLI PUNNAIAH'),
    179: ('ALLUR', 54199, 'D. LAKSHMIKANTHA REDDY'),
    190: ('KOILKUNTLA', 63463, 'B. V. SUBBA REDDY'),
    199: ('GADWAL', 71587, 'KRISHNA RAM BHOPAL'),
    226: ('VICARABAD (SC)', 62007, 'A. RAMASWAMY'),
    244: ('ARMOOR', 65009, 'T. RANGA REDDY'),
}
TARGET_CODES = set(DECLARATIONS)


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def norm(name: str) -> str:
    return re.sub(r'[^a-z0-9]', '', name.casefold())


def section(text: str, start: str, end: str) -> str:
    match = re.search(start + r'\b(.*?)' + end, text, re.I | re.S)
    if match is None:
        raise ValueError(f'Official 1962 Andhra Pradesh summary section missing: {start}')
    return match[1]


def total(text: str, ordinal: int = 3) -> int:
    line = re.search(r'(?m)^[ \t]*' + str(ordinal) + r'\. TOTAL[^\n]*$', text, re.I)
    values = re.findall(r'\d+', line[0]) if line else []
    if len(values) < 2:
        raise ValueError('Official 1962 Andhra Pradesh summary total missing')
    return int(values[-1])


def summary_pages(pdf: fitz.Document) -> dict[int, tuple[int, str, str]]:
    pages = {}
    for index in range(18, 318):
        text = pdf[index].get_text(sort=True)
        identity = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text, re.I)
        if ('CONSTITUENCY DATA - SUMMARY' not in text
                or 'LEGISLATIVE ASSEMBLY OF ANDHRA PRADESH' not in text.upper()
                or identity is None or int(identity[1]) in pages):
            raise ValueError(f'Official 1962 Andhra Pradesh summary identity differs: {index + 1}')
        pages[int(identity[1])] = (index + 1, text, identity[2].strip())
    if set(pages) != set(range(1, 301)):
        raise ValueError('Official 1962 Andhra Pradesh 300-summary coverage differs')
    return pages


def reconcile(record: dict, page: int, text: str, source_name: str) -> str:
    code = record['code']
    name, declared_electors, declared_winner = DECLARATIONS[code]
    if (page != code + 18 or record['name'] != name
            or record['number_of_seats'] != 1 or record['state_name'] != 'Andhra Pradesh'
            or record['status'] != 'needs_review' or record.get('summary_page') is not None
            or record.get('source_warning_code') is not None or norm(source_name) != norm(record['name'])):
        raise ValueError(f'1962 Andhra Pradesh one-seat identity differs: {code}')
    electors = total(section(text, r'II\. ELECTORS', r'III\. ELECTORS WHO VOTED'))
    if electors != declared_electors or electors != record['electors']:
        raise ValueError(f'1962 Andhra Pradesh electors differ: {code}')
    voter_section = section(text, r'III\. ELECTORS WHO VOTED', r'IV\. VOTES')
    vote_section = section(text, r'IV\. VOTES', r'V\. POLLING STATIONS')
    result_section = section(text, r'VII\. RESULT', r'rptConstituencySummary')
    declaration = re.search(r'Winner\s*:\s*(\S+)\s+(.+?)\s+Returned\s+Uncontested',
                            result_section, re.I | re.S)
    candidate = record['candidates'][0] if len(record['candidates']) == 1 else None
    if (record['error'] != UNCONTESTED_ERROR or candidate is None
            or record['votes_polled'] != 0 or record['valid_candidate_votes'] != 0
            or candidate['votes'] != 0 or declaration is None
            or (declaration[1], declaration[2].strip()) != ('INC', declared_winner)
            or (candidate['party_at_election'], candidate['candidate_name']) != ('INC', declared_winner)
            or 'Uncontested' not in voter_section or 'Uncontested' not in vote_section
            or re.search(r'(?m)^[ \t]*3\. TOTAL[ \t]+\d+', voter_section)
            or re.search(r'(?m)^[ \t]*(1\. POLLED|2\. VALID)[ \t]+\d+', vote_section)
            or re.search(r'Runner up|MARGIN[ \t]*:', result_section, re.I)):
        raise ValueError(f'1962 Andhra Pradesh uncontested declaration differs: {code}')
    record['previous_review_note'] = record['error']
    record['original_extraction_warning'] = record['error']
    record['summary_page'] = page
    record['summary_source_file'] = SOURCE_FILE
    record['summary_source_sha256'] = SOURCE_SHA
    record['detail_source_file'] = SOURCE_FILE
    record['detail_source_sha256'] = SOURCE_SHA
    record['official_source_url'] = SOURCE_URL
    record['official_summary_constituency_name'] = source_name
    record['official_summary_state'] = 'Andhra Pradesh'
    record['error'] = (f'Official 1962 Andhra Pradesh summary declares {declared_winner} (INC) returned '
                       'uncontested. No voter, valid-vote or margin total is reported; archived zero '
                       'candidate/voter fields are extraction placeholders, not measured turnout.')
    record['source_warning_code'] = 'official_uncontested_summary'
    record['summary_source_rows'] = [f'Winner INC {declared_winner} Returned Uncontested']
    record['summary_totals'] = {'electors': electors, 'votes_polled': None,
                                'valid_candidate_votes': None}
    return 'uncontested'


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    source = folder / SOURCE_FILE
    prior_release = root / 'exports' / (PRIOR_RELEASE + '.zip')
    if (prior_release.is_symlink() or digest(prior_release.read_bytes()) != PRIOR_RELEASE_SHA
            or source.is_symlink() or digest(source.read_bytes()) != SOURCE_SHA):
        raise ValueError('1962 Andhra Pradesh queued predecessor or source PDF differs')
    with zipfile.ZipFile(prior_release) as release:
        with zipfile.ZipFile(io.BytesIO(release.read(f'correction-{EDITION}.zip'))) as prior:
            old_body = prior.read(f'election-archive/{EDITION}/extraction.json')
    if digest(old_body) != PREDECESSOR:
        raise ValueError('1962 Andhra Pradesh queued extraction bytes differ')
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    before = json.loads(old_body)
    after = json.loads(old_body)
    if (manifest['url'] != SOURCE_URL
            or next(file for file in manifest['files'] if file['file'] == SOURCE_FILE)['sha256'] != SOURCE_SHA
            or before['kind'] != 'ac' or before['year'] != 1962
            or before['source_url'] != SOURCE_URL or before['source_file'] != SOURCE_FILE
            or before['source_sha256'] != SOURCE_SHA or len(before['records']) != 300):
        raise ValueError('1962 Andhra Pradesh archive provenance differs')
    seen = []
    with fitz.open(source) as pdf:
        if len(pdf) != 357:
            raise ValueError('Official 1962 Andhra Pradesh PDF page count differs')
        pages = summary_pages(pdf)
        for record in after['records']:
            code = record['code']
            if code not in TARGET_CODES:
                continue
            page, text, source_name = pages[code]
            if reconcile(record, page, text, source_name) != 'uncontested':
                raise ValueError(f'1962 Andhra Pradesh result kind differs: {code}')
            seen.append({'code': code, 'summary_page': page})
    if TARGET_CODES != {19, 179, 190, 199, 226, 244} or seen != [
            {'code': code, 'summary_page': code + 18} for code in sorted(TARGET_CODES)]:
        raise ValueError('1962 Andhra Pradesh one-seat coverage differs')
    common = {'previous_review_note', 'original_extraction_warning', 'summary_page',
              'summary_source_file', 'summary_source_sha256', 'detail_source_file',
              'detail_source_sha256', 'official_source_url',
              'official_summary_constituency_name', 'official_summary_state',
              'error', 'source_warning_code', 'summary_source_rows', 'summary_totals'}
    for old, new in zip(before['records'], after['records'], strict=True):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        expected = common
        if (old['code'] != new['code'] or old['candidates'] != new['candidates']
                or changed != (expected if old['code'] in TARGET_CODES else set())):
            raise ValueError(f'Unrelated 1962 Andhra Pradesh evidence changed: {old["code"]}')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{PREDECESSOR}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='ac-1962-andhra-pradesh-', dir=root / 'exports') as temporary:
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
                'scope': 'six officially returned uncontested 1962 Andhra Pradesh AC declarations',
                'edition': EDITION, 'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
                'source_sha256': SOURCE_SHA, 'previous_sha256': PREDECESSOR,
                'new_sha256': digest(new_body), 'results': seen,
                'uncontested_codes': sorted(TARGET_CODES),
            }, indent=2))
            release.writestr('IMPORT.sh', multi_seat_import_script())
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'previous_sha256': PREDECESSOR,
            'new_sha256': digest(new_body), 'uncontested': len(TARGET_CODES)}


if __name__ == '__main__':
    print(json.dumps(build()))
