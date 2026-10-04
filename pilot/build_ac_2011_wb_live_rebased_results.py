"""Apply verified 2011 West Bengal declarations to the live turnout revision."""

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
EDITION = '79ebd83ef86bed336cc3ef0f'
NAME = 'pollmedia-ac-2011-wb-live-rebased-results-20261004'
DECLARATIONS = 'pollmedia-election-corrections-20261003-wave11'
DECLARATIONS_SHA = 'e29ce17f2c7bd6875ae2f997c4667f2feacba742445bae2f3cd81b9b21b3cdb9'
DECLARATIONS_INNER = 'pollmedia-ac-2011-west-bengal-declared-results-20261003'
TURNOUT = 'pollmedia-pc-ac-zero-turnout-corrections-20261001-v3'
TURNOUT_SHA = '5449afc95c14440ecb3865d7ffe9717886ce8576f6f2a8ad5f0ce5667b86e8d7'
OLD_SHA = '6df3220522335c31e6abb9a7b23626a1bcd05a5765451050a6388d5ad705cfc8'
PROPOSED_SHA = 'f5f4f340c82ace3d86088a6f27bcc046eebfd3663c3fe5226dda9ac9b9118e48'
LIVE_SHA = '6f62f61beccf3ba00a59e2a4e4d130d5fc924c5258d4093463860e76aca3ceca'
SOURCE_SHA = 'd0c45dabc902cdd086c5850f1ad566ee6ac944c04cbca553b00d6b15488f1950'
DISCREPANCY_CODES = {11, 98, 206, 211, 215}
RESULT_NOTE = (' Official summary also declares the winner and margin; displayed result values use '
               'that summary. Review the linked official report for the discrepancy.')


