"""Publish 17 official 1967 PC turnout totals including recorded missing votes."""

import csv
import hashlib
import io
import json
from pathlib import Path
import tempfile
import zipfile

import fitz

from build_pc_1962_remaining_bundle import detailed_section, official_turnout
from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


EDITION = 'd7365c7939cfc38b09eb9581'
NAME = 'pollmedia-pc-1967-summary-missing-votes-20261003'
PREVIOUS_BUNDLE = 'pollmedia-pc-ac-detailed-source-turnout-corrections-20261001-v2'
PREVIOUS_SHA256 = '72a85c74410159bafc94c267b59e22e16330e303fb1dfddd7e64e4e75cf4a7da'
DETAIL_FILE = EDITION + '-9743.pdf'
SUMMARY_FILE = EDITION + '-9744.pdf'
TARGET_CODES = {114, 116, 117, 123, 124, 125, 126, 128, 129,
                247, 250, 253, 254, 260, 293, 319, 320}


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def previous_body(exports: Path) -> bytes:
    path = exports / (PREVIOUS_BUNDLE + '.zip')
    expected = (exports / (PREVIOUS_BUNDLE + '.sha256')).read_text(encoding='ascii').split()[0]
    if digest(path.read_bytes()) != expected:
        raise ValueError('Previously imported 1967 bundle checksum differs')
    with zipfile.ZipFile(path) as outer:
        audit = json.loads(outer.read('AUDIT.json'))
        editions = [row for row in audit['editions'] if row['edition'] == EDITION]
        if len(editions) != 1 or editions[0]['new_sha256'] != PREVIOUS_SHA256:
            raise ValueError('Previously imported 1967 edition identity differs')
        inner_name = 'correction-' + EDITION + '.zip'
        checksums = {name: checksum for checksum, name in
                     (line.split(None, 1) for line in outer.read('SHA256SUMS').decode('ascii').splitlines())}
        archive = outer.read(inner_name)
        if digest(archive) != checksums[inner_name]:
            raise ValueError('Previously imported 1967 inner package checksum differs')
        with zipfile.ZipFile(io.BytesIO(archive)) as inner:
            body = inner.read(f'election-archive/{EDITION}/extraction.json')
    if digest(body) != PREVIOUS_SHA256:
        raise ValueError('Previously imported 1967 JSON checksum differs')
    return body


def audit_codes(path: Path) -> None:
    with path.open(encoding='utf-8-sig', newline='') as source:
        rows = [row for row in csv.DictReader(source)
                if row['edition_id'] == EDITION and row['issue'] == 'source_turnout_hidden']
    if ({int(row['code']) for row in rows} != TARGET_CODES or len(rows) != 17
            or len({row['extraction_sha256'] for row in rows}) != 1
            or rows[0]['extraction_sha256'] != PREVIOUS_SHA256):
        raise ValueError('Live audit does not match the 17 hidden 1967 PC turnout rows')


def build(root: Path) -> dict:
    exports = root / 'exports'
    output = exports / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    audit_codes(exports / 'pc-ac-display-audit-after-bdd282d.csv')
    old_body = previous_body(exports)
    old = json.loads(old_body)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    if old['kind'] != 'pc' or old['year'] != 1967 or old['source_url'] != manifest['url']:
        raise ValueError('Official 1967 election identity differs')
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
            if (record['status'] != 'needs_review' or record['error'] != 'Detailed and summary votes polled differ'
                    or record.get('source_warning_code') is not None
                    or not 1 <= record['summary_page'] <= len(summary)):
                raise ValueError('Archived 1967 reviewed row differs: ' + str(record['code']))
            totals = official_turnout(summary[record['summary_page'] - 1].get_text(sort=True),
                                      detailed_section(detail, record), record)
            record['original_extraction_warning'] = record['error']
            record['detail_votes_polled'] = totals['detail_votes_polled']
            record['votes_polled'] = totals['summary_votes_polled']
            record['source_discrepancy'] = {'field': 'votes_polled', **totals}
            record['summary_source_file'] = SUMMARY_FILE
            record['summary_source_sha256'] = files[SUMMARY_FILE]['sha256']
            record['detail_source_file'] = DETAIL_FILE
            record['detail_source_sha256'] = files[DETAIL_FILE]['sha256']
            record['error'] = ('The official summary includes ' + str(totals['summary_missing_votes'])
                               + ' missing votes in turnout; the detailed report excludes them. '
                               'Turnout uses the summary total. See both official pages.')
            results.append({'code': record['code'], 'name': record['name'], **totals})
    if {row['code'] for row in results} != TARGET_CODES or len(results) != 17:
        raise ValueError('The 17 source-verified 1967 turnout rows differ')
    allowed = {'original_extraction_warning', 'detail_votes_polled', 'votes_polled',
               'source_discrepancy', 'summary_source_file', 'summary_source_sha256',
               'detail_source_file', 'detail_source_sha256', 'error'}
    for before, after in zip(old['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if before['code'] != after['code'] or changed != (allowed if before['code'] in TARGET_CODES else set()):
            raise ValueError('An unrelated 1967 election value changed: ' + str(before['code']))
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    new_sha = digest(new_body)

    with tempfile.TemporaryDirectory(prefix='pc-1967-missing-votes-', dir=exports) as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{PREVIOUS_SHA256}.json'
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
                    PREVIOUS_SHA256 if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
            inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as archive:
            for path in inner:
                archive.write(path, path.name)
            archive.writestr('SHA256SUMS', ''.join(f'{digest(path.read_bytes())}  {path.name}\n' for path in inner))
            archive.writestr('ARCHIVES', EDITION + '\n')
            archive.writestr('AUDIT.json', json.dumps({
                'scope': '17 1967 PC turnout rows reconciled through official missing-vote totals',
                'edition': EDITION, 'source_url': old['source_url'],
                'detail_file': DETAIL_FILE, 'detail_sha256': files[DETAIL_FILE]['sha256'],
                'summary_file': SUMMARY_FILE, 'summary_sha256': files[SUMMARY_FILE]['sha256'],
                'previous_sha256': PREVIOUS_SHA256, 'new_sha256': new_sha, 'records': results,
            }, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    output.with_suffix('.sha256').write_bytes(
        f'{digest(output.read_bytes())}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'records': len(results), 'previous_sha256': PREVIOUS_SHA256,
            'new_sha256': new_sha, 'sha256': digest(output.read_bytes())}


if __name__ == '__main__':
    print(json.dumps(build(Path(__file__).resolve().parents[1])))
