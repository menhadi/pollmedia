"""Corroborate 1999 Goa AC declarations with the official report."""

import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_pc_1951_multi_seat_declared_members import multi_seat_import_script
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
EDITION = '1828febe749b3b5c3c0110ca'
NAME = 'pollmedia-ac-1999-goa-declared-results-20261008'
PREDECESSOR = 'de14fdb8eed3b627314fe50b6b007dbf8ad18baaa9a80d4132a64c16d8f37f8c'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3852-goa-1999/'
SOURCE_FILE = '1828febe749b3b5c3c0110ca-9091.pdf'
SOURCE_SHA = 'e4df06b9fa91b9a57d8350c78a67eaa191b4cf80349da6d4c4633ae176e31524'
PENDING = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.'
UNCONTESTED = PENDING + '; Reported elector and voter totals are inconsistent.'


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def section(text: str, start: str, end: str) -> str:
    match = re.search(start + r'\b(.*?)' + end, text, re.I | re.S)
    if match is None:
        raise ValueError(f'Official 1999 Goa summary section missing: {start}')
    return match[1]


def total(text: str) -> int:
    line = re.search(r'(?m)^\s*3\. TOTAL[^\n]*$', text)
    values = re.findall(r'\d+', line[0]) if line else []
    if len(values) < 2:
        raise ValueError('Official 1999 Goa summary total missing')
    return int(values[-1])


