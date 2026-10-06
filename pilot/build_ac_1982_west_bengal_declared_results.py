"""Corroborate 1982 West Bengal AC declarations with the official report."""

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
EDITION = '9982b63a332a67579dae045f'
NAME = 'pollmedia-ac-1982-west-bengal-reviewed-results-20261006'
PREDECESSOR = '832554900da498dfa4398d5161ec94c63f42ba83c0efaab82f2f0e0caefbccef'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3189-west-bengal-1982/'
SOURCE_FILE = f'{EDITION}-7315.pdf'
SOURCE_SHA = 'd7aa7423d5d0e2c252d758df89b7b0274f2303689bfc67d44a4e64732d0d81f3'
PENDING = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.'
UNCONTESTED = PENDING + '; Reported elector and voter totals are inconsistent.'


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def section(text: str, start: str, end: str) -> str:
    match = re.search(start + r'\b(.*?)' + end, text, re.I | re.S)
    if match is None:
        raise ValueError(f'Official 1982 West Bengal summary section missing: {start}')
    return match[1]


def total(text: str) -> int:
    line = re.search(r'(?m)^\s*3\. TOTAL[^\n]*$', text)
    values = re.findall(r'\d+', line[0]) if line else []
    if len(values) < 2:
        raise ValueError('Official 1982 West Bengal summary total missing')
    return int(values[-1])


