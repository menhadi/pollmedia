"""Publish six source-declared Haryana 1991 results with candidate warnings retained."""

import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from audit_pc_ac_zero_values import correction_index, effective_body
from build_pc_ac_zero_turnout_bundle import import_script
from extract_assembly_summary_totals import read_summary_pages
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
EDITION = '06dc8260d112f93ec51b9e06'
NAME = 'pollmedia-ac-haryana-1991-six-summary-results-20261004'
PRIOR_PACKAGE = 'pollmedia-pc-ac-zero-turnout-corrections-20261001-v3.zip'
PRIOR_SHA = '528892e843621a4d68e5fd2f88d4ceacbfeba4dbbaf30b1024e19feaf7e26926'
SOURCE_FILE = f'{EDITION}-9009.pdf'
SOURCE_SHA = '4f7fd0f4dfc995f474512148cb7f22c58d020489ceeba02bfca47f9c63a7cef0'
EXPECTED = {
    26: ('PUNDRI', 14, 'ISHWAR S/O SIND RAM', 8184),
    27: ('PAI', 0, 'TEJRNDRA PAL', 10848),
    41: ('SONEPAT', 44, 'SHAM DASS', 8600),
    42: ('RAI', 0, 'JAIPAL', 597),
    57: ('FEROZEPUR JHIRKA', 52, 'SHAKRULLA KHAN', 3477),
    58: ('NUH', 0, 'MOHAMMAD ILIYAS', 4243),
}


