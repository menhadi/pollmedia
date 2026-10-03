"""Keep Hata's impossible 1957 turnout blank but show its official declared result."""

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
EDITION = 'd31cb3f180e44ec4b9e59209'
NAME = 'pollmedia-ac-1957-hata-declared-result-20261004'
SOURCE_FILE = f'{EDITION}-7464.pdf'
SOURCE_SHA256 = '7e0ea66649df0c20d8e381018ce33e049bc16c139c3facf09b04d935019da98c'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3242-uttar-pradesh-1957/'
PREVIOUS_SHA256 = 'd64b93f486ff1ad0580082b633b094c4647c22cf05cc7458768e9d815fa1b7e7'
CODE = 234
NOTE = ('The official report prints more voters than electors; turnout is withheld. '
        'Its declared winner and margin are shown for review.')


def sha(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def norm(value: str) -> str:
    return re.sub(r'[^a-z0-9]', '', value.casefold())


def reported_total(section: str) -> int:
    row = re.search(r'^\s*1\. TOTAL\s+.*?\b(\d+)\s*$', section, re.M | re.I)
    if row is None:
        raise ValueError('Hata official summary total is missing')
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
            or len(before['records']) != 341
            or len([row for row in manifest['files'] if row['file'] == SOURCE_FILE and row['sha256'] == SOURCE_SHA256]) != 1
            or sha(source.read_bytes()) != SOURCE_SHA256):
        raise ValueError('Hata official source identity differs')
    after = json.loads(before_body)
    record = next(row for row in after['records'] if row['code'] == CODE)
    if (record['name'] != 'HATA' or record['number_of_seats'] != 1 or record['status'] != 'needs_review'
            or record['error'] != 'Electorate and voter totals are inconsistent'
            or record['summary_page'] != 256 or record['detail_page'] != 406
            or record['summary_totals'] != {'electors': 7807, 'votes_polled': 30962, 'valid_candidate_votes': 30962}
            or (record['electors'], record['votes_polled'], record['valid_candidate_votes']) != (7807, 30962, 30962)
            or len(record['candidates']) != 4 or sum(row['votes'] for row in record['candidates']) != 30962
            or record.get('winner') is not None or record.get('margin') is not None):
        raise ValueError('Hata original table differs')
    with fitz.open(source) as pdf:
        summary = pdf[255].get_text(sort=True)
        detail = pdf[405].get_text(sort=True)
    heading = re.search(r'Field7:CONSTITUENCY\s*:\s*234\s*-\s*HATA\s+NUMBER OF SEATS\s*:\s*1\b', summary, re.I)
    detail_heading = re.search(r'Constituency\s+234\s+HATA\s+NUMBER OF SEATS\s+1\b', detail, re.I)
    electors = reported_total(summary[summary.index('II. ELECTORS'):summary.index('III. ELECTORS WHO VOTED')])
    voters = reported_total(summary[summary.index('III. ELECTORS WHO VOTED'):summary.index('IV. VOTES')])
    valid = re.search(r'^\s*2\. VALID\s+(\d+)', summary, re.M | re.I)
    winner = re.search(r'^\s*Winner\s+(\S+)\s+(.+?)\s+(\d+)\s*$', summary, re.M | re.I)
    runner = re.search(r'^\s*Runner up\s+(\S+)\s+(.+?)\s+(\d+)\s*$', summary, re.M | re.I)
    margin = re.search(r'^\s*MARGIN\s*:\s*(\d+)\b', summary, re.M | re.I)
    ranked = sorted(record['candidates'], key=lambda row: -row['votes'])
    if (not all((heading, detail_heading, valid, winner, runner, margin))
            or (electors, voters, int(valid[1])) != (7807, 30962, 30962)
            or int(margin[1]) != ranked[0]['votes'] - ranked[1]['votes']
            or any(norm(source_row[2]) != norm(ranked[index]['candidate_name'])
                   or source_row[1] != ranked[index]['party_at_election']
                   or int(source_row[3]) != ranked[index]['votes']
                   for index, source_row in enumerate((winner, runner)))):
        raise ValueError('Hata official declared result differs')
    record['original_extraction_warning'] = record['error']
    record['error'] = NOTE
    record['source_warning_code'] = 'official_ac_declared_result_invalid_turnout'
    record['official_summary_state'] = 'Uttar Pradesh'
    record['official_source_url'] = SOURCE_URL
    record['summary_source_file'] = SOURCE_FILE
    record['summary_source_sha256'] = SOURCE_SHA256
    record['summary_result'] = {'winner': ranked[0]['candidate_name'], 'winner_party': ranked[0]['party_at_election'],
                                'winner_votes': ranked[0]['votes'], 'runner': ranked[1]['candidate_name'],
                                'runner_party': ranked[1]['party_at_election'], 'runner_votes': ranked[1]['votes'],
                                'margin': int(margin[1])}
    expected = {'original_extraction_warning', 'error', 'source_warning_code', 'official_summary_state',
                'official_source_url', 'summary_source_file', 'summary_source_sha256', 'summary_result'}
    for old, new in zip(before['records'], after['records']):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        if old['code'] != new['code'] or changed != (expected if old['code'] == CODE else set()):
            raise ValueError(f'Unrelated 1957 Uttar Pradesh extraction changed: {old["code"]}')
    after_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    audit = {'scope': 'Hata 1957 AC official declared result with invalid turnout withheld',
             'edition': EDITION, 'code': CODE, 'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
             'source_sha256': SOURCE_SHA256, 'previous_sha256': sha(before_body), 'new_sha256': sha(after_body)}
    return before_body, after_body, audit


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    before, after, audit = revised_edition(root)
    with tempfile.TemporaryDirectory(prefix='ac-hata-1957-', dir=output.parent) as temporary:
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
