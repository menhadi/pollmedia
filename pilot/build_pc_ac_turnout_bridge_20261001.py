"""Bridge four observed live archive revisions to the verified zero-turnout bundle.

The prior bytes come from earlier checksum-verified election packages. This
does not recalculate votes or alter existing nonblank totals/candidate rows.
"""

import hashlib
import io
import json
from pathlib import Path
import tempfile
import zipfile

from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


NAME = 'pollmedia-pc-ac-zero-turnout-live-bridge-20261001'
TARGET_PACKAGE = 'pollmedia-pc-ac-zero-turnout-corrections-20261001-v3.zip'
CASES = (
    ('25d92852e9975cf32d1b94bd', 'pollmedia-ac-summary-corrections-20261001-v6.zip', 'snapshot',
     'a69c88e061fb437b278f4fb85fb8b08dcdf893bf2c4ead6105d4c0729cf724a4', 182),
    ('775e12dc77eb9f634ba9a490', 'pollmedia-ac-summary-corrections-20261001-v5.zip', 'correction',
     '7741bae98489c726a3d366c2d745c6a8cd4c84384148c958eea4ff8718c36eef', 56),
    ('13651fccf222dbaabb514501', 'pollmedia-ac-summary-corrections-20261001-v5.zip', 'correction',
     'd565a4740d05728cee47ec7f50c335a2e6d80c77d8f642dc14f155be319d69a3', 15),
    ('e1372f39c9e60519335a29f8', 'pollmedia-ac-summary-corrections-20261001-v6.zip', 'snapshot',
     'dc376eb800fdb5c1a8c593371aa13d0a8232b56de30c98e9794b8a28a7a738fa', 68),
)
ALLOWED = {'electors', 'votes_polled', 'error', 'summary_totals', 'summary_page',
           'original_extraction_warning', 'source_warning_code', 'source_discrepancy',
           'summary_source_file', 'summary_source_sha256'}


def sha256(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def verified_payload(root: Path, filename: str, prefix: str, edition: str) -> tuple[bytes, dict]:
    path = root / 'exports' / filename
    with path.open('rb') as source:
        digest = hashlib.file_digest(source, 'sha256').hexdigest()
    if digest != path.with_suffix('.sha256').read_text(encoding='ascii').split()[0]:
        raise ValueError('Bundle checksum differs: ' + filename)
    with zipfile.ZipFile(path) as outer:
        with zipfile.ZipFile(io.BytesIO(outer.read(f'{prefix}-{edition}.zip'))) as inner:
            manifest = json.loads(inner.read('manifest.json'))['files'][0]
            body = inner.read(manifest['path'])
    if (manifest['path'] != f'election-archive/{edition}/extraction.json' and prefix != 'snapshot'):
        raise ValueError('Correction path differs')
    if prefix == 'snapshot' and manifest['path'] != f'election-archive/{edition}/extraction-{manifest["sha256"]}.json':
        raise ValueError('Snapshot path differs')
    if sha256(body) != manifest['sha256']:
        raise ValueError('Payload checksum differs')
    return body, manifest


def checked_change(before: bytes, after: bytes, expected_count: int) -> list[int]:
    old, new = json.loads(before), json.loads(after)
    if {key: value for key, value in old.items() if key != 'records'} != {
            key: value for key, value in new.items() if key != 'records'}:
        raise ValueError('Official edition identity or source changed')
    if len(old['records']) != len(new['records']):
        raise ValueError('Constituency coverage changed')
    changed = []
    for previous, target in zip(old['records'], new['records']):
        if {key: value for key, value in previous.items() if key not in ALLOWED} != {
                key: value for key, value in target.items() if key not in ALLOWED}:
            raise ValueError('A candidate, seat identity, or other source field changed')
        if previous == target:
            continue
        if (previous.get('votes_polled') not in (None, 0)
                or not isinstance(target.get('votes_polled'), int) or target['votes_polled'] <= 0
                or previous.get('electors') not in (None, 0, target.get('electors'))):
            raise ValueError('An existing nonblank total would change')
        changed.append(previous['code'])
    if len(changed) != expected_count or len(changed) != len(set(changed)):
        raise ValueError('Expected source-verified seat changes differ')
    return changed


def build(root: Path) -> dict:
    exports = root / 'exports'
    bundle = exports / (NAME + '.zip')
    if bundle.exists() or bundle.with_suffix('.sha256').exists():
        raise FileExistsError(bundle)
    details = []
    for edition, source_package, prefix, expected_live, expected_count in CASES:
        live, _ = verified_payload(root, source_package, prefix, edition)
        target, target_manifest = verified_payload(root, TARGET_PACKAGE, 'correction', edition)
        if sha256(live) != expected_live:
            raise ValueError('Observed live checksum does not match preserved package: ' + edition)
        changed = checked_change(live, target, expected_count)
        details.append({'edition': edition, 'live': live, 'target': target,
                        'live_sha256': expected_live, 'target_sha256': target_manifest['sha256'],
                        'changed_codes': changed, 'prior_package': source_package})
    with tempfile.TemporaryDirectory(prefix='pc-ac-bridge-', dir=exports) as temporary:
        staging = Path(temporary)
        archive = staging / 'archive'
        packages = staging / 'packages'
        packages.mkdir()
        for detail in details:
            edition = detail['edition']
            for relative, body in ((f'election-archive/{edition}/extraction-{detail["live_sha256"]}.json', detail['live']),
                                   (f'election-archive/{edition}/extraction.json', detail['target'])):
                path = archive / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(body)
        inner = []
        for prefix in ('snapshot', 'correction'):
            for detail in details:
                edition = detail['edition']
                previous = f'election-archive/{edition}/extraction-{detail["live_sha256"]}.json'
                relative = previous if prefix == 'snapshot' else f'election-archive/{edition}/extraction.json'
                output = packages / f'{prefix}-{edition}.zip'
                bucket = hashlib.sha256(relative.encode()).digest()[0] % 8
                package(archive, output, 'election-archive', bucket, 8, [relative],
                        detail['live_sha256'] if prefix == 'correction' else None,
                        previous if prefix == 'correction' else None)
                inner.append(output)
        partial = bundle.with_suffix('.zip.partial')
        if partial.exists():
            raise FileExistsError(partial)
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as zipped:
            for item in inner:
                zipped.write(item, item.name)
            zipped.writestr('SHA256SUMS', ''.join(f'{sha256(item.read_bytes())}  {item.name}\n' for item in inner))
            zipped.writestr('ARCHIVES', ''.join(d['edition'] + '\n' for d in details))
            zipped.writestr('AUDIT.json', json.dumps({'scope': 'Four earlier live revisions to verified blank turnout target',
                                                    'target_bundle': TARGET_PACKAGE,
                                                    'editions': [{k: v for k, v in d.items() if k not in ('live', 'target')}
                                                                 for d in details]}, indent=2))
            zipped.writestr('IMPORT.sh', import_script([d['edition'] for d in details]))
        partial.replace(bundle)
    checksum = sha256(bundle.read_bytes())
    bundle.with_suffix('.sha256').write_bytes((checksum + '  ' + bundle.name + '\n').encode('ascii'))
    return {'bundle': str(bundle), 'sha256': checksum,
            'editions': len(details), 'filled_turnout_rows': sum(len(d['changed_codes']) for d in details)}


if __name__ == '__main__':
    print(json.dumps(build(Path(__file__).resolve().parents[1])))
