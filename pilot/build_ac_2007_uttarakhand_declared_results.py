"""Package declarations in the official Uttarakhand 2007 AC report."""

import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from audit_pc_ac_zero_values import correction_index, effective_body
from build_pc_1992_summary_result_bundle import normalized
from build_pc_ac_zero_turnout_bundle import import_script
from extract_assembly_summary_totals import read_summary_pages
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
EDITION = '3fdbf401aeb74266e09309ab'
NAME = 'pollmedia-ac-2007-uttarakhand-declared-results-20261003'
PRIOR_PACKAGES = tuple(f'pollmedia-ac-summary-corrections-20261001-v{i}.zip' for i in range(2, 8)) + tuple(
    f'pollmedia-pc-ac-zero-turnout-corrections-20261001-v{i}.zip' for i in range(1, 4))
PRIOR_SHA256 = '657de6f5828f744f094ef4012320c0d2538a2b095ee1508a3375caea7ab94ff7'
SOURCE_NOTE = 'Official constituency summary supplies electors and voters; detailed candidate rows remain under review.'
RESULT_NOTE = ' Official summary declares the winner and margin.'
REVIEW_CODES = {2, 35, 38, 46, 47, 53, 60, 61, 70}


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def declaration(text: str, code: int, name: str, valid_votes: int) -> dict:
    heading = re.search(r'CONSTITUENCY\s+(\d+)\s*-\s*([^\n]+)', text, re.I)
    winner = re.search(r'^\s*WINNER\s+(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
    runner = re.search(r'^\s*RUNNER-UP\s+(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
    margin = re.search(r'^\s*MARGIN\s+(\d+)\b', text, re.I | re.M)
    if (not all((heading, winner, runner, margin)) or int(heading[1]) != code
            or normalized(heading[2]) != normalized(name)
            or 'legislative assembly of  Uttarakhand' not in text):
        raise ValueError(f'Official summary declaration identity differs: {code}')
    winning_votes, runner_votes, margin_votes = int(winner[3]), int(runner[3]), int(margin[1])
    if not 0 <= runner_votes < winning_votes <= valid_votes or winning_votes - runner_votes != margin_votes:
        raise ValueError(f'Official summary declaration arithmetic differs: {code}')
    return {'winner': winner[2].strip(), 'winner_party': winner[1].strip(),
            'winner_votes': winning_votes, 'runner': runner[2].strip(),
            'runner_party': runner[1].strip(), 'runner_votes': runner_votes,
            'margin': margin_votes}


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    revisions = correction_index(root, PRIOR_PACKAGES)
    if len(revisions.get(EDITION, [])) != 9:
        raise ValueError('Prior Uttarakhand 2007 revisions are missing')
    old_body = effective_body(folder / 'extraction.json', revisions[EDITION])
    if digest(old_body) != PRIOR_SHA256:
        raise ValueError('Prior Uttarakhand 2007 extraction checksum differs')
    old = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    matches = [item for item in manifest['files'] if item['file'] == old['source_file']]
    pdf_path = folder / old['source_file']
    if (old['kind'] != 'ac' or old['year'] != 2007 or old['source_url'] != manifest['url']
            or len(matches) != 1 or matches[0]['sha256'] != old['source_sha256']
            or digest(pdf_path.read_bytes()) != old['source_sha256']):
        raise ValueError('Official Uttarakhand 2007 source identity differs')
    summaries = read_summary_pages(pdf_path)
    codes = {row['code'] for row in old['records']}
    if (len(summaries) != 70 or len(codes) != 69 or set(summaries) - codes != {59}
            or sum(row.get('source_warning_code') == 'official_summary_turnout_only'
                   for row in old['records']) != len(REVIEW_CODES)):
        raise ValueError('Official 2007 summary coverage differs')
    revised = json.loads(old_body)
    evidence = []
    with fitz.open(pdf_path) as pdf:
        for record in revised['records']:
            if record['code'] not in REVIEW_CODES:
                continue
            summary = summaries[record['code']]
            if (record['state_name'] != 'Uttarakhand' or record['number_of_seats'] != 1
                    or record['source_warning_code'] != 'official_summary_turnout_only'
                    or not record['error'].startswith(SOURCE_NOTE)
                    or record['summary_page'] != summary['summary_page']
                    or normalized(record['name']) != normalized(summary['name'])
                    or record['summary_totals'] != {k: summary[k] for k in ('electors', 'votes_polled', 'valid_candidate_votes')}
                    or record['summary_source_sha256'] != old['source_sha256']
                    or record.get('summary_result') is not None):
                raise ValueError(f'Reviewed 2007 constituency differs: {record["code"]}')
            result = declaration(pdf[summary['summary_page'] - 1].get_text(sort=True),
                                 record['code'], record['name'], summary['valid_candidate_votes'])
            record['error'] += RESULT_NOTE
            record['summary_result'] = result
            evidence.append({'code': record['code'], 'page': summary['summary_page'], **result})
        if {item['code'] for item in evidence} != REVIEW_CODES:
            raise ValueError('Uttarakhand 2007 result inventory differs')
        bajpur = summaries[59]
        if (bajpur != {'code': 59, 'name': 'Bajpur', 'electors': 97040, 'votes_polled': 76147,
                      'valid_candidate_votes': 76147, 'summary_page': 75}
                or [page.number + 1 for page in pdf if 'BAJPUR' in page.get_text().upper()] != [75]):
            raise ValueError('Bajpur summary-only source coverage differs')
        result = declaration(pdf[74].get_text(sort=True), 59, bajpur['name'], bajpur['valid_candidate_votes'])
        revised['records'].append({
            'candidates': [], 'number_of_seats': 1, 'status': 'needs_review',
            'error': ('The official report has a Bajpur constituency summary but no detailed candidate page. '
                      'Its declared result and turnout are shown from that summary.'),
            'code': 59, 'name': 'BAJPUR', 'state_name': 'Uttarakhand',
            'source_warning_code': 'official_summary_turnout_only',
            'electors': bajpur['electors'], 'votes_polled': bajpur['votes_polled'],
            'valid_candidate_votes': bajpur['valid_candidate_votes'],
            'summary_totals': {k: bajpur[k] for k in ('electors', 'votes_polled', 'valid_candidate_votes')},
            'summary_page': 75, 'summary_source_file': old['source_file'],
            'summary_source_sha256': old['source_sha256'], 'summary_result': result,
        })
        evidence.append({'code': 59, 'page': 75, **result})
    revised['records'].sort(key=lambda row: row['code'])
    if len(revised['records']) != 70 or [row['code'] for row in revised['records']] != list(range(1, 71)):
        raise ValueError('Uttarakhand 2007 constituency order differs')
    original = {row['code']: row for row in old['records']}
    for row in revised['records']:
        if row['code'] == 59:
            continue
        before = original[row['code']]
        changed = {key for key in set(before) | set(row) if before.get(key) != row.get(key)}
        if changed != ({'error', 'summary_result'} if row['code'] in REVIEW_CODES else set()):
            raise ValueError(f'Unrelated 2007 constituency evidence changed: {row["code"]}')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    return old_body, new_body, {'edition': EDITION, 'year': 2007,
                                'source_url': old['source_url'], 'source_file': old['source_file'],
                                'source_sha256': old['source_sha256'], 'previous_sha256': digest(old_body),
                                'new_sha256': digest(new_body), 'results': evidence,
                                'prior_packages': list(PRIOR_PACKAGES)}


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body, new_body, detail = revised_edition(root)
    old_sha = digest(old_body)
    with tempfile.TemporaryDirectory(prefix='ac-2007-uttarakhand-results-', dir=output.parent) as temporary:
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
            archive.writestr('AUDIT.json', json.dumps({'scope': '9 reviewed 2007 AC results and Bajpur summary-only seat',
                                                      **detail}, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'results': len(detail['results']),
            'previous_sha256': old_sha, 'new_sha256': detail['new_sha256']}


if __name__ == '__main__':
    print(json.dumps(build()))
