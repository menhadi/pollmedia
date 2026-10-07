"""Corroborate 1991 Kerala AC declarations with the official report."""

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
EDITION = '9aa0b4cc113d580f406a6084'
NAME = 'pollmedia-ac-1991-kerala-declared-results-20261007'
PREDECESSOR = 'd1ed3e5810f0cfe9b63bb8f2e637063125b3b2906612202fe6ba32bd27405941'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3758-kerala-1991/'
SOURCE_FILE = '9aa0b4cc113d580f406a6084-8836.pdf'
SOURCE_SHA = '0aca312f04bc3a46eba9a5bf968b75718c53409e7a624b114ea5760d71c04593'
MISSING = {1: 33, 5: 27, 12: 18, 15: 17, 18: 25, 19: 71, 20: 32, 23: 148, 24: 17, 26: 10, 31: 21, 32: 21, 39: 14, 44: 17, 47: 42, 49: 108, 58: 39, 66: 13, 68: 50, 70: 19, 83: 13, 98: 32, 101: 73, 102: 38, 105: 1, 111: 12, 115: 6, 119: 10, 124: 5}
SET_APART = {5: 4042, 7: 748, 18: 829, 101: 2846}
PENDING = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.'
UNCONTESTED = PENDING + '; Reported elector and voter totals are inconsistent.'


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def section(text: str, start: str, end: str) -> str:
    match = re.search(start + r'\b(.*?)' + end, text, re.I | re.S)
    if match is None:
        raise ValueError(f'Official 1991 Kerala summary section missing: {start}')
    return match[1]


def total(text: str) -> int:
    line = re.search(r'(?m)^\s*3\. TOTAL[^\n]*$', text)
    values = re.findall(r'\d+', line[0]) if line else []
    if len(values) < 2:
        raise ValueError('Official 1991 Kerala summary total missing')
    return int(values[-1])


