"""Rebase sealed correction waves onto explicitly observed live election bytes."""

import hashlib
import io
import json
from pathlib import Path
import tempfile
import zipfile

from pilot.rebase_election_revision import rebase
from pilot.preserve_archive_json import package
from pilot.build_pc_ac_zero_turnout_bundle import import_script

ROOT = Path(__file__).resolve().parents[1]
NAME = 'pollmedia-election-corrections-20261003-resume-v1'


def sha(body):
    return hashlib.sha256(body).hexdigest()


def extraction(package_body, edition):
    with zipfile.ZipFile(io.BytesIO(package_body)) as archive:
        names = [n for n in archive.namelist() if n.startswith('election-archive/' + edition + '/') and n.endswith('.json')]
        if len(names) != 1:
            raise ValueError('Ambiguous extraction package: ' + edition)
        return archive.read(names[0])


def build():
    exports = ROOT / 'exports'
    observed = dict(line.split() for line in (exports / 'election-live-checksums-20261003.txt').read_text().splitlines())
    versions, steps = {}, []
    for wave in range(1, 8):
        path = exports / f'pollmedia-election-corrections-20261003-wave{wave}.zip'
        expected = path.with_suffix('.sha256').read_text().split()[0]
        if sha(path.read_bytes()) != expected:
            raise ValueError('Wave checksum differs')
        with zipfile.ZipFile(path) as outer:
            for name in outer.read('ORDER').decode().splitlines():
                body = outer.read(name + '.zip')
                if sha(body) != outer.read(name + '.sha256').decode().split()[0]:
                    raise ValueError('Inner bundle checksum differs')
                with zipfile.ZipFile(io.BytesIO(body)) as bundle:
                    for edition in bundle.read('ARCHIVES').decode().split():
                        old = extraction(bundle.read('snapshot-' + edition + '.zip'), edition)
                        proposed = extraction(bundle.read('correction-' + edition + '.zip'), edition)
                        versions[sha(old)] = old
                        versions[sha(proposed)] = proposed
                        steps.append((edition, old, proposed, name))
    missing = set(observed.values()) - set(versions)
    # Read only saved correction packages, never credentials or raw page manifests.
    for path in sorted(exports.glob('*.zip')):
        if not missing:
            break
        if path.stat().st_size > 30 * 1024 * 1024:
            continue
        with zipfile.ZipFile(path) as outer:
            for name in outer.namelist():
                if not name.startswith(('snapshot-', 'correction-')) or not name.endswith('.zip'):
                    continue
                edition = name.removesuffix('.zip').split('-', 1)[1]
                if edition not in observed:
                    continue
                body = extraction(outer.read(name), edition)
                digest = sha(body)
                if digest in missing:
                    versions[digest] = body
                    missing.remove(digest)
    if missing:
        raise ValueError('Exact live bytes not located: ' + ', '.join(sorted(missing)))
    current = {edition: versions[digest] for edition, digest in observed.items()}
    evidence = {edition: [] for edition in observed}
    for edition, old, proposed, source in steps:
        current[edition], changes = rebase(old, proposed, current[edition])
        evidence[edition].append({'source_bundle': source, **changes})
    output = exports / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    audit = []
    with tempfile.TemporaryDirectory(prefix='election-resume-', dir=exports) as temp:
        stage, packages = Path(temp) / 'stage', Path(temp) / 'packages'
        packages.mkdir()
        for edition, body in current.items():
            prior = observed[edition]
            if sha(body) == prior:
                continue
            relative = f'election-archive/{edition}/extraction.json'
            previous = f'election-archive/{edition}/extraction-{prior}.json'
            (stage / relative).parent.mkdir(parents=True, exist_ok=True)
            (stage / relative).write_bytes(body)
            (stage / previous).write_bytes(versions[prior])
            for label, target, replaces, snapshot in [('snapshot', previous, None, None), ('correction', relative, prior, previous)]:
                bucket = hashlib.sha256(target.encode()).digest()[0] % 8
                package(stage, packages / f'{label}-{edition}.zip', 'election-archive', bucket, 8, [target], replaces, snapshot)
            audit.append({'edition': edition, 'previous_sha256': prior, 'new_sha256': sha(body), 'steps': evidence[edition]})
        sums = ''.join(f'{sha(p.read_bytes())}  {p.name}\n' for p in sorted(packages.glob('*.zip')))
        script = import_script([a['edition'] for a in audit])
        script = script.replace('exec 9> .import.lock', 'exec 9> /home/pollmedia/tmp/.pollmedia-election-release.lock')
        script = script.replace('sha256sum -c SHA256SUMS', 'git -c safe.directory=/home/pollmedia/app -C /home/pollmedia/app merge-base --is-ancestor e21695c HEAD\nsha256sum -c SHA256SUMS')
        with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_STORED) as bundle:
            for path in sorted(packages.glob('*.zip')):
                bundle.write(path, path.name)
            bundle.writestr('SHA256SUMS', sums)
            bundle.writestr('ARCHIVES', ''.join(a['edition'] + '\n' for a in audit))
            bundle.writestr('IMPORT.sh', script)
            bundle.writestr('AUDIT.json', json.dumps({'observed_live': observed, 'editions': audit}, indent=2))
        output.with_suffix('.sha256').write_bytes(f'{sha(output.read_bytes())}  {output.name}\n'.encode())
    return {'bundle': str(output), 'changed_editions': len(audit), 'already_applied': len(observed) - len(audit)}


if __name__ == '__main__':
    print(json.dumps(build()))
