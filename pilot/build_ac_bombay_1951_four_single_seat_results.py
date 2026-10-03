"""Recover four 1951 Bombay AC declarations from official constituency summaries."""

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
EDITION = '3d43a1c5d9759834aee6f0cb'
NAME = 'pollmedia-ac-bombay-1951-four-single-seat-results-20261004'
SOURCE_FILE = f'{EDITION}-9726.pdf'
SOURCE_SHA256 = '8dcef73993ecfec1614eaecc395721c9b3cf4d9e275b6f121d0bc091a9e34fae'
SOURCE_URL = 'https://old.eci.gov.in/files/file/4107-bombay-1951/'
PREVIOUS_SHA256 = '7092d104b51029f8a1813c27cef0a1171847740892a90b28247984633c2246e5'
PAGES = {245: 261, 254: 270, 257: 273, 268: 284}
HEADING = re.compile(r'Field7:CONSTITUENCY\s*:\s*(\d+)\s*-\s*(.*?)\s+NUMBER OF SEATS\s*:\s*(\d+)', re.I)
WINNER = re.compile(r'^\s*Winner\s+(\S+)\s+(.+?)\s+(\d+)\s*$', re.M | re.I)
RUNNER = re.compile(r'^\s*Runner up\s+(\S+)\s+(.+?)\s+(\d+)\s*$', re.M | re.I)
MARGIN = re.compile(r'^\s*MARGIN\s*:\s*(\d+)\b', re.M | re.I)


