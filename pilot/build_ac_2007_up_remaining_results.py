"""Restore nine remaining Uttar Pradesh 2007 declarations hidden by small total differences."""

import hashlib
import io
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_pc_1992_summary_result_bundle import normalized
from build_pc_1951_multi_seat_declared_members import multi_seat_import_script
from extract_assembly_summary_totals import read_summary_pages
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
EDITION = '174ec81b511a8fb1aeca553f'
NAME = 'pollmedia-ac-2007-up-remaining-results-20261008'
PRIOR_SHA256 = '0a5099ecefb83eef3c5e44fc0e04287784080750946cadbf3d1865c0cc5bc00c'
DIFFERENCES = {11: -3, 33: 1, 35: 4, 37: 1, 44: -4, 45: -4, 104: 4, 106: 1, 370: 5}
CODES = set(DIFFERENCES)
RESULT_NOTE = ' The official summary declares the winner and margin; candidate and summary valid-vote totals differ.'


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    with zipfile.ZipFile(root / 'exports/pollmedia-ac-2007-uttar-pradesh-declared-results-20261003.zip') as prior:
        if json.loads(prior.read('AUDIT.json'))['new_sha256'] != PRIOR_SHA256:
            raise ValueError('Prior bundle audit differs')
        with zipfile.ZipFile(io.BytesIO(prior.read(f'correction-{EDITION}.zip'))) as inner:
            old_body = inner.read(f'election-archive/{EDITION}/extraction.json')
    if digest(old_body) != PRIOR_SHA256:
        raise ValueError('Prior Uttar Pradesh 2007 extraction checksum differs')
    old = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    files = [row for row in manifest['files'] if row['file'] == old['source_file']]
    pdf_path = folder / old['source_file']
    if (old['kind'] != 'ac' or old['year'] != 2007 or len(old['records']) != 403
            or old['source_url'] != manifest['url'] or len(files) != 1
            or files[0]['sha256'] != old['source_sha256']
            or digest(pdf_path.read_bytes()) != old['source_sha256']):
        raise ValueError('Official Uttar Pradesh 2007 source identity differs')
    summaries = read_summary_pages(pdf_path)
    if len(summaries) != 403 or set(summaries) != set(range(1, 404)):
        raise ValueError('Official 2007 summary coverage differs')
    revised = json.loads(old_body)
    results = []
    with fitz.open(pdf_path) as pdf:
        for record in revised['records']:
            if record['code'] not in CODES:
                continue
            summary = summaries[record['code']]
            if (record['status'] != 'needs_review' or not record['error'].startswith('Candidate sum ')
                    or not record['error'].endswith('Totals differ.')
                    or record['summary_page'] != summary['summary_page']
                    or normalized(record['name']) != normalized(summary['name'])
                    or any(record[key] != summary[key] for key in ('electors', 'votes_polled', 'valid_candidate_votes'))
                    or record.get('summary_result') is not None
                    or record.get('source_warning_code') is not None):
                raise ValueError(f'Reviewed Uttar Pradesh record differs: {record["code"]}')
            text = pdf[summary['summary_page'] - 1].get_text(sort=True)
            heading = re.search(r'CONSTITUENCY\s+(\d+)\s*-\s*([^\n]+)', text, re.I)
            def declaration(label: str, stop: str):
                block = re.search(r'^\s*' + label + r'\b(.*?)^\s*' + stop + r'\b', text, re.M | re.S)
                if block is None:
                    raise ValueError('Missing declaration block')
                parsed = re.fullmatch(r'\s*(\S+)[ \t]+([^\n]+?)[ \t]+(\d+)[ \t]*(?:\n(.*))?', block[1], re.S)
                if parsed is None or re.search(r'\d', parsed[4] or ''):
                    raise ValueError('Invalid declaration continuation')
                name = re.sub(r'\s+', ' ', parsed[2] + ' ' + (parsed[4] or '')).strip()
                return (None, parsed[1], name, parsed[3])
            winner = declaration('WINNER', 'RUNNER-UP')
            runner = declaration('RUNNER-UP', 'MARGIN')
            margin = re.search(r'^\s*MARGIN\s+(\d+)\b', text, re.I | re.M)
            if (not all((heading, winner, runner, margin))
                    or 'legislative assembly of  Uttar Pradesh' not in text
                    or int(heading[1]) != record['code']
                    or normalized(heading[2]) != normalized(record['name'])):
                raise ValueError(f'Official declaration identity differs: {record["code"]}')
            if (sum(row['votes'] for row in record['candidates']) - summary['valid_candidate_votes'] != DIFFERENCES[record['code']]
                    or record['detailed_totals']['votes'] != sum(row['votes'] for row in record['candidates'])):
                raise ValueError('Pinned candidate-total discrepancy differs')
            top = sorted(record['candidates'], key=lambda row: row['votes'], reverse=True)[:2]
            if (len(top) != 2 or winner[2].strip() != top[0]['candidate_name']
                    or winner[1] != top[0]['party_at_election'] or int(winner[3]) != top[0]['votes']
                    or runner[2].strip() != top[1]['candidate_name']
                    or runner[1] != top[1]['party_at_election'] or int(runner[3]) != top[1]['votes']
                    or int(winner[3]) - int(runner[3]) != int(margin[1])
                    or int(winner[3]) > summary['valid_candidate_votes']):
                raise ValueError(f'Official declaration and candidate detail differ: {record["code"]}')
            result = {'winner': winner[2].strip(), 'winner_party': winner[1],
                      'winner_votes': int(winner[3]), 'runner': runner[2].strip(),
                      'runner_party': runner[1], 'runner_votes': int(runner[3]),
                      'margin': int(margin[1])}
            record['source_warning_code'] = 'summary_turnout_with_detail_warnings'
            record['summary_source_file'] = old['source_file']
            record['summary_source_sha256'] = old['source_sha256']
            record['summary_totals'] = {key: summary[key] for key in ('electors', 'votes_polled', 'valid_candidate_votes')}
            record['summary_result'] = result
            record['error'] += RESULT_NOTE
            results.append({'code': record['code'], 'page': summary['summary_page'], **result})
    if {item['code'] for item in results} != CODES:
        raise ValueError('Uttar Pradesh 2007 result inventory differs')
    for before, after in zip(old['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        expected = ({'source_warning_code', 'summary_source_file', 'summary_source_sha256',
                     'summary_totals', 'summary_result', 'error'} if before['code'] in CODES else set())
        if before['code'] != after['code'] or changed != expected:
            raise ValueError(f'Unrelated Uttar Pradesh evidence changed: {before["code"]}')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    return old_body, new_body, {'edition': EDITION, 'year': 2007,
                                'source_url': old['source_url'], 'source_file': old['source_file'],
                                'source_sha256': old['source_sha256'], 'previous_sha256': digest(old_body),
                                'new_sha256': digest(new_body), 'results': results}


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body, new_body, detail = revised_edition(root)
    old_sha = digest(old_body)
    with tempfile.TemporaryDirectory(prefix='ac-2007-up-results-', dir=output.parent) as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        inner = []
        for kind, relative, body in (
                ('snapshot', f'election-archive/{EDITION}/extraction-{old_sha}.json', old_body),
                ('correction', f'election-archive/{EDITION}/extraction.json', new_body)):
            target = staged / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(body)
            bucket = hashlib.sha256(relative.encode('utf-8')).digest()[0] % 8
            path = packages / f'{kind}-{EDITION}.zip'
            package(staged, path, 'election-archive', bucket, 8, [relative],
                    old_sha if kind == 'correction' else None,
                    f'election-archive/{EDITION}/extraction-{old_sha}.json' if kind == 'correction' else None)
            inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as archive:
            for path in inner:
                archive.write(path, path.name)
            archive.writestr('SHA256SUMS', ''.join(f'{digest(path.read_bytes())}  {path.name}\n' for path in inner))
            archive.writestr('ARCHIVES', EDITION + '\n')
            archive.writestr('AUDIT.json', json.dumps({'scope': '9 reviewed 2007 Uttar Pradesh AC results; 394 records preserved',
                                                      **detail}, indent=2))
            archive.writestr('IMPORT.sh', multi_seat_import_script())
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'results': len(detail['results']),
            'previous_sha256': old_sha, 'new_sha256': detail['new_sha256']}


if __name__ == '__main__':
    print(json.dumps(build()))
