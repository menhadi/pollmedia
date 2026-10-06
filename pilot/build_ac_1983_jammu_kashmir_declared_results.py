"""Corroborate 1983 Jammu & Kashmir AC declarations with the official report."""

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
EDITION = '2f28536c3658989c7519ffc4'
NAME = 'pollmedia-ac-1983-jammu-kashmir-reviewed-results-20261006'
PREDECESSOR = 'b080b003281e1f850da250cf5cbb319b204e71e1e4f5e1bee56f659897cee340'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3792-jammu-kashmir-1983/'
SOURCE_FILE = '2f28536c3658989c7519ffc4-8926.pdf'
SOURCE_SHA = 'f61275e0eb88b574c453c83caead37e039b04d86ffcc45371cc3bfa9a0f4010a'
PENDING = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.'
UNCONTESTED = PENDING + '; Reported elector and voter totals are inconsistent.'


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def section(text: str, start: str, end: str) -> str:
    match = re.search(start + r'\b(.*?)' + end, text, re.I | re.S)
    if match is None:
        raise ValueError(f'Official 1983 Jammu & Kashmir summary section missing: {start}')
    return match[1]


def total(text: str) -> int:
    line = re.search(r'(?m)^\s*3\. TOTAL[^\n]*$', text)
    values = re.findall(r'\d+', line[0]) if line else []
    if len(values) < 2:
        raise ValueError('Official 1983 Jammu & Kashmir summary total missing')
    return int(values[-1])


