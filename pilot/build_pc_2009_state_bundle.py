"""Preserve and package the official 2009 PC State/UT heading correction."""

import copy
import hashlib
import json
from pathlib import Path
import zipfile

from extract_pc_2009 import extract
from preserve_archive_json import package


EDITION = 'e5346f9160ad32fb68a34578'
PREVIOUS_SHA256 = '25c10ce6274f98fe77cc3079038235bcce8186f955a421b43301e65a44f30c39'


def digest(data):
    return hashlib.sha256(data).hexdigest()


def build(root):
    archive = root / 'application/storage/app/private'
    folder = archive / 'election-archive' / EDITION
    extraction = folder / 'extraction.json'
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    old_bytes = extraction.read_bytes()
    if digest(old_bytes) != PREVIOUS_SHA256:
        raise ValueError('The 2009 extraction no longer matches the exact prior revision')
    old = json.loads(old_bytes)
    if manifest['url'] != old['source_url'] or old['kind'] != 'pc' or old['year'] != 2009:
        raise ValueError('The 2009 official edition identity differs')
    sources = {item['file']: item for item in manifest['files']}
    for item in [dict(file=old['source_file'], sha256=old['source_sha256']), *old['additional_sources']]:
        source = sources[item['file']]
        if source['sha256'] != item['sha256'] or digest((folder / item['file']).read_bytes()) != item['sha256']:
            raise ValueError('A preserved official 2009 PDF checksum differs')

    records = extract(folder / old['source_file'], folder / old['additional_sources'][0]['file'])
    if len(records) != len(old['records']) or len(records) != 543:
        raise ValueError('The 2009 PC record count differs')
    for previous, revised in zip(old['records'], records):
        expected = previous | {
            'state_name': revised['state_name'],
            'state_heading_page': revised['state_heading_page'],
            'name': revised['name'],
        }
        if revised != expected:
            raise ValueError('A 2009 PC candidate, vote, status, warning or identity changed')
    new = copy.deepcopy(old)
    new['records'] = records
    new_bytes = json.dumps(new, ensure_ascii=False, indent=2).encode('utf-8')
    new_sha256 = digest(new_bytes)

    output = root / 'exports'
    snapshot_zip = output / 'pollmedia-pc-2009-state-snapshot-20260930.zip'
    correction_zip = output / 'pollmedia-pc-2009-state-correction-20260930.zip'
    bundle = output / 'pollmedia-pc-2009-state-correction-bundle-20260930.zip'
    if any(path.exists() or path.with_suffix('.sha256').exists()
           for path in [snapshot_zip, correction_zip, bundle]):
        raise FileExistsError('A 2009 correction package already exists')
    if extraction.read_bytes() != old_bytes:
        raise ValueError('The 2009 extraction changed during source review')

    snapshot_path = f'election-archive/{EDITION}/extraction-{PREVIOUS_SHA256}.json'
    snapshot = archive / snapshot_path
    if snapshot.exists():
        if snapshot.read_bytes() != old_bytes:
            raise ValueError('The preserved 2009 extraction snapshot differs')
    else:
        with snapshot.open('xb') as output:
            output.write(old_bytes)
    temporary = folder / 'extraction.statefix.tmp'
    if temporary.exists():
        raise FileExistsError(temporary)
    temporary.write_bytes(new_bytes)
    temporary.replace(extraction)

    for path, relative, prior in [
        (snapshot_zip, snapshot_path, False),
        (correction_zip, f'election-archive/{EDITION}/extraction.json', True),
    ]:
        bucket = hashlib.sha256(relative.encode('utf-8')).digest()[0] % 8
        package(archive, path, 'election-archive', bucket, 8, [relative],
                PREVIOUS_SHA256 if prior else None, snapshot_path if prior else None)

    inner = [snapshot_zip, correction_zip]
    checksums = [(path, digest(path.read_bytes())) for path in inner]
    script = '''#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
exec 9> .import.lock
flock -n 9 || { echo 'Election correction import already running' >&2; exit 1; }
sha256sum -c SHA256SUMS
artisan=/home/pollmedia/app/application/artisan
test -f "$artisan"
check_disk() {
    available=$(df -Pk /home/pollmedia/app | awk 'NR==2 {print $4}')
    test "$available" -ge 10485760 || { echo 'Less than 10 GiB free on server' >&2; exit 1; }
}
check_disk
snapshot=pollmedia-pc-2009-state-snapshot-20260930.zip
correction=pollmedia-pc-2009-state-correction-20260930.zip
snapshot_sha=$(sha256sum "$snapshot" | awk '{print $1}')
correction_sha=$(sha256sum "$correction" | awk '{print $1}')
php8.4 "$artisan" archive:import-json "$PWD/$snapshot" "--sha256=$snapshot_sha" --check
php8.4 "$artisan" archive:import-json "$PWD/$snapshot" "--sha256=$snapshot_sha"
check_disk
php8.4 "$artisan" archive:import-json "$PWD/$correction" "--sha256=$correction_sha" --check --allow-revision
php8.4 "$artisan" archive:import-json "$PWD/$correction" "--sha256=$correction_sha" --allow-revision
check_disk
checked=$(php8.4 "$artisan" archive:index-constituencies --check)
echo "$checked"
[[ "$checked" == *'Verified 74218 PC/AC constituency tables in 448 source editions.'* ]]
php8.4 "$artisan" archive:index-constituencies
check_disk
'''
    with zipfile.ZipFile(bundle, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=6) as zipped:
        for path, _ in checksums:
            zipped.write(path, path.name)
        zipped.writestr('SHA256SUMS', ''.join(f'{sha}  {path.name}\n' for path, sha in checksums))
        zipped.writestr('IMPORT.sh', script)
    bundle_sha = digest(bundle.read_bytes())
    bundle.with_suffix('.sha256').write_text(f'{bundle_sha}  {bundle.name}\n', encoding='ascii')
    return {'old_sha256': PREVIOUS_SHA256, 'new_sha256': new_sha256,
            'bundle': str(bundle), 'bundle_sha256': bundle_sha,
            'bundle_bytes': bundle.stat().st_size, 'records': len(records)}


if __name__ == '__main__':
    print(json.dumps(build(Path(__file__).resolve().parents[1])))