def sha(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def checked_outer(root: Path, name: str, expected_sha: str) -> zipfile.ZipFile:
    path = root / 'exports' / (name + '.zip')
    if sha(path.read_bytes()) != expected_sha or path.with_suffix('.sha256').read_bytes() != (
            f'{expected_sha}  {path.name}\n').encode('ascii'):
        raise ValueError('Sealed election package differs: ' + name)
    return zipfile.ZipFile(path)


def extraction(package_body: bytes) -> bytes:
    with zipfile.ZipFile(io.BytesIO(package_body)) as archive:
        names = [name for name in archive.namelist()
                 if name.startswith(f'election-archive/{EDITION}/') and name.endswith('.json')]
        if len(names) != 1:
            raise ValueError('Expected one archived election extraction')
        body = archive.read(names[0])
        manifest = json.loads(archive.read('manifest.json'))['files'][0]
        if manifest['path'] != names[0] or manifest['sha256'] != sha(body):
            raise ValueError('Archived election extraction checksum differs')
        return body


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    with checked_outer(root, DECLARATIONS, DECLARATIONS_SHA) as wave:
        inner_body = wave.read(DECLARATIONS_INNER + '.zip')
        if sha(inner_body) != wave.read(DECLARATIONS_INNER + '.sha256').decode('ascii').split()[0]:
            raise ValueError('Declared-results bundle checksum differs')
        with zipfile.ZipFile(io.BytesIO(inner_body)) as bundle:
            old_body = extraction(bundle.read(f'snapshot-{EDITION}.zip'))
            proposed_body = extraction(bundle.read(f'correction-{EDITION}.zip'))
    with checked_outer(root, TURNOUT, TURNOUT_SHA) as turnout:
        live_body = extraction(turnout.read(f'correction-{EDITION}.zip'))
    if (sha(old_body), sha(proposed_body), sha(live_body)) != (OLD_SHA, PROPOSED_SHA, LIVE_SHA):
        raise ValueError('2011 West Bengal revision chain differs')
    source = root / 'application/storage/app/private/election-archive' / EDITION / f'{EDITION}-7327.pdf'
    if sha(source.read_bytes()) != SOURCE_SHA:
        raise ValueError('Official West Bengal 2011 report checksum differs')

    old = json.loads(old_body)
    proposed = json.loads(proposed_body)
    live = json.loads(live_body)
    if (old['kind'], old['year'], old['source_sha256']) != ('ac', 2011, SOURCE_SHA):
        raise ValueError('West Bengal edition identity differs')
    original_rows = {row['code']: row for row in old['records']}
    live_rows = {row['code']: row for row in live['records']}
    for row in proposed['records']:
        code = row['code']
        if code not in DISCREPANCY_CODES:
            continue
        before, current = original_rows[code], live_rows[code]
        if (current.get('original_extraction_warning') != before.get('error')
                or not current.get('error', '').startswith('Official constituency summary supplies')
                or not row.get('source_discrepancy') or not row.get('summary_result')):
            raise ValueError(f'Unreviewed live/declared result overlap at constituency {code}')
        row['error'] = before['error']

    adjusted = json.dumps(proposed, ensure_ascii=False, indent=2).encode('utf-8')
    merged_body, changes = rebase(old_body, adjusted, live_body)
    merged = json.loads(merged_body)
    merged_rows = {row['code']: row for row in merged['records']}
    if (len(merged_rows) != 294 or len(changes['changed_fields']) != 294
            or changes['added_codes'] or changes['edition_fields']):
        raise ValueError('Declared-result coverage differs')
    for code in DISCREPANCY_CODES:
        merged_rows[code]['error'] += RESULT_NOTE
    for code, prior in live_rows.items():
        result = merged_rows[code]
        if (result['candidates'] != prior['candidates']
                or result.get('votes_polled') != prior.get('votes_polled')
                or result.get('original_extraction_warning') != prior.get('original_extraction_warning')
                or not result.get('summary_result')):
            raise ValueError(f'Live candidate, turnout, or warning changed: {code}')
    new_body = json.dumps(merged, ensure_ascii=False, indent=2).encode('utf-8')
    return live_body, new_body, {
        'scope': '294 West Bengal 2011 declared results rebased on the observed live turnout revision',
        'edition': EDITION, 'year': 2011, 'source_url': old['source_url'],
        'source_file': old['source_file'], 'source_sha256': SOURCE_SHA,
        'previous_sha256': LIVE_SHA, 'new_sha256': sha(new_body),
        'result_count': 294, 'preserved_turnout_discrepancy_codes': sorted(DISCREPANCY_CODES),
        'rebase_changes': changes,
    }


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body, new_body, audit = revised_edition(root)
    with tempfile.TemporaryDirectory(prefix='ac-2011-wb-rebase-', dir=output.parent) as temporary:
        stage = Path(temporary) / 'stage'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{LIVE_SHA}.json'
        correction = f'election-archive/{EDITION}/extraction.json'
        for kind, relative, body in (('snapshot', snapshot, old_body), ('correction', correction, new_body)):
            target = stage / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(body)
            bucket = hashlib.sha256(relative.encode('utf-8')).digest()[0] % 8
            package(stage, packages / f'{kind}-{EDITION}.zip', 'election-archive', bucket, 8,
                    [relative], LIVE_SHA if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
        inner = sorted(packages.glob('*.zip'))
        with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_STORED) as bundle:
            for path in inner:
                bundle.write(path, path.name)
            bundle.writestr('SHA256SUMS', ''.join(f'{sha(path.read_bytes())}  {path.name}\n'
                                                 for path in inner))
            bundle.writestr('ARCHIVES', EDITION + '\n')
            bundle.writestr('AUDIT.json', json.dumps(audit, indent=2))
            bundle.writestr('IMPORT.sh', import_script([EDITION]))
    checksum = sha(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{checksum}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': checksum, 'edition': EDITION,
            'previous_sha256': LIVE_SHA, 'new_sha256': audit['new_sha256']}


if __name__ == '__main__':
    print(json.dumps(build()))
