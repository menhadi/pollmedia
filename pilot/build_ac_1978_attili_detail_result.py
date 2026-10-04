"""Restore Attili's missing 1978 candidate from the retained official detailed PDF."""

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
EDITION = '5ceb07b4b18fc2ea5dcb6112'
NAME = 'pollmedia-ac-andhra-1978-attili-detail-result-20261004'
OLD_SHA = '8cc93408bf7587b0706cd4d8a57212ac44df8cbc055221998c98a21327fe3414'
SOURCE_SHA = 'd9c006a204df72781b7c0271282c8946e4b105d156709ce4ccf81fda29e6a3ed'
SOURCE_FILE = f'{EDITION}-9598.pdf'
OLD_WARNING = ('Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.; '
               'Some candidate text could not be parsed; see the original PDF.; '
               'Candidate serial numbers are incomplete or duplicated.; '
               'Extracted candidate votes do not match the reported valid votes.')
REVIEW_NOTE = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.'


def sha(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    old_body = (folder / 'extraction.json').read_bytes()
    if sha(old_body) != OLD_SHA:
        raise ValueError('1978 extraction checksum differs')
    data = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    source_path = folder / SOURCE_FILE
    if (data['kind'] != 'ac' or data['year'] != 1978 or data['source_file'] != SOURCE_FILE
            or data['source_url'] != manifest['url'] or data['source_sha256'] != SOURCE_SHA
            or len(manifest['files']) != 1 or manifest['files'][0]['sha256'] != SOURCE_SHA
            or sha(source_path.read_bytes()) != SOURCE_SHA):
        raise ValueError('Official 1978 PDF identity differs')
    record = next(row for row in data['records'] if row['code'] == 66)
    if (record['name'] != 'ATTILI' or record['number_of_seats'] != 1
            or record['status'] != 'needs_review' or record['error'] != OLD_WARNING
            or record['detail_page'] != 322 or record['electors'] != 103978
            or record['votes_polled'] != 80517 or record['valid_candidate_votes'] != 79215
            or [(r['candidate_name'], r['party_at_election'], r['votes'], r['source_row'])
                for r in record['candidates']] != [
                    ('INDUKURI RAMAKRISHANAM RAJU', 'INC(I)', 32541, 1),
                    ('GUDIMETLA VARAHALA REDDI', 'JNP', 23037, 3)]):
        raise ValueError('Attili record differs from audited incomplete extraction')

    with fitz.open(source_path) as pdf:
        page = pdf[record['detail_page'] - 1].get_text(sort=True)
    block = re.search(r'Constituency\s*:\s*66\s*\.\s*ATTILI\b(.*?)Constituency\s*:\s*67\s*\.',
                      page, re.S)
    if not block:
        raise ValueError('Attili source block missing')
    detail = block.group(1)
    for serial, name, party, votes in [
        (1, 'INDUKURI RAMAKRISHANAM RAJU', 'INC(I)', 32541),
        (2, 'VEGESBA KANKA DURGAVENKATA SATYANARAYANA RAJU', 'INC', 23637),
        (3, 'GUDIMETLA VARAHALA REDDI', 'JNP', 23037),
    ]:
        pattern = rf'(?m)^\s*{serial}\s*\.\s*{re.escape(name)}\s+M\s+{re.escape(party)}\s+{votes}\s+'
        if len(re.findall(pattern, detail)) != 1:
            raise ValueError(f'Attili candidate {serial} differs in official PDF')
    if len(re.findall(r'(?m)^\s*\d+\s*\.', detail)) != 3:
        raise ValueError('Unexpected Attili candidate rows')
    if len(re.findall(r'ELECTORS\s*:\s*103978\s+VOTERS\s*:\s*80517\b.*?VALID VOTES\s*:\s*79215\b', detail)) != 1:
        raise ValueError('Attili official totals differ')

    revised = json.loads(old_body)
    fixed = next(row for row in revised['records'] if row['code'] == 66)
    missing = {'candidate_name': 'VEGESBA KANKA DURGAVENKATA SATYANARAYANA RAJU',
               'sex': 'M', 'party_at_election': 'INC', 'votes': 23637,
               'reported_vote_percent': 29.84, 'source_row': 2,
               'general_votes': None, 'postal_votes': None}
    fixed['candidates'].insert(1, missing)
    if sum(candidate['votes'] for candidate in fixed['candidates']) != fixed['valid_candidate_votes']:
        raise ValueError('Official candidate votes do not reconcile')
    fixed['original_extraction_warning'] = OLD_WARNING
    fixed['error'] = REVIEW_NOTE
    fixed['corrected_candidate_source_file'] = SOURCE_FILE
    fixed['corrected_candidate_source_sha256'] = SOURCE_SHA
    fixed['corrected_candidate_source_page'] = 322
    for before, after in zip(data['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if before['code'] != after['code'] or changed != (
                {'candidates', 'original_extraction_warning', 'error',
                 'corrected_candidate_source_file', 'corrected_candidate_source_sha256',
                 'corrected_candidate_source_page'} if before['code'] == 66 else set()):
            raise ValueError('Unrelated 1978 source evidence changed')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    return old_body, new_body, {'edition': EDITION, 'year': 1978, 'code': 66,
                                'source_url': data['source_url'], 'source_file': SOURCE_FILE,
                                'source_sha256': SOURCE_SHA, 'source_page': 322,
                                'previous_sha256': sha(old_body), 'new_sha256': sha(new_body),
                                'winner': fixed['candidates'][0]['candidate_name'],
                                'runner': missing['candidate_name'], 'margin': 32541 - 23637}


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / f'{NAME}.zip'
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body, new_body, audit = revised_edition(root)
    with tempfile.TemporaryDirectory(prefix='ac-1978-attili-', dir=output.parent) as temporary:
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
            archive.writestr('AUDIT.json', json.dumps({'scope': 'Attili 1978 missing second candidate restored from official PDF; original extraction archived', **audit}, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    bundle_sha = sha(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{bundle_sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': bundle_sha, **audit}


if __name__ == '__main__':
    print(json.dumps(build()))
