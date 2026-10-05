"""Corroborate 1977 Madhya Pradesh AC declarations with the official report."""

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
EDITION = '020e199c1805ae55307b4181'
NAME = 'pollmedia-ac-1977-madhya-pradesh-reviewed-results-20261006'
PREDECESSOR = '51a6252a3b9a22cbfe7db7fffb0fe38031d4cd3da27042c547cb7807391f2ff1'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3732-madhya-pradesh-1977/'
SOURCE_FILE = f'{EDITION}-8780.pdf'
SOURCE_SHA = 'ff33c5bccf8674cc1dc5a653b830a1c53eee364cdaefc698633fcf231e1e21e3'
PENDING = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.'
UNCONTESTED = PENDING + '; Reported elector and voter totals are inconsistent.'


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def section(text: str, start: str, end: str) -> str:
    match = re.search(start + r'\b(.*?)' + end, text, re.I | re.S)
    if match is None:
        raise ValueError(f'Official 1977 Madhya Pradesh summary section missing: {start}')
    return match[1]


def total(text: str) -> int:
    line = re.search(r'(?m)^\s*3\. TOTAL[^\n]*$', text)
    values = re.findall(r'\d+', line[0]) if line else []
    if len(values) < 2:
        raise ValueError('Official 1977 Madhya Pradesh summary total missing')
    return int(values[-1])


def reconcile(row: dict, page: int, text: str) -> str:
    code = row['code']
    identity = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text, re.I)
    if (identity is None or int(identity[1]) != code
            or re.sub(r'\s+', ' ', identity[2]).strip() != re.sub(r'\s+', ' ', row['name']).strip()
            or page != code + 19 or 'CONSTITUENCY DATA - SUMMARY' not in text
            or 'LEGISLATIVE ASSEMBLY OF MADHYA PRADESH' not in text.upper()
            or row['state_name'] != 'Madhya Pradesh' or row['number_of_seats'] != 1
            or row['status'] != 'needs_review' or row.get('summary_page') is not None
            or row.get('source_warning_code') is not None):
        raise ValueError(f'Official 1977 Madhya Pradesh identity differs: {code}')
    electors = total(section(text, r'II\. ELECTORS', r'III\. ELECTORS WHO VOTED'))
    voters_section = section(text, r'III\. ELECTORS WHO VOTED', r'IV\. VOTES')
    votes_section = section(text, r'IV\. VOTES', r'V\. POLLING STATIONS')
    results = section(text, r'VII\. RESULT', r'rptConstituencySummary')
    if electors != row['electors'] or len(row['candidates']) < 1:
        raise ValueError(f'Official 1977 Madhya Pradesh electors differ: {code}')
    row['previous_review_note'] = row['error']
    row['original_extraction_warning'] = row['error']
    row['summary_page'] = page
    row['summary_source_file'] = SOURCE_FILE
    row['summary_source_sha256'] = SOURCE_SHA
    row['detail_source_file'] = SOURCE_FILE
    row['detail_source_sha256'] = SOURCE_SHA
    row['official_source_url'] = SOURCE_URL
    row['official_summary_constituency_name'] = identity[2].strip()
    row['official_summary_state'] = 'Madhya Pradesh'
    if 'Uncontested' in text:
        declaration = re.search(r'Winner\s*:?\s*(\S+)\s+(.+?)\s+Returned\s+Uncontested',
                                results, re.I | re.S)
        candidate = row['candidates'][0]
        if (row['error'] != UNCONTESTED or len(row['candidates']) != 1
                or row['votes_polled'] != 0 or row['valid_candidate_votes'] != 0
                or candidate['votes'] != 0 or declaration is None
                or (declaration[1], re.sub(r'\s+', ' ', declaration[2]).strip()) !=
                (candidate['party_at_election'], re.sub(r'\s+', ' ', candidate['candidate_name']).strip())
                or 'Uncontested' not in voters_section or 'Uncontested' not in votes_section
                or re.search(r'(?m)^[ \t]*3\. TOTAL[ \t]+\d+', voters_section)
                or re.search(r'(?m)^[ \t]*(1\. POLLED|2\. VALID)[ \t]+\d+', votes_section)
                or re.search(r'Runner up|MARGIN\s*:', results, re.I)):
            raise ValueError(f'Official 1977 Madhya Pradesh uncontested result differs: {code}')
        row['error'] = (f'Official 1977 Madhya Pradesh summary declares {candidate["candidate_name"]} '
                        f'({candidate["party_at_election"]}) returned uncontested. No voter, valid-vote '
                        'or margin total is reported; archived zero candidate/voter fields are extraction '
                        'placeholders, not measured turnout.')
        row['source_warning_code'] = 'official_uncontested_summary'
        row['summary_source_rows'] = [f'Winner {candidate["party_at_election"]} '
                                      f'{candidate["candidate_name"]} Returned Uncontested']
        row['summary_totals'] = {'electors': electors, 'votes_polled': None,
                                 'valid_candidate_votes': None}
        return 'uncontested'
    if row['error'] != PENDING or len(row['candidates']) < 2:
        raise ValueError(f'Official 1977 Madhya Pradesh contested state differs: {code}')
    voters = total(voters_section)
    printed = {}
    for ordinal, label in ((1, 'POLLED'), (2, 'VALID'), (3, 'REJECTED'), (4, 'MISSING')):
        match = re.search(r'(?m)^\s*' + str(ordinal) + r'\. ' + label + r'\s+(\d+)', votes_section)
        if match is None:
            raise ValueError(f'Official 1977 Madhya Pradesh {label} missing: {code}')
        printed[label] = int(match[1])
    if (voters < 1 or voters > electors or voters != printed['POLLED']
            or (voters, printed['VALID']) != (row['votes_polled'], row['valid_candidate_votes'])
            or printed['VALID'] + printed['REJECTED'] != voters or printed['MISSING'] != 0):
        raise ValueError(f'Official 1977 Madhya Pradesh totals differ: {code}')
    declared = []
    for label, stop in (('Winner', 'Runner up'), ('Runner up', 'MARGIN')):
        block_match = re.search(re.escape(label) + r'\s*:(.*?)' + re.escape(stop), results, re.S)
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
        raise ValueError(f'Official 1977 Madhya Pradesh result differs: {code}')
    for source, candidate in zip(declared, ranked[:2], strict=True):
        if (source[2].strip(), source[1], int(source[3])) != (
                candidate['candidate_name'], candidate['party_at_election'], candidate['votes']):
            raise ValueError(f'Official 1977 Madhya Pradesh candidate differs: {code}')
    row['error'] = ('Official summary corroborates the detailed electors, voters, valid votes, '
                    'declared winner and margin; archived review warning retained.')
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



