"""Package Bihar 1951 single-seat AC results verified against both official tables."""

import csv
import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_pc_1992_summary_result_bundle import normalized
from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


EDITION = '276e7b08f2a72796688eefc3'
SOURCE_FILE = EDITION + '-9191.pdf'
NAME = 'pollmedia-ac-bihar-1951-summary-reconciliation-20261003'
EXPECTED = 221
OLD_SHA256 = 'e8497fabe069f83d349a3b295b431222553d01b12daae82ebf73a062903fca60'
OLD_WARNING = ('Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.; '
               'Detailed report pages are missing, duplicated or out of order.')
NEW_WARNING = ('Candidate rows transcribed from the detailed PDF; summary totals reconcile; '
               'publication review pending.')


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def audited_codes(root: Path) -> set[int]:
    with (root / 'exports/pc-ac-display-audit-after-bdd282d.csv').open(encoding='utf-8-sig', newline='') as source:
        rows = [row for row in csv.DictReader(source)
                if row['edition_id'] == EDITION and row['issue'] == 'source_turnout_hidden']
    if len(rows) != 273 or {row['extraction_sha256'] for row in rows} != {OLD_SHA256}:
        raise ValueError('Last live Bihar 1951 turnout audit differs')
    codes = {int(row['code']) for row in rows}
    if len(codes) != len(rows):
        raise ValueError('Duplicate Bihar 1951 audit code')
    return codes


def detail_sections(pdf: fitz.Document) -> dict[int, tuple[int, str]]:
    pages = [(page + 1, pdf[page].get_text(sort=True)) for page in range(292, len(pdf))]
    joined = '\n'.join(text for _, text in pages)
    starts = []
    offset = 0
    for page, text in pages:
        starts.extend((int(match[1]), page, offset + match.start(), offset + match.end())
                      for match in re.finditer(r'Constituency\s*:\s*(\d+)\s*\.', text, re.I))
        offset += len(text) + 1
    if len(starts) != 276 or {item[0] for item in starts} != set(range(1, 277)):
        raise ValueError('Official detailed constituency coverage is incomplete')
    return {code: (page, joined[end:starts[index + 1][2] if index + 1 < len(starts) else len(joined)])
            for index, (code, page, _, end) in enumerate(starts)}


def verified_record(record: dict, summary: str, detail: str, detail_page: int) -> dict:
    code = record['code']
    heading = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*(.*?)\s+NUMBER OF SEATS\s*:\s*(\d+)',
                        summary, re.I | re.S)
    if (not heading or int(heading[1]) != code or int(heading[3]) != 1
            or normalized(heading[2]) != normalized(record['name'])
            or detail_page != record['detail_page']):
        raise ValueError('Official Bihar 1951 constituency identity differs: ' + str(code))
    patterns = (r'II\. ELECTORS\s+1\. TOTAL\s+(\d+)',
                r'III\. ELECTORS WHO VOTED\s+1\. TOTAL\s+(\d+)',
                r'IV\. VOTES\s+1\. POLLED\s+(\d+)',
                r'2\. VALID\s+(\d+)')
    matches = [re.search(pattern, summary, re.I) for pattern in patterns]
    if any(match is None for match in matches):
        raise ValueError('Official Bihar 1951 summary totals missing: ' + str(code))
    electors, voters, polled, valid = (int(match[1]) for match in matches)
    if ((electors, voters, polled, valid) !=
            (record['electors'], record['votes_polled'], record['votes_polled'], record['valid_candidate_votes'])
            or not 0 < valid <= polled <= electors):
        raise ValueError('Official Bihar 1951 summary totals differ: ' + str(code))
    detailed = re.search(r'ELECTORS\s*:\s*(\d+)\s+VOTERS\s*:\s*(\d+).*?VALID VOTES\s*:?\s*(\d+)',
                         detail, re.I | re.S)
    if not detailed or tuple(map(int, detailed.groups())) != (electors, polled, valid):
        raise ValueError('Official Bihar 1951 detailed totals differ: ' + str(code))
    candidates = record['candidates']
    if len(candidates) < 2 or sum(candidate['votes'] for candidate in candidates) != valid:
        raise ValueError('Bihar 1951 candidate votes do not reconcile: ' + str(code))
    for candidate in candidates:
        pattern = (re.escape(candidate['candidate_name']).replace(r'\ ', r'\s+')
                   + r'\s+' + re.escape(candidate['sex'])
                   + r'\s+' + re.escape(candidate['party_at_election'])
                   + r'\s+' + str(candidate['votes']) + r'(?=\s|$)')
        if not re.search(pattern, detail, re.I):
            raise ValueError('Official Bihar 1951 candidate row differs: ' + str(code))
    ranked = sorted(candidates, key=lambda candidate: candidate['votes'], reverse=True)
    if ranked[0]['votes'] <= ranked[1]['votes']:
        raise ValueError('Official Bihar 1951 winner is tied: ' + str(code))
    rows = re.findall(r'^\s*(Winner|Runner up)\s+(\S+)\s+(.+?)\s+(\d+)\s*$', summary, re.I | re.M)
    if len(rows) != 2:
        raise ValueError('Official Bihar 1951 result rows missing: ' + str(code))
    for source_row, candidate in zip(rows, ranked):
        if (source_row[1] != candidate['party_at_election']
                or normalized(source_row[2]) != normalized(candidate['candidate_name'])
                or int(source_row[3]) != candidate['votes']):
            raise ValueError('Official Bihar 1951 winner or runner differs: ' + str(code))
    margin = re.search(r'MARGIN\s*:\s*(\d+)', summary, re.I)
    if not margin or int(margin[1]) != ranked[0]['votes'] - ranked[1]['votes']:
        raise ValueError('Official Bihar 1951 margin differs: ' + str(code))
    return {'winner': ranked[0]['candidate_name'], 'winner_party': ranked[0]['party_at_election'],
            'winner_votes': ranked[0]['votes'], 'runner': ranked[1]['candidate_name'],
            'runner_party': ranked[1]['party_at_election'], 'runner_votes': ranked[1]['votes'],
            'margin': int(margin[1])}


