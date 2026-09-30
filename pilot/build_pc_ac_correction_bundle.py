"""Bundle verified PC/AC correction ZIPs for one guarded server-side import."""
import hashlib
from pathlib import Path
import zipfile


IMPORT = '''#!/usr/bin/env bash
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
for f in *snapshot-20260930.zip; do
    check_disk
    sha=$(sha256sum "$f" | awk '{print $1}')
    php8.4 "$artisan" archive:import-json "$PWD/$f" "--sha256=$sha" --check
    php8.4 "$artisan" archive:import-json "$PWD/$f" "--sha256=$sha"
done
for f in *correction-20260930.zip; do
    check_disk
    sha=$(sha256sum "$f" | awk '{print $1}')
    php8.4 "$artisan" archive:import-json "$PWD/$f" "--sha256=$sha" --check --allow-revision
done
for f in *correction-20260930.zip; do
    check_disk
    sha=$(sha256sum "$f" | awk '{print $1}')
    php8.4 "$artisan" archive:import-json "$PWD/$f" "--sha256=$sha" --allow-revision
done
check_disk
checked=$(php8.4 "$artisan" archive:index-constituencies --check)
echo "$checked"
[[ "$checked" == *'Verified 74218 PC/AC constituency tables in 448 source editions.'* ]]
php8.4 "$artisan" archive:index-constituencies
check_disk
'''


def build(root):
    output = root / 'exports'
    files = sorted([*output.glob('pollmedia-ac-*-20260930.zip'),
                    *output.glob('pollmedia-gujarat-*-ac-*-20260930.zip')])
    if len(files) != 18 or len({path.name for path in files}) != 18:
        raise ValueError('Expected nine exact old-snapshot and correction pairs')
    names = [path.name for path in files]
    if sum('snapshot-20260930.zip' in name for name in names) != 9 or sum('correction-20260930.zip' in name for name in names) != 9:
        raise ValueError('Unexpected correction package names')
    checksums = []
    for path in files:
        with path.open('rb') as source:
            checksums.append((path, hashlib.file_digest(source, 'sha256').hexdigest()))
        with zipfile.ZipFile(path) as inner:
            manifest = inner.read('manifest.json')
            if not manifest or inner.testzip() is not None:
                raise ValueError('Broken inner package: ' + path.name)
    bundle = output / 'pollmedia-pc-ac-corrections-20260930.zip'
    if bundle.exists():
        raise FileExistsError(bundle)
    with zipfile.ZipFile(bundle, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=6) as zipped:
        for path, digest in checksums:
            zipped.write(path, path.name)
        zipped.writestr('SHA256SUMS', ''.join(f'{digest}  {path.name}\n' for path, digest in checksums))
        zipped.writestr('IMPORT.sh', IMPORT)
    with bundle.open('rb') as source:
        digest = hashlib.file_digest(source, 'sha256').hexdigest()
    bundle.with_suffix('.sha256').write_text(digest + '  ' + bundle.name + '\n', encoding='ascii')
    return {'bundle': bundle.as_posix(), 'sha256': digest, 'packages': len(files), 'bytes': bundle.stat().st_size}


if __name__ == '__main__':
    import json
    print(json.dumps(build(Path(__file__).resolve().parents[1])))
