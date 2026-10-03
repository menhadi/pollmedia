"""Preserve Mahad's 1962 tied votes and source-declared winner."""

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
EDITION = 'c6fecebc52d31001b62978a5'
NAME = 'pollmedia-ac-maharashtra-1962-mahad-declared-tie-20261004'
PREVIOUS_SHA256 = '3899c4db36ec156e8cccac76c8975f2ce883b6533805c64ee2e16b70d35696d1'
SOURCE_FILE = f'{EDITION}-8744.pdf'
SOURCE_SHA256 = '3587499768cc77889d6613a4b5a61ec5c55dd196cb1dba9bc98137ce898f7a88'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3714-maharashtra-1962/'
WARNING = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.'
NOTE = ('The official summary declares a winner after equal candidate votes; the winning margin is zero. '
        'Check the linked report.')


def sha(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def total(section: str, label: str) -> int:
    match = re.search(r'^\s*' + re.escape(label) + r'\s+.*?\b(\d+)\s*$', section, re.M | re.I)
    if match is None:
        raise ValueError('Mahad official total is missing: ' + label)
    return int(match[1])


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    before_body = (folder / 'extraction.json').read_bytes()
    before = json.loads(before_body)
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    source = folder / SOURCE_FILE
    if (sha(before_body) != PREVIOUS_SHA256 or before['year'] != 1962 or before['kind'] != 'ac'
            or len(before['records']) != 264 or before['source_url'] != SOURCE_URL
            or before['source_file'] != SOURCE_FILE or before['source_sha256'] != SOURCE_SHA256
            or manifest['url'] != SOURCE_URL
            or len([row for row in manifest['files'] if row['file'] == SOURCE_FILE
                    and row['sha256'] == SOURCE_SHA256]) != 1 or sha(source.read_bytes()) != SOURCE_SHA256):
        raise ValueError('Maharashtra 1962 official source differs')
    after = json.loads(before_body)
    record = next(row for row in after['records'] if row['code'] == 43)
    if (record['name'] != 'MAHAD' or record['state_name'] != 'Maharashtra'
            or record['number_of_seats'] != 1 or record['status'] != 'needs_review'
            or record['error'] != WARNING or record.get('summary_page') is not None
            or record['detail_page'] != 289
            or (record['electors'], record['votes_polled'], record['valid_candidate_votes']) != (58162, 36311, 34013)
            or len(record['candidates']) != 5
            or sum(candidate['votes'] for candidate in record['candidates']) != 34013
            or record.get('summary_result') is not None):
        raise ValueError('Mahad prior extraction differs')
    with fitz.open(source) as pdf:
        summary = pdf[60].get_text(sort=True)
        detail = pdf[288].get_text(sort=True)
    heading = re.search(r'^Field7:CONSTITUENCY\s*:\s*43\s*-\s*MAHAD\s*$', summary, re.M | re.I)
    winner = re.search(r'^\s*Winner\s*:\s*(\S+)\s+(.+?)\s+(\d+)\s*$', summary, re.M | re.I)
    runner = re.search(r'^\s*Runner up\s*:\s*(\S+)\s+(.+?)\s+(\d+)\s*$', summary, re.M | re.I)
    margin = re.search(r'^\s*MARGIN\s*:\s*(\d+)\b', summary, re.M | re.I)
    totals = {
        'electors': total(summary[summary.index('II. ELECTORS'):summary.index('III. ELECTORS WHO VOTED')], '3. TOTAL'),
        'votes_polled': total(summary[summary.index('III. ELECTORS WHO VOTED'):summary.index('IV. VOTES')], '3. TOTAL'),
        'valid_candidate_votes': total(summary[summary.index('IV. VOTES'):summary.index('V. POLLING STATIONS')], '2. VALID'),
    }
    candidates = {(candidate['candidate_name'], candidate['party_at_election'], candidate['votes'])
                  for candidate in record['candidates']}
    if (not all((heading, winner, runner, margin))
            or totals != {'electors': 58162, 'votes_polled': 36311, 'valid_candidate_votes': 34013}
            or (winner[2].strip(), winner[1], int(winner[3])) != ('SHANKAR BABAJI SAWANT', 'INC', 12664)
            or (runner[2].strip(), runner[1], int(runner[3])) != ('SAKHARAM VITHOBA SALUNKE', 'PSP', 12664)
            or int(margin[1]) != 0
            or (winner[2].strip(), winner[1], int(winner[3])) not in candidates
            or (runner[2].strip(), runner[1], int(runner[3])) not in candidates
            or re.search(r'Constituency\s+43\s+MAHAD\b', detail, re.I) is None
            or re.search(r'1\s*\.\s*SHANKAR BABAJI SAWANT\s+M\s+INC\s+12664\b', detail, re.I) is None
            or re.search(r'2\s*\.\s*SAKHARAM VITHOBA SALUNKE\s+M\s+PSP\s+12664\b', detail, re.I) is None):
        raise ValueError('Mahad official tied result differs')
    record['original_extraction_warning'] = record['error']
    record['error'] = NOTE
    record['source_warning_code'] = 'official_declared_tie'
    record['official_source_url'] = SOURCE_URL
    record['summary_source_file'] = SOURCE_FILE
    record['summary_source_sha256'] = SOURCE_SHA256
    record['summary_page'] = 61
    record['summary_totals'] = totals
    record['summary_result'] = {'winner': 'SHANKAR BABAJI SAWANT', 'winner_party': 'INC', 'winner_votes': 12664,
                                'runner': 'SAKHARAM VITHOBA SALUNKE', 'runner_party': 'PSP',
                                'runner_votes': 12664, 'margin': 0}
    fields = {'original_extraction_warning', 'error', 'source_warning_code', 'official_source_url',
              'summary_source_file', 'summary_source_sha256', 'summary_page', 'summary_totals', 'summary_result'}
    for old, new in zip(before['records'], after['records']):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        if old['code'] != new['code'] or changed != (fields if old['code'] == 43 else set()):
            raise ValueError(f'Unrelated Maharashtra 1962 row changed: {old["code"]}')
    after_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    audit = {'edition': EDITION, 'code': 43, 'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
             'source_sha256': SOURCE_SHA256, 'previous_sha256': sha(before_body), 'new_sha256': sha(after_body)}
    return before_body, after_body, audit


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    before, after, audit = revised_edition(root)
    with tempfile.TemporaryDirectory(prefix='ac-mahad-1962-', dir=output.parent) as temporary:
        staging = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{PREVIOUS_SHA256}.json'
        revision = f'election-archive/{EDITION}/extraction.json'
        inner = []
        for kind, relative, body in (('snapshot', snapshot, before), ('correction', revision, after)):
            target = staging / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(body)
            bucket = hashlib.sha256(relative.encode()).digest()[0] % 8
            path = packages / f'{kind}-{EDITION}.zip'
            package(staging, path, 'election-archive', bucket, 8, [relative],
                    PREVIOUS_SHA256 if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
            inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as zipped:
            for path in inner:
                zipped.write(path, path.name)
            zipped.writestr('SHA256SUMS', ''.join(f'{sha(path.read_bytes())}  {path.name}\n' for path in inner))
            zipped.writestr('ARCHIVES', EDITION + '\n')
            zipped.writestr('AUDIT.json', json.dumps(audit, indent=2))
            zipped.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    with output.with_suffix('.sha256').open('w', encoding='ascii', newline='\n') as checksum:
        checksum.write(f'{sha(output.read_bytes())}  {output.name}\n')
    return {'bundle': str(output), 'sha256': sha(output.read_bytes()), **audit}


if __name__ == '__main__':
    print(json.dumps(build()))
