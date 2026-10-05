"""Record the explicitly unopposed 1977 Sikkim PC member, without turnout."""

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
EDITION = 'f536c924d1bedb6f02754d1d'
NAME = 'pollmedia-pc-1977-sikkim-uncontested-result-20261005'
PREDECESSOR = '49f2c8ab8ab55e9633564a8de6e53792d77e82c1cfbb8989148203904eb9befc'
SOURCE_URL = 'https://old.eci.gov.in/files/file/4116-general-election-1977-vol-i-ii/'
DETAIL_FILE = f'{EDITION}-9749.pdf'
DETAIL_SHA = '32ea1bcc14d115ecdf173213d5f9da89ec819ea00ec4b6a8bec13b8d8a32007a'
SUMMARY_FILE = f'{EDITION}-9750.pdf'
SUMMARY_SHA = '062092409769f67f1f7b216654f05f72962cb28b218bb34f7862e5d28eee8aff'
OLD_NOTE = 'Uncontested or inconsistent elector/voter totals; Summary value missing or ambiguous: TOTAL'
NEW_NOTE = ('Official constituency summary declares CHATRA BAHADUR CHHETRI (INC) returned '
            'uncontested. Voter and valid-vote totals are unreported; archived zero fields '
            'are placeholders, not measured turnout. Original review warning retained.')


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    original = folder / 'extraction.json'
    if original.is_symlink():
        raise ValueError('1977 PC extraction is a symlink')
    old_body = original.read_bytes()
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    before = json.loads(old_body)
    after = json.loads(old_body)
    if (digest(old_body) != PREDECESSOR or manifest['url'] != SOURCE_URL
            or before['kind'] != 'pc' or before['year'] != 1977
            or before['source_url'] != SOURCE_URL or before['source_file'] != DETAIL_FILE
            or before['source_sha256'] != DETAIL_SHA or len(before['records']) != 542):
        raise ValueError('1977 PC extraction provenance differs')
    files = {item['file']: item['sha256'] for item in manifest['files']}
    for filename, expected in ((DETAIL_FILE, DETAIL_SHA), (SUMMARY_FILE, SUMMARY_SHA)):
        path = folder / filename
        if path.is_symlink() or files.get(filename) != expected or digest(path.read_bytes()) != expected:
            raise ValueError(f'1977 PC official PDF differs: {filename}')
    record = after['records'][361]
    if (record['code'] != 362 or record['official_pc_code'] != 1
            or record['state_name'] != 'SIKKIM' or record['constituency_name'] != 'SIKKIM'
            or record['number_of_seats'] != 1 or record['state_code'] != 'S21'
            or record['summary_page'] != 366 or record['detail_page'] != 174
            or record['electors'] != 124023 or record['votes_polled'] != 0
            or record['valid_candidate_votes'] != 0 or record['status'] != 'needs_review'
            or record['error'] != OLD_NOTE or record.get('source_warning_code') is not None
            or record['candidates'] != [{
                'source_row': 1, 'candidate_name': 'CHATRA BAHADUR CHHETRI',
                'party_at_election': 'INC', 'votes': 0,
                'general_votes': None, 'postal_votes': None,
            }]):
        raise ValueError('1977 Sikkim archived row differs')
    with fitz.open(folder / DETAIL_FILE) as detail, fitz.open(folder / SUMMARY_FILE) as summary:
        if len(detail) != 203 or len(summary) != 546:
            raise ValueError('1977 PC official PDF coverage differs')
        detail_text = detail[173].get_text(sort=True)
        totals_text = detail[174].get_text(sort=True)
        summary_text = summary[365].get_text(sort=True)
    if (re.search(r'Constituency\s*:\s*1\s*\.\s*SIKKIM\b', detail_text, re.I) is None
            or re.search(r'CHATRA BAHADUR CHHETRI\s+M\s+INC\s+0\s+#Num!', detail_text) is None
            or re.search(r'ELECTORS\s*:\s*124023\s+VOTERS\s*:\s*0\b', totals_text) is None
            or re.search(r'STATE/UT\s*:\s*SIKKIM\s+CODE\s*:\s*S21', summary_text) is None
            or re.search(r'CONSTITUENCY\s*:\s*SIKKIM\s+NO\s*:\s*1\b', summary_text) is None
            or re.search(r'4\. CONTESTED\s+1\s+0\s+1', summary_text) is None
            or re.search(r'3\. TOTAL\s+64682\s+59341\s+124023', summary_text) is None
            or re.search(r'Winner\s*:\s*INC\s+CHATRA BAHADUR CHHETRI\s+Returned\s+Uncontested', summary_text, re.I) is None
            or 'Uncontested' not in summary_text):
        raise ValueError('1977 Sikkim official declaration differs')
    record['previous_review_note'] = record['error']
    record['original_extraction_warning'] = record['error']
    record['error'] = NEW_NOTE
    record['source_warning_code'] = 'official_uncontested_summary'
    record['summary_source_file'] = SUMMARY_FILE
    record['summary_source_sha256'] = SUMMARY_SHA
    record['detail_source_file'] = DETAIL_FILE
    record['detail_source_sha256'] = DETAIL_SHA
    record['official_source_url'] = SOURCE_URL
    record['official_summary_state'] = 'SIKKIM'
    record['official_summary_constituency_name'] = 'SIKKIM'
    record['summary_source_rows'] = ['Winner INC CHATRA BAHADUR CHHETRI Returned Uncontested']
    record['summary_totals'] = {'electors': 124023, 'votes_polled': None,
                                'valid_candidate_votes': None}
    changed = {key for key in set(before['records'][361]) | set(record)
               if before['records'][361].get(key) != record.get(key)}
    allowed = {'previous_review_note', 'original_extraction_warning', 'error',
               'source_warning_code', 'summary_source_file', 'summary_source_sha256',
               'detail_source_file', 'detail_source_sha256', 'official_source_url',
               'official_summary_state', 'official_summary_constituency_name',
               'summary_source_rows', 'summary_totals'}
    if changed != allowed or any(before['records'][i] != after['records'][i]
                                  for i in range(542) if i != 361):
        raise ValueError('Unrelated 1977 PC evidence changed')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{PREDECESSOR}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='pc-1977-sikkim-', dir=root / 'exports') as temporary:
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
                'scope': '1977 Sikkim PC unopposed declaration; Arunachal West remains unresolved',
                'edition': EDITION, 'source_url': SOURCE_URL,
                'detail_file': DETAIL_FILE, 'detail_sha256': DETAIL_SHA,
                'summary_file': SUMMARY_FILE, 'summary_sha256': SUMMARY_SHA,
                'previous_sha256': PREDECESSOR, 'new_sha256': digest(new_body),
                'code': 362, 'winner': 'CHATRA BAHADUR CHHETRI',
                'turnout': None, 'margin': None,
            }, indent=2))
            release.writestr('IMPORT.sh', multi_seat_import_script())
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'previous_sha256': PREDECESSOR,
            'new_sha256': digest(new_body), 'winner': 'CHATRA BAHADUR CHHETRI'}


if __name__ == '__main__':
    print(json.dumps(build()))