def build(root: Path) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    old_body = (folder / 'extraction.json').read_bytes()
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    source = next(file for file in manifest['files'] if file['file'] == SOURCE_FILE)
    source_path = folder / SOURCE_FILE
    old = json.loads(old_body)
    if (digest(old_body) != OLD_SHA256 or old['kind'] != 'ac' or old['year'] != 1951
            or old['source_url'] != manifest['url'] or old['source_file'] != SOURCE_FILE
            or old['source_sha256'] != source['sha256'] or not source_path.is_file()
            or source_path.is_symlink() or digest(source_path.read_bytes()) != source['sha256']):
        raise ValueError('Official Bihar 1951 source identity or checksum differs')
    audited = audited_codes(root)
    revised = json.loads(old_body)
    results = []
    with fitz.open(source_path) as pdf:
        if len(pdf) != 340:
            raise ValueError('Official Bihar 1951 PDF page count differs')
        details = detail_sections(pdf)
        for record in revised['records']:
            code = record['code']
            if code not in audited or record['number_of_seats'] != 1 or record['error'] != OLD_WARNING:
                continue
            if (record['status'] != 'needs_review' or record.get('summary_page') is not None
                    or record.get('summary_totals') is not None or record.get('source_warning_code') is not None
                    or record.get('winner') is not None or record.get('margin') is not None):
                raise ValueError('Archived Bihar 1951 record differs: ' + str(code))
            summary_page = code + 16
            result = verified_record(record, pdf[summary_page - 1].get_text(sort=True),
                                     details[code][1], details[code][0])
            record['original_extraction_warning'] = record['error']
            record['error'] = NEW_WARNING
            record['summary_page'] = summary_page
            record['summary_totals'] = {key: record[key] for key in ('electors', 'votes_polled', 'valid_candidate_votes')}
            record['summary_result'] = result
            record['summary_source_file'] = SOURCE_FILE
            record['summary_source_sha256'] = source['sha256']
            record['detail_source_file'] = SOURCE_FILE
            record['detail_source_sha256'] = source['sha256']
            results.append({'code': code, 'name': record['name'], 'summary_page': summary_page,
                            'detail_page': details[code][0], 'winner': result['winner'], 'margin': result['margin']})
    target_codes = {row['code'] for row in results}
    if len(results) != EXPECTED or len(target_codes) != EXPECTED:
        raise ValueError('Bihar 1951 source-backed target coverage differs')
    allowed = {'original_extraction_warning', 'error', 'summary_page', 'summary_totals',
               'summary_result', 'summary_source_file', 'summary_source_sha256',
               'detail_source_file', 'detail_source_sha256'}
    for before, after in zip(old['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if before['code'] != after['code'] or changed != (allowed if before['code'] in target_codes else set()):
            raise ValueError('An unrelated Bihar 1951 election value changed: ' + str(before['code']))
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    new_sha = digest(new_body)
    with tempfile.TemporaryDirectory(prefix='ac-bihar-1951-', dir=root / 'exports') as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{OLD_SHA256}.json'
        revision = f'election-archive/{EDITION}/extraction.json'
        for relative, body in ((snapshot, old_body), (revision, new_body)):
            path = staged / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(body)
        inner = []
        for kind, relative in (('snapshot', snapshot), ('correction', revision)):
            bucket = hashlib.sha256(relative.encode('utf-8')).digest()[0] % 8
            path = packages / f'{kind}-{EDITION}.zip'
            package(staged, path, 'election-archive', bucket, 8, [relative],
                    OLD_SHA256 if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
            inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as archive:
            for path in inner:
                archive.write(path, path.name)
            archive.writestr('SHA256SUMS', ''.join(f'{digest(path.read_bytes())}  {path.name}\n' for path in inner))
            archive.writestr('ARCHIVES', EDITION + '\n')
            archive.writestr('AUDIT.json', json.dumps({
                'scope': 'Bihar 1951 single-seat AC results reconciled to official summary and detail pages',
                'edition': EDITION, 'source_url': old['source_url'], 'source_file': SOURCE_FILE,
                'source_sha256': source['sha256'], 'previous_sha256': OLD_SHA256,
                'new_sha256': new_sha, 'records_reconciled': len(results),
                'records_unchanged': len(old['records']) - len(results), 'records': results,
            }, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    output.with_suffix('.sha256').write_bytes(
        f'{digest(output.read_bytes())}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'reconciled': len(results), 'previous_sha256': OLD_SHA256,
            'new_sha256': new_sha, 'sha256': digest(output.read_bytes())}


if __name__ == '__main__':
    print(json.dumps(build(Path(__file__).resolve().parents[1])))
