"""Rebase verified Andhra 1978 declarations onto the confirmed live Attili repair."""
import hashlib
import io
import json
from pathlib import Path
import tempfile
import zipfile
from preserve_archive_json import package
from build_pc_1951_multi_seat_declared_members import multi_seat_import_script

ROOT = Path(__file__).resolve().parents[1]
EDITION = '5ceb07b4b18fc2ea5dcb6112'
NAME = 'pollmedia-ac-1978-andhra-live-rebase-20261007'
OLD = 'b8aeac15d3735bb09d47e8c20012c418d0b4b5a22f451d1ecaca42fad1a06175'
TARGET = '22cb5472e8e6fc2b495e8ea3a23d851f94bb816fe364282c69b85faffbe53dd6'
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
    old = read_release('pollmedia-ac-andhra-1978-attili-detail-result-20261004', OLD)
    target = read_release('pollmedia-ac-1978-attili-candidate-recovery-20261006', TARGET)
    before, after = json.loads(old), json.loads(target)
    if len(before['records']) != 294 or len(after['records']) != 294:
        raise ValueError('Coverage differs')
    for prior, row in zip(before['records'], after['records'], strict=True):
        if prior['code'] != row['code'] or prior['candidates'] != row['candidates']:
            raise ValueError('Existing candidate evidence differs')
        for key, value in prior.items():
            if key not in row:
                row[key] = value
        if prior.get('original_extraction_warning') and prior['original_extraction_warning'] != row.get('original_extraction_warning'):
            raise ValueError('Original warning differs')
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
            z.writestr('AUDIT.json', json.dumps({'edition': EDITION, 'previous_sha256': OLD, 'new_sha256': sha(new), 'verified_target_sha256': TARGET, 'scope': '294 declarations rebased with existing candidate provenance retained', 'source_url': after['source_url'], 'source_sha256': after['source_sha256']}, indent=2))
    output.with_suffix('.sha256').write_bytes((sha(output.read_bytes()) + '  ' + output.name + '\n').encode())
    print(json.dumps({'bundle': NAME, 'sha256': sha(output.read_bytes()), 'new_sha256': sha(new)}))
if __name__ == '__main__':
    build()