def reconcile(row: dict, page: int, text: str) -> str:
    code = row['code']
    identity = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text, re.I)
    if (identity is None or int(identity[1]) != code
            or re.sub(r'\s+', ' ', identity[2]).strip() != re.sub(r'\s+', ' ', row['name']).strip()
            or page != code + 10 or 'CONSTITUENCY DATA - SUMMARY' not in text
            or 'LEGISLATIVE ASSEMBLY OF JAMMU & KASHMIR' not in text.upper()
            or row['state_name'] != 'Jammu & Kashmir' or row['number_of_seats'] != 1
            or row['status'] != 'needs_review' or row.get('summary_page') is not None
            or row.get('source_warning_code') is not None):
        raise ValueError(f'Official 1983 Jammu & Kashmir identity differs: {code}')
    electors = total(section(text, r'II\. ELECTORS', r'III\. ELECTORS WHO VOTED'))
    voters_section = section(text, r'III\. ELECTORS WHO VOTED', r'IV\. VOTES')
    votes_section = section(text, r'IV\. VOTES', r'V\. POLLING STATIONS')
    results = section(text, r'VII\. RESULT', r'rptConstituencySummary')
    if electors != row['electors'] or len(row['candidates']) < 1:
        raise ValueError(f'Official 1983 Jammu & Kashmir electors differ: {code}')
    row['previous_review_note'] = row['error']
    row['original_extraction_warning'] = row['error']
    row['summary_page'] = page
    row['summary_source_file'] = SOURCE_FILE
    row['summary_source_sha256'] = SOURCE_SHA
    row['detail_source_file'] = SOURCE_FILE
    row['detail_source_sha256'] = SOURCE_SHA
    row['official_source_url'] = SOURCE_URL
    row['official_summary_constituency_name'] = identity[2].strip()
    row['official_summary_state'] = 'Jammu & Kashmir'
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
            raise ValueError(f'Official 1983 Jammu & Kashmir uncontested result differs: {code}')
        row['error'] = (f'Official 1983 Jammu & Kashmir summary declares {candidate["candidate_name"]} '
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
        raise ValueError(f'Official 1983 Jammu & Kashmir contested state differs: {code}')
    voters = total(voters_section)
    printed = {}
    for ordinal, label in ((1, 'POLLED'), (2, 'VALID'), (3, 'REJECTED'), (4, 'MISSING')):
        match = re.search(r'(?m)^\s*' + str(ordinal) + r'\. ' + label + r'\s+(\d+)', votes_section)
        if match is None:
            raise ValueError(f'Official 1983 Jammu & Kashmir {label} missing: {code}')
        printed[label] = int(match[1])
    if (voters < 1 or voters > electors or voters != printed['POLLED']
            or (voters, printed['VALID']) != (row['votes_polled'], row['valid_candidate_votes'])
            or printed['VALID'] + printed['REJECTED'] != voters or printed['MISSING'] != 0):
        raise ValueError(f'Official 1983 Jammu & Kashmir totals differ: {code}')
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
        raise ValueError(f'Official 1983 Jammu & Kashmir result differs: {code}')
    for source, candidate in zip(declared, ranked[:2], strict=True):
        if (source[2].strip(), source[1], int(source[3])) != (
                candidate['candidate_name'], candidate['party_at_election'], candidate['votes']):
            raise ValueError(f'Official 1983 Jammu & Kashmir candidate differs: {code}')
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


EXCEPTIONS = {18: {'detail_page': 89,
      'detail_sha256': 'd89212b8693b5332dd355925e94fe1840e3df4950318e24cdf26ca6d63df8698',
      'name': 'HABAKADAL',
      'summary_page': 28,
      'summary_sha256': '7f0cbd5d65037c1428a75a517a21365a4adcd1291df7e52daa675f01c95fbc27'},
 23: {'detail_page': 90,
      'detail_sha256': '8263f78ed1ec31b535c8265ee5290bcac6ef58143c3fea746787040a92c80cfe',
      'name': 'BEERWAH',
      'summary_page': 33,
      'summary_sha256': 'a35e7d55c333752aa482898ae8bfc7797c1bdf1aec4284b120069baecf3fabcc'},
 48: {'detail_page': 94,
      'detail_sha256': 'a6ffbe8e5a50f2ebd7b78aee05b65eaedf8940cb1e75d9f60f4ac06f7dcfd699',
      'name': 'DODA',
      'summary_page': 58,
      'summary_sha256': '59a646ad14bd8bb8018a48bd31336f32396282c0a4c097de7379a61b26d297d7'},
 50: {'detail_page': 95,
      'detail_sha256': '0567d08c252ad28143dcb43db39a57abeca1a79e7d7977fdf4393886e21dc285',
      'name': 'BANIHAL',
      'summary_page': 60,
      'summary_sha256': '7fdafce4fe493a8b36b4d5bf6cd0e21adc88e3e942cdd6c7fda672f2f277f151'}}

def annotate_exception(row: dict, pdf) -> None:
    code = row['code']; source = EXCEPTIONS[code]
    text = pdf[source['summary_page']-1].get_text(sort=True)
    detail = pdf[source['detail_page']-1].get_text(sort=True)
    block = re.search(r'Constituency\s+' + str(code) + r'\s+' + re.escape(source['name']) + r'(.*?)(?=Constituency\s+|rptDetailedResults|$)', detail, re.S)
    detail_text = block[0] if block else ''
    if code == 18:
        detail_text += pdf[89].get_text(sort=True).split('Constituency')[0]
    if (row['name'] != source['name'] or row['state_name'] != 'Jammu & Kashmir'
            or row['detail_page'] != source['detail_page'] or row['status'] != 'needs_review'
            or row['number_of_seats'] != 1 or digest(text.encode()) != source['summary_sha256']
            or digest(detail_text.encode()) != source['detail_sha256']):
        raise ValueError('JK source exception fingerprint differs')
    row.update({'previous_review_note': row['error'], 'original_extraction_warning': row['error'],
                'summary_page': source['summary_page'], 'summary_source_file': SOURCE_FILE,
                'summary_source_sha256': SOURCE_SHA, 'detail_source_file': SOURCE_FILE,
                'detail_source_sha256': SOURCE_SHA, 'official_source_url': SOURCE_URL,
                'official_summary_constituency_name': source['name'], 'official_summary_state': 'Jammu & Kashmir',
                'source_warning_code': 'official_ac_source_discrepancy'})
    if code == 48:
        if (len(row['candidates']) != 1 or row['candidates'][0]['candidate_name'] != 'RESULT WHITHHEAL BY HIGH COURT OF J AND K'
                or row['candidates'][0]['votes'] != 0 or row['electors'] != 1 or row['votes_polled'] != 0):
            raise ValueError('Doda original court-held placeholder differs')
        row['error'] = ('Official report states RESULT WHITHHEAL BY HIGH COURT OF J AND K in the candidate slot. '
                        'This is a court-withheld-result placeholder, not a named winner. Original zero vote cells '
                        'and one-elector placeholder are retained as extraction evidence; no winner or turnout is inferred.')
        row['source_warning_code'] = 'official_result_withheld_by_court'
        row['summary_totals'] = {'electors': 0, 'votes_polled': None, 'valid_candidate_votes': None}
        return
    electors = total(section(text, r'II\. ELECTORS', r'III\. ELECTORS WHO VOTED'))
    voters = total(section(text, r'III\. ELECTORS WHO VOTED', r'IV\. VOTES'))
    votes = section(text, r'IV\. VOTES', r'V\. POLLING STATIONS')
    valid = int(re.search(r'2\. VALID\s+(\d+)',votes)[1])
    result = section(text, r'VII\. RESULT', r'rptConstituencySummary')
    declared = re.findall(r'^\s*(Winner|Runner up)\s*:?\s+(\S+)\s+(.+?)\s+(\d+)\s*$', result,re.M)
    margin = int(re.search(r'MARGIN\s*:\s*(\d+)',result)[1]);ranked=sorted(row['candidates'],key=lambda c:c['votes'],reverse=True)
    if (len(declared)!=2 or sum(c['votes'] for c in ranked)!=valid or margin!=ranked[0]['votes']-ranked[1]['votes']
            or [(v[2].strip(),v[1],int(v[3])) for v in declared] != [(c['candidate_name'],c['party_at_election'],c['votes']) for c in ranked[:2]]):
        raise ValueError('JK source exception result differs')
    row['summary_totals'] = {'electors':electors,'votes_polled':voters,'valid_candidate_votes':valid}
    row['summary_result'] = {'winner':ranked[0]['candidate_name'],'winner_party':ranked[0]['party_at_election'],'winner_votes':ranked[0]['votes'],
                             'runner':ranked[1]['candidate_name'],'runner_party':ranked[1]['party_at_election'],'runner_votes':ranked[1]['votes'],'margin':margin}
    row['error'] = (f'Official detail reports {row["electors"]:,} electors and {row["votes_polled"]:,} voters; '
                    f'summary reports {electors:,} electors and {voters:,} voters. Both report {valid:,} valid votes. '
                    f'Source-declared winner {ranked[0]["candidate_name"]} ({ranked[0]["party_at_election"]}), {ranked[0]["votes"]:,} votes; '
                    f'runner {ranked[1]["candidate_name"]} ({ranked[1]["party_at_election"]}), {ranked[1]["votes"]:,}; margin {margin:,}. '
                    'Source discrepancy retained for review; turnout and aggregate results withheld.')


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    source = folder / SOURCE_FILE
    original = folder / 'extraction.json'
    if (source.is_symlink() or original.is_symlink() or digest(source.read_bytes()) != SOURCE_SHA):
        raise ValueError('1983 Jammu & Kashmir official source differs')
    old_body = original.read_bytes()
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    before = json.loads(old_body)
    after = json.loads(old_body)
    if (digest(old_body) != PREDECESSOR or manifest['url'] != SOURCE_URL
            or next(file for file in manifest['files'] if file['file'] == SOURCE_FILE)['sha256'] != SOURCE_SHA
            or before['kind'] != 'ac' or before['year'] != 1983
            or before['source_url'] != SOURCE_URL or before['source_file'] != SOURCE_FILE
            or before['source_sha256'] != SOURCE_SHA or len(before['records']) != 76
            or [row['code'] for row in before['records']] != list(range(1, 77))):
        raise ValueError('1983 Jammu & Kashmir extraction provenance differs')
    kinds = {'contested': [], 'uncontested': []}
    with fitz.open(source) as pdf:
        if len(pdf) != 101:
            raise ValueError('1983 Jammu & Kashmir PDF page coverage differs')
        for row in after['records']:
            code = row['code']
            if code in EXCEPTIONS:
                annotate_exception(row, pdf)
                continue
            kind = reconcile(row, code + 10, pdf[code + 9].get_text(sort=True))
            kinds[kind].append(code)
    if len(kinds['contested']) != 72 or len(kinds['uncontested']) != 0:
        raise ValueError('1983 Jammu & Kashmir contest coverage differs')
    common = {'previous_review_note', 'original_extraction_warning', 'summary_page',
              'summary_source_file', 'summary_source_sha256', 'detail_source_file',
              'detail_source_sha256', 'official_source_url', 'official_summary_constituency_name',
              'official_summary_state', 'error', 'source_warning_code', 'summary_totals'}
    for old, new in zip(before['records'], after['records'], strict=True):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        expected = common | ({'summary_result'} if old['code'] in kinds['contested'] else {'summary_source_rows'})
        if old['code'] in EXCEPTIONS:
            expected = common | ({'summary_result'} if old['code'] != 48 else set())
        if (old['code'] != new['code'] or old['candidates'] != new['candidates'] or changed != expected):
            raise ValueError(f'Unrelated 1983 Jammu & Kashmir evidence changed: {old["code"]}')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{PREDECESSOR}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='ac-1983-jammu-kashmir-', dir=root / 'exports') as temporary:
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
                'scope': '72 ordinary plus three discrepancies and one court-withheld result; 76 preserved 1983 Jammu & Kashmir AC declarations',
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
