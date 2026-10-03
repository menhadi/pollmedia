"""Package source-verified Punjab 2007 AC declarations without altering detail."""

import hashlib
import json
from pathlib import Path
import tempfile
import zipfile

from audit_ac_2007_punjab_declared_results import EDITION, PRIOR_PACKAGES, ROOT, SOURCE_NOTE, audit
from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


NAME = 'pollmedia-ac-2007-punjab-declared-results-20261003'
RESULT_NOTE = ' Official summary declares the winner and margin.'


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    old_body, results, source = audit(root)
    if len(results) != 11 or source['held'] or len(json.loads(old_body)['records']) != 116:
        raise ValueError('Punjab 2007 result inventory differs')
    old = json.loads(old_body)
    revised = json.loads(old_body)
    by_code = {row['code']: row for row in results}
    if len(by_code) != 11:
        raise ValueError('Duplicate reviewed 2007 constituency')
    for record in revised['records']:
        evidence = by_code.get(record['code'])
        if evidence is None:
            continue
        if (not record['error'].startswith(SOURCE_NOTE) or record['summary_page'] != evidence['summary_page']
                or record.get('summary_result') is not None or record.get('winner') is not None
                or record.get('margin') is not None):
            raise ValueError('Already revised or unexpected 2007 source result')
        record['error'] += RESULT_NOTE
        record['summary_result'] = evidence['result']
    for before, after in zip(old['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if before['code'] != after['code'] or changed != ({'error', 'summary_result'} if before['code'] in by_code else set()):
            raise ValueError('Unrelated 2007 constituency evidence changed')
    beas = source['summary_only_beas']
    if beas['code'] in {row['code'] for row in old['records']} or source['source_url'] != old['source_url']:
        raise ValueError('Beas missing-seat identity differs')
    new_record = {
        'candidates': [], 'number_of_seats': 1, 'status': 'needs_review',
        'error': ('The official report has a Beas constituency summary but no detailed candidate page. '
                  'Its declared result and turnout are shown from that summary.'),
        'code': beas['code'], 'name': beas['name'], 'state_name': 'Punjab',
        'source_warning_code': 'official_summary_turnout_only',
        'electors': beas['electors'], 'votes_polled': beas['votes_polled'],
        'valid_candidate_votes': beas['valid_candidate_votes'],
        'summary_totals': {key: beas[key] for key in ('electors', 'votes_polled', 'valid_candidate_votes')},
        'summary_page': beas['summary_page'],
        'summary_source_file': source['source_file'], 'summary_source_sha256': source['source_sha256'],
        'summary_result': beas['result'],
    }
    revised['records'].append(new_record)
    revised['records'].sort(key=lambda row: row['code'])
    if (len(revised['records']) != 117
            or [row['code'] for row in revised['records']] != list(range(1, 118))
            or next(row for row in revised['records'] if row['code'] == 12) != new_record):
        raise ValueError('Punjab 2007 constituency order or missing-seat evidence differs')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    return old_body, new_body, {'edition': EDITION, 'year': 2007, 'source': source,
                                'previous_sha256': digest(old_body), 'new_sha256': digest(new_body),
                                'prior_packages': list(PRIOR_PACKAGES), 'codes': sorted(by_code),
                                'added_code': 12}


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body, new_body, detail = revised_edition(root)
    old_sha = detail['previous_sha256']
    with tempfile.TemporaryDirectory(prefix='ac-2007-punjab-results-', dir=output.parent) as temporary:
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
            archive.writestr('AUDIT.json', json.dumps({'scope': '11 reviewed 2007 Punjab AC results and Beas summary-only seat',
                                                      **detail}, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'results': len(detail['codes']),
            'previous_sha256': old_sha, 'new_sha256': detail['new_sha256']}


if __name__ == '__main__':
    print(json.dumps(build()))
