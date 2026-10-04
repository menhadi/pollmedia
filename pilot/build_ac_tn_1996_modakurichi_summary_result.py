"""Show Modakurichi's official 1996 result while retaining 1,033 candidate rows."""

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
EDITION = '82e8650b7962c1d4c78305f3'
NAME = 'pollmedia-ac-tamil-nadu-1996-modakurichi-summary-result-20261004'
OLD_SHA = 'b29b5d955f4a5da1c9e645f9847911012b1da4ad33d6a8beab672f95c46541cd'
SOURCE_FILE = f'{EDITION}-7708.pdf'
SOURCE_SHA = 'be3d98d39a829445a6ee69cd5841c6e97dedda9a2bfe2f0b1293fd73bf6fff76'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3336-tamil-nadu-1996/'
WARNING = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.'
NOTE = ('Official summary confirms turnout, winner and margin. The detailed candidate rows sum five votes '
        'above its printed valid-vote total; both source values are retained for review.')


def sha(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    old_body = (folder / 'extraction.json').read_bytes()
    data = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    source_path = folder / SOURCE_FILE
    if (sha(old_body) != OLD_SHA or data['kind'] != 'ac' or data['year'] != 1996
            or len(data['records']) != 234 or data['source_url'] != SOURCE_URL
            or data['source_file'] != SOURCE_FILE or data['source_sha256'] != SOURCE_SHA
            or manifest['url'] != SOURCE_URL
            or len([item for item in manifest['files'] if item['file'] == SOURCE_FILE
                    and item['sha256'] == SOURCE_SHA]) != 1 or sha(source_path.read_bytes()) != SOURCE_SHA):
        raise ValueError('Official Tamil Nadu 1996 PDF identity differs')
    summaries = read_summary_pages(source_path)
    if len(summaries) != 234:
        raise ValueError('Official Tamil Nadu 1996 summary coverage differs')
    revised = json.loads(old_body)
    record = next(row for row in revised['records'] if row['code'] == 118)
    summary = summaries[118]
    candidate_sum = sum(candidate['votes'] for candidate in record['candidates'])
    if (record['name'] != 'MODAKURICHI' or record['state_name'] != 'Tamil Nadu'
            or record['number_of_seats'] != 1 or record['status'] != 'needs_review'
            or record['error'] != WARNING or record['detail_page'] != 308
            or len(record['candidates']) != 1033 or record.get('summary_result') is not None
            or (record['electors'], record['votes_polled'], record['valid_candidate_votes']) != (194579, 118286, 117215)
            or (summary['name'], summary['electors'], summary['votes_polled'],
                summary['valid_candidate_votes'], summary['summary_page']) != ('MODAKURICHI', 194579, 118286, 117210, 143)
            or candidate_sum != summary['valid_candidate_votes'] + 5):
        raise ValueError('Modakurichi detailed and summary totals differ from audited values')
    with fitz.open(source_path) as pdf:
        text = pdf[142].get_text(sort=True)
        detail = pdf[307].get_text(sort=True)
    winner = re.search(r'^\s*Winner\s*:\s*(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
    runner = re.search(r'^\s*Runner up\s*:\s*(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
    margin = re.search(r'^\s*MARGIN\s*:\s*(\d+)\b', text, re.I | re.M)
    ranked = sorted(record['candidates'], key=lambda candidate: -candidate['votes'])
    if (not all((winner, runner, margin))
            or re.search(r'Field7:CONSTITUENCY\s*:\s*118\s*-\s*MODAKURICHI\b', text, re.I) is None
            or re.search(r'Constituency\s*:\s*118\s*\.\s*MODAKURICHI\b', detail, re.I) is None
            or (winner[2].strip(), winner[1], int(winner[3])) != ('SUBBULAKSHMI JEGADEESAN', 'DMK', 64436)
            or (runner[2].strip(), runner[1], int(runner[3])) != ('KITTUSAMY, R.N.', 'ADMK', 24896)
            or int(margin[1]) != 39540
            or [(ranked[i]['candidate_name'], ranked[i]['party_at_election'], ranked[i]['votes']) for i in range(2)] != [
                ('SUBBULAKSHMI JEGADEESAN', 'DMK', 64436), ('KITTUSAMY, R.N.', 'ADMK', 24896)]):
        raise ValueError('Modakurichi official declaration differs')
    record['original_extraction_warning'] = record['error']
    record['error'] = NOTE
    record['source_warning_code'] = 'official_summary_turnout_only'
    record['summary_page'] = 143
    record['summary_totals'] = {'electors': 194579, 'votes_polled': 118286, 'valid_candidate_votes': 117210}
    record['summary_result'] = {'winner': 'SUBBULAKSHMI JEGADEESAN', 'winner_party': 'DMK',
                                'winner_votes': 64436, 'runner': 'KITTUSAMY, R.N.',
                                'runner_party': 'ADMK', 'runner_votes': 24896, 'margin': 39540}
    record['summary_source_file'] = SOURCE_FILE
    record['summary_source_sha256'] = SOURCE_SHA
    record['candidate_source_discrepancy'] = {'candidate_sum': candidate_sum,
                                               'printed_valid_votes': 117210, 'difference': 5}
    changed_fields = {'original_extraction_warning', 'error', 'source_warning_code', 'summary_page',
                      'summary_totals', 'summary_result', 'summary_source_file', 'summary_source_sha256',
                      'candidate_source_discrepancy'}
    for before, after in zip(data['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if before['code'] != after['code'] or changed != (changed_fields if before['code'] == 118 else set()):
            raise ValueError('Unrelated Tamil Nadu 1996 source evidence changed')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    return old_body, new_body, {'edition': EDITION, 'year': 1996, 'code': 118,
                                'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
                                'source_sha256': SOURCE_SHA, 'summary_page': 143,
                                'candidate_count': 1033, 'candidate_difference': 5,
                                'previous_sha256': sha(old_body), 'new_sha256': sha(new_body),
                                'winner': 'SUBBULAKSHMI JEGADEESAN', 'margin': 39540}


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / f'{NAME}.zip'
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body, new_body, audit = revised_edition(root)
    with tempfile.TemporaryDirectory(prefix='ac-1996-modakurichi-', dir=output.parent) as temporary:
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
            archive.writestr('AUDIT.json', json.dumps({'scope': 'Modakurichi 1996 official result with all 1033 candidate rows retained', **audit}, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    bundle_sha = sha(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{bundle_sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': bundle_sha, **audit}


if __name__ == '__main__':
    print(json.dumps(build()))