def sha(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def official_result(text: str, code: int) -> dict:
    rows = re.findall(r'^\s*(Winner|Runner up)\s*:\s*(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
    margin = re.search(r'^\s*MARGIN\s*:\s*(\d+)\b', text, re.I | re.M)
    if len(rows) != 2 or not margin or rows[0][0].lower() != 'winner' or rows[1][0].lower() != 'runner up':
        raise ValueError(f'Official Haryana result lines missing for {code}')
    result = {'winner': rows[0][2].strip(), 'winner_party': rows[0][1], 'winner_votes': int(rows[0][3]),
              'runner': rows[1][2].strip(), 'runner_party': rows[1][1], 'runner_votes': int(rows[1][3]),
              'margin': int(margin[1])}
    if result['winner_votes'] - result['runner_votes'] != result['margin']:
        raise ValueError(f'Official Haryana margin differs for {code}')
    return result


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    revisions = correction_index(root, (PRIOR_PACKAGE,))
    if len(revisions.get(EDITION, [])) != 1:
        raise ValueError('Prior Haryana turnout revision missing')
    old_body = effective_body(folder / 'extraction.json', revisions[EDITION])
    if sha(old_body) != PRIOR_SHA:
        raise ValueError('Prior Haryana 1991 revision checksum differs')
    data = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    source_path = folder / SOURCE_FILE
    if (data['kind'] != 'ac' or data['year'] != 1991 or len(data['records']) != 90
            or data['source_url'] != manifest['url'] or data['source_file'] != SOURCE_FILE
            or data['source_sha256'] != SOURCE_SHA
            or len([item for item in manifest['files'] if item['file'] == SOURCE_FILE
                    and item['sha256'] == SOURCE_SHA]) != 1 or sha(source_path.read_bytes()) != SOURCE_SHA):
        raise ValueError('Official Haryana 1991 PDF identity differs')
    summaries = read_summary_pages(source_path)
    if len(summaries) != 90:
        raise ValueError('Official Haryana 1991 summary coverage differs')
    revised = json.loads(old_body)
    audit_rows = []
    with fitz.open(source_path) as pdf:
        for record in revised['records']:
            code = record['code']
            if code not in EXPECTED:
                continue
            name, expected_delta, expected_winner, expected_margin = EXPECTED[code]
            summary = summaries[code]
            votes = sum(row['votes'] for row in record['candidates'])
            delta = summary['valid_candidate_votes'] - votes
            if (record['name'] != name or summary['name'] != name or record['state_name'] != 'Haryana'
                    or record['number_of_seats'] != 1 or record['status'] != 'needs_review'
                    or summary['electors'] != record['electors']
                    or summary['votes_polled'] != record['votes_polled']
                    or not 0 < summary['valid_candidate_votes'] <= summary['votes_polled'] <= summary['electors']
                    or delta != expected_delta or record.get('summary_result') is not None):
                raise ValueError(f'Haryana 1991 seat or totals differ: {code}')
            page = pdf[summary['summary_page'] - 1].get_text(sort=True)
            if re.search(rf'Field7:CONSTITUENCY\s*:\s*{code}\s*-\s*{re.escape(name)}\b', page, re.I) is None:
                raise ValueError(f'Haryana 1991 summary heading differs: {code}')
            result = official_result(page, code)
            ranked = sorted(record['candidates'], key=lambda row: -row['votes'])
            if (len(ranked) < 2 or result['winner'] != expected_winner
                    or result['margin'] != expected_margin
                    or [(ranked[i]['candidate_name'], ranked[i]['party_at_election'], ranked[i]['votes'])
                        for i in range(2)] != [
                            (result['winner'], result['winner_party'], result['winner_votes']),
                            (result['runner'], result['runner_party'], result['runner_votes'])]):
                raise ValueError(f'Haryana 1991 declaration differs from top candidate rows: {code}')
            old_note = record['error']
            if not isinstance(old_note, str) or not old_note:
                raise ValueError(f'Haryana 1991 extraction warning missing: {code}')
            record.setdefault('original_extraction_warning', old_note)
            record['previous_review_note'] = old_note
            record['error'] = ('Official summary confirms turnout, winner and margin. '
                               + (f'Detailed candidate rows sum {delta} votes below the printed valid total; '
                                  if delta else 'Detailed candidate text has an extraction warning; ')
                               + 'see the linked report for review.')
            record['source_warning_code'] = 'official_summary_turnout_only'
            record['summary_page'] = summary['summary_page']
            record['summary_totals'] = {field: summary[field] for field in ('electors', 'votes_polled', 'valid_candidate_votes')}
            record['summary_result'] = result
            record['summary_source_file'] = SOURCE_FILE
            record['summary_source_sha256'] = SOURCE_SHA
            if delta:
                record['candidate_source_discrepancy'] = {'candidate_sum': votes,
                                                           'printed_valid_votes': summary['valid_candidate_votes'],
                                                           'difference': delta}
            audit_rows.append({'code': code, 'name': name, 'summary_page': summary['summary_page'],
                               'candidate_difference': delta, 'result': result})
    if {row['code'] for row in audit_rows} != set(EXPECTED):
        raise ValueError('Haryana 1991 audited result inventory differs')
    allowed = {'original_extraction_warning', 'previous_review_note', 'error', 'source_warning_code',
               'summary_page', 'summary_totals', 'summary_result', 'summary_source_file',
               'summary_source_sha256', 'candidate_source_discrepancy'}
    for before, after in zip(data['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if before['code'] != after['code'] or (before['code'] in EXPECTED and not changed <= allowed) \
                or (before['code'] not in EXPECTED and changed):
            raise ValueError('Unrelated Haryana 1991 source evidence changed')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    return old_body, new_body, {'edition': EDITION, 'year': 1991, 'source_url': data['source_url'],
                                'source_file': SOURCE_FILE, 'source_sha256': SOURCE_SHA,
                                'prior_package': PRIOR_PACKAGE, 'previous_sha256': sha(old_body),
                                'new_sha256': sha(new_body), 'results': audit_rows}


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / f'{NAME}.zip'
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body, new_body, audit = revised_edition(root)
    with tempfile.TemporaryDirectory(prefix='ac-1991-haryana-results-', dir=output.parent) as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{audit["previous_sha256"]}.json'
        revision = f'election-archive/{EDITION}/extraction.json'
        inner = []
        for kind, relative, body in (('snapshot', snapshot, old_body), ('correction', revision, new_body)):
            target = staged / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(body)
            bucket = hashlib.sha256(relative.encode('utf-8')).digest()[0] % 8
            path = packages / f'{kind}-{EDITION}.zip'
            package(staged, path, 'election-archive', bucket, 8, [relative],
                    audit['previous_sha256'] if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
            inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as archive:
            for path in inner:
                archive.write(path, path.name)
            archive.writestr('SHA256SUMS', ''.join(f'{sha(path.read_bytes())}  {path.name}\n' for path in inner))
            archive.writestr('ARCHIVES', EDITION + '\n')
            archive.writestr('AUDIT.json', json.dumps({'scope': 'Six Haryana 1991 declared AC results with candidate warnings', **audit}, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    bundle_sha = sha(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{bundle_sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': bundle_sha, 'edition': EDITION,
            'previous_sha256': audit['previous_sha256'], 'new_sha256': audit['new_sha256'],
            'result_count': len(audit['results'])}


if __name__ == '__main__':
    print(json.dumps(build()))
