"""Show Deganga's 1971 official summary with its detailed-table discrepancy."""

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
EDITION = 'fed20e0bd380929811cd1a1f'
NAME = 'pollmedia-ac-west-bengal-1971-deganga-summary-result-20261004'
PREVIOUS_SHA256 = 'e93c997506d793057cde053095acb9477a09606bfbbf37ae8c92a3f22a8f0676'
SOURCE_FILE = f'{EDITION}-7309.pdf'
SOURCE_SHA256 = '6350616cdca377cb5fe5e77006e098e966e2bdbcdc60a08d25d4b0cae832a305'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3186-west-bengal-general-legislative-election-1971/'
WARNING = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.'
NOTE = ('The official summary reports 46,831 votes polled and declares the winner. The detailed table '
        'prints 47,151 and repeats a 320-vote candidate row; review the linked report.')


def sha(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def total(section: str, label: str) -> int:
    match = re.search(r'^\s*' + re.escape(label) + r'\s+.*?\b(\d+)\s*$', section, re.M | re.I)
    if match is None:
        raise ValueError('Deganga official total is missing: ' + label)
    return int(match[1])


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    before_body = (folder / 'extraction.json').read_bytes()
    before = json.loads(before_body)
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    source = folder / SOURCE_FILE
    if (sha(before_body) != PREVIOUS_SHA256 or before['year'] != 1971 or before['kind'] != 'ac'
            or len(before['records']) != 279 or before['source_url'] != SOURCE_URL
            or before['source_file'] != SOURCE_FILE or before['source_sha256'] != SOURCE_SHA256
            or manifest['url'] != SOURCE_URL
            or len([row for row in manifest['files'] if row['file'] == SOURCE_FILE
                    and row['sha256'] == SOURCE_SHA256]) != 1 or sha(source.read_bytes()) != SOURCE_SHA256):
        raise ValueError('West Bengal 1971 source differs')
    after = json.loads(before_body)
    record = next(row for row in after['records'] if row['code'] == 84)
    if (record['name'] != 'DEGANGA' or record['state_name'] != 'West Bengal'
            or record['number_of_seats'] != 1 or record['status'] != 'needs_review'
            or record['error'] != WARNING or record.get('summary_page') is not None
            or record['detail_page'] != 309
            or (record['electors'], record['votes_polled'], record['valid_candidate_votes']) != (74781, 47151, 43369)
            or len(record['candidates']) != 8
            or sum(candidate['votes'] for candidate in record['candidates']) != 43369
            or record.get('summary_result') is not None):
        raise ValueError('Deganga prior extraction differs')
    with fitz.open(source) as pdf:
        summary = pdf[99].get_text(sort=True)
        detail = pdf[308].get_text(sort=True)
    heading = re.search(r'^Field7:CONSTITUENCY\s*:\s*84\s*-\s*DEGANGA\s*$', summary, re.M | re.I)
    winner = re.search(r'^\s*Winner\s*:\s*(\S+)\s+(.+?)\s+(\d+)\s*$', summary, re.M | re.I)
    runner = re.search(r'^\s*Runner up\s*:\s*(\S+)\s+(.+?)\s+(\d+)\s*$', summary, re.M | re.I)
    margin = re.search(r'^\s*MARGIN\s*:\s*(\d+)\b', summary, re.M | re.I)
    totals = {
        'electors': total(summary[summary.index('II. ELECTORS'):summary.index('III. ELECTORS WHO VOTED')], '3. TOTAL'),
        'votes_polled': total(summary[summary.index('III. ELECTORS WHO VOTED'):summary.index('IV. VOTES')], '3. TOTAL'),
        'valid_candidate_votes': total(summary[summary.index('IV. VOTES'):summary.index('V. POLLING STATIONS')], '2. VALID'),
    }
    ranked = sorted(record['candidates'], key=lambda row: -row['votes'])
    duplicate = [candidate for candidate in record['candidates']
                 if candidate['candidate_name'] == 'SK MD HANIF' and candidate['party_at_election'] == 'IND'
                 and candidate['votes'] == 320]
    if (not all((heading, winner, runner, margin))
            or totals != {'electors': 74781, 'votes_polled': 46831, 'valid_candidate_votes': 43369}
            or len(duplicate) != 2 or record['votes_polled'] - totals['votes_polled'] != 320
            or re.search(r'Constituency\s*:\s*84\s*\.\s*DEGANGA\b', detail, re.I) is None
            or len(re.findall(r'SK MD HANIF\s+M\s+IND\s+320\b', detail, re.I)) != 2
            or any((source_row[2].strip(), source_row[1], int(source_row[3]))
                   != (ranked[index]['candidate_name'], ranked[index]['party_at_election'], ranked[index]['votes'])
                   for index, source_row in enumerate((winner, runner)))
            or int(margin[1]) != 10951 or int(margin[1]) != ranked[0]['votes'] - ranked[1]['votes']):
        raise ValueError('Deganga official source discrepancy differs')
    record['original_extracted_totals'] = {'electors': 74781, 'votes_polled': 47151,
                                           'valid_candidate_votes': 43369}
    record['original_extraction_warning'] = record['error']
    record['votes_polled'] = 46831
    record['error'] = NOTE
    record['source_warning_code'] = 'summary_only_turnout'
    record['source_discrepancy'] = {'field': 'votes_polled', 'detail_value': 47151,
                                    'summary_value': 46831, 'difference': 320}
    record['summary_source_file'] = SOURCE_FILE
    record['summary_source_sha256'] = SOURCE_SHA256
    record['summary_page'] = 100
    record['summary_totals'] = totals
    record['summary_result'] = {'winner': ranked[0]['candidate_name'], 'winner_party': ranked[0]['party_at_election'],
                                'winner_votes': ranked[0]['votes'], 'runner': ranked[1]['candidate_name'],
                                'runner_party': ranked[1]['party_at_election'], 'runner_votes': ranked[1]['votes'],
                                'margin': int(margin[1])}
    fields = {'original_extracted_totals', 'original_extraction_warning', 'votes_polled', 'error',
              'source_warning_code', 'source_discrepancy', 'summary_source_file', 'summary_source_sha256',
              'summary_page', 'summary_totals', 'summary_result'}
    for old, new in zip(before['records'], after['records']):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        if old['code'] != new['code'] or changed != (fields if old['code'] == 84 else set()):
            raise ValueError(f'Unrelated West Bengal 1971 row changed: {old["code"]}')
    after_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    audit = {'edition': EDITION, 'code': 84, 'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
             'source_sha256': SOURCE_SHA256, 'previous_sha256': sha(before_body), 'new_sha256': sha(after_body)}
    return before_body, after_body, audit


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    before, after, audit = revised_edition(root)
    with tempfile.TemporaryDirectory(prefix='ac-deganga-1971-', dir=output.parent) as temporary:
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
