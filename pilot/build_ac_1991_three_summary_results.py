"""Show three 1991 AC results from their archived official constituency summaries."""

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
NAME = 'pollmedia-ac-1991-three-summary-results-20261004'
SOURCES = {
    'f0d9e36a60bcef19312cabc4': {
        'prior_packages': ('pollmedia-pc-ac-zero-turnout-corrections-20261001-v3.zip',
                           'pollmedia-election-corrections-20261003-resume-v1.zip'),
        'prior_sha': 'c07dee55ea799b667e7b23604af9596bc3162cf74c022231f0ac9a3ad8cf9f15',
        'source_file': 'f0d9e36a60bcef19312cabc4-7706.pdf',
        'source_sha': 'b0e737e80ed399ec5c87263bedd3f1dfc1078adc259360eebc1b9292142b8cf8',
        'records': {131: ('PERIYAKULAM', 'M. PERIYAVEERAN', 42042, 0)},
    },
    'f3a3490e9bee0775959176ef': {
        'prior_packages': ('pollmedia-pc-ac-zero-turnout-corrections-20261001-v3.zip',),
        'prior_sha': '2d9b3ce79b19c076e917f7cc06a0f9364a5fe5368963832de49c7278c0d6c175',
        'source_file': 'f3a3490e9bee0775959176ef-7499.pdf',
        'source_sha': 'b6d15c357e034fb02df40be2a79676c3936dc37f6f2f7ea1e7d4d685511d8db6',
        'records': {351: ('FIROZABAD', 'RAM KISHAN DADAJU', 11615, 12),
                    352: ('BAH', 'ARIDAMAN SINGH', 7193, 0)},
    },
}


