"""Reconcile 1951 single-seat PC results against official detailed and summary PDFs."""

import csv
import hashlib
import json
from pathlib import Path
import tempfile
import zipfile

import fitz

from build_pc_1957_single_seat_results_bundle import verified_summary
from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


EDITION = '9a57af51e71e6ff194d3f409'
NAME = 'pollmedia-pc-1951-single-seat-results-20261003'
DETAIL_FILE = EDITION + '-9734.pdf'
SUMMARY_FILE = EDITION + '-9735.pdf'
EXPECTED_HIDDEN = 175
EXPECTED_SINGLE_SEAT = 88


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def audited_codes(path: Path) -> tuple[str, set[int]]:
    with path.open(encoding='utf-8-sig', newline='') as source:
        rows = [row for row in csv.DictReader(source)
                if row['edition_id'] == EDITION and row['issue'] == 'winner_hidden_with_candidate_votes']
    if len(rows) != EXPECTED_HIDDEN or len({row['extraction_sha256'] for row in rows}) != 1:
        raise ValueError('Live audit does not match the 1951 PC hidden results')
    codes = {int(row['code']) for row in rows}
    if len(codes) != EXPECTED_HIDDEN:
        raise ValueError('Duplicate constituency code in the 1951 PC audit')
    return rows[0]['extraction_sha256'], codes


def build(root: Path) -> dict:
    exports = root / 'exports'
    output = exports / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    old_body = (folder / 'extraction.json').read_bytes()
    old_sha = digest(old_body)
    audit_sha, hidden_codes = audited_codes(exports / 'pc-ac-display-audit-after-bdd282d.csv')
    if old_sha != audit_sha:
        raise ValueError('Local and last live 1951 election JSON checksums differ')
    old = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    if (old['kind'] != 'pc' or old['year'] != 1951 or old['source_url'] != manifest['url']
            or old['source_file'] != DETAIL_FILE):
        raise ValueError('Official 1951 election identity differs')
    files = {file['file']: file for file in manifest['files']}
    if old['source_sha256'] != files[DETAIL_FILE]['sha256']:
        raise ValueError('Archived detailed source SHA differs')
    for filename in (DETAIL_FILE, SUMMARY_FILE):
        path = folder / filename
        if not path.is_file() or path.is_symlink() or digest(path.read_bytes()) != files[filename]['sha256']:
            raise ValueError('Official 1951 PDF checksum differs: ' + filename)

    selected = {record['code'] for record in old['records']
                if record['code'] in hidden_codes and record['number_of_seats'] == 1}
    if len(selected) != EXPECTED_SINGLE_SEAT:
        raise ValueError('1951 single-seat coverage changed')
    revised = json.loads(old_body)
    results = []
    with fitz.open(folder / DETAIL_FILE) as detail, fitz.open(folder / SUMMARY_FILE) as summary:
        for record in revised['records']:
            if record['code'] not in selected:
                continue
            if (record['status'] != 'needs_review'
                    or record['error'] != 'Detailed and summary constituency names differ'
                    or record.get('winner') is not None or record.get('margin') is not None
                    or not 1 <= record['detail_page'] <= len(detail)
                    or not 1 <= record['summary_page'] <= len(summary)):
                raise ValueError('Archived 1951 result differs: ' + str(record['code']))
            name, result = verified_summary(
                summary[record['summary_page'] - 1].get_text(sort=True),
                detail[record['detail_page'] - 1].get_text(sort=True), record,
                minimum_summary_prefix=13, allow_wrapped_detail_name=True)
            record['original_extraction_warning'] = record['error']
            record['source_warning_code'] = 'official_pc_summary_reconciled_detail_warning'
            record['detail_candidate_count'] = len(record['candidates'])
            record['summary_result'] = result
            record['official_summary_constituency_name'] = name
            record['summary_source_file'] = SUMMARY_FILE
            record['summary_source_sha256'] = files[SUMMARY_FILE]['sha256']
            record['detail_source_file'] = DETAIL_FILE
            record['detail_source_sha256'] = files[DETAIL_FILE]['sha256']
            results.append({'code': record['code'], 'name': record['name'],
                            'summary_name': name, 'winner': result['winner'], 'margin': result['margin']})
    if {row['code'] for row in results} != selected or len(revised['records']) != len(old['records']):
        raise ValueError('The 1951 single-seat results were not all verified')
    allowed = {'original_extraction_warning', 'source_warning_code', 'detail_candidate_count',
               'summary_result', 'official_summary_constituency_name', 'summary_source_file',
               'summary_source_sha256', 'detail_source_file', 'detail_source_sha256'}
    for before, after in zip(old['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if before['code'] != after['code'] or changed != (allowed if before['code'] in selected else set()):
            raise ValueError('An unrelated 1951 election value changed: ' + str(before['code']))
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    new_sha = digest(new_body)

    with tempfile.TemporaryDirectory(prefix='pc-1951-single-', dir=exports) as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{old_sha}.json'
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
                    old_sha if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
            inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as archive:
            for path in inner:
                archive.write(path, path.name)
            archive.writestr('SHA256SUMS', ''.join(f'{digest(path.read_bytes())}  {path.name}\n' for path in inner))
            archive.writestr('ARCHIVES', EDITION + '\n')
            archive.writestr('AUDIT.json', json.dumps({
                'scope': '1951 single-seat PC winners and margins verified from official PDFs',
                'edition': EDITION, 'source_url': old['source_url'],
                'detail_file': DETAIL_FILE, 'detail_sha256': files[DETAIL_FILE]['sha256'],
                'summary_file': SUMMARY_FILE, 'summary_sha256': files[SUMMARY_FILE]['sha256'],
                'previous_sha256': old_sha, 'new_sha256': new_sha,
                'single_seat_reconciled': len(results), 'multi_member_unchanged': EXPECTED_HIDDEN - len(results),
                'records': results,
            }, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    output.with_suffix('.sha256').write_bytes(
        f'{digest(output.read_bytes())}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'results': len(results),
            'previous_sha256': old_sha, 'new_sha256': new_sha, 'sha256': digest(output.read_bytes())}


if __name__ == '__main__':
    print(json.dumps(build(Path(__file__).resolve().parents[1])))
