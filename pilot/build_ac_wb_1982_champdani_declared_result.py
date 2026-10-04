"""Show Champdani's declared 1982 result while withholding impossible turnout."""

import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from audit_pc_ac_zero_values import correction_index, effective_body
from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
EDITION = '9982b63a332a67579dae045f'
NAME = 'pollmedia-ac-west-bengal-1982-champdani-declared-result-20261004'
PRIOR_PACKAGE = 'pollmedia-ac-west-bengal-1982-onda-summary-result-20261003.zip'
PRIOR_SHA = '0600d8a800b04d1ce5855eb3e7475461ee703986244d68dbd4aa52ab4ede6e8d'
SOURCE_FILE = f'{EDITION}-7315.pdf'
SOURCE_SHA = 'd7aa7423d5d0e2c252d758df89b7b0274f2303689bfc67d44a4e64732d0d81f3'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3189-west-bengal-1982/'
WARNING = ('Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.; '
           'Reported elector and voter totals are inconsistent.')
NOTE = ('The official report prints more voters than electors; turnout is withheld. '
        'Its declared winner and margin are shown for review.')


def sha(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    revisions = correction_index(root, (PRIOR_PACKAGE,))
    if len(revisions.get(EDITION, [])) != 1:
        raise ValueError('Prior Onda 1982 revision missing')
    old_body = effective_body(folder / 'extraction.json', revisions[EDITION])
    if sha(old_body) != PRIOR_SHA:
        raise ValueError('Prior West Bengal 1982 revision differs')
    data = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    source_path = folder / SOURCE_FILE
    if (data['kind'] != 'ac' or data['year'] != 1982 or len(data['records']) != 294
            or data['source_url'] != SOURCE_URL or data['source_file'] != SOURCE_FILE
            or data['source_sha256'] != SOURCE_SHA or manifest['url'] != SOURCE_URL
            or len([file for file in manifest['files'] if file['file'] == SOURCE_FILE
                    and file['sha256'] == SOURCE_SHA]) != 1 or sha(source_path.read_bytes()) != SOURCE_SHA):
        raise ValueError('Official West Bengal 1982 source differs')
    revised = json.loads(old_body)
    record = next(row for row in revised['records'] if row['code'] == 181)
    if (record['name'] != 'CHAMPDANI' or record['state_name'] != 'West Bengal'
            or record['number_of_seats'] != 1 or record['status'] != 'needs_review'
            or record['error'] != WARNING or record['detail_page'] != 335
            or (record['electors'], record['votes_polled'], record['valid_candidate_votes']) != (87335, 91850, 89899)
            or len(record['candidates']) != 4 or sum(c['votes'] for c in record['candidates']) != 89899
            or record.get('summary_result') is not None):
        raise ValueError('Champdani incomplete record differs')
    ranked = sorted(record['candidates'], key=lambda row: -row['votes'])
    if [(r['candidate_name'], r['party_at_election'], r['votes']) for r in ranked[:2]] != [
            ('SAILENDRA NATH CHATTOPADHYAY', 'CPM', 47301),
            ('SWARAJ MUKHOPADHYAY', 'INC', 40682)]:
        raise ValueError('Champdani candidate ranking differs')
    with fitz.open(source_path) as pdf:
        summary = pdf[196].get_text(sort=True)
        detail = pdf[334].get_text(sort=True)
    checks = [
        (summary, r'Field7:CONSTITUENCY\s*:\s*181\s*-\s*CHAMPDANI\b'),
        (summary, r'II\. ELECTORS.*?3\. TOTAL\s+46035\s+41300\s+87335\b'),
        (summary, r'III\. ELECTORS WHO VOTED.*?3\. TOTAL\s+51494\s+40356\s+91850\b'),
        (summary, r'IV\. VOTES.*?2\. VALID\s+89899\b'),
        (summary, r'Winner\s*:\s*CPM\s+SAILENDRA NATH CHATTOPADHYAY\s+47301\b'),
        (summary, r'Runner up\s*:\s*INC\s+SWARAJ MUKHOPADHYAY\s+40682\b'),
        (summary, r'MARGIN\s*:\s*6619\b'),
        (detail, r'Constituency\s*:\s*181\s*\.\s*CHAMPDANI\b'),
        (detail, r'1\s*\.\s*SAILENDRA NATH CHATTOPADHYAY\s+M\s+CPM\s+47301\b'),
        (detail, r'2\s*\.\s*SWARAJ MUKHOPADHYAY\s+M\s+INC\s+40682\b'),
        (detail, r'ELECTORS\s*:\s*87335\s+VOTERS\s*:\s*91850\b.*?VALID VOTES\s*:\s*89899\b'),
    ]
    if any(len(re.findall(pattern, text, re.I | re.S)) != 1 for text, pattern in checks):
        raise ValueError('Champdani official PDF declaration or totals differ')
    record['original_extraction_warning'] = record['error']
    record['error'] = NOTE
    record['source_warning_code'] = 'official_ac_declared_result_invalid_turnout'
    record['official_summary_state'] = 'West Bengal'
    record['official_source_url'] = SOURCE_URL
    record['summary_source_file'] = SOURCE_FILE
    record['summary_source_sha256'] = SOURCE_SHA
    record['summary_page'] = 197
    record['summary_totals'] = {'electors': 87335, 'votes_polled': 91850, 'valid_candidate_votes': 89899}
    record['summary_result'] = {'winner': ranked[0]['candidate_name'], 'winner_party': ranked[0]['party_at_election'],
                                'winner_votes': ranked[0]['votes'], 'runner': ranked[1]['candidate_name'],
                                'runner_party': ranked[1]['party_at_election'], 'runner_votes': ranked[1]['votes'],
                                'margin': 6619}
    changed_fields = {'original_extraction_warning', 'error', 'source_warning_code', 'official_summary_state',
                      'official_source_url', 'summary_source_file', 'summary_source_sha256', 'summary_page',
                      'summary_totals', 'summary_result'}
    for before, after in zip(json.loads(old_body)['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if before['code'] != after['code'] or changed != (changed_fields if before['code'] == 181 else set()):
            raise ValueError('Unrelated 1982 West Bengal row changed')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    return old_body, new_body, {'edition': EDITION, 'year': 1982, 'code': 181, 'source_url': SOURCE_URL,
                                'source_file': SOURCE_FILE, 'source_sha256': SOURCE_SHA, 'summary_page': 197,
                                'previous_sha256': sha(old_body), 'new_sha256': sha(new_body),
                                'prior_package': PRIOR_PACKAGE, 'winner': ranked[0]['candidate_name'],
                                'margin': 6619}


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / f'{NAME}.zip'
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body, new_body, audit = revised_edition(root)
    with tempfile.TemporaryDirectory(prefix='ac-1982-champdani-', dir=output.parent) as temporary:
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
            archive.writestr('AUDIT.json', json.dumps({'scope': 'Champdani 1982 declared result; invalid turnout withheld', **audit}, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    bundle_sha = sha(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{bundle_sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': bundle_sha, **audit}


if __name__ == '__main__':
    print(json.dumps(build()))
