"""Show the source-declared Sausar 1957 winner while withholding impossible turnout."""

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
EDITION = '6dfd6b3caf24c34e288769cf'
NAME = 'pollmedia-ac-1957-sausar-declared-result-20261004'
SOURCE_FILE = f'{EDITION}-8772.pdf'
SOURCE_SHA256 = '195cd97b8e6b172ebc043127587148960526803a0009670887c4cb13536dfdd2'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3728-madhya-pradesh-1957/'
PREVIOUS_SHA256 = 'c8852e48c8f57785ad50eadcb0bd5c75e14dd73245dacd0b39d164eba1b2d112'
CODE = 116
WARNING = ('Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.; '
           'Reported elector and voter totals are inconsistent.')
NOTE = ('The official report prints more voters than electors; turnout is withheld. '
        'Its declared winner and margin are shown for review.')


def sha(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def norm(value: str) -> str:
    return re.sub(r'[^a-z0-9]', '', value.casefold())


def total(section: str) -> int:
    row = re.search(r'^\s*1\. TOTAL\s+.*?\b(\d+)\s*$', section, re.M | re.I)
    if row is None:
        raise ValueError('Sausar official summary total is missing')
    return int(row[1])


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    before_body = (folder / 'extraction.json').read_bytes()
    before = json.loads(before_body)
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    source = folder / SOURCE_FILE
    if (sha(before_body) != PREVIOUS_SHA256 or before['kind'] != 'ac' or before['year'] != 1957
            or before['source_url'] != SOURCE_URL or before['source_file'] != SOURCE_FILE
            or before['source_sha256'] != SOURCE_SHA256 or manifest['url'] != SOURCE_URL
            or len([row for row in manifest['files'] if row['file'] == SOURCE_FILE and row['sha256'] == SOURCE_SHA256]) != 1
            or sha(source.read_bytes()) != SOURCE_SHA256):
        raise ValueError('Sausar official source identity differs')
    after = json.loads(before_body)
    record = next(row for row in after['records'] if row['code'] == CODE)
    if (record['name'] != 'SAUSAR (ST)' or record['number_of_seats'] != 1
            or record['status'] != 'needs_review' or record['error'] != WARNING
            or record.get('summary_page') is not None or record['detail_page'] != 256
            or (record['electors'], record['votes_polled'], record['valid_candidate_votes']) != (52018, 96630, 96630)
            or len(record['candidates']) != 6 or sum(row['votes'] for row in record['candidates']) != 96630
            or record.get('winner') is not None or record.get('margin') is not None):
        raise ValueError('Sausar original table differs')
    with fitz.open(source) as pdf:
        summary = pdf[133].get_text(sort=True)
        detail = pdf[255].get_text(sort=True)
        successful = pdf[8].get_text(sort=True)
    heading = re.search(r'Field7:CONSTITUENCY\s*:\s*116\s*-\s*SAUSAR \(ST\)\s+NUMBER OF SEATS\s*:\s*1\b', summary, re.I)
    detail_heading = re.search(r'Constituency\s+116\s+SAUSAR \(ST\)\s+NUMBER OF SEATS\s+1\b', detail, re.I)
    listed_winner = re.search(r'116\.\s+SAUSAR \(ST\)\s+RAICHANDBHAI NARSIBHAI\s+INC\b', successful, re.I)
    electors = total(summary[summary.index('II. ELECTORS'):summary.index('III. ELECTORS WHO VOTED')])
    voters = total(summary[summary.index('III. ELECTORS WHO VOTED'):summary.index('IV. VOTES')])
    valid = re.search(r'^\s*2\. VALID\s+(\d+)', summary, re.M | re.I)
    winner = re.search(r'^\s*Winner\s+(\S+)\s+(.+?)\s+(\d+)\s*$', summary, re.M | re.I)
    runner = re.search(r'^\s*Runner up\s+(\S+)\s+(.+?)\s+(\d+)\s*$', summary, re.M | re.I)
    margin = re.search(r'^\s*MARGIN\s*:\s*(\d+)\b', summary, re.M | re.I)
    ranked = sorted(record['candidates'], key=lambda row: -row['votes'])
    if (not all((heading, detail_heading, listed_winner, valid, winner, runner, margin))
            or (electors, voters, int(valid[1])) != (52018, 96630, 96630)
            or int(margin[1]) != ranked[0]['votes'] - ranked[1]['votes']
            or any(norm(source_row[2]) != norm(ranked[index]['candidate_name'])
                   or source_row[1] != ranked[index]['party_at_election']
                   or int(source_row[3]) != ranked[index]['votes']
                   for index, source_row in enumerate((winner, runner)))):
        raise ValueError('Sausar official declared result differs')
    record['original_extraction_warning'] = record['error']
    record['error'] = NOTE
    record['source_warning_code'] = 'official_ac_declared_result_invalid_turnout'
    record['official_summary_state'] = 'Madhya Pradesh'
    record['official_source_url'] = SOURCE_URL
    record['summary_source_file'] = SOURCE_FILE
    record['summary_source_sha256'] = SOURCE_SHA256
    record['summary_page'] = 134
    record['summary_totals'] = {'electors': 52018, 'votes_polled': 96630, 'valid_candidate_votes': 96630}
    record['summary_result'] = {'winner': ranked[0]['candidate_name'], 'winner_party': ranked[0]['party_at_election'],
                                'winner_votes': ranked[0]['votes'], 'runner': ranked[1]['candidate_name'],
                                'runner_party': ranked[1]['party_at_election'], 'runner_votes': ranked[1]['votes'],
                                'margin': int(margin[1])}
    expected = {'original_extraction_warning', 'error', 'source_warning_code', 'official_summary_state',
                'official_source_url', 'summary_source_file', 'summary_source_sha256', 'summary_page',
                'summary_totals', 'summary_result'}
    for old, new in zip(before['records'], after['records']):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        if old['code'] != new['code'] or changed != (expected if old['code'] == CODE else set()):
            raise ValueError(f'Unrelated 1957 Madhya Pradesh extraction changed: {old["code"]}')
    after_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    audit = {'scope': 'Sausar 1957 AC official declared result with invalid turnout withheld',
             'edition': EDITION, 'code': CODE, 'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
             'source_sha256': SOURCE_SHA256, 'previous_sha256': sha(before_body), 'new_sha256': sha(after_body)}
    return before_body, after_body, audit


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    before, after, audit = revised_edition(root)
    with tempfile.TemporaryDirectory(prefix='ac-sausar-1957-', dir=output.parent) as temporary:
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
