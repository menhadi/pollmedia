"""Package all Gujarat 2012 AC summary declarations with preserved OCR evidence."""

import hashlib
import json
from pathlib import Path
import tempfile
import zipfile

from audit_ac_2012_gujarat_result_strips import STRIPS_SHA256, audit as audit_strips
from audit_ac_2012_gujarat_summary_results import EDITION, ROOT, SOURCE_SHA256
from build_ac_2012_gujarat_summary_results import revised_edition
from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


NAME = 'pollmedia-ac-gujarat-2012-summary-results-20261003-v5'


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def revised(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    old_body, v4_body, prior = revised_edition(root, refined=True,
                                               shifted_pages=True, result_fallback=True)
    if (len(prior['changed']) != 182
            or sum(row['result'] for row in prior['changed']) != 152):
        raise ValueError('Prior Gujarat 2012 summary coverage differs')
    evidence = audit_strips(root)
    if len(evidence['verified']) != 30 or evidence['unresolved']:
        raise ValueError('Remaining official Gujarat result inventory differs')
    staged = json.loads(v4_body)
    reviewed = []
    for record in staged['records']:
        code = record['code']
        item = evidence['verified'].get(code)
        if item is None:
            continue
        if (record.get('summary_result') is not None
                or record.get('source_warning_code') != 'official_summary_turnout_only'
                or record.get('summary_page') != item['source_page']
                or record.get('summary_source_sha256') != SOURCE_SHA256):
            raise ValueError(f'Unexpected Gujarat prior result: {code}')
        result = item['result']
        if (not 0 < result['runner_votes'] < result['winner_votes']
                or result['margin'] != result['winner_votes'] - result['runner_votes']
                or result['winner_votes'] > record['summary_totals']['valid_candidate_votes']):
            raise ValueError(f'Invalid official Gujarat declaration: {code}')
        record['summary_result'] = result
        record['summary_result_strip_ocr_file'] = 'summary-result-ocr-strips-v1.json'
        record['summary_result_strip_ocr_sha256'] = STRIPS_SHA256
        record['summary_result_evidence'] = item['reason']
        if item['reason'] == 'official_summary_with_detail_difference':
            record['error'] += (' The official summary declares the result; its winner or runner '
                                'does not reconcile with the extracted detailed candidate rows.')
        else:
            record['error'] += ' The official summary declaration agrees with the detailed candidate rows.'
        reviewed.append({'code': code, 'name': record['name'], 'summary_page': record['summary_page'],
                         'winner': result['winner'], 'margin': result['margin'],
                         'detail_difference': item['reason'] == 'official_summary_with_detail_difference'})
    if len(reviewed) != 30 or sum(row['detail_difference'] for row in reviewed) != 17:
        raise ValueError('Gujarat source-result coverage differs')
    for before, after in zip(json.loads(v4_body)['records'], staged['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        expected = ({'summary_result', 'summary_result_strip_ocr_file',
                     'summary_result_strip_ocr_sha256', 'summary_result_evidence', 'error'}
                    if before['code'] in evidence['verified'] else set())
        if changed != expected or before['candidates'] != after['candidates']:
            raise ValueError(f'Unrelated Gujarat record changed: {before["code"]}')
    new_body = json.dumps(staged, ensure_ascii=False, indent=2).encode('utf-8')
    return old_body, new_body, {'edition': EDITION, 'source_url': staged['source_url'],
                                'source_sha256': SOURCE_SHA256,
                                'strip_ocr_sha256': STRIPS_SHA256,
                                'previous_sha256': digest(old_body), 'new_sha256': digest(new_body),
                                'turnout': 182, 'results': 182,
                                'results_with_detail_difference': 17,
                                'new_result_declarations': reviewed}


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body, new_body, detail = revised(root)
    old_sha = detail['previous_sha256']
    with tempfile.TemporaryDirectory(prefix='ac-gujarat-2012-complete-', dir=output.parent) as temporary:
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
            archive.writestr('AUDIT.json', json.dumps({'scope': 'Gujarat 2012 AC official summary turnout and source-backed declarations',
                                                      **detail}, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_text(f'{sha}  {output.name}\n', encoding='ascii')
    return {'bundle': str(output), 'sha256': sha, 'turnout': detail['turnout'],
            'results': detail['results'], 'detail_differences': detail['results_with_detail_difference'],
            'previous_sha256': old_sha, 'new_sha256': detail['new_sha256']}


if __name__ == '__main__':
    print(json.dumps(build()))
