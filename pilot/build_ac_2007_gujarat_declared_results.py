"""Package source-verified Gujarat 2007 AC declarations without altering detail."""

import hashlib
import json
from pathlib import Path
import tempfile
import zipfile

from audit_ac_2007_gujarat_declared_results import EDITION, PRIOR_PACKAGES, ROOT, SOURCE_NOTES, audit
from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


NAME = 'pollmedia-ac-2007-gujarat-declared-results-20261003'
RESULT_NOTE = ' Official summary declares the winner and margin.'
DISCREPANCIES = [
    {'code': 114, 'printed_margin': 13269, 'candidate_difference': 13268},
    {'code': 127, 'printed_margin': 4115, 'candidate_difference': 4114},
    {'code': 181, 'printed_margin': 10664, 'candidate_difference': 10665},
]


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    old_body, results, source = audit(root)
    if len(results) != 182 or source['held'] or source['discrepancies'] != DISCREPANCIES:
        raise ValueError('Gujarat 2007 result inventory differs')
    old = json.loads(old_body)
    revised = json.loads(old_body)
    by_code = {row['code']: row for row in results}
    if len(by_code) != 182:
        raise ValueError('Duplicate reviewed 2007 constituency')
    for record in revised['records']:
        evidence = by_code.get(record['code'])
        if evidence is None:
            continue
        if (not any(record['error'].startswith(note) for note in SOURCE_NOTES)
                or record['summary_page'] != evidence['summary_page']
                or record.get('summary_result') is not None or record.get('winner') is not None
                or record.get('margin') is not None):
            raise ValueError('Already revised or unexpected 2007 source result')
        if evidence['reported_margin'] != evidence['result']['margin']:
            printed = evidence['reported_margin']
            calculated = evidence['result']['margin']
            record['error'] += (f' The report prints a margin of {printed:,}, but the declared winner and '
                                f'runner votes differ by {calculated:,}. The displayed margin is calculated '
                                'from those reported votes.')
            record['summary_reported_margin'] = printed
        else:
            record['error'] += RESULT_NOTE
        record['summary_result'] = evidence['result']
        if record['source_warning_code'] == 'summary_turnout_with_detail_warnings':
            if record.get('summary_source_file') is not None or record.get('summary_source_sha256') is not None:
                raise ValueError('Unexpected pre-existing Gujarat PDF summary provenance')
            record['summary_source_file'] = source['source_file']
            record['summary_source_sha256'] = source['source_sha256']
    for before, after in zip(old['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        expected = ({'error', 'summary_result'} if before['code'] in by_code else set())
        if before['code'] in {114, 127, 181}:
            expected |= {'summary_reported_margin'}
        if before['source_warning_code'] == 'summary_turnout_with_detail_warnings':
            expected |= {'summary_source_file', 'summary_source_sha256'}
        if before['code'] != after['code'] or changed != expected:
            raise ValueError('Unrelated 2007 constituency evidence changed')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    return old_body, new_body, {'edition': EDITION, 'year': 2007, 'source': source,
                                'previous_sha256': digest(old_body), 'new_sha256': digest(new_body),
                                'prior_packages': list(PRIOR_PACKAGES), 'codes': sorted(by_code)}


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body, new_body, detail = revised_edition(root)
    old_sha = detail['previous_sha256']
    with tempfile.TemporaryDirectory(prefix='ac-2007-gujarat-results-', dir=output.parent) as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{old_sha}.json'
        revision = f'election-archive/{EDITION}/extraction.json'
        inner = []
        for kind, relative, body in (('snapshot', snapshot, old_body), ('correction', revision, new_body)):
            target = staged / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(body)
            bucket = hashlib.sha256(relative.encode('utf-8')).digest()[0] % 8
            path = packages / f'{kind}-{EDITION}.zip'
            package(staged, path, 'election-archive', bucket, 8, [relative],
                    old_sha if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
            inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as archive:
            for path in inner:
                archive.write(path, path.name)
            archive.writestr('SHA256SUMS', ''.join(f'{digest(path.read_bytes())}  {path.name}\n' for path in inner))
            archive.writestr('ARCHIVES', EDITION + '\n')
            archive.writestr('AUDIT.json', json.dumps({'scope': '182 official 2007 Gujarat AC declared results',
                                                      **detail}, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'results': len(detail['codes']),
            'previous_sha256': old_sha, 'new_sha256': detail['new_sha256']}


if __name__ == '__main__':
    print(json.dumps(build()))