def sha(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def norm(value: str) -> str:
    return re.sub(r'[^a-z0-9]', '', value.casefold())


def printed_total(section: str, label: str) -> int:
    line = re.search(r'^\s*' + re.escape(label) + r'\s+.*?\b(\d+)\s*$', section, re.M | re.I)
    if line is None:
        raise ValueError(f'Bombay summary total missing: {label}')
    return int(line[1])


def summary_result(text: str, code: int) -> tuple[str, dict, dict]:
    heading, winner, runner, margin = HEADING.search(text), WINNER.search(text), RUNNER.search(text), MARGIN.search(text)
    if not all((heading, winner, runner, margin)) or int(heading[1]) != code or int(heading[3]) != 1:
        raise ValueError(f'Bombay declared result missing: {code}')
    continuation = text[winner.end():runner.start()].strip()
    winner_name = ' '.join((winner[2].strip(), ' '.join(continuation.split()))).strip()
    result = {'winner': winner_name, 'winner_party': winner[1], 'winner_votes': int(winner[3]),
              'runner': runner[2].strip(), 'runner_party': runner[1], 'runner_votes': int(runner[3]),
              'margin': int(margin[1])}
    if result['winner_votes'] <= result['runner_votes'] or result['margin'] != result['winner_votes'] - result['runner_votes']:
        raise ValueError(f'Bombay declared margin differs: {code}')
    totals = {
        'electors': printed_total(text[text.index('II. ELECTORS'):text.index('III. ELECTORS WHO VOTED')], '1. TOTAL'),
        'votes_polled': printed_total(text[text.index('III. ELECTORS WHO VOTED'):text.index('IV. VOTES')], '1. TOTAL'),
        'valid_candidate_votes': printed_total(text[text.index('IV. VOTES'):text.index('VI. DATES')], '2. VALID'),
    }
    if not 0 < totals['valid_candidate_votes'] <= totals['votes_polled'] <= totals['electors']:
        raise ValueError(f'Bombay source turnout differs: {code}')
    return heading[2].strip(), totals, result


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    before_body = (folder / 'extraction.json').read_bytes()
    before = json.loads(before_body)
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    source = folder / SOURCE_FILE
    if (sha(before_body) != PREVIOUS_SHA256 or before['kind'] != 'ac' or before['year'] != 1951
            or before['source_url'] != SOURCE_URL or before['source_file'] != SOURCE_FILE
            or before['source_sha256'] != SOURCE_SHA256 or manifest['url'] != SOURCE_URL
            or len(before['records']) != 268
            or len([row for row in manifest['files'] if row['file'] == SOURCE_FILE and row['sha256'] == SOURCE_SHA256]) != 1
            or sha(source.read_bytes()) != SOURCE_SHA256):
        raise ValueError('Bombay 1951 source edition identity differs')
    after = json.loads(before_body)
    by_code = {record['code']: record for record in after['records']}
    before_by_code = {record['code']: record for record in before['records']}
    if len(by_code) != len(after['records']):
        raise ValueError('Duplicate Bombay constituency code')
    with fitz.open(source) as pdf:
        for code, page in PAGES.items():
            record = by_code[code]
            text = pdf[page - 1].get_text(sort=True)
            name, totals, result = summary_result(text, code)
            # Several printed summary headings stop at the column edge; the
            # detailed table keeps the longer constituency name.
            if (len(norm(name)) < 20 or not norm(record['name']).startswith(norm(name))
                    or record['number_of_seats'] != 1
                    or record['status'] != 'needs_review' or not record.get('error')
                    or any(record[key] != totals[key] for key in totals)
                    or record.get('summary_page') is not None or record.get('winner') is not None
                    or record.get('margin') is not None or record['detail_page'] < page):
                raise ValueError(f'Bombay original record differs: {code}')
            candidates = record['candidates']
            candidate_total = sum(candidate['votes'] for candidate in candidates)
            if code == 245:
                if (candidate_total + result['winner_votes'] != totals['valid_candidate_votes']
                        or any(norm(candidate['candidate_name']) == norm(result['winner']) for candidate in candidates)
                        or sum(norm(candidate['candidate_name']) == norm(result['runner'])
                               and candidate['party_at_election'] == result['runner_party']
                               and candidate['votes'] == result['runner_votes'] for candidate in candidates) != 1):
                    raise ValueError('Bombay 1951 missing detailed winner is not uniquely verified')
                record['error'] = 'Official summary declares the winner and margin; its winner row is absent from the detailed extraction. Check the original report.'
            else:
                ranked = sorted(candidates, key=lambda candidate: -candidate['votes'])
                if candidate_total != totals['valid_candidate_votes'] or len(ranked) < 2 or any(
                    norm(result[key]) != norm(ranked[index]['candidate_name'])
                    or result[key + '_party'] != ranked[index]['party_at_election']
                    or result[key + '_votes'] != ranked[index]['votes']
                    for index, key in enumerate(('winner', 'runner'))
                ):
                    raise ValueError(f'Bombay detailed candidates differ from source summary: {code}')
                record['error'] = 'Official summary confirms turnout and the declared result; detailed candidate text remains under review.'
            record['original_extraction_warning'] = before_by_code[code]['error']
            record['source_warning_code'] = 'summary_only_turnout'
            record['summary_source_file'] = SOURCE_FILE
            record['summary_source_sha256'] = SOURCE_SHA256
            record['summary_page'] = page
            record['summary_totals'] = totals
            record['summary_result'] = result
    expected = {'original_extraction_warning', 'error', 'source_warning_code', 'summary_source_file',
                'summary_source_sha256', 'summary_page', 'summary_totals', 'summary_result'}
    for old, new in zip(before['records'], after['records']):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        if old['code'] != new['code'] or changed != (expected if old['code'] in PAGES else set()):
            raise ValueError(f'Unrelated Bombay extraction changed: {old["code"]}')
    after_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    audit = {'scope': 'Bombay 1951 AC four official single-seat results', 'edition': EDITION,
             'codes': sorted(PAGES), 'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
             'source_sha256': SOURCE_SHA256, 'previous_sha256': sha(before_body), 'new_sha256': sha(after_body)}
    return before_body, after_body, audit


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    before, after, audit = revised_edition(root)
    with tempfile.TemporaryDirectory(prefix='ac-bombay-1951-', dir=output.parent) as temporary:
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