def reconcile(row: dict, page: int, text: str) -> str:
    code = row['code']
    identity = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text, re.I)
    if (identity is None or int(identity[1]) != code
            or re.sub(r'\s+', ' ', identity[2]).strip() != re.sub(r'\s+', ' ', row['name']).strip()
            or page != code + 12 or 'CONSTITUENCY DATA - SUMMARY' not in text
            or 'LEGISLATIVE ASSEMBLY OF GOA' not in text.upper()
            or row['state_name'] != 'Goa' or row['number_of_seats'] != 1
            or row['status'] != 'needs_review' or row.get('summary_page') is not None
            or row.get('source_warning_code') is not None):
        raise ValueError(f'Official 1999 Goa identity differs: {code}')
    electors = total(section(text, r'II\. ELECTORS', r'III\. ELECTORS WHO VOTED'))
    voters_section = section(text, r'III\. ELECTORS WHO VOTED', r'IV\. VOTES')
    votes_section = section(text, r'IV\. VOTES', r'V\. POLLING STATIONS')
    results = section(text, r'VII\. RESULT', r'rptConstituencySummary')
    if electors != row['electors'] or len(row['candidates']) < 1:
        raise ValueError(f'Official 1999 Goa electors differ: {code}')
    row['previous_review_note'] = row['error']
    row['original_extraction_warning'] = row['error']
    row['summary_page'] = page
    row['summary_source_file'] = SOURCE_FILE
    row['summary_source_sha256'] = SOURCE_SHA
    row['detail_source_file'] = SOURCE_FILE
    row['detail_source_sha256'] = SOURCE_SHA
    row['official_source_url'] = SOURCE_URL
    row['official_summary_constituency_name'] = identity[2].strip()
    row['official_summary_state'] = 'Goa'
    if 'Uncontested' in text:
        raise ValueError('Uncontested record requires null-preserving verifier')
    if row['error'] != PENDING or len(row['candidates']) < 2:
        raise ValueError(f'Official 1999 Goa contested state differs: {code}')
    voters = total(voters_section)
    printed = {}
    for ordinal, label in ((1, 'POLLED'), (2, 'VALID'), (3, 'REJECTED'), (4, 'MISSING')):
        match = re.search(r'(?m)^\s*' + str(ordinal) + r'\. ' + label + r'[ \t]+(\d+)', votes_section)
        if match is None:
            raise ValueError(f'Official 1999 Goa {label} missing: {code}')
        printed[label] = int(match[1])
    if (voters < 1 or voters > electors or voters != printed['POLLED']
            or (voters, printed['VALID']) != (row['votes_polled'], row['valid_candidate_votes'])
            or printed['VALID'] + printed['REJECTED'] + printed['MISSING'] != voters or printed['MISSING'] != {}.get(code, 0)):
        raise ValueError(f'Official 1999 Goa totals differ: {code}')
    declared = []
    for label, stop in (('Winner', 'Runner up'), ('Runner up', 'MARGIN')):
        block_match = re.search(re.escape(label) + r'\s*:?(.*?)' + re.escape(stop), results, re.S)
        if block_match is None:
            raise ValueError(f'Official declaration missing: {code} {label}')
        block = block_match[1]
        parsed = re.fullmatch(r'\s*(\S+)[ \t]+([^\n]+?)[ \t]+(\d+)[ \t]*(?:\n(.*))?', block, re.S)
        if parsed is None:
            raise ValueError(f'Official declaration layout differs: {code} {label}')
        continuation = re.sub(r'\s+', ' ', parsed[4] or '').strip()
        if re.search(r'\d', continuation):
            raise ValueError(f'Unexpected numeric continuation: {code}')
        name = re.sub(r'\s+', ' ', parsed[2] + ' ' + continuation).strip()
        declared.append((label, parsed[1], name, parsed[3]))
    margin = re.search(r'MARGIN\s*:\s*(\d+)', results, re.I)
    ranked = sorted(row['candidates'], key=lambda candidate: candidate['votes'], reverse=True)
    if (len(declared) != 2 or margin is None or sum(candidate['votes'] for candidate in ranked) != printed['VALID']
            or ranked[0]['votes'] <= ranked[1]['votes']
            or int(margin[1]) != ranked[0]['votes'] - ranked[1]['votes']
            or len({(candidate['candidate_name'], candidate['party_at_election'], candidate['votes'])
                    for candidate in ranked}) != len(ranked)):
        raise ValueError(f'Official 1999 Goa result differs: {code}')
    for source, candidate in zip(declared, ranked[:2], strict=True):
        if (source[2].strip(), source[1], int(source[3])) != (
                candidate['candidate_name'], candidate['party_at_election'], candidate['votes']):
            raise ValueError(f'Official 1999 Goa candidate differs: {code}')
    row['error'] = ('Official summary corroborates the detailed electors, voters, valid votes, '
                    'declared winner and margin; archived review warning retained.')
    if printed['MISSING']:
        row['error'] += f" Official summary includes {printed['MISSING']} missing ballots in the polled total."
    row['source_warning_code'] = 'official_summary_turnout_only'
    row['summary_totals'] = {'electors': electors, 'votes_polled': voters,
                             'valid_candidate_votes': printed['VALID']}
    row['summary_result'] = {'winner': ranked[0]['candidate_name'],
                             'winner_party': ranked[0]['party_at_election'],
                             'winner_votes': ranked[0]['votes'],
                             'runner': ranked[1]['candidate_name'],
                             'runner_party': ranked[1]['party_at_election'],
                             'runner_votes': ranked[1]['votes'], 'margin': int(margin[1])}
    return 'contested'


UNCONTESTED_CODES = [27]
RECOVERED_WARNING = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.; Detailed totals are missing or use an unsupported layout.; The source reports an uncontested candidate without vote totals. Candidate rows recovered from the same archived source; earlier extraction note: Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.; Detailed totals are missing or use an unsupported layout.; Some candidate text could not be parsed; see the original PDF.'

