"""Show two Tamil Nadu 1971 declarations while withholding impossible turnout."""

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
EDITION = '7a130d7480f6fd17a797d5aa'
NAME = 'pollmedia-ac-tamil-nadu-1971-two-invalid-turnout-results-20261004'
PREVIOUS_SHA256 = '5294d1dfb330585d9e5b3b1077d81cf207cf391cedde69a1585d08ef1c32a64a'
SOURCE_FILE = f'{EDITION}-7685.pdf'
SOURCE_SHA256 = '9c57d16fc1e05bd43aa0896e80fe6fc946960eb115a360b6b8099fcd404a6dcd'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3326-tamil-nadu-1971/'
WARNING = ('Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.; '
           'Reported elector and voter totals are inconsistent.')
NOTE = ('The official report prints more voters than electors; turnout is withheld. '
        'Its declared winner and margin are shown for review.')
# code: (name, summary page, detail page, electors, voters, valid votes, candidate count, margin)
SPECS = {
    152: ('PERAMBALUR (SC)', 166, 267, 55108, 74732, 70623, 4, 15708),
    195: ('ILAYANGUDI', 209, 272, 58857, 75258, 73482, 5, 21413),
}


def sha(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def total(section: str, label: str) -> int:
    match = re.search(r'^\s*' + re.escape(label) + r'\s+.*?\b(\d+)\s*$', section, re.M | re.I)
    if match is None:
        raise ValueError('Tamil Nadu 1971 official total is missing: ' + label)
    return int(match[1])


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    before_body = (folder / 'extraction.json').read_bytes()
    before = json.loads(before_body)
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    source = folder / SOURCE_FILE
    if (sha(before_body) != PREVIOUS_SHA256 or before['year'] != 1971 or before['kind'] != 'ac'
            or len(before['records']) != 234 or before['source_url'] != SOURCE_URL
            or before['source_file'] != SOURCE_FILE or before['source_sha256'] != SOURCE_SHA256
            or manifest['url'] != SOURCE_URL
            or len([row for row in manifest['files'] if row['file'] == SOURCE_FILE
                    and row['sha256'] == SOURCE_SHA256]) != 1 or sha(source.read_bytes()) != SOURCE_SHA256):
        raise ValueError('Tamil Nadu 1971 source edition differs')
    after = json.loads(before_body)
    by_code = {record['code']: record for record in after['records']}
    if len(by_code) != len(after['records']):
        raise ValueError('Tamil Nadu 1971 duplicate constituency code')
    with fitz.open(source) as pdf:
        for code, (name, page, detail_page, electors, voters, valid, count, expected_margin) in SPECS.items():
            record = by_code[code]
            if (record['name'] != name or record['state_name'] != 'Tamil Nadu'
                    or record['number_of_seats'] != 1 or record['status'] != 'needs_review'
                    or record['error'] != WARNING or record.get('summary_page') is not None
                    or record['detail_page'] != detail_page
                    or (record['electors'], record['votes_polled'], record['valid_candidate_votes']) != (electors, voters, valid)
                    or len(record['candidates']) != count
                    or sum(candidate['votes'] for candidate in record['candidates']) != valid
                    or record.get('summary_result') is not None):
                raise ValueError(f'Tamil Nadu 1971 prior row differs: {code}')
            summary = pdf[page - 1].get_text(sort=True)
            detail = pdf[detail_page - 1].get_text(sort=True)
            heading = re.search(r'^Field7:CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)$', summary, re.M | re.I)
            winner = re.search(r'^\s*Winner\s*:\s*(\S+)\s+(.+?)\s+(\d+)\s*$', summary, re.M | re.I)
            runner = re.search(r'^\s*Runner up\s*:\s*(\S+)\s+(.+?)\s+(\d+)\s*$', summary, re.M | re.I)
            margin = re.search(r'^\s*MARGIN\s*:\s*(\d+)\b', summary, re.M | re.I)
            totals = {
                'electors': total(summary[summary.index('II. ELECTORS'):summary.index('III. ELECTORS WHO VOTED')], '3. TOTAL'),
                'votes_polled': total(summary[summary.index('III. ELECTORS WHO VOTED'):summary.index('IV. VOTES')], '3. TOTAL'),
                'valid_candidate_votes': total(summary[summary.index('IV. VOTES'):summary.index('V. POLLING STATIONS')], '2. VALID'),
            }
            ranked = sorted(record['candidates'], key=lambda row: -row['votes'])
            if (not all((heading, winner, runner, margin))
                    or (int(heading[1]), heading[2].strip()) != (code, name)
                    or totals != {'electors': electors, 'votes_polled': voters, 'valid_candidate_votes': valid}
                    or not electors < voters
                    or re.search(r'Constituency\s*:\s*' + str(code) + r'\.\s*' + re.escape(name) + r'(?:\s|$)', detail, re.I) is None
                    or any((source_row[2].strip(), source_row[1], int(source_row[3]))
                           != (ranked[index]['candidate_name'], ranked[index]['party_at_election'], ranked[index]['votes'])
                           for index, source_row in enumerate((winner, runner)))
                    or int(margin[1]) != expected_margin
                    or int(margin[1]) != ranked[0]['votes'] - ranked[1]['votes']):
                raise ValueError(f'Tamil Nadu 1971 declared result differs: {code}')
            record['original_extraction_warning'] = record['error']
            record['error'] = NOTE
            record['source_warning_code'] = 'official_ac_declared_result_invalid_turnout'
            record['official_summary_state'] = 'Tamil Nadu'
            record['official_source_url'] = SOURCE_URL
            record['summary_source_file'] = SOURCE_FILE
            record['summary_source_sha256'] = SOURCE_SHA256
            record['summary_page'] = page
            record['summary_totals'] = totals
            record['summary_result'] = {'winner': ranked[0]['candidate_name'], 'winner_party': ranked[0]['party_at_election'],
                                        'winner_votes': ranked[0]['votes'], 'runner': ranked[1]['candidate_name'],
                                        'runner_party': ranked[1]['party_at_election'], 'runner_votes': ranked[1]['votes'],
                                        'margin': int(margin[1])}
    fields = {'original_extraction_warning', 'error', 'source_warning_code', 'official_summary_state',
              'official_source_url', 'summary_source_file', 'summary_source_sha256', 'summary_page',
              'summary_totals', 'summary_result'}
    for old, new in zip(before['records'], after['records']):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        if old['code'] != new['code'] or changed != (fields if old['code'] in SPECS else set()):
            raise ValueError(f'Unrelated Tamil Nadu 1971 row changed: {old["code"]}')
    after_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    audit = {'edition': EDITION, 'codes': sorted(SPECS), 'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
             'source_sha256': SOURCE_SHA256, 'previous_sha256': sha(before_body), 'new_sha256': sha(after_body)}
    return before_body, after_body, audit


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    before, after, audit = revised_edition(root)
    with tempfile.TemporaryDirectory(prefix='ac-tamil-nadu-1971-', dir=output.parent) as temporary:
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
