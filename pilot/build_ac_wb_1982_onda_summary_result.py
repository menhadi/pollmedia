"""Show Onda 1982 turnout and result from its official summary with a data note."""

import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_pc_ac_zero_turnout_bundle import import_script
from extract_assembly_summary_totals import read_summary_pages
from preserve_archive_json import package


EDITION = '9982b63a332a67579dae045f'
CODE = 252
YEAR = 1982
OLD_SHA256 = '832554900da498dfa4398d5161ec94c63f42ba83c0efaab82f2f0e0caefbccef'
NAME = 'pollmedia-ac-west-bengal-1982-onda-summary-result-20261003'


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def revised_edition(root: Path) -> tuple[bytes, bytes, dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    old_body = (folder / 'extraction.json').read_bytes()
    old = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    source_file = old['source_file']
    files = [item for item in manifest['files'] if item['file'] == source_file]
    source_path = folder / source_file
    if (digest(old_body) != OLD_SHA256 or old['kind'] != 'ac' or old['year'] != YEAR
            or old['source_url'] != manifest['url'] or len(files) != 1
            or old['source_sha256'] != files[0]['sha256'] or not source_path.is_file()
            or source_path.is_symlink() or digest(source_path.read_bytes()) != files[0]['sha256']):
        raise ValueError('Official source identity or checksum differs')
    summaries = read_summary_pages(source_path)
    if len(summaries) != 293 or len(old['records']) != 294 or set(summaries) - {item['code'] for item in old['records']} or CODE not in summaries:
        raise ValueError('Official summary coverage differs')
    summary = summaries[CODE]
    revised = json.loads(old_body)
    record = next(item for item in revised['records'] if item['code'] == CODE)
    if (record['name'] != summary['name'] or record['number_of_seats'] != 1
            or record['status'] != 'needs_review' or record.get('summary_totals') is not None
            or record.get('winner') is not None or record.get('margin') is not None
            or record.get('source_warning_code') is not None or record.get('source_discrepancy') is not None
            or record['electors'] != summary['electors']
            or record['valid_candidate_votes'] != summary['valid_candidate_votes']
            or summary['votes_polled'] - record['votes_polled'] != 7
            or not 0 < summary['valid_candidate_votes'] <= summary['votes_polled'] <= summary['electors']):
        raise ValueError('Onda summary or archived detail differs')
    candidates = record['candidates']
    ranked = sorted(candidates, key=lambda item: item['votes'], reverse=True)
    if (len(candidates) != 8 or len({(item['candidate_name'].casefold(), item['party_at_election'].casefold(), item['votes'])
                                    for item in candidates}) != 8
            or sum(item['votes'] for item in candidates) != 81952 or ranked[0]['votes'] <= ranked[1]['votes']):
        raise ValueError('Onda candidate rows differ')
    with fitz.open(source_path) as pdf:
        page = pdf[summary['summary_page'] - 1].get_text(sort=True)
    rows = re.findall(r'^\s*(Winner|Runner up)\s*:\s*(\S+)\s+(.+?)\s+(\d+)\s*$', page, re.I | re.M)
    margin = re.search(r'MARGIN\s*:\s*(\d+)', page, re.I)
    if len(rows) != 2 or not margin or rows[0][0].casefold() != 'winner' or rows[1][0].casefold() != 'runner up':
        raise ValueError('Onda official result rows differ')
    result = {'winner': rows[0][2].strip(), 'winner_party': rows[0][1].strip(),
              'winner_votes': int(rows[0][3]), 'runner': rows[1][2].strip(),
              'runner_party': rows[1][1].strip(), 'runner_votes': int(rows[1][3]),
              'margin': int(margin[1])}
    if (result['margin'] != result['winner_votes'] - result['runner_votes']
            or any((ranked[index]['candidate_name'], ranked[index]['party_at_election'], ranked[index]['votes'])
                   != (result[label], result[label + '_party'], result[label + '_votes'])
                   for index, label in enumerate(('winner', 'runner')))):
        raise ValueError('Onda summary result differs from candidate rows')
    record['original_extraction_warning'] = record['error']
    record['error'] = ('Official summary records seven more voters than the detailed table. '
                       'The eight detailed candidate rows total 81,952 against 81,995 valid votes in the summary. '
                       'Turnout, winner and margin use the official summary; candidate rows remain under review.')
    record['source_warning_code'] = 'official_summary_turnout_only'
    record['summary_page'] = summary['summary_page']
    record['summary_totals'] = {field: summary[field] for field in ('electors', 'votes_polled', 'valid_candidate_votes')}
    record['summary_result'] = result
    record['summary_source_file'] = source_file
    record['summary_source_sha256'] = files[0]['sha256']
    record['source_discrepancy'] = {'field': 'votes_polled', 'detail_value': 84280, 'summary_value': 84287,
                                    'valid_detail_value': 81995, 'valid_summary_value': 81995}
    expected = {'original_extraction_warning', 'error', 'source_warning_code', 'summary_page',
                'summary_totals', 'summary_result', 'summary_source_file', 'summary_source_sha256',
                'source_discrepancy'}
    original = next(item for item in old['records'] if item['code'] == CODE)
    if original.get('summary_page') == summary['summary_page']:
        expected.discard('summary_page')
    for before, after in zip(old['records'], revised['records']):
        changed = {field for field in set(before) | set(after) if before.get(field) != after.get(field)}
        if before['code'] != after['code'] or changed != (expected if before['code'] == CODE else set()):
            raise ValueError('Unrelated constituency evidence changed')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    return old_body, new_body, {'edition': EDITION, 'code': CODE, 'year': YEAR,
                                'source_url': old['source_url'], 'source_file': source_file,
                                'source_sha256': files[0]['sha256'], 'previous_sha256': OLD_SHA256,
                                'new_sha256': digest(new_body), 'summary_page': summary['summary_page'],
                                'winner': result['winner'], 'margin': result['margin']}


def build(root: Path) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body, new_body, detail = revised_edition(root)
    with tempfile.TemporaryDirectory(prefix='ac-onda-1982-', dir=root / 'exports') as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{OLD_SHA256}.json'
        revision = f'election-archive/{EDITION}/extraction.json'
        inner = []
        for kind, relative, body in (('snapshot', snapshot, old_body), ('correction', revision, new_body)):
            target = staged / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(body)
            bucket = hashlib.sha256(relative.encode('utf-8')).digest()[0] % 8
            path = packages / f'{kind}-{EDITION}.zip'
            package(staged, path, 'election-archive', bucket, 8, [relative],
                    OLD_SHA256 if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
            inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as archive:
            for path in inner:
                archive.write(path, path.name)
            archive.writestr('SHA256SUMS', ''.join(f'{digest(path.read_bytes())}  {path.name}\n' for path in inner))
            archive.writestr('ARCHIVES', EDITION + '\n')
            archive.writestr('AUDIT.json', json.dumps({'scope': 'West Bengal 1982 AC Onda official summary result',
                                                      **detail}, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    output.with_suffix('.sha256').write_bytes(f'{digest(output.read_bytes())}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': digest(output.read_bytes()), 'records': 1}


if __name__ == '__main__':
    print(json.dumps(build(Path(__file__).resolve().parents[1])))
