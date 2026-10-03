"""Package the official 1969 Chhibramau result without publishing invalid turnout."""

import hashlib
import json
from pathlib import Path
import tempfile
import zipfile

from audit_ac_1969_chhibramau_result import CODE, EDITION, ROOT, WARNING, audit
from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


NAME = 'pollmedia-ac-1969-chhibramau-declared-result-20261003'
OLD_SHA256 = '5691573f60181928b1988eb93fc9d9c4d4ec7299f8a3d2c6180e53706510219c'
NOTE = ('The official report prints more voters than electors; turnout is withheld. '
        'Its declared winner and margin are shown for review.')


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    evidence = audit(root)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    old_body = (folder / 'extraction.json').read_bytes()
    old = json.loads(old_body)
    if (digest(old_body) != OLD_SHA256 or old['source_file'] != evidence['source_file']
            or old['source_sha256'] != evidence['source_sha256']
            or old['source_url'] != evidence['source_url']):
        raise ValueError('Archived source or original extraction differs')
    revised = json.loads(old_body)
    record = next(item for item in revised['records'] if item['code'] == CODE)
    if record.get('source_warning_code') is not None or record.get('winner') is not None or record.get('margin') is not None:
        raise ValueError('Already revised or source winner unexpectedly present')
    record['original_extraction_warning'] = WARNING
    record['error'] = NOTE
    record['source_warning_code'] = 'official_ac_declared_result_invalid_turnout'
    record['official_summary_state'] = evidence['official_state']
    record['official_source_url'] = evidence['source_url']
    record['summary_source_file'] = evidence['source_file']
    record['summary_source_sha256'] = evidence['source_sha256']
    record['summary_result'] = evidence['result']
    added = {'original_extraction_warning', 'error', 'source_warning_code', 'official_summary_state',
             'official_source_url', 'summary_source_file', 'summary_source_sha256', 'summary_result'}
    for before, after in zip(old['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if before['code'] != after['code'] or changed != (added if before['code'] == CODE else set()):
            raise ValueError('Unrelated constituency evidence changed')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    return old_body, new_body, {'edition': EDITION, 'code': CODE, 'year': 1969, 'source': evidence,
                                'previous_sha256': OLD_SHA256, 'new_sha256': digest(new_body)}


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body, new_body, detail = revised_edition(root)
    with tempfile.TemporaryDirectory(prefix='ac-chhibramau-1969-', dir=output.parent) as temporary:
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
            archive.writestr('AUDIT.json', json.dumps({'scope': '1969 Uttar Pradesh AC Chhibramau declared result; turnout withheld',
                                                      **detail}, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'records': 1,
            'previous_sha256': OLD_SHA256, 'new_sha256': detail['new_sha256']}


if __name__ == '__main__':
    print(json.dumps(build()))