def reconcile_barghat(row: dict, summary: str, detail: str) -> None:
    """Keep both conflicting elector totals while corroborating the declaration."""
    if (row['code'] != 212 or row['name'] != 'BARGHAT' or row['detail_page'] != 381
            or (row['electors'], row['votes_polled'], row['valid_candidate_votes']) != (408799, 43434, 41585)
            or len(row['candidates']) != 7):
        raise ValueError('Barghat archived identity or totals differ')
    block = re.search(r'Constituency\s*:\s*212\s*\.\s*BARGHAT(.*?)(?=Constituency\s*:|$)', detail, re.S)
    totals = re.search(r'ELECTORS\s*:\s*(\d+)\s+VOTERS\s*:\s*(\d+)\s+POLL PERCENTAGE\s*:\s*([\d.]+)%\s+VALID VOTES\s*:\s*(\d+)', block[1] if block else '')
    if totals is None or totals.groups() != ('408799', '43434', '10.62', '41585'):
        raise ValueError('Barghat detailed totals differ')
    components = section(summary, r'II\. ELECTORS', r'III\. ELECTORS WHO VOTED')
    if not re.search(r'3\. TOTAL\s+36229\s+39570\s+75799\b', components):
        raise ValueError('Barghat summary elector components differ')
    if not re.search(r'1\. POLLED\s+43434\s*\(\s*57\.30%', summary):
        raise ValueError('Barghat printed summary turnout differs')
    # Reuse declaration checks on a copy; the archived elector total stays intact.
    checked = json.loads(json.dumps(row))
    checked['electors'] = 75799
    reconcile(checked, 231, summary)
    if checked['summary_result'] != {
            'winner': 'BHARATLAL BISEN', 'winner_party': 'INC', 'winner_votes': 19412,
            'runner': 'JAGESHWAR NATH BISEN', 'runner_party': 'JNP',
            'runner_votes': 17039, 'margin': 2373}:
        raise ValueError('Barghat declaration differs')
    checked['electors'] = row['electors']
    checked['source_warning_code'] = 'official_ac_elector_total_conflict'
    checked['source_discrepancy'] = {'field': 'electors', 'detail_value': 408799,
                                    'summary_value': 75799, 'summary_male': 36229,
                                    'summary_female': 39570, 'detail_turnout_percent': '10.62',
                                    'summary_turnout_percent': '57.30'}
    checked['error'] = ('Official summary reports 75,799 electors (36,229 male + 39,570 female); '
                        'the detailed table reports 408,799. Both report 43,434 voters and 41,585 valid votes. '
                        'Turnout is withheld. Official winner BHARATLAL BISEN (INC), 19,412 votes; '
                        'runner-up JAGESHWAR NATH BISEN (JNP), 17,039; margin 2,373. '
                        'Declaration retained for review; aggregate result projection is withheld.')
    row.update(checked)


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    source = folder / SOURCE_FILE
    original = folder / 'extraction.json'
    if (source.is_symlink() or original.is_symlink() or digest(source.read_bytes()) != SOURCE_SHA):
        raise ValueError('1977 Madhya Pradesh official source differs')
    old_body = original.read_bytes()
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    before = json.loads(old_body)
    after = json.loads(old_body)
    if (digest(old_body) != PREDECESSOR or manifest['url'] != SOURCE_URL
            or next(file for file in manifest['files'] if file['file'] == SOURCE_FILE)['sha256'] != SOURCE_SHA
            or before['kind'] != 'ac' or before['year'] != 1977
            or before['source_url'] != SOURCE_URL or before['source_file'] != SOURCE_FILE
            or before['source_sha256'] != SOURCE_SHA or len(before['records']) != 320
            or [row['code'] for row in before['records']] != list(range(1, 321))):
        raise ValueError('1977 Madhya Pradesh extraction provenance differs')
    kinds = {'contested': [], 'uncontested': []}
    with fitz.open(source) as pdf:
        if len(pdf) != 397:
            raise ValueError('1977 Madhya Pradesh PDF page coverage differs')
        for row in after['records']:
            code = row['code']
            if code == 212:
                reconcile_barghat(row, pdf[230].get_text(sort=True), pdf[380].get_text(sort=True))
                continue
            kind = reconcile(row, code + 19, pdf[code + 18].get_text(sort=True))
            kinds[kind].append(code)
    if len(kinds['contested']) != 319 or len(kinds['uncontested']) != 0:
        raise ValueError('1977 Madhya Pradesh contest coverage differs')
    common = {'previous_review_note', 'original_extraction_warning', 'summary_page',
              'summary_source_file', 'summary_source_sha256', 'detail_source_file',
              'detail_source_sha256', 'official_source_url', 'official_summary_constituency_name',
              'official_summary_state', 'error', 'source_warning_code', 'summary_totals'}
    for old, new in zip(before['records'], after['records'], strict=True):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        expected = common | ({'summary_result'} if old['code'] in kinds['contested'] else {'summary_source_rows'})
        if old['code'] == 212:
            expected = common | {'summary_result', 'source_discrepancy'}
        if (old['code'] != new['code'] or old['candidates'] != new['candidates'] or changed != expected):
            raise ValueError(f'Unrelated 1977 Madhya Pradesh evidence changed: {old["code"]}')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{PREDECESSOR}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='ac-1977-madhya-pradesh-', dir=root / 'exports') as temporary:
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
                'scope': '319 ordinary declarations and Barghat elector discrepancy; 320 preserved AC records',
                'edition': EDITION, 'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
                'source_sha256': SOURCE_SHA, 'previous_sha256': PREDECESSOR,
                'new_sha256': digest(new_body), 'contested_codes': kinds['contested'],
                'uncontested_codes': kinds['uncontested'], 'discrepancy_codes': [212],
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
