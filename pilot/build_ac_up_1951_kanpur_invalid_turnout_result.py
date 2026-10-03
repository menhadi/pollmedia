"""Show Kanpur City North's 1951 declaration while withholding invalid turnout."""

import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_ac_bombay_1951_four_single_seat_results import HEADING, MARGIN, RUNNER, WINNER, printed_total
from build_ac_up_1951_residual_results_and_two_seat import revised_edition as prior_revision
from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
EDITION = '402db61ff727c908b4ac3170'
NAME = 'pollmedia-ac-up-1951-kanpur-invalid-turnout-result-20261004'
PRIOR_SHA256 = '65f82af0c0b6e23a83b492f1ab837e945b7af40588ce066deabca90f416b782f'
SOURCE_SHA256 = '3c2014c43fcc0c5c4636c84fd43cf6d7a68d967736e3f60d44924a7f641bfdb7'
SOURCE_FILE = '402db61ff727c908b4ac3170-7462.pdf'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3241-uttar-pradesh-1951/'
NOTE = ('The official report prints more voters than electors; turnout is withheld. '
        'Its declared winner and margin are shown for review.')


def sha(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    _, before_body, _ = prior_revision(root)
    if sha(before_body) != PRIOR_SHA256:
        raise ValueError('Uttar Pradesh 1951 prior result bundle differs')
    before = json.loads(before_body)
    after = json.loads(before_body)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    source = folder / SOURCE_FILE
    if (before['source_url'] != SOURCE_URL or before['source_file'] != SOURCE_FILE
            or before['source_sha256'] != SOURCE_SHA256 or sha(source.read_bytes()) != SOURCE_SHA256):
        raise ValueError('Uttar Pradesh 1951 official source differs')
    record = next(row for row in after['records'] if row['code'] == 130)
    if (record['name'] != 'KANPUR CITY NORTH' or record['number_of_seats'] != 1
            or record['status'] != 'needs_review'
            or record['error'] != 'Electorate and voter totals are inconsistent'
            or record['summary_page'] != 150 or record['detail_page'] != 393
            or (record['electors'], record['votes_polled'], record['valid_candidate_votes']) != (5064, 28326, 28326)
            or len(record['candidates']) != 13
            or sum(row['votes'] for row in record['candidates']) != 28326
            or record.get('source_warning_code') is not None or record.get('summary_result') is not None):
        raise ValueError('Kanpur City North prior row differs')
    with fitz.open(source) as pdf:
        summary = pdf[149].get_text(sort=True)
        detail = pdf[392].get_text(sort=True)
    heading = HEADING.search(summary)
    winner, runner, margin = WINNER.search(summary), RUNNER.search(summary), MARGIN.search(summary)
    totals = {
        'electors': printed_total(summary[summary.index('II. ELECTORS'):summary.index('III. ELECTORS WHO VOTED')], '1. TOTAL'),
        'votes_polled': printed_total(summary[summary.index('III. ELECTORS WHO VOTED'):summary.index('IV. VOTES')], '1. TOTAL'),
        'valid_candidate_votes': printed_total(summary[summary.index('IV. VOTES'):summary.index('VI. DATES')], '2. VALID'),
    }
    ranked = sorted(record['candidates'], key=lambda row: -row['votes'])
    if (not all((heading, winner, runner, margin)) or (int(heading[1]), heading[2].strip(), int(heading[3]))
            != (130, record['name'], 1)
            or totals != {'electors': 5064, 'votes_polled': 28326, 'valid_candidate_votes': 28326}
            or not (totals['electors'] < totals['votes_polled'])
            or re.search(r'Constituency\s+130\s+KANPUR CITY NORTH\s+NUMBER OF SEATS\s+1\b', detail, re.I) is None
            or any((source_row[2].strip(), source_row[1], int(source_row[3]))
                   != (ranked[index]['candidate_name'], ranked[index]['party_at_election'], ranked[index]['votes'])
                   for index, source_row in enumerate((winner, runner)))
            or int(margin[1]) != ranked[0]['votes'] - ranked[1]['votes']):
        raise ValueError('Kanpur City North official declaration differs')
    record['original_extraction_warning'] = record['error']
    record['error'] = NOTE
    record['source_warning_code'] = 'official_ac_declared_result_invalid_turnout'
    record['official_summary_state'] = 'Uttar Pradesh'
    record['official_source_url'] = SOURCE_URL
    record['summary_source_file'] = SOURCE_FILE
    record['summary_source_sha256'] = SOURCE_SHA256
    record['summary_totals'] = totals
    record['summary_result'] = {'winner': ranked[0]['candidate_name'], 'winner_party': ranked[0]['party_at_election'],
                                'winner_votes': ranked[0]['votes'], 'runner': ranked[1]['candidate_name'],
                                'runner_party': ranked[1]['party_at_election'], 'runner_votes': ranked[1]['votes'],
                                'margin': int(margin[1])}
    fields = {'original_extraction_warning', 'error', 'source_warning_code', 'official_summary_state',
              'official_source_url', 'summary_source_file', 'summary_source_sha256', 'summary_result'}
    for old, new in zip(before['records'], after['records']):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        if old['code'] != new['code'] or changed != (fields if old['code'] == 130 else set()):
            raise ValueError(f'Unrelated Uttar Pradesh 1951 extraction changed: {old["code"]}')
    after_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    audit = {'edition': EDITION, 'code': 130, 'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
             'source_sha256': SOURCE_SHA256, 'previous_sha256': sha(before_body), 'new_sha256': sha(after_body)}
    return before_body, after_body, audit


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    before, after, audit = revised_edition(root)
    with tempfile.TemporaryDirectory(prefix='ac-up-1951-kanpur-', dir=output.parent) as temporary:
        staging = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{PRIOR_SHA256}.json'
        revision = f'election-archive/{EDITION}/extraction.json'
        inner = []
        for kind, relative, body in (('snapshot', snapshot, before), ('correction', revision, after)):
            target = staging / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(body)
            bucket = hashlib.sha256(relative.encode()).digest()[0] % 8
            path = packages / f'{kind}-{EDITION}.zip'
            package(staging, path, 'election-archive', bucket, 8, [relative],
                    PRIOR_SHA256 if kind == 'correction' else None,
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
