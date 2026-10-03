"""Reconcile 118 reviewed 1999 PC results with official summary pages."""

import hashlib
import json
from pathlib import Path
import tempfile
import zipfile

from audit_pc_ac_zero_values import correction_index, effective_body
from audit_pc_1999_name_mismatches import DETAIL, EDITION, ROOT, SUMMARY, WARNING, audit
from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


NAME = 'pollmedia-pc-1999-name-variant-results-20261003'
PRIOR_PACKAGE = 'pollmedia-pc-1996-1999-detailed-results-20261003.zip'
TRUNCATION_NOTE = ('Official summary prints “Mumbai South Centra”; the detailed report prints '
                   '“MUMBAI SOUTH CENTRAL”. Turnout, candidates and margin reconcile; review the official PDFs.')


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    revisions = correction_index(root, (PRIOR_PACKAGE,))
    if len(revisions.get(EDITION, [])) != 1:
        raise ValueError('Prior 1999 detailed-result correction is missing')
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    old_body = effective_body(folder / 'extraction.json', revisions[EDITION])
    old = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    files = {item['file']: item for item in manifest['files']}
    if old['kind'] != 'pc' or old['year'] != 1999 or old['source_url'] != manifest['url']:
        raise ValueError('Official 1999 PC edition identity differs')
    source_rows = audit(root)
    by_code = {row['code']: row for row in source_rows}
    if len(by_code) != 118:
        raise ValueError('1999 reviewed result coverage differs')
    revised = json.loads(old_body)
    for record in revised['records']:
        source = by_code.get(record['code'])
        if source is None:
            continue
        if record['error'] != WARNING or record['status'] != 'needs_review' \
                or record['number_of_seats'] != 1 or record.get('winner') is not None \
                or record.get('margin') is not None or record.get('source_warning_code') is not None \
                or source['result'] is None:
            raise ValueError(f'1999 PC seat {record["code"]} differs from audited state')
        record['original_extraction_warning'] = record['error']
        if source['printed_truncation']:
            record['error'] = TRUNCATION_NOTE
        record['source_warning_code'] = 'official_pc_summary_reconciled_detail_warning'
        record['detail_candidate_count'] = len(record['candidates'])
        record['summary_result'] = source['result']
        record['summary_source_file'] = SUMMARY
        record['summary_source_sha256'] = files[SUMMARY]['sha256']
        record['detail_source_file'] = DETAIL
        record['detail_source_sha256'] = files[DETAIL]['sha256']
        record['official_summary_constituency_name'] = source['summary']
    allowed = {'original_extraction_warning', 'source_warning_code', 'detail_candidate_count',
               'summary_result', 'summary_source_file', 'summary_source_sha256',
               'detail_source_file', 'detail_source_sha256', 'official_summary_constituency_name'}
    for before, after in zip(old['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        expected = allowed | ({'error'} if before['code'] == 253 else set()) if before['code'] in by_code else set()
        if before['code'] != after['code'] or changed != expected:
            raise ValueError(f'1999 PC unrelated value changed: {before["code"]}')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    old_sha = digest(old_body)
    new_sha = digest(new_body)
    with tempfile.TemporaryDirectory(prefix='pc-1999-name-', dir=output.parent) as temporary:
        temporary = Path(temporary)
        staged = temporary / 'archive'
        packages = temporary / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{old_sha}.json'
        revision = f'election-archive/{EDITION}/extraction.json'
        for relative, body in ((snapshot, old_body), (revision, new_body)):
            path = staged / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(body)
        inner = []
        for kind, relative in (('snapshot', snapshot), ('correction', revision)):
            path = packages / f'{kind}-{EDITION}.zip'
            bucket = hashlib.sha256(relative.encode()).digest()[0] % 8
            package(staged, path, 'election-archive', bucket, 8, [relative],
                    old_sha if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
            inner.append(path)
        partial = output.with_suffix('.zip.partial')
        if partial.exists():
            raise FileExistsError(partial)
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as zipped:
            for path in inner:
                zipped.write(path, path.name)
            zipped.writestr('SHA256SUMS', ''.join(f'{digest(path.read_bytes())}  {path.name}\n' for path in inner))
            zipped.writestr('ARCHIVES', EDITION + '\n')
            zipped.writestr('AUDIT.json', json.dumps({
                'scope': '118 official 1999 PC summary/detail name variants; votes and candidate rows unchanged',
                'edition': EDITION, 'source_url': old['source_url'],
                'detail_file': DETAIL, 'detail_sha256': files[DETAIL]['sha256'],
                'summary_file': SUMMARY, 'summary_sha256': files[SUMMARY]['sha256'],
                'previous_sha256': old_sha, 'new_sha256': new_sha,
                'prior_package': PRIOR_PACKAGE,
                'codes': sorted(by_code), 'printed_truncation_code': 253,
            }, indent=2))
            zipped.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_text(sha + '  ' + output.name + '\n', encoding='ascii')
    return {'bundle': str(output), 'sha256': sha, 'records': len(by_code),
            'previous_sha256': old_sha, 'new_sha256': new_sha}


if __name__ == '__main__':
    print(json.dumps(build()))
