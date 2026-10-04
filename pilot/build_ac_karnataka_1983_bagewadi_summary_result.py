"""Show Bagewadi's declared 1983 result while retaining incomplete detail rows."""

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


ROOT = Path(__file__).resolve().parents[1]
EDITION = '650699d0f10c4acd449cd5a9'
NAME = 'pollmedia-ac-karnataka-1983-bagewadi-summary-result-20261004'
OLD_SHA = '271613ebf07bb02eb90f8e93b53f7df0cc5898a5ea31348af2c89257beb87bc6'
SOURCE_FILE = f'{EDITION}-8896.pdf'
SOURCE_SHA = '8c2ff4522636e799bdca763069353d2d0333bc91a3e3f49750cfda35bb92387d'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3777-karnataka-1983/'
WARNING = ('Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.; '
           'Some candidate text could not be parsed; see the original PDF.; Candidate serial numbers are incomplete or duplicated.; '
           'Extracted candidate votes do not match the reported valid votes.')
NOTE = ('Official summary confirms turnout, winner and margin. Detailed candidate rows are short by 2,000 votes '
        'and remain preserved for review.')


def sha(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    old_body = (folder / 'extraction.json').read_bytes()
    data = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    source_path = folder / SOURCE_FILE
    if (sha(old_body) != OLD_SHA or data['kind'] != 'ac' or data['year'] != 1983
            or len(data['records']) != 224 or data['source_url'] != SOURCE_URL
            or data['source_file'] != SOURCE_FILE or data['source_sha256'] != SOURCE_SHA
            or manifest['url'] != SOURCE_URL
            or len([item for item in manifest['files'] if item['file'] == SOURCE_FILE
                    and item['sha256'] == SOURCE_SHA]) != 1 or sha(source_path.read_bytes()) != SOURCE_SHA):
        raise ValueError('Official Karnataka 1983 source identity differs')
    summaries = read_summary_pages(source_path)
    if len(summaries) != 224:
        raise ValueError('Karnataka 1983 summary coverage differs')
    revised = json.loads(old_body)
    record = next(row for row in revised['records'] if row['code'] == 199)
    summary = summaries[199]
    candidate_sum = sum(candidate['votes'] for candidate in record['candidates'])
    with fitz.open(source_path) as pdf:
        text = pdf[214].get_text(sort=True)
        detail = pdf[274].get_text(sort=True)
    winner = re.search(r'^\s*Winner\s*:\s*(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
    runner = re.search(r'^\s*Runner up\s*:\s*(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
    margin = re.search(r'^\s*MARGIN\s*:\s*(\d+)\b', text, re.I | re.M)
    ranked = sorted(record['candidates'], key=lambda candidate: -candidate['votes'])
    if (record['name'] != 'BAGEWADI' or record['state_name'] != 'Karnataka'
            or record['status'] != 'needs_review' or record['error'] != WARNING
            or record['number_of_seats'] != 1 or record['detail_page'] != 275
            or len(record['candidates']) != 6 or record.get('summary_result') is not None
            or (record['electors'], record['votes_polled'], record['valid_candidate_votes']) != (82091, 58898, 57340)
            or (summary['name'], summary['electors'], summary['votes_polled'],
                summary['valid_candidate_votes'], summary['summary_page']) != ('BAGEWADI', 82091, 58898, 57340, 215)
            or candidate_sum != 55340
            or re.search(r'Field7:CONSTITUENCY\s*:\s*199\s*-\s*BAGEWADI\b', text, re.I) is None
            or 'BAGEWADI' not in detail.upper() or not all((winner, runner, margin))
            or (winner[2].strip(), winner[1], int(winner[3])) != ('ASJTEKAR GOVIND LAXMAN', 'IND', 21333)
            or (runner[2].strip(), runner[1], int(runner[3])) != ('PATIL NINGANAGOUDA BASANAGOUDA', 'JNP', 16981)
            or int(margin[1]) != 4352
            or [(ranked[i]['candidate_name'], ranked[i]['party_at_election'], ranked[i]['votes']) for i in range(2)] != [
                ('ASJTEKAR GOVIND LAXMAN', 'IND', 21333), ('PATIL NINGANAGOUDA BASANAGOUDA', 'JNP', 16981)]):
        raise ValueError('Bagewadi 1983 source evidence differs')
    record['original_extraction_warning'] = record['error']
    record['error'] = NOTE
    record['source_warning_code'] = 'official_summary_turnout_only'
    record['summary_page'] = 215
    record['summary_totals'] = {'electors': 82091, 'votes_polled': 58898, 'valid_candidate_votes': 57340}
    record['summary_result'] = {'winner': 'ASJTEKAR GOVIND LAXMAN', 'winner_party': 'IND',
                                'winner_votes': 21333, 'runner': 'PATIL NINGANAGOUDA BASANAGOUDA',
                                'runner_party': 'JNP', 'runner_votes': 16981, 'margin': 4352}
    record['summary_source_file'] = SOURCE_FILE
    record['summary_source_sha256'] = SOURCE_SHA
    record['candidate_source_discrepancy'] = {'candidate_sum': candidate_sum,
                                               'printed_valid_votes': 57340, 'difference': -2000}
    changed_fields = {'original_extraction_warning', 'error', 'source_warning_code', 'summary_page',
                      'summary_totals', 'summary_result', 'summary_source_file', 'summary_source_sha256',
                      'candidate_source_discrepancy'}
    for before, after in zip(data['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if before['code'] != after['code'] or changed != (changed_fields if before['code'] == 199 else set()):
            raise ValueError('Unrelated Karnataka 1983 evidence changed')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    return old_body, new_body, {'edition': EDITION, 'year': 1983, 'code': 199,
                                'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
                                'source_sha256': SOURCE_SHA, 'summary_page': 215,
                                'candidate_count': 6, 'candidate_difference': -2000,
                                'previous_sha256': sha(old_body), 'new_sha256': sha(new_body),
                                'winner': 'ASJTEKAR GOVIND LAXMAN', 'margin': 4352}


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / f'{NAME}.zip'
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body, new_body, audit = revised_edition(root)
    with tempfile.TemporaryDirectory(prefix='ac-1983-bagewadi-', dir=output.parent) as temporary:
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
            archive.writestr('AUDIT.json', json.dumps({'scope': 'Bagewadi 1983 official summary result with incomplete candidate detail retained', **audit}, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    bundle_sha = sha(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{bundle_sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': bundle_sha, **audit}


if __name__ == '__main__':
    print(json.dumps(build()))
