"""Apply Champdani's verified declaration to the separately corrected live edition."""

import hashlib
import io
import json
from pathlib import Path
import tempfile
import zipfile

from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package
from rebase_election_revision import rebase


ROOT = Path(__file__).resolve().parents[1]
EDITION = '9982b63a332a67579dae045f'
NAME = 'pollmedia-ac-west-bengal-1982-champdani-resume-result-20261004'
RESUME = 'pollmedia-election-corrections-20261003-resume-v1.zip'
PROPOSED = 'pollmedia-ac-west-bengal-1982-champdani-declared-result-20261004.zip'
LIVE_SHA = '7a4d6e80ba22c67ebb9cd73dc3075197cf1de6728e69b7a1b9a429a13702ca70'
SOURCE_FILE = f'{EDITION}-7315.pdf'
SOURCE_SHA = 'd7aa7423d5d0e2c252d758df89b7b0274f2303689bfc67d44a4e64732d0d81f3'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3189-west-bengal-1982/'


def sha(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def extraction(bundle: Path, kind: str) -> bytes:
    checksum = bundle.with_suffix('.sha256').read_text(encoding='ascii').split()[0]
    if sha(bundle.read_bytes()) != checksum:
        raise ValueError('Prior election bundle checksum differs: ' + bundle.name)
    with zipfile.ZipFile(bundle) as outer:
        with zipfile.ZipFile(io.BytesIO(outer.read(f'{kind}-{EDITION}.zip'))) as inner:
            name = f'election-archive/{EDITION}/extraction.json'
            if kind == 'snapshot':
                manifest = json.loads(inner.read('manifest.json'))['files'][0]
                name = manifest['path']
            body = inner.read(name)
            manifest = json.loads(inner.read('manifest.json'))['files'][0]
            if sha(body) != manifest['sha256']:
                raise ValueError('Prior election extraction checksum differs')
            return body


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    prior = extraction(root / 'exports' / RESUME, 'correction')
    before = extraction(root / 'exports' / PROPOSED, 'snapshot')
    proposed = extraction(root / 'exports' / PROPOSED, 'correction')
    source = root / 'application/storage/app/private/election-archive' / EDITION / SOURCE_FILE
    if sha(prior) != LIVE_SHA or sha(source.read_bytes()) != SOURCE_SHA:
        raise ValueError('Observed live revision or official PDF differs')
    new_body, changes = rebase(before, proposed, prior)
    new = json.loads(new_body)
    old = json.loads(prior)
    if (changes['added_codes'] or changes['edition_fields']
            or set(changes['changed_fields']) != {'181'}
            or new['source_url'] != SOURCE_URL or new['source_file'] != SOURCE_FILE
            or new['source_sha256'] != SOURCE_SHA):
        raise ValueError('Champdani rebase changed an unexpected source field')
    for a, b in zip(old['records'], new['records']):
        if a['code'] != b['code'] or (a['code'] != 181 and a != b):
            raise ValueError('Unrelated West Bengal 1982 result changed')
    record = next(row for row in new['records'] if row['code'] == 181)
    if ((record['summary_result']['winner'], record['summary_result']['margin']) !=
            ('SAILENDRA NATH CHATTOPADHYAY', 6619)):
        raise ValueError('Champdani declaration differs')
    return prior, new_body, {'edition': EDITION, 'year': 1982, 'code': 181,
                             'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
                             'source_sha256': SOURCE_SHA, 'previous_sha256': sha(prior),
                             'new_sha256': sha(new_body), 'rebase_changes': changes,
                             'winner': 'SAILENDRA NATH CHATTOPADHYAY', 'margin': 6619}


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / f'{NAME}.zip'
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body, new_body, audit = revised_edition(root)
    with tempfile.TemporaryDirectory(prefix='ac-wb-1982-resume-', dir=output.parent) as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{audit["previous_sha256"]}.json'
        revision = f'election-archive/{EDITION}/extraction.json'
        inner = []
        for kind, relative, body in (('snapshot', snapshot, old_body), ('correction', revision, new_body)):
            target = staged / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(body)
            bucket = hashlib.sha256(relative.encode('utf-8')).digest()[0] % 8
            path = packages / f'{kind}-{EDITION}.zip'
            package(staged, path, 'election-archive', bucket, 8, [relative],
                    audit['previous_sha256'] if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
            inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as archive:
            for path in inner:
                archive.write(path, path.name)
            archive.writestr('SHA256SUMS', ''.join(f'{sha(path.read_bytes())}  {path.name}\n' for path in inner))
            archive.writestr('ARCHIVES', EDITION + '\n')
            archive.writestr('AUDIT.json', json.dumps({'scope': 'Champdani 1982 declaration rebased on observed live revision', **audit}, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    bundle_sha = sha(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{bundle_sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': bundle_sha, **audit}


if __name__ == '__main__':
    print(json.dumps(build()))
