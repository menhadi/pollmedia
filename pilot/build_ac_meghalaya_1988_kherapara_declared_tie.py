"""Preserve Kherapara's tied votes and the official 1988 declaration."""

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
EDITION = '59e71c23b10c39b1a339d4c9'
NAME = 'pollmedia-ac-meghalaya-1988-kherapara-declared-tie-20261004'
OLD_SHA = '30c0f56749cada3dd78b064f6914d7e45a0e1575793d9ae2b2d4874341b4aedc'
SOURCE_FILE = f'{EDITION}-8623.pdf'
SOURCE_SHA = '0db3e9f2e504d3dc1c1599a22a8bbb7a0d82b8a68dc5ecd1b4dd64e6f8b9bd24'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3674-meghalaya-1988/'
WARNING = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.'
NOTE = ('The official summary declares a winner after equal candidate votes; the winning margin is zero. '
        'Check the linked report.')


def sha(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    old_body = (folder / 'extraction.json').read_bytes()
    data = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    source_path = folder / SOURCE_FILE
    if (sha(old_body) != OLD_SHA or data['kind'] != 'ac' or data['year'] != 1988
            or len(data['records']) != 60 or data['source_url'] != SOURCE_URL
            or data['source_file'] != SOURCE_FILE or data['source_sha256'] != SOURCE_SHA
            or manifest['url'] != SOURCE_URL
            or len([file for file in manifest['files'] if file['file'] == SOURCE_FILE
                    and file['sha256'] == SOURCE_SHA]) != 1 or sha(source_path.read_bytes()) != SOURCE_SHA):
        raise ValueError('Official Meghalaya 1988 PDF identity differs')
    revised = json.loads(old_body)
    record = next(row for row in revised['records'] if row['code'] == 54)
    if (record['name'] != 'KHERAPARA (ST)' or record['state_name'] != 'Meghalaya'
            or record['number_of_seats'] != 1 or record['status'] != 'needs_review'
            or record['error'] != WARNING or record['detail_page'] != 79
            or (record['electors'], record['votes_polled'], record['valid_candidate_votes']) != (12209, 8947, 8623)
            or len(record['candidates']) != 4 or sum(candidate['votes'] for candidate in record['candidates']) != 8623
            or record.get('summary_result') is not None):
        raise ValueError('Kherapara prior extraction differs')
    if [(c['candidate_name'], c['party_at_election'], c['votes']) for c in record['candidates'][:2]] != [
            ('CHAMBERUN MARAK', 'IND', 2591), ('ROSTER M. SANGMA', 'INC', 2591)]:
        raise ValueError('Kherapara tied candidates differ')
    with fitz.open(source_path) as pdf:
        summary = pdf[64].get_text(sort=True)
        detail = pdf[78].get_text(sort=True)
    checks = [
        (summary, r'Field7:CONSTITUENCY\s*:\s*54\s*-\s*KHERAPARA \(ST\)'),
        (summary, r'II\. ELECTORS.*?3\. TOTAL\s+5998\s+6211\s+12209\b'),
        (summary, r'III\. ELECTORS WHO VOTED.*?3\. TOTAL\s+4947\s+4000\s+8947\b'),
        (summary, r'IV\. VOTES.*?2\. VALID\s+8623\b'),
        (summary, r'Winner\s*:\s*IND\s+CHAMBERUN MARAK\s+2591\b'),
        (summary, r'Runner up\s*:\s*INC\s+ROSTER M\. SANGMA\s+2591\b'),
        (summary, r'MARGIN\s*:\s*0\b'),
        (detail, r'Constituency\s*:\s*54\s*\.\s*KHERAPARA \(ST\)'),
        (detail, r'1\s*\.\s*CHAMBERUN MARAK\s+M\s+IND\s+2591\b'),
        (detail, r'2\s*\.\s*ROSTER M\. SANGMA\s+M\s+INC\s+2591\b'),
        (detail, r'ELECTORS\s*:\s*12209\s+VOTERS\s*:\s*8947\b.*?VALID VOTES\s*:\s*8623\b'),
    ]
    if any(len(re.findall(pattern, text, re.I | re.S)) != 1 for text, pattern in checks):
        raise ValueError('Kherapara official tied declaration or totals differ')
    record['original_extraction_warning'] = record['error']
    record['error'] = NOTE
    record['source_warning_code'] = 'official_declared_tie'
    record['official_source_url'] = SOURCE_URL
    record['summary_source_file'] = SOURCE_FILE
    record['summary_source_sha256'] = SOURCE_SHA
    record['summary_page'] = 65
    record['summary_totals'] = {'electors': 12209, 'votes_polled': 8947, 'valid_candidate_votes': 8623}
    record['summary_result'] = {'winner': 'CHAMBERUN MARAK', 'winner_party': 'IND', 'winner_votes': 2591,
                                'runner': 'ROSTER M. SANGMA', 'runner_party': 'INC',
                                'runner_votes': 2591, 'margin': 0}
    changed_fields = {'original_extraction_warning', 'error', 'source_warning_code', 'official_source_url',
                      'summary_source_file', 'summary_source_sha256', 'summary_page', 'summary_totals',
                      'summary_result'}
    for before, after in zip(data['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if before['code'] != after['code'] or changed != (changed_fields if before['code'] == 54 else set()):
            raise ValueError('Unrelated Meghalaya 1988 source evidence changed')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    return old_body, new_body, {'edition': EDITION, 'year': 1988, 'code': 54,
                                'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
                                'source_sha256': SOURCE_SHA, 'summary_page': 65,
                                'previous_sha256': sha(old_body), 'new_sha256': sha(new_body),
                                'declared_winner': 'CHAMBERUN MARAK', 'tied_votes': 2591, 'margin': 0}


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / f'{NAME}.zip'
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body, new_body, audit = revised_edition(root)
    with tempfile.TemporaryDirectory(prefix='ac-1988-kherapara-', dir=output.parent) as temporary:
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
            archive.writestr('AUDIT.json', json.dumps({'scope': 'Kherapara 1988 tied vote and source-declared winner', **audit}, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    bundle_sha = sha(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{bundle_sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': bundle_sha, **audit}


if __name__ == '__main__':
    print(json.dumps(build()))