def sha(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def source_result(text: str, code: int) -> dict:
    rows = re.findall(r'^\s*(Winner|Runner up)\s*:\s*(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
    margin = re.search(r'^\s*MARGIN\s*:\s*(\d+)\b', text, re.I | re.M)
    if len(rows) != 2 or not margin or [row[0].lower() for row in rows] != ['winner', 'runner up']:
        raise ValueError(f'Official 1991 result lines missing: {code}')
    result = {'winner': rows[0][2].strip(), 'winner_party': rows[0][1], 'winner_votes': int(rows[0][3]),
              'runner': rows[1][2].strip(), 'runner_party': rows[1][1], 'runner_votes': int(rows[1][3]),
              'margin': int(margin[1])}
    if result['winner_votes'] <= result['runner_votes'] \
            or result['winner_votes'] - result['runner_votes'] != result['margin']:
        raise ValueError(f'Official 1991 result arithmetic differs: {code}')
    return result


def revised_editions(root: Path = ROOT) -> list[tuple[str, bytes, bytes, dict]]:
    revisions = {edition: correction_index(root, source['prior_packages'])
                 for edition, source in SOURCES.items()}
    editions = []
    for edition, source in SOURCES.items():
        folder = root / 'application/storage/app/private/election-archive' / edition
        if len(revisions[edition].get(edition, [])) != len(source['prior_packages']):
            raise ValueError(f'Prior 1991 revisions missing: {edition}')
        old_body = effective_body(folder / 'extraction.json', revisions[edition][edition])
        data = json.loads(old_body)
        manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
        source_path = folder / source['source_file']
        if (sha(old_body) != source['prior_sha'] or data['kind'] != 'ac' or data['year'] != 1991
                or data['source_url'] != manifest['url'] or data['source_file'] != source['source_file']
                or data['source_sha256'] != source['source_sha']
                or len([item for item in manifest['files'] if item['file'] == source['source_file']
                        and item['sha256'] == source['source_sha']]) != 1
                or sha(source_path.read_bytes()) != source['source_sha']):
            raise ValueError(f'Official 1991 source identity differs: {edition}')
        summaries = read_summary_pages(source_path)
        revised = json.loads(old_body)
        audit_rows = []
        with fitz.open(source_path) as pdf:
            for record in revised['records']:
                code = record['code']
                if code not in source['records']:
                    continue
                name, winner, margin, delta = source['records'][code]
                summary = summaries[code]
                candidate_sum = sum(candidate['votes'] for candidate in record['candidates'])
                if (record['name'] != name or summary['name'] != name
                        or record['number_of_seats'] != 1 or record['status'] != 'needs_review'
                        or record['source_warning_code'] != 'official_summary_turnout_only'
                        or record['summary_page'] != summary['summary_page']
                        or record['summary_totals'] != {key: summary[key] for key in ('electors', 'votes_polled', 'valid_candidate_votes')}
                        or record['electors'] != summary['electors'] or record['votes_polled'] != summary['votes_polled']
                        or summary['valid_candidate_votes'] - candidate_sum != delta
                        or record.get('summary_result') is not None):
                    raise ValueError(f'Archived 1991 seat differs: {edition}/{code}')
                text = pdf[summary['summary_page'] - 1].get_text(sort=True)
                if re.search(rf'Field7:CONSTITUENCY\s*:\s*{code}\s*-\s*{re.escape(name)}\b', text, re.I) is None:
                    raise ValueError(f'Official 1991 summary heading differs: {edition}/{code}')
                result = source_result(text, code)
                ranked = sorted(record['candidates'], key=lambda candidate: -candidate['votes'])
                if (result['winner'] != winner or result['margin'] != margin or len(ranked) < 2
                        or [(ranked[i]['candidate_name'], ranked[i]['party_at_election'], ranked[i]['votes'])
                            for i in range(2)] != [
                                (result['winner'], result['winner_party'], result['winner_votes']),
                                (result['runner'], result['runner_party'], result['runner_votes'])]):
                    raise ValueError(f'Official 1991 top candidates differ: {edition}/{code}')
                record['previous_review_note'] = record['error']
                record['error'] = ('Official summary confirms turnout, winner and margin. '
                                   + (f'Detailed candidate rows sum {delta} votes below the printed valid total; '
                                      if delta else 'Detailed candidate rows remain available for review; ')
                                   + 'see the linked report.')
                record['summary_result'] = result
                if delta:
                    record['candidate_source_discrepancy'] = {'candidate_sum': candidate_sum,
                                                               'printed_valid_votes': summary['valid_candidate_votes'],
                                                               'difference': delta}
                audit_rows.append({'code': code, 'name': name, 'summary_page': summary['summary_page'],
                                   'candidate_difference': delta, 'result': result})
        if {row['code'] for row in audit_rows} != set(source['records']):
            raise ValueError(f'1991 result inventory differs: {edition}')
        allowed = {'previous_review_note', 'error', 'summary_result', 'candidate_source_discrepancy'}
        for before, after in zip(data['records'], revised['records']):
            changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
            if before['code'] != after['code'] or (before['code'] in source['records'] and not changed <= allowed) \
                    or (before['code'] not in source['records'] and changed):
                raise ValueError(f'Unrelated 1991 source evidence changed: {edition}/{before["code"]}')
        new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
        audit = {'edition': edition, 'year': 1991, 'source_url': data['source_url'],
                 'source_file': source['source_file'], 'source_sha256': source['source_sha'],
                 'prior_packages': source['prior_packages'], 'previous_sha256': sha(old_body),
                 'new_sha256': sha(new_body), 'results': audit_rows}
        editions.append((edition, old_body, new_body, audit))
    return editions


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / f'{NAME}.zip'
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    editions = revised_editions(root)
    with tempfile.TemporaryDirectory(prefix='ac-1991-three-results-', dir=output.parent) as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        inner = []
        for edition, old_body, new_body, audit in editions:
            snapshot = f'election-archive/{edition}/extraction-{audit["previous_sha256"]}.json'
            revision = f'election-archive/{edition}/extraction.json'
            for kind, relative, body in (('snapshot', snapshot, old_body), ('correction', revision, new_body)):
                target = staged / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(body)
                bucket = hashlib.sha256(relative.encode('utf-8')).digest()[0] % 8
                path = packages / f'{kind}-{edition}.zip'
                package(staged, path, 'election-archive', bucket, 8, [relative],
                        audit['previous_sha256'] if kind == 'correction' else None,
                        snapshot if kind == 'correction' else None)
                inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as archive:
            for path in inner:
                archive.write(path, path.name)
            archive.writestr('SHA256SUMS', ''.join(f'{sha(path.read_bytes())}  {path.name}\n' for path in inner))
            archive.writestr('ARCHIVES', ''.join(edition + '\n' for edition, *_ in editions))
            archive.writestr('AUDIT.json', json.dumps({'scope': 'Three official 1991 AC declarations',
                                                      'editions': [row[3] for row in editions]}, indent=2))
            archive.writestr('IMPORT.sh', import_script([row[0] for row in editions]))
        partial.replace(output)
    bundle_sha = sha(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{bundle_sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': bundle_sha, 'editions': len(editions),
            'results': sum(len(row[3]['results']) for row in editions)}


if __name__ == '__main__':
    print(json.dumps(build()))