def reconcile(row: dict, page: int, text: str) -> str:
    code = row['code']
    identity = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text, re.I)
    if (identity is None or int(identity[1]) != code
            or re.sub(r'\s+', ' ', identity[2]).strip() != re.sub(r'\s+', ' ', row['name']).strip()
            or page != code + 14 or 'CONSTITUENCY DATA - SUMMARY' not in text
            or 'LEGISLATIVE ASSEMBLY OF KERALA' not in text.upper()
            or row['state_name'] != 'Kerala' or row['number_of_seats'] != 1
            or row['status'] != 'needs_review' or row.get('summary_page') is not None
            or row.get('source_warning_code') is not None):
        raise ValueError(f'Official 1991 Kerala identity differs: {code}')
    electors = total(section(text, r'II\. ELECTORS', r'III\. ELECTORS WHO VOTED'))
    voters_section = section(text, r'III\. ELECTORS WHO VOTED', r'IV\. VOTES')
    votes_section = section(text, r'IV\. VOTES', r'V\. POLLING STATIONS')
    results = section(text, r'VII\. RESULT', r'rptConstituencySummary')
    if electors != row['electors'] or len(row['candidates']) < 1:
        raise ValueError(f'Official 1991 Kerala electors differ: {code}')
    row['previous_review_note'] = row['error']
    row['original_extraction_warning'] = row['error']
    row['summary_page'] = page
    row['summary_source_file'] = SOURCE_FILE
    row['summary_source_sha256'] = SOURCE_SHA
    row['detail_source_file'] = SOURCE_FILE
    row['detail_source_sha256'] = SOURCE_SHA
    row['official_source_url'] = SOURCE_URL
    row['official_summary_constituency_name'] = identity[2].strip()
    row['official_summary_state'] = 'Kerala'
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
            raise ValueError(f'Official 1991 Kerala uncontested result differs: {code}')
        row['error'] = (f'Official 1991 Kerala summary declares {candidate["candidate_name"]} '
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
        raise ValueError(f'Official 1991 Kerala contested state differs: {code}')
    voters = total(voters_section)
    printed = {}
    for ordinal, label in ((1, 'POLLED'), (2, 'VALID'), (3, 'REJECTED'), (4, 'MISSING')):
        match = re.search(r'(?m)^\s*' + str(ordinal) + r'\. ' + label + r'\s+(\d+)', votes_section)
        if match is None:
            raise ValueError(f'Official 1991 Kerala {label} missing: {code}')
        printed[label] = int(match[1])
    set_apart = re.search(r'5\. NOT RETREAVED FROM EVM /SET APART\s+(\d+)', votes_section)
    if set_apart is None or int(set_apart[1]) != SET_APART.get(code, 0):
        raise ValueError(f'Official set-apart ballots differ: {code}')
    if (voters < 1 or voters > electors or voters != printed['POLLED']
            or (voters, printed['VALID']) != (row['votes_polled'], row['valid_candidate_votes'])
            or printed['VALID'] + printed['REJECTED'] + printed['MISSING'] + int(set_apart[1]) != voters
            or printed['MISSING'] != MISSING.get(code, 0)):
        raise ValueError(f'Official 1991 Kerala totals differ: {code}')
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
        raise ValueError(f'Official 1991 Kerala result differs: {code}')
    for source, candidate in zip(declared, ranked[:2], strict=True):
        if (source[2].strip(), source[1], int(source[3])) != (
                candidate['candidate_name'], candidate['party_at_election'], candidate['votes']):
            raise ValueError(f'Official 1991 Kerala candidate differs: {code}')
    row['error'] = ('Official summary corroborates the detailed electors, voters, valid votes, '
                    'declared winner and margin; archived review warning retained.')
    if printed['MISSING'] or int(set_apart[1]):
        row['error'] += (f" Printed totals include {printed['MISSING']} missing and "
                         f"{int(set_apart[1])} set-apart ballots.")
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


EXCEPTIONS = {95: {'name': 'KADUTHURUTHY', 'summary_page': 109, 'summary_text_sha256': 'fd630c0058f1a8b3b7680d0bb2769ee8e372e65278ea5a611345b36fd308a52b', 'detail_pages': [170, 171], 'detail_page_text_sha256': {'170': '99eb4e2d467cc2922d3b64b4355f886c4d084196abc74b2f9a50d86348637378', '171': '3456dc8d7f64d6219c6f49f879723d8dc12a9657fe803657ecc33364946f88bd'}}, 137: {'name': 'NEMOM', 'summary_page': 151, 'summary_text_sha256': 'aef8eda4f6c985c41710c6b6297c28fb2e2811503cbf22ddda8cfc6726e04b02', 'detail_pages': [178], 'detail_page_text_sha256': {'178': 'e4f2759329ca1e9d4a11564676d3e9104b383df6881b9b49a2c66eb861fe769a'}}}


def annotate_exception(row: dict, pdf) -> None:
    code = row['code']; source = EXCEPTIONS[code]
    text = pdf[source['summary_page'] - 1].get_text(sort=True)
    if (row['name'] != source['name'] or row['state_name'] != 'Kerala'
            or row['detail_page'] != source['detail_pages'][0] or row['status'] != 'needs_review'
            or row['number_of_seats'] != 1 or row['error'] != PENDING
            or row.get('summary_page') is not None or row.get('source_warning_code') is not None
            or digest(text.encode()) != source['summary_text_sha256']):
        raise ValueError('Kerala exception provenance differs')
    for page, expected in source['detail_page_text_sha256'].items():
        if digest(pdf[int(page)-1].get_text(sort=True).encode()) != expected:
            raise ValueError('Kerala detail exception fingerprint differs')
    electors = total(section(text, r'II\. ELECTORS', r'III\. ELECTORS WHO VOTED'))
    voters = total(section(text, r'III\. ELECTORS WHO VOTED', r'IV\. VOTES'))
    votes = section(text, r'IV\. VOTES', r'V\. POLLING STATIONS')
    valid = int(re.search(r'2\. VALID\s+(\d+)', votes)[1])
    results = section(text, r'VII\. RESULT', r'rptConstituencySummary')
    declared = re.findall(r'^\s*(Winner|Runner up)\s*:?\s+(\S+)\s+(.+?)\s+(\d+)\s*$', results,re.M)
    ranked = sorted(row['candidates'], key=lambda c:c['votes'], reverse=True)
    margin = int(re.search(r'MARGIN\s*:\s*(\d+)',results)[1])
    if (len(declared) != 2 or [v[0] for v in declared] != ['Winner','Runner up']
            or [(v[2].strip(),int(v[3])) for v in declared] != [(c['candidate_name'],c['votes']) for c in ranked[:2]]
            or margin != ranked[0]['votes']-ranked[1]['votes']
            or sum(c['votes'] for c in ranked) != row['valid_candidate_votes']
            or (electors,voters) != (row['electors'],row['votes_polled'])):
        raise ValueError('Kerala exception result differs')
    if code == 95:
        if (valid,row['valid_candidate_votes'],margin) != (93560,93580,13732) or [v[1] for v in declared] != [c['party_at_election'] for c in ranked[:2]]:
            raise ValueError('Kaduthuruthy discrepancy differs')
        note = 'Official summary reports 93,560 valid votes; detailed total and candidate sum report 93,580 (20-vote discrepancy).'
    else:
        if (code,valid,margin) != (137,106674,6862) or [v[1] for v in declared] != ['CPM','CMP(K)'] or [c['party_at_election'] for c in ranked[:2]] != ['CPM','CPM(K)']:
            raise ValueError('Nemom discrepancy differs')
        note = 'Official summary labels runner STANLY SATHYANESAN CMP(K); detail and archived candidate row label CPM(K). Both report 40,201 votes.'
    row.update({'previous_review_note':row['error'],'original_extraction_warning':row['error'],
        'summary_page':source['summary_page'],'summary_source_file':SOURCE_FILE,'summary_source_sha256':SOURCE_SHA,
        'detail_source_file':SOURCE_FILE,'detail_source_sha256':SOURCE_SHA,'official_source_url':SOURCE_URL,
        'official_summary_constituency_name':source['name'],'official_summary_state':'Kerala',
        'source_warning_code':'official_ac_source_discrepancy',
        'summary_totals':{'electors':electors,'votes_polled':voters,'valid_candidate_votes':valid},
        'summary_result':{'winner':declared[0][2].strip(),'winner_party':declared[0][1],'winner_votes':int(declared[0][3]),
            'runner':declared[1][2].strip(),'runner_party':declared[1][1],'runner_votes':int(declared[1][3]),'margin':margin}})
    row['error'] = (note + f' Source-declared winner {declared[0][2].strip()} ({declared[0][1]}), {int(declared[0][3]):,} votes; margin {margin:,}. '
                    'Elector and voter totals agree across summary and detail; turnout is shown with review. Source discrepancy retained for review; aggregate candidate results withheld.')


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    source = folder / SOURCE_FILE
    original = folder / 'extraction.json'
    if (source.is_symlink() or original.is_symlink() or digest(source.read_bytes()) != SOURCE_SHA):
        raise ValueError('1991 Kerala official source differs')
    old_body = original.read_bytes()
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    before = json.loads(old_body)
    after = json.loads(old_body)
    if (digest(old_body) != PREDECESSOR or manifest['url'] != SOURCE_URL
            or next(file for file in manifest['files'] if file['file'] == SOURCE_FILE)['sha256'] != SOURCE_SHA
            or before['kind'] != 'ac' or before['year'] != 1991
            or before['source_url'] != SOURCE_URL or before['source_file'] != SOURCE_FILE
            or before['source_sha256'] != SOURCE_SHA or len(before['records']) != 140
            or [row['code'] for row in before['records']] != list(range(1, 141))):
        raise ValueError('1991 Kerala extraction provenance differs')
    kinds = {'contested': [], 'uncontested': []}
    with fitz.open(source) as pdf:
        if len(pdf) != 178:
            raise ValueError('1991 Kerala PDF page coverage differs')
        for row in after['records']:
            code = row['code']
            if code in EXCEPTIONS:
                annotate_exception(row, pdf)
                kind = 'contested'
            else:
                kind = reconcile(row, code + 14, pdf[code + 13].get_text(sort=True))
            kinds[kind].append(code)
    if len(kinds['contested']) != 140 or len(kinds['uncontested']) != 0:
        raise ValueError('1991 Kerala contest coverage differs')
    common = {'previous_review_note', 'original_extraction_warning', 'summary_page',
              'summary_source_file', 'summary_source_sha256', 'detail_source_file',
              'detail_source_sha256', 'official_source_url', 'official_summary_constituency_name',
              'official_summary_state', 'error', 'source_warning_code', 'summary_totals'}
    for old, new in zip(before['records'], after['records'], strict=True):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        expected = common | ({'summary_result'} if old['code'] in kinds['contested'] else {'summary_source_rows'})
        if (old['code'] != new['code'] or old['candidates'] != new['candidates'] or changed != expected):
            raise ValueError(f'Unrelated 1991 Kerala evidence changed: {old["code"]}')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{PREDECESSOR}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='ac-1991-kerala-', dir=root / 'exports') as temporary:
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
                'scope': '138 corroborated and 2 source-discrepancy 1991 Kerala AC declarations',
                'edition': EDITION, 'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
                'source_sha256': SOURCE_SHA, 'previous_sha256': PREDECESSOR,
                'new_sha256': digest(new_body), 'contested_codes': kinds['contested'],
                'uncontested_codes': kinds['uncontested'], 'source_discrepancy_codes': sorted(EXCEPTIONS),
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
