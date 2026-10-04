"""Reconcile the wrapped elected-member name in the 1951 Surguja Raigarh summary."""

import hashlib
import io
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_pc_1951_multi_seat_declared_members import (
    DETAIL_FILE, DETAIL_SHA, EDITION, SOURCE_URL, SUMMARY_FILE, SUMMARY_SHA,
    declared_members, multi_seat_import_script,
)
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
NAME = 'pollmedia-pc-1951-surguja-two-declared-members-20261004'
PREVIOUS_BUNDLE = 'pollmedia-pc-1951-multi-seat-declared-members-20261004.zip'
PREVIOUS_SHA = 'd7bb07b69fbc919c5dcc2f020c38d846f68cc3cff142f401b6889a91f03d97fe'
CODE = 92
NOTE = ('Official 1951 constituency summary declares two elected members; the second name '
        'continues on the next printed line. Reported votes across seats are not ordinary '
        'one-seat turnout. Original extraction warnings and candidate rows remain for review.')


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def predecessor(root: Path) -> bytes:
    path = root / 'exports' / PREVIOUS_BUNDLE
    expected = path.with_suffix('.sha256').read_text(encoding='ascii').split()[0]
    if digest(path.read_bytes()) != expected:
        raise ValueError('Earlier 1951 multi-seat release checksum differs')
    with zipfile.ZipFile(path) as outer:
        inner = outer.read(f'correction-{EDITION}.zip')
    with zipfile.ZipFile(io.BytesIO(inner)) as correction:
        body = correction.read(f'election-archive/{EDITION}/extraction.json')
    if digest(body) != PREVIOUS_SHA:
        raise ValueError('Earlier 1951 extraction checksum differs')
    return body


def joined_wrapped_declaration(source: str) -> str:
    wrapped = re.search(
        r'^\s*Winner 2\s+IND\s+MAHARAJKUMAR CHANDIKESHWAR SHARAN\s+101178\s*\n'
        r'\s+SINGH JU DEO\s*$', source, re.I | re.M)
    if wrapped is None or len(re.findall(r'^\s*Winner 2\b', source, re.I | re.M)) != 1:
        raise ValueError('Official 1951 Surguja wrapped declaration differs')
    joined = 'Winner 2 IND MAHARAJKUMAR CHANDIKESHWAR SHARAN SINGH JU DEO 101178'
    return source[:wrapped.start()] + '\n' + joined + '\n' + source[wrapped.end():]


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body = predecessor(root)
    before = json.loads(old_body)
    after = json.loads(old_body)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    files = {file['file']: file for file in manifest['files']}
    if (before['kind'] != 'pc' or before['year'] != 1951 or len(before['records']) != 401
            or before['source_url'] != SOURCE_URL or manifest['url'] != SOURCE_URL
            or before['source_file'] != DETAIL_FILE or before['source_sha256'] != DETAIL_SHA
            or files[DETAIL_FILE]['sha256'] != DETAIL_SHA
            or files[SUMMARY_FILE]['sha256'] != SUMMARY_SHA):
        raise ValueError('Official 1951 Vol I/II identity differs')
    for filename, expected in ((DETAIL_FILE, DETAIL_SHA), (SUMMARY_FILE, SUMMARY_SHA)):
        path = folder / filename
        if path.is_symlink() or digest(path.read_bytes()) != expected:
            raise ValueError('Official 1951 PDF checksum differs: ' + filename)
    record = next(row for row in after['records'] if row['code'] == CODE)
    if (record['status'] != 'needs_review' or record['number_of_seats'] != 2
            or record['summary_page'] != 96
            or record.get('source_warning_code') is not None
            or record.get('official_multi_seat_winners') is not None
            or record['error'] != 'Multi-member constituency: individual winners require review; '
                                  'Summary label missing or ambiguous: Winner'):
        raise ValueError('Live 1951 Surguja review state differs')
    with fitz.open(folder / SUMMARY_FILE) as summary:
        source = summary[record['summary_page'] - 1].get_text(sort=True)
    name, totals, winners = declared_members(joined_wrapped_declaration(source), record)
    if ([(winner['name'], winner['party'], winner['votes']) for winner in winners]
            != [('BABUNATH SINGH', 'INC', 148487),
                ('MAHARAJKUMAR CHANDIKESHWAR SHARAN SINGH JU DEO', 'IND', 101178)]):
        raise ValueError('Official 1951 Surguja member list differs')
    record['previous_review_note'] = record['error']
    record['original_extraction_warning'] = record['error']
    record['error'] = NOTE
    record['source_warning_code'] = 'official_multi_seat_summary'
    record['summary_totals'] = totals
    record['summary_source_file'] = SUMMARY_FILE
    record['summary_source_sha256'] = SUMMARY_SHA
    record['detail_source_file'] = DETAIL_FILE
    record['detail_source_sha256'] = DETAIL_SHA
    record['official_summary_constituency_name'] = name
    record['official_multi_seat_winners'] = winners
    allowed = {'previous_review_note', 'original_extraction_warning', 'error', 'source_warning_code',
               'summary_totals', 'summary_source_file', 'summary_source_sha256', 'detail_source_file',
               'detail_source_sha256', 'official_summary_constituency_name', 'official_multi_seat_winners'}
    for old, new in zip(before['records'], after['records'], strict=True):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        if old['code'] != new['code'] or changed != (allowed if old['code'] == CODE else set()):
            raise ValueError('Unrelated 1951 extraction evidence changed: ' + str(old['code']))
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    with tempfile.TemporaryDirectory(prefix='pc-1951-surguja-', dir=root / 'exports') as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{PREVIOUS_SHA}.json'
        revision = f'election-archive/{EDITION}/extraction.json'
        inner = []
        for kind, relative, body in (('snapshot', snapshot, old_body), ('correction', revision, new_body)):
            file = staged / relative
            file.parent.mkdir(parents=True, exist_ok=True)
            file.write_bytes(body)
            bucket = hashlib.sha256(relative.encode()).digest()[0] % 8
            archive = packages / f'{kind}-{EDITION}.zip'
            package(staged, archive, 'election-archive', bucket, 8, [relative],
                    PREVIOUS_SHA if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
            inner.append(archive)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as bundle:
            for archive in inner:
                bundle.write(archive, archive.name)
            bundle.writestr('SHA256SUMS', ''.join(f'{digest(archive.read_bytes())}  {archive.name}\n'
                                               for archive in inner))
            bundle.writestr('ARCHIVES', EDITION + '\n')
            bundle.writestr('AUDIT.json', json.dumps({
                'scope': 'Two source-declared 1951 Surguja Raigarh elected members; no ordinary turnout',
                'edition': EDITION, 'source_url': SOURCE_URL,
                'detail_file': DETAIL_FILE, 'detail_sha256': DETAIL_SHA,
                'summary_file': SUMMARY_FILE, 'summary_sha256': SUMMARY_SHA,
                'previous_sha256': PREVIOUS_SHA, 'new_sha256': digest(new_body),
                'result': {'code': CODE, 'summary_page': 96, 'winners': winners},
                'unresolved_codes': [277],
            }, indent=2))
            bundle.writestr('IMPORT.sh', multi_seat_import_script())
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'previous_sha256': PREVIOUS_SHA,
            'new_sha256': digest(new_body), 'declared_members': len(winners)}


if __name__ == '__main__':
    print(json.dumps(build()))
