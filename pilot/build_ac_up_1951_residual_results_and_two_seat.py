"""Recover three UP 1951 declarations and four historical two-seat identities."""

import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from audit_pc_ac_zero_values import correction_index, effective_body
from build_ac_bombay_1951_four_single_seat_results import HEADING, norm, printed_total, summary_result
from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
EDITION = '402db61ff727c908b4ac3170'
NAME = 'pollmedia-ac-up-1951-three-results-four-two-seat-20261004'
PRIOR_SHA256 = 'ee25ee226bffa57687cd34475f5a3d4c6ce7c029d93dff1ff164714fae9160dd'
SOURCE_SHA256 = '3c2014c43fcc0c5c4636c84fd43cf6d7a68d967736e3f60d44924a7f641bfdb7'
SOURCE_FILE = '402db61ff727c908b4ac3170-7462.pdf'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3241-uttar-pradesh-1951/'
PRIOR_BUNDLE = 'pollmedia-ac-up-1951-28-truncated-summary-results-20261004.zip'
EARLIER = tuple(f'pollmedia-ac-residual-turnout-corrections-20261001-v{i}.zip' for i in range(1, 5))
RESULT_CODES = (53, 105, 341)
TWO_SEAT_CODES = (143, 146, 207, 210)
WINNER = re.compile(r'^\s*Winner\s+[12]\s+(\S+)\s+(.+?)\s+(\d+)\s*$', re.M | re.I)


