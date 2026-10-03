"""Show Sattenpalli's declared 1955 winner without impossible turnout."""

import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_ac_bombay_1951_four_single_seat_results import HEADING, MARGIN, RUNNER, WINNER, printed_total
from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
EDITION = '165392d9f968ef073166ef32'
NAME = 'pollmedia-ac-andhra-1955-sattenpalli-invalid-turnout-result-20261004'
PREVIOUS_SHA256 = '728038dc08b89a76b56a8d8a00f3fb88a284a0309145dc4162b66a2520631919'
SOURCE_FILE = f'{EDITION}-9588.pdf'
SOURCE_SHA256 = 'b087d7c7f4391e0a6c9b60cbd4cda3c1562d70a29e5b1a85c4723caa18b22255'
SOURCE_URL = 'https://old.eci.gov.in/files/file/4042-andhra-pradesh-1955/'
WARNING = ('Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.; '
           'Reported elector and voter totals are inconsistent.')
NOTE = ('The official report prints more voters than electors; turnout is withheld. '
        'Its declared winner and margin are shown for review.')


def sha(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    before_body = (folder / 'extraction.json').read_bytes()
    before = json.loads(before_body)
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    source = folder / SOURCE_FILE
    if (sha(before_body) != PREVIOUS_SHA256 or before['kind'] != 'ac' or before['year'] != 1955
            or len(before['records']) != 167 or before['source_url'] != SOURCE_URL
            or before['source_file'] != SOURCE_FILE or before['source_sha256'] != SOURCE_SHA256
            or manifest['url'] != SOURCE_URL
            or len([row for row in manifest['files'] if row['file'] == SOURCE_FILE
                    and row['sha256'] == SOURCE_SHA256]) != 1 or sha(source.read_bytes()) != SOURCE_SHA256):
        raise ValueError('Andhra Pradesh 1955 official source differs')
    after = json.loads(before_body)
    record = next(row for row in after['records'] if row['code'] == 96)
    if (record['name'] != 'SATTENPALLI' or record['state_name'] != 'Andhra Pradesh'
            or record['number_of_seats'] != 1 or record['status'] != 'needs_review'
            or record['error'] != WARNING or record.get('summary_page') is not None
            or record['detail_page'] != 194
            or (record['electors'], record['votes_polled'], record['valid_candidate_votes']) != (2473, 40566, 40566)
            or len(record['candidates']) != 3
            or sum(candidate['votes'] for candidate in record['candidates']) != 40566
            or record.get('summary_result') is not None):
        raise ValueError('Sattenpalli prior extraction differs')
    with fitz.open(source) as pdf:
        summary = pdf[108].get_text(sort=True)
        detail = pdf[193].get_text(sort=True)
    heading = HEADING.search(summary)
    winner, runner, margin = WINNER.search(summary), RUNNER.search(summary), MARGIN.search(summary)
    totals = {
        'electors': printed_total(summary[summary.index('II. ELECTORS'):summary.index('III. ELECTORS WHO VOTED')], '1. TOTAL'),
        'votes_polled': printed_total(summary[summary.index('III. ELECTORS WHO VOTED'):summary.index('IV. VOTES')], '1. TOTAL'),
        'valid_candidate_votes': printed_total(summary[summary.index('IV. VOTES'):summary.index('VI. DATES')], '2. VALID'),
    }
    ranked = sorted(record['candidates'], key=lambda row: -row['votes'])
    if (not all((heading, winner, runner, margin))
            or (int(heading[1]), heading[2].strip(), int(heading[3])) != (96, 'SATTENPALLI', 1)
            or totals != {'electors': 2473, 'votes_polled': 40566, 'valid_candidate_votes': 40566}
            or not (totals['electors'] < totals['votes_polled'])
            or re.search(r'Constituency\s*:\s*96\s+SATTENPALLI\s+NUMBER OF SEATS\s+1\b', detail, re.I) is None
            or any((source_row[2].strip(), source_row[1], int(source_row[3]))
                   != (ranked[index]['candidate_name'], ranked[index]['party_at_election'], ranked[index]['votes'])
                   for index, source_row in enumerate((winner, runner)))
            or int(margin[1]) != 875 or int(margin[1]) != ranked[0]['votes'] - ranked[1]['votes']):
        raise ValueError('Sattenpalli official declared result differs')
    record['original_extraction_warning'] = record['error']
    record['error'] = NOTE
    record['source_warning_code'] = 'official_ac_declared_result_invalid_turnout'
    record['official_summary_state'] = 'Andhra Pradesh'
    record['official_source_url'] = SOURCE_URL
    record['summary_source_file'] = SOURCE_FILE
    record['summary_source_sha256'] = SOURCE_SHA256
    record['summary_page'] = 109
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
        if old['code'] != new['code'] or changed != (fields if old['code'] == 96 else set()):
            raise ValueError(f'Unrelated Andhra Pradesh 1955 row changed: {old["code"]}')
    after_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    audit = {'edition': EDITION, 'code': 96, 'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
             'source_sha256': SOURCE_SHA256, 'previous_sha256': sha(before_body), 'new_sha256': sha(after_body)}
    return before_body, after_body, audit


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    before, after, audit = revised_edition(root)
    with tempfile.TemporaryDirectory(prefix='ac-andhra-1955-', dir=output.parent) as temporary:
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
