"""Group verified election-only corrections into one guarded upload."""

import hashlib
import json
from pathlib import Path
import zipfile


ROOT = Path(__file__).resolve().parents[1]
NAME = 'pollmedia-election-corrections-20261003-wave1'
REQUIRED_COMMIT = '1093225'
BUNDLES = [
    ('pollmedia-ac-assam-1996-official-summary-results-20261003', 'b36cfa37db1d4ea5854d7809bd0617c5f5c979c8c32511a91d3d47eeb3c27c71'),
    ('pollmedia-ac-bihar-1995-tn-1991-official-summary-results-20261003', 'a82ddc6b8705af057b7eb765556d8adf56bec43a37588da24a587ba712cc05fd'),
    ('pollmedia-ac-three-reviewed-summary-results-20261003', '77d763631333562782478f8759afbe71c965ee63b85a7b9f73622f3d86108e29'),
    ('pollmedia-ac-west-bengal-1982-onda-summary-result-20261003', '4bdb30c6df569a4103eae52477a8e9f6e40e1f90ddc971d06606e9e711ce6628'),
    ('pollmedia-pc-1996-1999-detailed-results-20261003', 'a6222b34844a4e2df50d825aae9309f013bf1312866aa9607be35fcf0f8e13a0'),
    ('pollmedia-pc-1999-name-variant-results-20261003', '94b1738116aaf51c20bbed2331d3be3a80c4ff1d297d41842647e85442fa9db2'),
    ('pollmedia-pc-1996-1998-name-variant-results-20261003', '415e6e94a7432a26375858f4ce490c4834695da963f25df29c08c7328c5a52bc'),
    ('pollmedia-ac-1969-chhibramau-declared-result-20261003', 'dbabf7bbc30ec7dc609e1b3e536e42d5ec14fe8bbbfc3417c8dd7a5b51a2cb04'),
    ('pollmedia-ac-1974-up-candidate-count-results-20261003', 'ef64350d2b73ff0c5a25d3e51ff7beee137e6047fcc9444768512634c8cca745'),
    ('pollmedia-ac-1989-thondamuthur-declared-result-20261003', '91383693c5c386f966d2d6bcc101df9791a58a4c726817656e23b08dd0e8221a'),
    ('pollmedia-ac-2002-jk-declared-results-20261003', 'bbf6671a18f785a9006c3364f983bf6f0234a0a19cebfa2d6fecaa3ce0f903bd'),
    ('pollmedia-ac-2006-wb-declared-results-20261003', '9838d00c1290058255ce24b942132f0f5817f1ad2e11f40f5496a76ce2573779'),
]


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def import_script() -> str:
    return f'''#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
exec 9> /home/pollmedia/tmp/.pollmedia-election-release.lock
flock -n 9 || {{ echo 'Another election release is running' >&2; exit 1; }}
git -c safe.directory=/home/pollmedia/app -C /home/pollmedia/app merge-base --is-ancestor {REQUIRED_COMMIT} HEAD || {{ echo 'Pull the tested election code first' >&2; exit 1; }}
check_disk() {{
    available=$(df -Pk /home/pollmedia/app | awk 'NR==2 {{print $4}}')
    test "$available" -ge 10485760 || {{ echo 'Less than 10 GiB free on server' >&2; exit 1; }}
}}
check_disk
while IFS= read -r name; do
    case "$name" in pollmedia-[a-zA-Z0-9-]*) ;; *) echo 'Unexpected election bundle name' >&2; exit 1 ;; esac
    check_disk
    sha256sum -c "$name.sha256"
    workdir=$(mktemp -d "./.run-${{name}}.XXXXXX")
    unzip -q "$name.zip" -d "$workdir"
    bash "$workdir/IMPORT.sh"
done < ORDER
check_disk
echo 'All selected election corrections imported and indexed.'
'''


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    sidecar = output.with_suffix('.sha256')
    if output.exists() or sidecar.exists():
        raise FileExistsError(output)
    editions = {}
    items = []
    for name, expected_sha in BUNDLES:
        path = root / 'exports' / (name + '.zip')
        sha_path = path.with_suffix('.sha256')
        body, sha_body = path.read_bytes(), sha_path.read_bytes()
        if (digest(body) != expected_sha or sha_body != f'{expected_sha}  {path.name}\n'.encode('ascii')):
            raise ValueError('Election bundle checksum differs: ' + name)
        with zipfile.ZipFile(path) as bundle:
            audit = json.loads(bundle.read('AUDIT.json'))
            for edition in (audit.get('editions') or [audit]):
                key = edition.get('edition')
                prior, new = edition.get('previous_sha256'), edition.get('new_sha256')
                if not key or not prior or not new:
                    raise ValueError('Election revision metadata incomplete: ' + name)
                if key in editions and editions[key] != prior:
                    raise ValueError('Election correction order conflicts: ' + name + ' ' + key)
                editions[key] = new
            if b'check_disk' not in bundle.read('IMPORT.sh') or b'--allow-revision' not in bundle.read('IMPORT.sh'):
                raise ValueError('Guarded election import missing: ' + name)
        items.append({'name': name, 'sha256': expected_sha, 'editions': bundle_editions(path)})
    partial = output.with_suffix('.zip.partial')
    if partial.exists():
        raise FileExistsError(partial)
    with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as release:
        for name, _ in BUNDLES:
            for suffix in ('.zip', '.sha256'):
                path = root / 'exports' / (name + suffix)
                release.write(path, path.name)
        release.writestr('ORDER', ''.join(name + '\n' for name, _ in BUNDLES))
        release.writestr('IMPORT_ALL.sh', import_script())
        release.writestr('AUDIT.json', json.dumps({'scope': 'Verified election-only PC/AC corrections',
                                                 'required_code_commit': REQUIRED_COMMIT,
                                                 'bundles': items}, indent=2))
    partial.replace(output)
    sha = digest(output.read_bytes())
    sidecar.write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'bundles': len(items), 'editions': len(editions)}


def bundle_editions(path: Path) -> list[str]:
    with zipfile.ZipFile(path) as bundle:
        return bundle.read('ARCHIVES').decode('ascii').split()


if __name__ == '__main__':
    print(json.dumps(build()))
