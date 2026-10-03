"""Package conservative Gujarat 2012 AC summary totals and declarations."""

import hashlib
import json
from pathlib import Path
import tempfile
import zipfile

from audit_ac_2012_gujarat_summary_results import EDITION, ROOT, SOURCE_SHA256, WORDS_SHA256, audit, audit_refined, load
from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


NAME = 'pollmedia-ac-gujarat-2012-summary-results-20261003'
NAME_V2 = 'pollmedia-ac-gujarat-2012-summary-results-20261003-v2'
NAME_V3 = 'pollmedia-ac-gujarat-2012-summary-results-20261003-v3'
NAME_V4 = 'pollmedia-ac-gujarat-2012-summary-results-20261003-v4'


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def revised_edition(root: Path = ROOT, *, refined: bool = False,
                    shifted_pages: bool = False,
                    result_fallback: bool = False) -> tuple[bytes, bytes, dict]:
    evidence = audit_refined(root, shifted_pages=shifted_pages,
                             result_fallback=result_fallback) if refined else audit(root)
    old, _, _, _, old_body = load(root)
    total = evidence['coverage'].get('source_totals_verified', 0) + evidence['coverage'].get('source_totals_verified_by_arithmetic', 0)
    expected_total, expected_result = ((182, 152 if result_fallback else 147)
                                       if shifted_pages else (180, 146)) if refined else (171, 140)
    if (total != expected_total
            or evidence['coverage'].get('source_result_verified') != expected_result):
        raise ValueError('Gujarat 2012 evidence inventory differs')
    revised = json.loads(old_body)
    changed = []
    for record, row in zip(revised['records'], evidence['rows']):
        if record['code'] != row['code'] or record['name'] != row['name']:
            raise ValueError('Gujarat 2012 constituency order differs')
        totals = row['totals']
        if totals is None:
            continue
        if (record['status'] != 'needs_review' or record.get('summary_totals') is not None
                or record.get('summary_result') is not None
                or record.get('source_warning_code') not in (None, 'official_detail_turnout_only')):
            raise ValueError(f'Gujarat prior review state differs: {record["code"]}')
        previous_polled = record.get('votes_polled')
        record['previous_summary_reconciliation_warning'] = record.get('error') or ''
        record['error'] = ('Official constituency summary reports turnout and valid votes. '
                           'The detailed candidate table remains available for review.')
        if previous_polled not in (None, totals['votes_polled']):
            record['error'] += (' The earlier detailed-page total differs from the summary voters total; '
                                'the displayed turnout uses the summary voters total.')
            record['previous_detail_votes_polled'] = previous_polled
        record['source_warning_code'] = 'official_summary_turnout_only'
        record['votes_polled'] = totals['votes_polled']
        record['summary_totals'] = {key: totals[key] for key in
                                    ('electors', 'votes_polled', 'valid_candidate_votes')}
        record['summary_page'] = totals['source_page']
        record['summary_source_file'] = old['source_file']
        record['summary_source_sha256'] = SOURCE_SHA256
        record['summary_ocr_file'] = 'summary-result-ocr-words-v1.json'
        record['summary_ocr_sha256'] = WORDS_SHA256
        if record.get('electors') is None:
            record['electors'] = totals['electors']
        elif record['electors'] != totals['electors']:
            if refined and record['electors'] <= 10 and totals['electors'] > 10000:
                record['previous_detail_electors'] = record['electors']
                record['electors'] = totals['electors']
                record['error'] += ' The earlier extracted elector total was an incomplete OCR value.'
            else:
                record['source_discrepancy'] = {
                    'field': 'electors', 'detail_value': record['electors'],
                    'summary_value': totals['electors'],
                }
                record['error'] += ' The source pages differ slightly on the elector total.'
        if row['result'] is not None:
            record['summary_result'] = row['result']
        changed.append({'code': record['code'], 'name': record['name'], 'page': totals['source_page'],
                        'turnout': totals['votes_polled'], 'result': row['result'] is not None,
                        'earlier_detail_total': previous_polled})
    if len(changed) != expected_total or sum(row['result'] for row in changed) != expected_result:
        raise ValueError('Gujarat 2012 packaged coverage differs')
    allowed = {'previous_summary_reconciliation_warning', 'error', 'previous_detail_votes_polled',
               'source_warning_code', 'votes_polled', 'summary_totals', 'summary_page',
               'summary_source_file', 'summary_source_sha256', 'summary_ocr_file',
               'summary_ocr_sha256', 'electors', 'previous_detail_electors',
               'source_discrepancy', 'summary_result'}
    for before, after in zip(old['records'], revised['records']):
        fields = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if fields - allowed or before['code'] != after['code']:
            raise ValueError(f'Unrelated Gujarat field changed: {before["code"]}: {fields - allowed}')
        if before['code'] not in {row['code'] for row in changed} and fields:
            raise ValueError(f'Unresolved Gujarat constituency changed: {before["code"]}')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    return old_body, new_body, {'edition': EDITION, 'source_url': old['source_url'],
                                'source_sha256': SOURCE_SHA256, 'summary_ocr_sha256': WORDS_SHA256,
                                'previous_sha256': digest(old_body), 'new_sha256': digest(new_body),
                                'coverage': evidence['coverage'], 'changed': changed,
                                'unresolved_codes': [row['code'] for row in evidence['rows'] if row['totals'] is None]}


def build(root: Path = ROOT, *, refined: bool = False, shifted_pages: bool = False,
          result_fallback: bool = False) -> dict:
    name = NAME_V4 if result_fallback else NAME_V3 if shifted_pages else NAME_V2 if refined else NAME
    output = root / 'exports' / (name + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body, new_body, detail = revised_edition(root, refined=refined,
                                                 shifted_pages=shifted_pages,
                                                 result_fallback=result_fallback)
    old_sha = detail['previous_sha256']
    with tempfile.TemporaryDirectory(prefix='ac-gujarat-2012-summary-', dir=output.parent) as temporary:
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
            archive.writestr('AUDIT.json', json.dumps({'scope': 'Gujarat 2012 AC official summary turnout and source-backed results',
                                                      **detail}, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_text(f'{sha}  {output.name}\n', encoding='ascii')
    return {'bundle': str(output), 'sha256': sha, 'turnout': len(detail['changed']),
            'results': sum(row['result'] for row in detail['changed']),
            'previous_sha256': old_sha, 'new_sha256': detail['new_sha256']}


if __name__ == '__main__':
    print(json.dumps(build()))
