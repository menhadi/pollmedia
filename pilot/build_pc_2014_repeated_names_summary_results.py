"""Show four 2014 PC results confirmed by the ECI constituency summary workbook."""

import hashlib
import json
from pathlib import Path
import tempfile
import zipfile

import openpyxl

from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


EDITION = '3a136496a89deb7c38ecfe18'
OLD_SHA256 = 'bcc2d3427bafe070893f8894dd0230e43b0cd10a57b125ebf4ed2e05d40f256d'
SUMMARY_FILE = '51512f85716a7b6349923689-6469.xlsx'
SUMMARY_SHA256 = '478e81954bfaf6f42b303ed015081cf4ffaf2469de864f76c7d93a3536a0eeb2'
NAME = 'pollmedia-pc-2014-repeated-names-official-results-20261003'
NOTE = ('Some separate candidate rows share a name. Candidate votes, including NOTA, reconcile with '
        'the official summary, which confirms turnout, winner and margin; candidate identities remain under review.')
TARGETS = {323: ('S22', 7), 508: ('U05', 6), 537: ('S26', 5), 541: ('S26', 9)}


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def revised_edition(root: Path) -> tuple[bytes, bytes, dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    old_body = (folder / 'extraction.json').read_bytes()
    old = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    original_files = [item for item in manifest['files'] if item['file'] == old['source_file']]
    summary_files = [item for item in manifest['files'] if item['file'] == SUMMARY_FILE]
    original_path = folder / old['source_file']
    workbook_path = folder / SUMMARY_FILE
    if (digest(old_body) != OLD_SHA256 or old['kind'] != 'pc' or old['year'] != 2014
            or old['source_url'] != manifest['url'] or len(old['records']) != 543
            or len(original_files) != 1 or len(summary_files) != 1
            or old['source_sha256'] != original_files[0]['sha256']
            or summary_files[0]['sha256'] != SUMMARY_SHA256
            or not original_path.is_file() or original_path.is_symlink()
            or not workbook_path.is_file() or workbook_path.is_symlink()
            or digest(original_path.read_bytes()) != old['source_sha256']
            or digest(workbook_path.read_bytes()) != SUMMARY_SHA256
            or summary_files[0]['source_page'] != 'https://old.eci.gov.in/files/file/2787-constituency-data-summary/'):
        raise ValueError('Official 2014 workbook identity or checksum differs')
    revised = json.loads(old_body)
    details = []
    workbook = openpyxl.load_workbook(workbook_path, read_only=True, data_only=True)
    try:
        for record in revised['records']:
            code = record['code']
            if code not in TARGETS:
                continue
            state_code, pc_code = TARGETS[code]
            sheet_name = record['summary_locator'].split(': ', 1)[0]
            if (record['status'] != 'needs_review' or record['error'] != 'Duplicate candidate identities require review'
                    or record['number_of_seats'] != 1 or record['state_code'] != state_code
                    or record['official_pc_code'] != pc_code or sheet_name not in workbook.sheetnames
                    or record.get('summary_result') is not None or record.get('summary_result_source_file') is not None
                    or record.get('source_warning_code') is not None or record.get('winner') is not None
                    or record.get('margin') is not None):
                raise ValueError('Archived 2014 PC target identity differs: ' + str(code))
            sheet = workbook[sheet_name]
            heading = str(sheet['D2'].value or '')
            candidate_count = sheet['J7'].value
            electors, polled, valid, nota = (sheet[cell].value for cell in ('J13', 'J19', 'J29', 'J31'))
            candidates = record['candidates']
            nota_rows = [candidate for candidate in candidates if candidate.get('is_nota') is True]
            source_rows = [candidate.get('source_row') for candidate in candidates]
            if (not heading.strip().casefold().startswith(record['constituency_name'].strip().casefold())
                    or not heading.strip().endswith('-' + str(pc_code))
                    or record['summary_locator'] != sheet_name + ': J7, J13, J19, J29, J31, H40/H41, D42'
                    or (electors, polled, valid) != (record['electors'], record['votes_polled'], record['valid_candidate_votes'])
                    or not isinstance(nota, int) or nota <= 0 or len(nota_rows) != 1 or nota_rows[0]['votes'] != nota
                    or candidate_count != len(candidates) - 1 or len(source_rows) != len(set(source_rows))
                    or any(not isinstance(row, int) or row <= 0 for row in source_rows)
                    or sum(candidate['votes'] for candidate in candidates) != valid + nota):
                raise ValueError('Official 2014 PC summary or candidate reconciliation differs: ' + str(code))
            if (sheet['B40'].value != 'Winner' or sheet['B41'].value != 'Runner-Up' or sheet['B42'].value != 'Margin'):
                raise ValueError('Official 2014 result labels differ: ' + str(code))
            result = {'winner': str(sheet['F40'].value).strip(), 'winner_party': str(sheet['D40'].value).strip(),
                      'winner_votes': sheet['H40'].value, 'runner': str(sheet['F41'].value).strip(),
                      'runner_party': str(sheet['D41'].value).strip(), 'runner_votes': sheet['H41'].value,
                      'margin': sheet['D42'].value}
            ranked = sorted((candidate for candidate in candidates if not candidate.get('is_nota')),
                            key=lambda candidate: candidate['votes'], reverse=True)
            if (not all(isinstance(result[field], int) and result[field] > 0 for field in ('winner_votes', 'runner_votes', 'margin'))
                    or result['winner_votes'] - result['runner_votes'] != result['margin']
                    or result['winner_votes'] > valid or len(ranked) < 2 or ranked[0]['votes'] <= ranked[1]['votes']
                    or any((ranked[index]['candidate_name'].strip().casefold(), ranked[index]['party_at_election'].strip(), ranked[index]['votes'])
                           != (result[label].casefold(), result[label + '_party'], result[label + '_votes'])
                           for index, label in enumerate(('winner', 'runner')))):
                raise ValueError('Official 2014 winner or margin differs: ' + str(code))
            record['original_extraction_warning'] = record['error']
            record['error'] = NOTE
            record['summary_totals']['nota_votes'] = nota
            record['summary_candidate_count'] = candidate_count
            record['summary_result'] = result
            record['summary_result_source_sheet'] = sheet_name
            record['summary_result_source_file'] = SUMMARY_FILE
            record['summary_result_source_sha256'] = SUMMARY_SHA256
            record['summary_result_source_url'] = summary_files[0]['source_page']
            details.append({'code': code, 'state_code': state_code, 'official_pc_code': pc_code,
                            'sheet': sheet_name, 'winner': result['winner'], 'margin': result['margin'],
                            'candidates': candidate_count, 'nota_votes': nota})
    finally:
        workbook.close()
    if {item['code'] for item in details} != set(TARGETS):
        raise ValueError('Four 2014 PC targets were not reconciled')
    expected_fields = {'original_extraction_warning', 'error', 'summary_totals', 'summary_candidate_count',
                       'summary_result', 'summary_result_source_sheet', 'summary_result_source_file',
                       'summary_result_source_sha256', 'summary_result_source_url'}
    for before, after in zip(old['records'], revised['records']):
        changed = {field for field in set(before) | set(after) if before.get(field) != after.get(field)}
        if before['code'] != after['code'] or changed != (expected_fields if before['code'] in TARGETS else set()):
            raise ValueError('Unrelated 2014 constituency evidence changed: ' + str(before['code']))
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    return old_body, new_body, {'edition': EDITION, 'previous_sha256': OLD_SHA256,
                                'new_sha256': digest(new_body), 'source_url': old['source_url'],
                                'summary_source_url': summary_files[0]['source_page'],
                                'summary_source_file': SUMMARY_FILE, 'summary_source_sha256': SUMMARY_SHA256,
                                'records_reconciled': details}


def build(root: Path) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body, new_body, detail = revised_edition(root)
    with tempfile.TemporaryDirectory(prefix='pc-2014-repeated-', dir=root / 'exports') as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{OLD_SHA256}.json'
        revision = f'election-archive/{EDITION}/extraction.json'
        inner = []
        for kind, relative, body in (('snapshot', snapshot, old_body), ('correction', revision, new_body)):
            target = staged / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(body)
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
            archive.writestr('AUDIT.json', json.dumps({'scope': 'Four 2014 PC repeated-name results from the official constituency summary',
                                                      **detail}, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    output.with_suffix('.sha256').write_bytes(f'{digest(output.read_bytes())}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': digest(output.read_bytes()), 'records': 4}


if __name__ == '__main__':
    print(json.dumps(build(Path(__file__).resolve().parents[1])))