def reconcile(row: dict, page: int, text: str) -> str:
    code = row['code']
    identity = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text, re.I)
    if (identity is None or int(identity[1]) != code
            or re.sub(r'\s+', ' ', identity[2]).strip() != re.sub(r'\s+', ' ', row['name']).strip()
            or page != code + 16 or 'CONSTITUENCY DATA - SUMMARY' not in text
            or 'LEGISLATIVE ASSEMBLY OF WEST BENGAL' not in text.upper()
            or row['state_name'] != 'West Bengal' or row['number_of_seats'] != 1
            or row['status'] != 'needs_review' or row.get('summary_page') is not None
            or row.get('source_warning_code') is not None):
        raise ValueError(f'Official 1982 West Bengal identity differs: {code}')
    electors = total(section(text, r'II\. ELECTORS', r'III\. ELECTORS WHO VOTED'))
    voters_section = section(text, r'III\. ELECTORS WHO VOTED', r'IV\. VOTES')
    votes_section = section(text, r'IV\. VOTES', r'V\. POLLING STATIONS')
    results = section(text, r'VII\. RESULT', r'rptConstituencySummary')
    if electors != row['electors'] or len(row['candidates']) < 1:
        raise ValueError(f'Official 1982 West Bengal electors differ: {code}')
    row['previous_review_note'] = row['error']
    row['original_extraction_warning'] = row['error']
    row['summary_page'] = page
    row['summary_source_file'] = SOURCE_FILE
    row['summary_source_sha256'] = SOURCE_SHA
    row['detail_source_file'] = SOURCE_FILE
    row['detail_source_sha256'] = SOURCE_SHA
    row['official_source_url'] = SOURCE_URL
    row['official_summary_constituency_name'] = identity[2].strip()
    row['official_summary_state'] = 'West Bengal'
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
            raise ValueError(f'Official 1982 West Bengal uncontested result differs: {code}')
        row['error'] = (f'Official 1982 West Bengal summary declares {candidate["candidate_name"]} '
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
        raise ValueError(f'Official 1982 West Bengal contested state differs: {code}')
    voters = total(voters_section)
    printed = {}
    for ordinal, label in ((1, 'POLLED'), (2, 'VALID'), (3, 'REJECTED'), (4, 'MISSING')):
        match = re.search(r'(?m)^\s*' + str(ordinal) + r'\. ' + label + r'\s+(\d+)', votes_section)
        if match is None:
            raise ValueError(f'Official 1982 West Bengal {label} missing: {code}')
        printed[label] = int(match[1])
    if (voters < 1 or voters > electors or voters != printed['POLLED']
            or (voters, printed['VALID']) != (row['votes_polled'], row['valid_candidate_votes'])
            or printed['VALID'] + printed['REJECTED'] != voters or printed['MISSING'] != 0):
        raise ValueError(f'Official 1982 West Bengal totals differ: {code}')
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
        raise ValueError(f'Official 1982 West Bengal result differs: {code}')
    for source, candidate in zip(declared, ranked[:2], strict=True):
        if (source[2].strip(), source[1], int(source[3])) != (
                candidate['candidate_name'], candidate['party_at_election'], candidate['votes']):
            raise ValueError(f'Official 1982 West Bengal candidate differs: {code}')
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



EXCEPTIONS = {26: {'code': 26,
      'name': 'PHANSIDEWA (ST)',
      'summary_page': 42,
      'detail_page': 314,
      'detail_totals': {'electors': 127854, 'votes_polled': 94213, 'valid_candidate_votes': 90370},
      'summary_electors': 127854,
      'summary_voters': 94203,
      'summary_votes': {'POLLED': 94203, 'VALID': 90380, 'REJECTED': 3833, 'MISSING': 0},
      'candidate_sum': 90370,
      'summary_declarations': [['Winner', 'CPM', 'PATRAS MINZ', '41362'],
                               ['Runner up', 'INC', 'ISWAR CHANDRA TIRKEY', '36076']],
      'summary_margin': 5286,
      'detail_top_two': [{'candidate_name': 'PATRAS MINZ',
                          'party_at_election': 'CPM',
                          'votes': 41362},
                         {'candidate_name': 'ISWAR CHANDRA TIRKEY',
                          'party_at_election': 'INC',
                          'votes': 36076}],
      'detail_margin': 5286,
      'original_warning': 'Candidate rows transcribed from the detailed PDF; independent summary '
                          'reconciliation is pending.'},
 58: {'code': 58,
      'name': 'MURSHIDABAD',
      'summary_page': 74,
      'detail_page': 318,
      'detail_totals': {'electors': 114467, 'votes_polled': 94145, 'valid_candidate_votes': 92470},
      'summary_electors': 114467,
      'summary_voters': 94145,
      'summary_votes': {'POLLED': 99955, 'VALID': 92470, 'REJECTED': 1675, 'MISSING': 0},
      'candidate_sum': 92470,
      'summary_declarations': [['Winner', 'FBL', 'CHHAYA GHOSH', '51353'],
                               ['Runner up', 'ICS', 'DEDAR BAKSHI', '39259']],
      'summary_margin': 12094,
      'detail_top_two': [{'candidate_name': 'CHHAYA GHOSH',
                          'party_at_election': 'FBL',
                          'votes': 51353},
                         {'candidate_name': 'DEDAR BAKSHI',
                          'party_at_election': 'ICS',
                          'votes': 39259}],
      'detail_margin': 12094,
      'original_warning': 'Candidate rows transcribed from the detailed PDF; independent summary '
                          'reconciliation is pending.'},
 234: {'code': 234,
       'name': 'MANBAZAR',
       'summary_page': 250,
       'detail_page': 342,
       'detail_totals': {'electors': 101542, 'votes_polled': 81240, 'valid_candidate_votes': 80132},
       'summary_electors': 101542,
       'summary_voters': 82240,
       'summary_votes': {'POLLED': 82240, 'VALID': 80132, 'REJECTED': 2108, 'MISSING': 0},
       'candidate_sum': 80132,
       'summary_declarations': [['Winner', 'CPM', 'KAMALA KANTA MAHATO', '36760'],
                                ['Runner up', 'INC', 'SITARAM MAHATO', '35401']],
       'summary_margin': 1359,
       'detail_top_two': [{'candidate_name': 'KAMALA KANTA MAHATO',
                           'party_at_election': 'CPM',
                           'votes': 36760},
                          {'candidate_name': 'SITARAM MAHATO',
                           'party_at_election': 'INC',
                           'votes': 35401}],
       'detail_margin': 1359,
       'original_warning': 'Candidate rows transcribed from the detailed PDF; independent summary '
                           'reconciliation is pending.'},
 245: {'code': 245,
       'name': 'RAIPUR (ST)',
       'summary_page': 261,
       'detail_page': 344,
       'detail_totals': {'electors': 107257, 'votes_polled': 85413, 'valid_candidate_votes': 83196},
       'summary_electors': 107257,
       'summary_voters': 85423,
       'summary_votes': {'POLLED': 85423, 'VALID': 83196, 'REJECTED': 2227, 'MISSING': 0},
       'candidate_sum': 83196,
       'summary_declarations': [['Winner', 'CPM', 'UPEN KISKU', '41609'],
                                ['Runner up', 'INC', 'BHOBATOSH SAREN', '28884']],
       'summary_margin': 12725,
       'detail_top_two': [{'candidate_name': 'UPEN KISKU',
                           'party_at_election': 'CPM',
                           'votes': 41609},
                          {'candidate_name': 'BHOBATOSH SAREN',
                           'party_at_election': 'INC',
                           'votes': 28894}],
       'detail_margin': 12715,
       'original_warning': 'Candidate rows transcribed from the detailed PDF; independent summary '
                           'reconciliation is pending.'},
 252: {'code': 252,
       'name': 'ONDA',
       'summary_page': 268,
       'detail_page': 345,
       'detail_totals': {'electors': 107835, 'votes_polled': 84280, 'valid_candidate_votes': 81995},
       'summary_electors': 107835,
       'summary_voters': 84287,
       'summary_votes': {'POLLED': 84287, 'VALID': 81995, 'REJECTED': 2335, 'MISSING': 0},
       'candidate_sum': 81952,
       'summary_declarations': [['Winner', 'FBL', 'ANIL MUKHOPADHYAY', '41801'],
                                ['Runner up', 'INC', 'SAMBHU NARAYAN GOSWAMI', '35342']],
       'summary_margin': 6459,
       'detail_top_two': [{'candidate_name': 'ANIL MUKHOPADHYAY',
                           'party_at_election': 'FBL',
                           'votes': 41801},
                          {'candidate_name': 'SAMBHU NARAYAN GOSWAMI',
                           'party_at_election': 'INC',
                           'votes': 35342}],
       'detail_margin': 6459,
       'original_warning': 'Candidate rows transcribed from the detailed PDF; independent summary '
                           'reconciliation is pending.; Extracted candidate votes do not match the '
                           'reported valid votes.'},
 259: {'code': 259,
       'name': 'HIRAPUR',
       'summary_page': 275,
       'detail_page': 346,
       'detail_totals': {'electors': 101097, 'votes_polled': 65340, 'valid_candidate_votes': 63290},
       'summary_electors': 101097,
       'summary_voters': 65768,
       'summary_votes': {'POLLED': 65768, 'VALID': 63718, 'REJECTED': 2050, 'MISSING': 0},
       'candidate_sum': 63290,
       'summary_declarations': [['Winner', 'CPM', 'BAMAPADA MUKHERJEE', '32790'],
                                ['Runner up', 'INC', 'SHIBDAS GHATAK', '28700']],
       'summary_margin': 4090,
       'detail_top_two': [{'candidate_name': 'BAMAPADA MUKHERJEE',
                           'party_at_election': 'CPM',
                           'votes': 32790},
                          {'candidate_name': 'SHIBDAS GHATAK',
                           'party_at_election': 'INC',
                           'votes': 28700}],
       'detail_margin': 4090,
       'original_warning': 'Candidate rows transcribed from the detailed PDF; independent summary '
                           'reconciliation is pending.'},
 264: {'code': 264,
       'name': 'DURGAPUR-I',
       'summary_page': 280,
       'detail_page': 347,
       'detail_totals': {'electors': 100777, 'votes_polled': 73972, 'valid_candidate_votes': 72453},
       'summary_electors': 100777,
       'summary_voters': 73952,
       'summary_votes': {'POLLED': 73952, 'VALID': 72453, 'REJECTED': 1499, 'MISSING': 0},
       'candidate_sum': 72453,
       'summary_declarations': [['Winner', 'CPM', 'DILIP MAZUMDAR', '35213'],
                                ['Runner up', 'INC', 'SUDEB ROY', '31791']],
       'summary_margin': 3422,
       'detail_top_two': [{'candidate_name': 'DILIP MAZUMDAR',
                           'party_at_election': 'CPM',
                           'votes': 35213},
                          {'candidate_name': 'SUDEB ROY',
                           'party_at_election': 'INC',
                           'votes': 31791}],
       'detail_margin': 3422,
       'original_warning': 'Candidate rows transcribed from the detailed PDF; independent summary '
                           'reconciliation is pending.'},
 181: {'code': 181,
       'name': 'CHAMPDANI',
       'summary_page': 197,
       'detail_page': 335,
       'detail_totals': {'electors': 87335, 'votes_polled': 91850, 'valid_candidate_votes': 89899},
       'summary_electors': 87335,
       'summary_voters': 91850,
       'summary_votes': {'POLLED': 91850, 'VALID': 89899, 'REJECTED': 1951, 'MISSING': 0},
       'candidate_sum': 89899,
       'summary_declarations': [['Winner', 'CPM', 'SAILENDRA NATH CHATTOPADHYAY', '47301'],
                                ['Runner up', 'INC', 'SWARAJ MUKHOPADHYAY', '40682']],
       'summary_margin': 6619,
       'detail_margin': 6619,
       'original_warning': 'Candidate rows transcribed from the detailed PDF; independent summary '
                           'reconciliation is pending.; Reported elector and voter totals are '
                           'inconsistent.'}}


def reconcile_exception(row: dict, text: str, detail: str) -> None:
    source = EXCEPTIONS[row['code']]
    identity = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text)
    if (identity is None or int(identity[1]) != row['code'] or identity[2].strip() != source['name']
            or row['name'] != source['name'] or row['state_name'] != 'West Bengal'
            or row['number_of_seats'] != 1 or row['status'] != 'needs_review'
            or row['error'] != source['original_warning'] or row.get('source_warning_code') is not None
            or row['detail_page'] != source['detail_page'] or 'LEGISLATIVE ASSEMBLY OF WEST BENGAL' not in text.upper()):
        raise ValueError('West Bengal discrepancy identity differs')
    for key, value in source['detail_totals'].items():
        if row.get(key) != value:
            raise ValueError('West Bengal detailed total differs')
    block = re.search(r'Constituency\s*:\s*' + str(row['code']) + r'\s*\.\s*' + re.escape(row['name']) + r'(.*?)(?=Constituency\s*:|rptDetailedResults|$)', detail, re.S)
    totals = re.search(r'ELECTORS\s*:\s*(\d+)\s+VOTERS\s*:\s*(\d+).*?VALID VOTES\s*:\s*(\d+)', block[1] if block else '', re.S)
    if totals is None or tuple(map(int, totals.groups())) != tuple(source['detail_totals'].values()):
        raise ValueError('West Bengal detail source differs')
    electors = total(section(text, r'II\. ELECTORS', r'III\. ELECTORS WHO VOTED'))
    voters = total(section(text, r'III\. ELECTORS WHO VOTED', r'IV\. VOTES'))
    votes = section(text, r'IV\. VOTES', r'V\. POLLING STATIONS')
    printed = {label: int(re.search(r'(?m)^\s*' + str(n) + r'\. ' + label + r'\s+(\d+)', votes)[1])
               for n, label in [(1, 'POLLED'), (2, 'VALID'), (3, 'REJECTED'), (4, 'MISSING')]}
    result = section(text, r'VII\. RESULT', r'rptConstituencySummary')
    declarations = [list(v) for v in re.findall(r'^\s*(Winner|Runner up)\s*:?\s+(\S+)\s+(.+?)\s+(\d+)\s*$', result, re.M)]
    margin = int(re.search(r'MARGIN\s*:\s*(\d+)', result)[1])
    ranked = sorted(row['candidates'], key=lambda c: c['votes'], reverse=True)
    if (electors != source['summary_electors'] or voters != source['summary_voters']
            or printed != source['summary_votes'] or declarations != source['summary_declarations']
            or margin != source['summary_margin'] or sum(c['votes'] for c in ranked) != source['candidate_sum']
            or ranked[0]['votes'] - ranked[1]['votes'] != source['detail_margin']):
        raise ValueError('West Bengal summary discrepancy differs')
    row.update({'previous_review_note': row['error'], 'original_extraction_warning': row['error'],
                'summary_page': source['summary_page'], 'summary_source_file': SOURCE_FILE,
                'summary_source_sha256': SOURCE_SHA, 'detail_source_file': SOURCE_FILE,
                'detail_source_sha256': SOURCE_SHA, 'official_source_url': SOURCE_URL,
                'official_summary_constituency_name': source['name'], 'official_summary_state': 'West Bengal',
                'source_warning_code': 'official_ac_source_discrepancy',
                'summary_totals': {'electors': electors, 'votes_polled': printed['POLLED'], 'valid_candidate_votes': printed['VALID']},
                'source_discrepancy': {'field': 'official_report_totals', 'detail_totals': source['detail_totals'],
                    'summary_voters': voters, 'summary_votes': printed, 'candidate_sum': source['candidate_sum'],
                    'detail_margin': source['detail_margin'], 'summary_margin': margin},
                'summary_result': {'winner': declarations[0][2], 'winner_party': declarations[0][1], 'winner_votes': int(declarations[0][3]),
                    'runner': declarations[1][2], 'runner_party': declarations[1][1], 'runner_votes': int(declarations[1][3]), 'margin': margin}})
    row['error'] = (f'Official detail: {row["electors"]:,} electors, {row["votes_polled"]:,} voters, '
                    f'{row["valid_candidate_votes"]:,} valid votes; candidate sum {source["candidate_sum"]:,}. '
                    f'Summary: {electors:,} electors, {voters:,} voters, POLLED {printed["POLLED"]:,}, '
                    f'VALID {printed["VALID"]:,}, REJECTED {printed["REJECTED"]:,}. '
                    f'Summary winner {declarations[0][2]} ({declarations[0][1]}) {declarations[0][3]} votes; '
                    f'runner {declarations[1][2]} ({declarations[1][1]}) {declarations[1][3]}; margin {margin:,}. '
                    f'Detailed runner {ranked[1]["votes"]:,}; calculated detailed margin {source["detail_margin"]:,}. '
                    'Source inconsistency retained for review; turnout and aggregate result are withheld.')


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    source = folder / SOURCE_FILE
    original = folder / 'extraction.json'
    if (source.is_symlink() or original.is_symlink() or digest(source.read_bytes()) != SOURCE_SHA):
        raise ValueError('1982 West Bengal official source differs')
    old_body = original.read_bytes()
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    before = json.loads(old_body)
    after = json.loads(old_body)
    if (digest(old_body) != PREDECESSOR or manifest['url'] != SOURCE_URL
            or next(file for file in manifest['files'] if file['file'] == SOURCE_FILE)['sha256'] != SOURCE_SHA
            or before['kind'] != 'ac' or before['year'] != 1982
            or before['source_url'] != SOURCE_URL or before['source_file'] != SOURCE_FILE
            or before['source_sha256'] != SOURCE_SHA or len(before['records']) != 294
            or [row['code'] for row in before['records']] != list(range(1, 295))):
        raise ValueError('1982 West Bengal extraction provenance differs')
    kinds = {'contested': [], 'uncontested': []}
    with fitz.open(source) as pdf:
        if len(pdf) != 351:
            raise ValueError('1982 West Bengal PDF page coverage differs')
        for row in after['records']:
            code = row['code']
            if code in EXCEPTIONS:
                reconcile_exception(row, pdf[code + 15].get_text(sort=True), pdf[row['detail_page'] - 1].get_text(sort=True))
                continue
            kind = reconcile(row, code + 16, pdf[code + 15].get_text(sort=True))
            kinds[kind].append(code)
    if len(kinds['contested']) != 286 or len(kinds['uncontested']) != 0:
        raise ValueError('1982 West Bengal contest coverage differs')
    common = {'previous_review_note', 'original_extraction_warning', 'summary_page',
              'summary_source_file', 'summary_source_sha256', 'detail_source_file',
              'detail_source_sha256', 'official_source_url', 'official_summary_constituency_name',
              'official_summary_state', 'error', 'source_warning_code', 'summary_totals'}
    for old, new in zip(before['records'], after['records'], strict=True):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        expected = common | ({'summary_result'} if old['code'] in kinds['contested'] else {'summary_source_rows'})
        if old['code'] in EXCEPTIONS:
            expected = common | {'summary_result', 'source_discrepancy'}
        if (old['code'] != new['code'] or old['candidates'] != new['candidates'] or changed != expected):
            raise ValueError(f'Unrelated 1982 West Bengal evidence changed: {old["code"]}')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{PREDECESSOR}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='ac-1982-west-bengal-', dir=root / 'exports') as temporary:
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
                'scope': '286 ordinary declarations and eight source exceptions; 294 preserved AC records',
                'edition': EDITION, 'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
                'source_sha256': SOURCE_SHA, 'previous_sha256': PREDECESSOR,
                'new_sha256': digest(new_body), 'contested_codes': kinds['contested'],
                'uncontested_codes': kinds['uncontested'], 'discrepancy_codes': sorted(EXCEPTIONS),
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
