"""Reconcile 97 reserved PC results with the official 1962 summary and detail."""

import csv
import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_pc_1989_summary_result_bundle import verified_summary
from build_pc_1992_summary_result_bundle import normalized
from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


EDITION = '02f72a2ef53a457f8e97e539'
NAME = 'pollmedia-pc-1962-reserved-results-20261003'
DETAIL_FILE = EDITION + '-9740.pdf'
SUMMARY_FILE = EDITION + '-9741.pdf'
TARGET_CODES = {
    2, 6, 7, 9, 20, 21, 30, 38, 43, 45, 48, 57, 58, 65, 73, 75, 79, 81, 85, 94,
    97, 105, 106, 107, 121, 125, 136, 145, 149, 153, 157, 158, 162, 164, 165,
    169, 171, 173, 183, 209, 216, 221, 231, 239, 244, 249, 253, 259, 264, 270,
    290, 294, 297, 299, 300, 302, 304, 311, 313, 315, 317, 318, 326, 328, 330,
    345, 347, 349, 351, 358, 372, 376, 379, 381, 386, 388, 396, 397, 410, 413,
    417, 422, 423, 435, 437, 444, 446, 450, 458, 459, 473, 475, 478, 481, 485,
    490, 494,
}


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def live_audit_sha(path: Path) -> str:
    with path.open(encoding='utf-8-sig', newline='') as source:
        matches = [row for row in csv.DictReader(source)
                   if row['edition_id'] == EDITION and row['issue'] == 'winner_hidden_with_candidate_votes']
    if len(matches) != 112 or len({row['extraction_sha256'] for row in matches}) != 1:
        raise ValueError('Live audit does not match the 1962 PC hidden-result scope')
    if not TARGET_CODES <= {int(row['code']) for row in matches}:
        raise ValueError('A target is not listed among the hidden 1962 PC results')
    return matches[0]['extraction_sha256']


def summary_name(text: str, record: dict) -> str:
    match = re.search(r'CONSTITUENCY\s*:\s*([^\n]+?)\s+NO\s*:\s*(\d+)', text, re.I)
    if not match or int(match[2]) != record['official_pc_code']:
        raise ValueError('Official summary PC number differs')
    name = match[1].strip()
    detail_base = re.sub(r'\s*\((?:SC|ST)\)\s*$', '', record['constituency_name'], flags=re.I)
    if (not re.search(r'\((?:SC|ST)\)$', record['constituency_name'], re.I)
            or normalized(name) != normalized(detail_base)):
        raise ValueError('The detailed and summary names differ beyond the reserved-seat suffix')
    return name


def build(root: Path) -> dict:
    exports = root / 'exports'
    output = exports / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    old_body = (folder / 'extraction.json').read_bytes()
    old_sha = digest(old_body)
    if old_sha != live_audit_sha(exports / 'pc-ac-display-audit-after-bdd282d.csv'):
        raise ValueError('Local and last live 1962 election JSON checksums differ')
    old = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    if old['kind'] != 'pc' or old['year'] != 1962 or old['source_url'] != manifest['url']:
        raise ValueError('Official 1962 election identity differs')
    files = {item['file']: item for item in manifest['files']}
    for filename in (DETAIL_FILE, SUMMARY_FILE):
        path = folder / filename
        if path.is_symlink() or digest(path.read_bytes()) != files[filename]['sha256']:
            raise ValueError('Official PDF checksum differs: ' + filename)

    revised = json.loads(old_body)
    results = []
    with fitz.open(folder / DETAIL_FILE) as detail, fitz.open(folder / SUMMARY_FILE) as summary:
        for record in revised['records']:
            if record['code'] not in TARGET_CODES:
                continue
            if (record['status'] != 'needs_review'
                    or record['error'] != 'Detailed and summary constituency names differ'
                    or record.get('winner') is not None or record.get('margin') is not None
                    or record.get('source_warning_code') is not None
                    or not 1 <= record['detail_page'] <= len(detail)
                    or not 1 <= record['summary_page'] <= len(summary)):
                raise ValueError('Archived reviewed record differs: ' + str(record['code']))
            summary_text = summary[record['summary_page'] - 1].get_text(sort=True)
            detail_text = detail[record['detail_page'] - 1].get_text(sort=True)
            name = summary_name(summary_text, record)
            result = verified_summary(summary_text, detail_text, record,
                                      (record['state_name'], record['constituency_name'],
                                       name, record['error']))
            record['original_extraction_warning'] = record['error']
            record['source_warning_code'] = 'official_pc_summary_reconciled_detail_warning'
            record['detail_candidate_count'] = len(record['candidates'])
            record['summary_result'] = result
            record['summary_source_file'] = SUMMARY_FILE
            record['summary_source_sha256'] = files[SUMMARY_FILE]['sha256']
            record['detail_source_file'] = DETAIL_FILE
            record['detail_source_sha256'] = files[DETAIL_FILE]['sha256']
            record['official_summary_constituency_name'] = name
            results.append({'code': record['code'], 'state': record['state_name'],
                            'detail_name': record['constituency_name'],
                            'summary_name': name, 'winner': result['winner'],
                            'margin': result['margin']})
    if {row['code'] for row in results} != TARGET_CODES or len(results) != 97:
        raise ValueError('The 97 source-verified 1962 PC results were not all reconciled')
    allowed = {'original_extraction_warning', 'source_warning_code', 'detail_candidate_count',
               'summary_result', 'summary_source_file', 'summary_source_sha256',
               'detail_source_file', 'detail_source_sha256', 'official_summary_constituency_name'}
    for before, after in zip(old['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if before['code'] != after['code'] or changed != (allowed if before['code'] in TARGET_CODES else set()):
            raise ValueError('An unrelated election value changed: ' + str(before['code']))
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    new_sha = digest(new_body)

    with tempfile.TemporaryDirectory(prefix='pc-1962-reserved-', dir=exports) as temporary:
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
                'scope': '97 1962 PC results with reserved-seat name suffix differences',
                'edition': EDITION, 'source_url': old['source_url'],
                'detail_file': DETAIL_FILE, 'detail_sha256': files[DETAIL_FILE]['sha256'],
                'summary_file': SUMMARY_FILE, 'summary_sha256': files[SUMMARY_FILE]['sha256'],
                'previous_sha256': old_sha, 'new_sha256': new_sha, 'records': results,
            }, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    output.with_suffix('.sha256').write_bytes(
        f'{digest(output.read_bytes())}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'records': len(results), 'previous_sha256': old_sha,
            'new_sha256': new_sha, 'sha256': digest(output.read_bytes())}


if __name__ == '__main__':
    print(json.dumps(build(Path(__file__).resolve().parents[1])))