def sha(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    revisions = correction_index(root, EARLIER + (PRIOR_BUNDLE,))
    if len(revisions.get(EDITION, [])) != 5:
        raise ValueError('Uttar Pradesh 1951 prior revision chain differs')
    before_body = effective_body(folder / 'extraction.json', revisions[EDITION])
    before = json.loads(before_body)
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    source = folder / SOURCE_FILE
    if (sha(before_body) != PRIOR_SHA256 or before['year'] != 1951 or before['kind'] != 'ac'
            or len(before['records']) != 347 or before['source_url'] != SOURCE_URL
            or before['source_file'] != SOURCE_FILE or before['source_sha256'] != SOURCE_SHA256
            or manifest['url'] != SOURCE_URL
            or len([row for row in manifest['files'] if row['file'] == SOURCE_FILE
                    and row['sha256'] == SOURCE_SHA256]) != 1 or sha(source.read_bytes()) != SOURCE_SHA256):
        raise ValueError('Uttar Pradesh 1951 source edition differs')
    after = json.loads(before_body)
    by_code = {record['code']: record for record in after['records']}
    if len(by_code) != len(after['records']):
        raise ValueError('Uttar Pradesh 1951 duplicate constituency codes')
    with fitz.open(source) as pdf:
        for code in RESULT_CODES + TWO_SEAT_CODES:
            record = by_code[code]
            page = code + 20
            text = pdf[page - 1].get_text(sort=True)
            heading = HEADING.search(text)
            if (heading is None or int(heading[1]) != code
                    or len(norm(heading[2])) < 15
                    or not norm(record['name']).startswith(norm(heading[2]))
                    or record['number_of_seats'] != 1 or record['status'] != 'needs_review'
                    or record['source_warning_code'] != 'official_turnout_from_residual_source'
                    or record['error'] != ('Official source prints the constituency turnout total; '
                                           'previous candidate/source warnings remain available for review.')
                    or record['summary_page'] != page or record['detail_page'] <= page
                    or record.get('valid_candidate_votes') is not None
                    or record.get('summary_result') is not None
                    or record['turnout_source_sha256'] != SOURCE_SHA256
                    or record['turnout_source_page'] != page):
                raise ValueError(f'Uttar Pradesh 1951 prior row differs: {code}')
            totals = {
                'electors': printed_total(text[text.index('II. ELECTORS'):text.index('III. ELECTORS WHO VOTED')], '1. TOTAL'),
                'votes_polled': printed_total(text[text.index('III. ELECTORS WHO VOTED'):text.index('IV. VOTES')], '1. TOTAL'),
                'valid_candidate_votes': printed_total(text[text.index('IV. VOTES'):text.index('VI. DATES')], '2. VALID'),
            }
            if ((totals['electors'], totals['votes_polled']) != (record['electors'], record['votes_polled'])
                    or totals['valid_candidate_votes'] != sum(candidate['votes'] for candidate in record['candidates'])):
                raise ValueError(f'Uttar Pradesh 1951 official totals differ: {code}')
            record['previous_review_note'] = record['error']
            record['previous_source_warning_code'] = record['source_warning_code']
            record['valid_candidate_votes'] = totals['valid_candidate_votes']
            record['summary_source_file'] = SOURCE_FILE
            record['summary_source_sha256'] = SOURCE_SHA256
            record['summary_totals'] = totals
            if code in RESULT_CODES:
                name, parsed_totals, result = summary_result(text, code)
                ranked = sorted(record['candidates'], key=lambda row: -row['votes'])
                if (int(heading[3]) != 1 or norm(name) != norm(heading[2]) or parsed_totals != totals
                        or len(ranked) < 2
                        or any((result[key], result[key + '_party'], result[key + '_votes'])
                               != (ranked[index]['candidate_name'], ranked[index]['party_at_election'], ranked[index]['votes'])
                               for index, key in enumerate(('winner', 'runner')))):
                    raise ValueError(f'Uttar Pradesh 1951 declared result differs: {code}')
                record['error'] = ('Official summary confirms turnout, winner and margin; earlier '
                                   'candidate/source warnings remain available for review.')
                record['source_warning_code'] = 'summary_only_turnout'
                record['summary_result'] = result
            else:
                matches = WINNER.findall(text)
                ranked = sorted(record['candidates'], key=lambda row: -row['votes'])
                if (int(heading[3]) != 2 or len(matches) != 2 or len(ranked) < 2
                        or any((winner[1], winner[0], int(winner[2]))
                               != (ranked[index]['candidate_name'], ranked[index]['party_at_election'], ranked[index]['votes'])
                               for index, winner in enumerate(matches))):
                    raise ValueError(f'Uttar Pradesh 1951 two winners differ: {code}')
                record['original_extracted_number_of_seats'] = 1
                record['number_of_seats'] = 2
                record['official_summary_winners'] = [
                    {'winner': winner[1], 'party': winner[0], 'votes': int(winner[2])}
                    for winner in matches]
                record['error'] = ('Official summary records two seats and two winners. This historical '
                                   'multi-seat constituency is excluded from one-seat graphs; see the report.')
                record['source_warning_code'] = 'official_two_seat_summary'
    base = {'previous_review_note', 'previous_source_warning_code', 'valid_candidate_votes',
            'summary_source_file', 'summary_source_sha256', 'summary_totals', 'error', 'source_warning_code'}
    for old, new in zip(before['records'], after['records']):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        expected = (base | {'summary_result'} if old['code'] in RESULT_CODES else
                    base | {'original_extracted_number_of_seats', 'number_of_seats', 'official_summary_winners'}
                    if old['code'] in TWO_SEAT_CODES else set())
        if old['code'] != new['code'] or changed != expected:
            raise ValueError(f'Uttar Pradesh 1951 unrelated record changed: {old["code"]}')
    after_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    audit = {'edition': EDITION, 'result_codes': RESULT_CODES, 'two_seat_codes': TWO_SEAT_CODES,
             'source_url': SOURCE_URL, 'source_file': SOURCE_FILE, 'source_sha256': SOURCE_SHA256,
             'previous_sha256': sha(before_body), 'new_sha256': sha(after_body)}
    return before_body, after_body, audit


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    before, after, audit = revised_edition(root)
    with tempfile.TemporaryDirectory(prefix='ac-up-1951-residual-', dir=output.parent) as temporary:
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