def verified_uncontested(row, summary, detail):
    norm = lambda value: re.sub(r"\s+", " ", value).strip()
    if len(row['candidates']) != 1 or any(key in row for key in ('electors', 'votes_polled', 'valid_candidate_votes')):
        raise ValueError('Expected original absent total keys')
    candidate = row['candidates'][0]
    if candidate['votes'] is not None:
        raise ValueError('Expected original explicit null candidate votes')
    identity = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', summary)
    if not identity or int(identity[1]) != row['code'] or norm(identity[2]) != norm(row['name']):
        raise ValueError('Summary identity differs')
    if 'State Election, 1999 to the Legislative Assembly of GOA' not in summary:
        raise ValueError('Summary jurisdiction/year differs')
    results = summary.split('VII. RESULT', 1)[1].split('rptConstituencySummary', 1)[0]
    declaration = re.search(r'Winner\s*:?\s*(\S+)\s+(.+?)\s+Returned\s+Uncontested', results, re.S)
    if not declaration or (declaration[1], norm(declaration[2])) != (candidate['party_at_election'], norm(candidate['candidate_name'])):
        raise ValueError('Explicit uncontested declaration differs')
    if re.search(r'Runner up|MARGIN', results):
        raise ValueError('Unexpected contested result evidence')
    for start, stop in [('III. ELECTORS WHO VOTED', 'IV. VOTES'), ('IV. VOTES', 'V. POLLING STATIONS')]:
        section = summary.split(start, 1)[1].split(stop, 1)[0]
        if 'Uncontested' not in section or re.search(r'(?m)^[ \t]*[1-5]\. (?:GENERAL|POSTAL|TOTAL|POLLED|VALID|REJECTED|MISSING|TENDERED)[ \t]+\d', section):
            raise ValueError('Uncontested totals are not explicitly blank')
    electors_section = summary.split('II. ELECTORS', 1)[1].split('III. ELECTORS WHO VOTED', 1)[0]
    total = re.search(r'(?m)^[ \t]*3\. TOTAL[^\n]*', electors_section)
    if not total:
        raise ValueError('Summary electors missing')
    electors = int(re.findall(r'\d+', total[0])[-1])
    block = re.search(r'Constituency\s*:\s*'+str(row['code'])+r'\s*\.([^\n]+)(.*?)(?=Constituency\s*:|\Z)', detail, re.S)
    if not block or norm(block[1]) != norm(row['name']):
        raise ValueError('Detailed constituency boundary differs')
    elector_match = re.search(r'ELECTORS[ \t]*:[ \t]*(\d+)', block[2])
    candidate_match = re.search(r'(?m)^[ \t]*1[ \t]*\.[ \t]*(.+?)[ \t]+([MF])[ \t]+(\S+)[ \t]+Uncontested[ \t]*$', block[2])
    if not elector_match or int(elector_match[1]) != electors or not candidate_match:
        raise ValueError('Detailed elector/candidate evidence missing')
    if (norm(candidate_match[1]), candidate_match[3]) != (norm(candidate['candidate_name']), candidate['party_at_election']):
        raise ValueError('Detailed candidate differs')
    return {'electors': electors, 'votes_polled': None, 'valid_candidate_votes': None}


def review_uncontested(row, page, summary, pdf):
    if (row['code'] not in UNCONTESTED_CODES or row['number_of_seats'] != 1
            or row['state_name'] != 'Goa' or row['status'] != 'needs_review'
            or row['error'] != RECOVERED_WARNING or row.get('summary_page') is not None
            or row.get('source_warning_code') is not None or page != row['code'] + 12):
        raise ValueError('Unexpected original uncontested record')
    detail_page = row['detail_page']
    detail = pdf[detail_page - 1].get_text(sort=True)
    pages = [detail_page]
    # Identity can end a page before its candidate and electorate continue.
    if detail_page < len(pdf):
        detail += '\n' + pdf[detail_page].get_text(sort=True)
        pages.append(detail_page + 1)
    totals = verified_uncontested(row, summary, detail)
    candidate = row['candidates'][0]
    row.update(previous_review_note=row['error'], original_extraction_warning=row['error'],
               summary_page=page, summary_source_file=SOURCE_FILE, summary_source_sha256=SOURCE_SHA,
               detail_source_file=SOURCE_FILE, detail_source_sha256=SOURCE_SHA,
               detail_verification_pages=pages, official_source_url=SOURCE_URL,
               official_summary_constituency_name=row['name'], official_summary_state='Goa',
               source_warning_code='official_uncontested_summary', summary_totals=totals,
               summary_source_rows=[f"Winner {candidate['party_at_election']} {candidate['candidate_name']} Returned Uncontested"],
               error='Official summary and detail explicitly declare this candidate returned uncontested. '
                     'No voter, valid-vote or margin total is reported. Original absent total fields, '
                     'null candidate votes and candidate-recovery warning are preserved.')
    return 'uncontested'

