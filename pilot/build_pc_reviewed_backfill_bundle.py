"""Combine four guarded PC corrections into one data-only server import."""

import hashlib
import json
from pathlib import Path
import tempfile
import zipfile

from build_pc_ac_zero_turnout_bundle import import_script


NAME = 'pollmedia-pc-reviewed-backfill-20261002'
CHILDREN = (
    'pollmedia-pc-1991-five-turnout-summaries-20261002',
    'pollmedia-pc-1989-summary-results-20261002',
    'pollmedia-pc-1984-kanakapura-result-20261002',
    'pollmedia-pc-1980-eluru-result-20261002',
)


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def build(root: Path) -> dict:
    exports = root / 'exports'
    output = exports / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    packages = {}
    editions = []
    revisions = []
    for child_name in CHILDREN:
        path = exports / (child_name + '.zip')
        expected = (exports / (child_name + '.sha256')).read_text(encoding='ascii').split()[0]
        if digest(path.read_bytes()) != expected:
            raise ValueError('Child bundle checksum differs: ' + child_name)
        with zipfile.ZipFile(path) as child:
            audit = json.loads(child.read('AUDIT.json'))
            edition = audit['edition']
            if child.read('ARCHIVES').decode('ascii').strip() != edition or edition in editions:
                raise ValueError('Child bundle edition is missing or duplicated')
            checksums = {}
            for line in child.read('SHA256SUMS').decode('ascii').splitlines():
                checksum, filename = line.split(None, 1)
                checksums[filename] = checksum
            expected_names = {f'snapshot-{edition}.zip', f'correction-{edition}.zip'}
            if set(checksums) != expected_names:
                raise ValueError('Child bundle packages differ: ' + child_name)
            for filename, checksum in checksums.items():
                body = child.read(filename)
                if digest(body) != checksum:
                    raise ValueError('Child package checksum differs: ' + filename)
                packages[filename] = body
            editions.append(edition)
            revisions.append({'name': child_name, 'edition': edition,
                              'previous_sha256': audit['previous_sha256'],
                              'new_sha256': audit['new_sha256'],
                              'scope': audit['scope']})

    with tempfile.TemporaryDirectory(prefix='pc-reviewed-backfill-', dir=exports) as temporary:
        partial = Path(temporary) / output.name
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as archive:
            for filename, body in sorted(packages.items()):
                archive.writestr(filename, body)
            archive.writestr('SHA256SUMS', ''.join(f'{digest(body)}  {filename}\n'
                                                for filename, body in sorted(packages.items())))
            archive.writestr('ARCHIVES', ''.join(edition + '\n' for edition in editions))
            archive.writestr('AUDIT.json', json.dumps({'scope': 'Four checksum-guarded PC election corrections',
                                                      'revisions': revisions}, indent=2))
            archive.writestr('IMPORT.sh', import_script(editions))
        partial.replace(output)
    output.with_suffix('.sha256').write_bytes(
        f'{digest(output.read_bytes())}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'editions': len(editions), 'packages': len(packages),
            'sha256': digest(output.read_bytes())}


if __name__ == '__main__':
    print(json.dumps(build(Path(__file__).resolve().parents[1])))
