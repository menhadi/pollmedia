"""Rebase west_bengal 1982 declarations while preserving prior reviewed records."""
import hashlib
import io
import json
from pathlib import Path
import tempfile
import zipfile
from preserve_archive_json import package
from build_pc_1951_multi_seat_declared_members import multi_seat_import_script

ROOT = Path(__file__).resolve().parents[1]
EDITION = '9982b63a332a67579dae045f'
NAME = 'pollmedia-ac-1982-west-bengal-live-rebase-20261007'
OLD = '2988dcbecd70101d3597e17948e9e65ebcbfbe46bb7379d4d183482d05a9e376'
TARGET = '998dda7c4f50606c54e46901dc91f3d671823d530cc6bb37f75f93da36bb3ecc'
def sha(body):
    return hashlib.sha256(body).hexdigest()
def read_release(name, expected):
    with zipfile.ZipFile(ROOT / 'exports' / (name + '.zip')) as outer:
        with zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{EDITION}.zip'))) as inner:
            body = inner.read(f'election-archive/{EDITION}/extraction.json')
    if sha(body) != expected:
        raise ValueError('Correction input checksum differs')
    return body

def build():
    old = read_release('pollmedia-ac-west-bengal-1982-champdani-resume-result-20261004', OLD)
    target = read_release('pollmedia-ac-1982-west-bengal-reviewed-results-20261006', TARGET)
    before, after = json.loads(old), json.loads(target)
    if len(before['records']) != 294 or len(after['records']) != 294:
        raise ValueError('Coverage differs')
    retained = [181, 252]
    for index, (prior, row) in enumerate(zip(before['records'], after['records'], strict=True)):
        if prior['code'] != row['code'] or prior['candidates'] != row['candidates']:
            raise ValueError('Candidate evidence differs')
        if prior['code'] in retained:
            after['records'][index] = prior
        else:
            for key, value in prior.items():
                if key not in row:
                    row[key] = value
    for key in ('kind', 'year', 'source_url', 'source_file', 'source_sha256'):
        if before[key] != after[key]:
            raise ValueError('Source identity differs')
    new = json.dumps(after, ensure_ascii=False, indent=2).encode()
    output = ROOT / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    snapshot = f'election-archive/{EDITION}/extraction-{OLD}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(dir=ROOT / 'exports') as temp:
        root = Path(temp)
        archives = []
        for kind, relative, body in [('snapshot', snapshot, old), ('correction', revision, new)]:
            path = root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(body)
            archive = root / (kind + '-' + EDITION + '.zip')
            bucket = hashlib.sha256(relative.encode()).digest()[0] % 8
            package(root, archive, 'election-archive', bucket, 8, [relative], OLD if kind == 'correction' else None, snapshot if kind == 'correction' else None)
            archives.append(archive)
        with zipfile.ZipFile(output, 'w') as z:
            for archive in archives:
                z.write(archive, archive.name)
            z.writestr('SHA256SUMS', ''.join(sha(a.read_bytes()) + '  ' + a.name + '\n' for a in archives))
            z.writestr('ARCHIVES', EDITION + '\n')
            z.writestr('IMPORT.sh', multi_seat_import_script())
            z.writestr('AUDIT.json', json.dumps({'edition': EDITION, 'previous_sha256': OLD, 'new_sha256': sha(new), 'verified_target_sha256': TARGET, 'scope': '294 declarations; prior reviewed records [181, 252] retained unchanged', 'source_url': after['source_url'], 'source_sha256': after['source_sha256']}, indent=2))
    output.with_suffix('.sha256').write_bytes((sha(output.read_bytes()) + '  ' + output.name + '\n').encode())
    print(json.dumps({'bundle': NAME, 'sha256': sha(output.read_bytes()), 'new_sha256': sha(new)}))
if __name__ == '__main__':
    build()
