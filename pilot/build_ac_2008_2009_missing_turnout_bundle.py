"""Package source-matched 2008–09 Assembly turnout missing from the live index."""

import csv
import hashlib
import json
from pathlib import Path
import tempfile
import zipfile

from audit_pc_ac_zero_values import correction_index, effective_body
from build_assembly_summary_bundle import revised_records, source_summaries
from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


NAME = 'pollmedia-ac-2008-2009-summary-turnout-20261002-v2'
AUDIT = 'pc-ac-display-audit-after-bdd282d.csv'
EARLIER_REVISION = 'pollmedia-ac-summary-corrections-20261001-v2.zip'
EARLIER_REVISED = {'163db19f7d25b7f62c9aa360', 'b56245f92253914a7569bae3'}
TARGETS = {
    '1be9e8a7976ebdc44658c8e9': 224,
    '25c81eb8ee370d8948a1dc3c': 87,
    '51a30ea845222a74460e8585': 40,
    'c2b9ef2bc73bbcc70a271a58': 60,
    'c9e2b9c993bfe7d06547c8f2': 230,
    'd82c217822e367756f2aad6e': 60,
    'f6de544acb0c3ab5a0c23542': 200,
    '163db19f7d25b7f62c9aa360': 17,
    '1901084c7189cfe9433f1842': 57,
    '4ac73455f798dcf3a8d2946f': 90,
    'b56245f92253914a7569bae3': 17,
    'cc0185e917711e78c149abf4': 288,
}
ALLOWED_CHANGES = {
    'votes_polled', 'error', 'original_extraction_warning', 'source_warning_code',
    'electors', 'summary_totals', 'summary_page', 'summary_source_file',
    'summary_source_sha256',
}


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def live_targets(path: Path) -> dict:
    targets = {}
    with path.open(encoding='utf-8-sig', newline='') as source:
        for row in csv.DictReader(source):
            edition = row['edition_id']
            if edition not in TARGETS or row['issue'] != 'source_turnout_missing_or_zero':
                continue
            if row['kind'] != 'ac' or row['year'] not in ('2008', '2009'):
                raise ValueError('Live audit election identity differs: ' + edition)
            entry = targets.setdefault(edition, {'sha256': row['extraction_sha256'], 'codes': set()})
            if entry['sha256'] != row['extraction_sha256']:
                raise ValueError('Live audit contains multiple JSON revisions: ' + edition)
            entry['codes'].add(int(row['code']))
    if set(targets) != set(TARGETS):
        raise ValueError('Live audit edition coverage differs')
    for edition, count in TARGETS.items():
        if len(targets[edition]['codes']) != count:
            raise ValueError('Live audit blank-turnout count differs: ' + edition)
    return targets


def build(root: Path) -> dict:
    exports = root / 'exports'
    output = exports / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    targets = live_targets(exports / AUDIT)
    earlier = correction_index(root, (EARLIER_REVISION,))
    details = []
    with tempfile.TemporaryDirectory(prefix='ac-2008-2009-', dir=exports) as temp:
        staged = Path(temp) / 'archive'
        packages = Path(temp) / 'packages'
        packages.mkdir()
        for edition, expected_count in TARGETS.items():
            folder = root / 'application/storage/app/private/election-archive' / edition
            extraction = folder / 'extraction.json'
            old_body = (effective_body(extraction, earlier[edition])
                        if edition in EARLIER_REVISED else extraction.read_bytes())
            old_sha = digest(old_body)
            if old_sha != targets[edition]['sha256']:
                raise ValueError('Live and local election JSON checksums differ: ' + edition)
            data = json.loads(old_body)
            if data.get('kind') != 'ac' or data.get('year') not in (2008, 2009):
                raise ValueError('Archived election identity differs: ' + edition)
            summaries, secondary = source_summaries(folder, data)
            revised, count = revised_records(data, summaries, secondary)
            changed = {after['code'] for before, after in zip(data['records'], revised['records'])
                       if before != after}
            if (len(data['records']) != len(revised['records']) or count != expected_count
                    or changed != targets[edition]['codes']):
                raise ValueError('Official summary and live blank seats do not match: ' + edition)
            for before, after in zip(data['records'], revised['records']):
                if before['code'] != after['code'] or any(
                    before.get(key) != after.get(key)
                    for key in set(before) | set(after) if key not in ALLOWED_CHANGES
                ):
                    raise ValueError('A candidate, identity, or unrelated field changed: ' + edition)
            new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
            if edition not in EARLIER_REVISED and extraction.read_bytes() != old_body:
                raise ValueError('Local election JSON changed during source review: ' + edition)
            snapshot = f'election-archive/{edition}/extraction-{old_sha}.json'
            revision = f'election-archive/{edition}/extraction.json'
            for relative, body in ((snapshot, old_body), (revision, new_body)):
                path = staged / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(body)
            details.append({'edition': edition, 'year': data['year'], 'revised_records': count,
                            'source_url': data['source_url'], 'source_sha256': data['source_sha256'],
                            'previous_sha256': old_sha, 'new_sha256': digest(new_body),
                            'snapshot_path': snapshot, 'revision_path': revision})

        inner = []
        for kind in ('snapshot', 'correction'):
            for detail in details:
                edition = detail['edition']
                relative = detail['snapshot_path' if kind == 'snapshot' else 'revision_path']
                bucket = hashlib.sha256(relative.encode('utf-8')).digest()[0] % 8
                path = packages / f'{kind}-{edition}.zip'
                package(staged, path, 'election-archive', bucket, 8, [relative],
                        detail['previous_sha256'] if kind == 'correction' else None,
                        detail['snapshot_path'] if kind == 'correction' else None)
                inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as archive:
            for path in inner:
                archive.write(path, path.name)
            archive.writestr('SHA256SUMS', ''.join(
                f'{digest(path.read_bytes())}  {path.name}\n' for path in inner))
            archive.writestr('ARCHIVES', ''.join(detail['edition'] + '\n' for detail in details))
            archive.writestr('AUDIT.json', json.dumps({
                'scope': 'Only official PDF summary turnout for 2008–09 AC seats blank in the live audit',
                'editions': details, 'revised_records': sum(item['revised_records'] for item in details),
            }, ensure_ascii=False, indent=2))
            archive.writestr('IMPORT.sh', import_script([detail['edition'] for detail in details]))
        partial.replace(output)
    output.with_suffix('.sha256').write_bytes(
        f'{digest(output.read_bytes())}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'editions': len(details),
            'revised_records': sum(item['revised_records'] for item in details),
            'sha256': digest(output.read_bytes())}


if __name__ == '__main__':
    print(json.dumps(build(Path(__file__).resolve().parents[1])))
