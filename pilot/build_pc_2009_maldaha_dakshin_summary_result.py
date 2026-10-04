"""Expose the official 2009 Maldaha Dakshin result despite conflicting totals."""

import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
EDITION = 'e5346f9160ad32fb68a34578'
NAME = 'pollmedia-pc-2009-maldaha-dakshin-summary-result-20261004'
OLD_SHA = 'dbf599b4be85e2cdc21bdfe6e361b207fe9ea4cae90f9562d28e5fbd9aef3863'
SUMMARY_FILE = 'e73b9b69acde3f3d048bb45d-6634.pdf'
SUMMARY_SHA = 'f12b58ceb4be0aa5fa98353ddf8718b5625cb61c98ca6a26654318ab3a246761'
DETAIL_FILE = '5556fc48df7b5645106ff974-6640.pdf'
DETAIL_SHA = '0689cff95684502c78b00a751184fc08a74fda9dd8ea33aef2aeb87ba20ae6a2'
SOURCE_URL = 'https://old.eci.gov.in/files/category/98-general-election-2009/'
NOTE = ('Official summary confirms 829,482 voters, winner and margin. Its 829,385 valid votes '
        'conflict with 829,486 votes in the detailed candidate table; see the official files.')
RESULT = {'winner': 'ABU HASEM KHAN CHOUDHURY', 'winner_party': 'INC', 'winner_votes': 443377,
          'runner': 'ABDUR RAZZAQUE', 'runner_party': 'CPM', 'runner_votes': 307097,
          'margin': 136280}


def sha(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    old_body = (folder / 'extraction.json').read_bytes()
    if sha(old_body) != OLD_SHA:
        raise ValueError('Maldaha Dakshin extraction bytes differ')
    data = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    if (data['kind'] != 'pc' or data['year'] != 2009 or data['source_url'] != SOURCE_URL
            or manifest['url'] != SOURCE_URL or len(data['records']) != 543):
        raise ValueError('2009 PC source identity differs')
    for filename, expected in ((SUMMARY_FILE, SUMMARY_SHA), (DETAIL_FILE, DETAIL_SHA)):
        if (sha((folder / filename).read_bytes()) != expected
                or len([entry for entry in manifest['files']
                        if entry['file'] == filename and entry['sha256'] == expected]) != 1):
            raise ValueError(f'Official {filename} differs')

    record = next(row for row in data['records'] if row['code'] == 466)
    ranked = sorted(record['candidates'], key=lambda candidate: -candidate['votes'])
    if (record['name'] != 'West Bengal / Maldaha Dakshin'
            or record['state_name'] != 'West Bengal' or record['official_pc_code'] != 8
            or record['number_of_seats'] != 1 or record['status'] != 'needs_review'
            or record['error'] != 'Summary and detailed totals differ'
            or record['summary_page'] != 466 or record['detail_page'] != 172
            or record['electors'] != 1052093 or record['votes_polled'] != 829482
            or record['valid_candidate_votes'] != 829486
            or record['summary_totals'] != {'electors': 1052093, 'votes_polled': 829482,
                                            'valid_candidate_votes': 829385}
            or len(record['candidates']) != 9
            or sum(candidate['votes'] for candidate in record['candidates']) != 829486
            or [(candidate['candidate_name'], candidate['party_at_election'], candidate['votes'])
                for candidate in ranked[:2]] != [
                    ('ABU HASEM KHAN CHOUDHURY', 'INC', 443377),
                    ('ABDUR RAZZAQUE', 'CPM', 307097)]
            or record.get('summary_result') is not None
            or record.get('source_warning_code') is not None):
        raise ValueError('Maldaha Dakshin extracted record differs')

    with fitz.open(folder / SUMMARY_FILE) as report:
        summary = report[465].get_text(sort=True)
    with fitz.open(folder / DETAIL_FILE) as report:
        detail = report[171].get_text(sort=True)
    evidence = [
        r'Constituency\s*:Maldaha Dakshin',
        r'No\.\s*:8',
        r'3\. TOTAL\s+545742\s+506351\s+1052093',
        r'4\. TOTAL\s+829482',
        r'3\. TOTAL VALID VOTES POLLED\s+829385',
        r'WINNER\s+INC\s+Abu Hasem Khan Choudhury\s+443377',
        r'RUNER-UP\s+CPM\s+Abdur Razzaque\s+307097',
        r'MARGIN\s+136280',
    ]
    if (any(re.search(pattern, summary, re.I) is None for pattern in evidence)
            or re.search(r'CONSTITUENCY\s*:\s*8\s*\.\s*Maldaha Dakshin', detail, re.I) is None
            or re.search(r'TOTAL:\s+827562\s+1924\s+829486', detail) is None):
        raise ValueError('2009 official summary and detail evidence differ')

    revised = json.loads(old_body)
    target = next(row for row in revised['records'] if row['code'] == 466)
    target['previous_review_note'] = target['error']
    target['error'] = NOTE
    target['source_warning_code'] = 'official_summary_turnout_only'
    target['summary_source_file'] = SUMMARY_FILE
    target['summary_source_sha256'] = SUMMARY_SHA
    target['summary_result'] = RESULT
    fields = {'previous_review_note', 'error', 'source_warning_code', 'summary_source_file',
              'summary_source_sha256', 'summary_result'}
    for before, after in zip(data['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if before['code'] != after['code'] or changed != (fields if before['code'] == 466 else set()):
            raise ValueError('Unrelated PC evidence changed')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    return old_body, new_body, {'edition': EDITION, 'year': 2009, 'code': 466,
                                'source_url': SOURCE_URL, 'summary_source_file': SUMMARY_FILE,
                                'summary_source_sha256': SUMMARY_SHA, 'summary_page': 466,
                                'detail_source_file': DETAIL_FILE, 'detail_source_sha256': DETAIL_SHA,
                                'detail_page': 172, 'candidate_count': 9,
                                'previous_sha256': sha(old_body), 'new_sha256': sha(new_body),
                                'winner': RESULT['winner'], 'margin': RESULT['margin']}


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / f'{NAME}.zip'
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body, new_body, audit = revised_edition(root)
    with tempfile.TemporaryDirectory(prefix='pc-2009-maldaha-', dir=output.parent) as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        inner = []
        for kind, relative, body in (
                ('snapshot', f'election-archive/{EDITION}/extraction-{audit["previous_sha256"]}.json', old_body),
                ('correction', f'election-archive/{EDITION}/extraction.json', new_body)):
            target = staged / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(body)
            bucket = hashlib.sha256(relative.encode('utf-8')).digest()[0] % 8
            path = packages / f'{kind}-{EDITION}.zip'
            package(staged, path, 'election-archive', bucket, 8, [relative],
                    audit['previous_sha256'] if kind == 'correction' else None,
                    f'election-archive/{EDITION}/extraction-{audit["previous_sha256"]}.json'
                    if kind == 'correction' else None)
            inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as archive:
            for path in inner:
                archive.write(path, path.name)
            archive.writestr('SHA256SUMS', ''.join(f'{sha(path.read_bytes())}  {path.name}\n' for path in inner))
            archive.writestr('ARCHIVES', EDITION + '\n')
            archive.writestr('AUDIT.json', json.dumps({'scope': 'Maldaha Dakshin 2009 official PC summary result', **audit}, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    bundle_sha = sha(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{bundle_sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': bundle_sha, **audit}


if __name__ == '__main__':
    print(json.dumps(build()))