def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    source = folder / SOURCE_FILE
    original = folder / 'extraction.json'
    if (source.is_symlink() or original.is_symlink() or digest(source.read_bytes()) != SOURCE_SHA):
        raise ValueError('1999 Goa official source differs')
    old_body = original.read_bytes()
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    before = json.loads(old_body)
    after = json.loads(old_body)
    if (digest(old_body) != PREDECESSOR or manifest['url'] != SOURCE_URL
            or next(file for file in manifest['files'] if file['file'] == SOURCE_FILE)['sha256'] != SOURCE_SHA
            or before['kind'] != 'ac' or before['year'] != 1999
            or before['source_url'] != SOURCE_URL or before['source_file'] != SOURCE_FILE
            or before['source_sha256'] != SOURCE_SHA or len(before['records']) != 40
            or [row['code'] for row in before['records']] != list(range(1, 41))):
        raise ValueError('1999 Goa extraction provenance differs')
    kinds = {'contested': [], 'uncontested': []}
    with fitz.open(source) as pdf:
        if len(pdf) != 59:
            raise ValueError('1999 Goa PDF page coverage differs')
        for row in after['records']:
            code = row['code']
            summary = pdf[code + 11].get_text(sort=True)
            kind = (review_uncontested(row, code + 12, summary, pdf) if code in UNCONTESTED_CODES
                    else reconcile(row, code + 12, summary))
            kinds[kind].append(code)
    if len(kinds['contested']) != 39 or kinds['uncontested'] != UNCONTESTED_CODES:
        raise ValueError('1999 Goa contest coverage differs')
    common = {'previous_review_note', 'original_extraction_warning', 'summary_page',
              'summary_source_file', 'summary_source_sha256', 'detail_source_file',
              'detail_source_sha256', 'official_source_url', 'official_summary_constituency_name',
              'official_summary_state', 'error', 'source_warning_code', 'summary_totals'}
    for old, new in zip(before['records'], after['records'], strict=True):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        expected = common | ({'summary_result'} if old['code'] in kinds['contested'] else {'summary_source_rows', 'detail_verification_pages'})
        if (old['code'] != new['code'] or old['candidates'] != new['candidates'] or changed != expected):
            raise ValueError(f'Unrelated 1999 Goa evidence changed: {old["code"]}')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{PREDECESSOR}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='ac-1999-goa-', dir=root / 'exports') as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        archives = []
        for kind, relative, body in (('snapshot', snapshot, old_body), ('correction', revision, new_body)):
            path = staged / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(body)
            bucket = hashlib.sha256(relative.encode()).digest()[0] % 8
            archive = packages / f'{kind}-{EDITION}.zip'
            package(staged, archive, 'election-archive', bucket, 8, [relative],
                    PREDECESSOR if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
            archives.append(archive)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as release:
            for archive in archives:
                release.write(archive, archive.name)
            release.writestr('SHA256SUMS', ''.join(f'{digest(archive.read_bytes())}  {archive.name}\n'
                                                for archive in archives))
            release.writestr('ARCHIVES', EDITION + '\n')
            release.writestr('AUDIT.json', json.dumps({
                'scope': '39 contested and 1 explicitly uncontested 1999 Goa AC declarations',
                'edition': EDITION, 'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
                'source_sha256': SOURCE_SHA, 'previous_sha256': PREDECESSOR,
                'new_sha256': digest(new_body), 'contested_codes': kinds['contested'],
                'uncontested_codes': kinds['uncontested'],
            }, indent=2))
            release.writestr('IMPORT.sh', multi_seat_import_script())
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'previous_sha256': PREDECESSOR,
            'new_sha256': digest(new_body),
            'contested': len(kinds['contested']), 'uncontested': len(kinds['uncontested'])}


if __name__ == '__main__':
    print(json.dumps(build()))
