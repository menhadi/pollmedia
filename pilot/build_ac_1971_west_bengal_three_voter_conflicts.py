"""Record three 1971 West Bengal declarations with contradictory voter totals."""

import hashlib
import io
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_pc_1951_multi_seat_declared_members import multi_seat_import_script
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
EDITION = 'fed20e0bd380929811cd1a1f'
NAME = 'pollmedia-ac-1971-west-bengal-three-voter-conflict-results-20261005'
PRIOR = 'pollmedia-ac-1971-west-bengal-276-reconciled-results-20261005'
PRIOR_ZIP_SHA = '49055c01199aae3fa2a0aa6e6c2a925a07917010e29031ef59e6713887766b7a'
PREDECESSOR = '7178230ad260ce7f2ea4dd78d2b8bbe5eadf06037da6f778579209f814417359'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3186-west-bengal-general-legislative-election-1971/'
SOURCE_FILE = f'{EDITION}-7309.pdf'
SOURCE_SHA = '6350616cdca377cb5fe5e77006e098e966e2bdbcdc60a08d25d4b0cae832a305'
PENDING = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.'
NOTE = ('The official summary voter total conflicts with its valid and rejected vote totals. '
        'The declared winner and margin are shown for review; turnout is withheld.')
# code: (name, summary page, detail page, electors, detail voters, summary voters,
#        valid votes, rejected votes, candidate count, winner, party, votes, runner, party, votes, margin)
SPECS = {
    84: ('DEGANGA', 100, 309, 74781, 47151, 46831, 43369, 3782, 8,
         'HARUN OP RASHID', 'IND', 20142, 'M. SAWKFTALI', 'INC', 9191, 10951),
    92: ('SANDESHKHALI (ST)', 108, 311, 73851, 51512, 51422, 49289, 2223, 5,
         'SARAT SARDER', 'CPM', 20053, 'DEBENDRA NATH SINHA', 'INC', 20006, 47),
    98: ('BARUIPUR (SC)', 114, 312, 78104, 57293, 87293, 54571, 2722, 5,
         'BIMAL MISTRY', 'CPM', 19711, 'RAM KANTA MANDAL', 'INC', 19265, 446),
}


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def number(text: str, ordinal: int, label: str) -> int:
    match = re.search(r'(?m)^\s*' + str(ordinal) + r'\.\s+' + label + r'\s+([^\n]+)', text, re.I)
    if match is None:
        raise ValueError(f'Official 1971 West Bengal {label} row missing')
    digits = re.findall(r'\d+', match[1])
    if not digits:
        raise ValueError(f'Official 1971 West Bengal {label} value missing')
    return int(digits[-1] if label == 'TOTAL' else digits[0])


def reconcile(row: dict, text: str, spec: tuple) -> None:
    (name, page, detail_page, electors, detail_voters, summary_voters, valid, rejected,
     count, winner, winner_party, winner_votes, runner, runner_party, runner_votes, margin) = spec
    code = row['code']
    identity = re.search(r'Field7:CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text, re.I)
    if (identity is None or int(identity[1]) != code or identity[2].strip() != name
            or row['name'] != name or page != code + 16 or row['detail_page'] != detail_page
            or row['state_name'] != 'West Bengal' or row['number_of_seats'] != 1
            or row['status'] != 'needs_review' or row['error'] != PENDING
            or row.get('summary_page') is not None or row.get('source_warning_code') is not None
            or (row['electors'], row['votes_polled'], row['valid_candidate_votes']) !=
            (electors, detail_voters, valid) or len(row['candidates']) != count
            or 'LEGISLATIVE ASSEMBLY OF WEST BENGAL' not in text.upper()):
        raise ValueError(f'1971 West Bengal conflict identity differs: {code}')
    e = text[text.index('II. ELECTORS'):text.index('III. ELECTORS WHO VOTED')]
    v = text[text.index('III. ELECTORS WHO VOTED'):text.index('IV. VOTES')]
    votes = text[text.index('IV. VOTES'):text.index('V. POLLING STATIONS')]
    result = text[text.index('VII. RESULT'):text.index('rptConstituencySummary')]
    heading_totals = (number(e, 3, 'TOTAL'), number(v, 3, 'TOTAL'),
                      number(votes, 1, 'POLLED'), number(votes, 2, 'VALID'),
                      number(votes, 3, 'REJECTED'), number(votes, 4, 'MISSING'))
    declared = re.findall(r'^\s*(Winner|Runner up)\s*:\s*(\S+)\s+(.+?)\s+(\d+)\s*$',
                          result, re.I | re.M)
    printed_margin = re.search(r'MARGIN\s*:\s*(\d+)', result, re.I)
    ranked = sorted(row['candidates'], key=lambda candidate: candidate['votes'], reverse=True)
    if (heading_totals != (electors, summary_voters, summary_voters, valid, rejected, 0)
            or summary_voters == detail_voters or valid + rejected != detail_voters
            or sum(candidate['votes'] for candidate in ranked) != valid
            or len(declared) != 2 or printed_margin is None or int(printed_margin[1]) != margin
            or (winner, winner_party, winner_votes, runner, runner_party, runner_votes) !=
            (ranked[0]['candidate_name'], ranked[0]['party_at_election'], ranked[0]['votes'],
             ranked[1]['candidate_name'], ranked[1]['party_at_election'], ranked[1]['votes'])
            or (declared[0][2].strip(), declared[0][1], int(declared[0][3]),
                declared[1][2].strip(), declared[1][1], int(declared[1][3])) !=
            (winner, winner_party, winner_votes, runner, runner_party, runner_votes)
            or winner_votes - runner_votes != margin):
        raise ValueError(f'1971 West Bengal printed conflict/result differs: {code}')
    row['previous_review_note'] = row['error']
    row['original_extraction_warning'] = row['error']
    row['error'] = NOTE
    row['source_warning_code'] = 'official_ac_voter_total_conflict'
    row['summary_page'] = page
    row['summary_source_file'] = SOURCE_FILE
    row['summary_source_sha256'] = SOURCE_SHA
    row['detail_source_file'] = SOURCE_FILE
    row['detail_source_sha256'] = SOURCE_SHA
    row['official_source_url'] = SOURCE_URL
    row['official_summary_state'] = 'West Bengal'
    row['official_summary_constituency_name'] = identity[2].strip()
    row['summary_totals'] = {'electors': electors, 'votes_polled': summary_voters,
                             'valid_candidate_votes': valid}
    row['source_discrepancy'] = {'field': 'votes_polled', 'detail_value': detail_voters,
                                 'summary_value': summary_voters, 'valid_votes': valid,
                                 'rejected_votes': rejected}
    row['summary_result'] = {'winner': winner, 'winner_party': winner_party,
                             'winner_votes': winner_votes, 'runner': runner,
                             'runner_party': runner_party, 'runner_votes': runner_votes,
                             'margin': margin}


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    prior = root / 'exports' / (PRIOR + '.zip')
    source = folder / SOURCE_FILE
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    if (digest(prior.read_bytes()) != PRIOR_ZIP_SHA or digest(source.read_bytes()) != SOURCE_SHA
            or manifest['url'] != SOURCE_URL
            or next(file for file in manifest['files'] if file['file'] == SOURCE_FILE)['sha256'] != SOURCE_SHA):
        raise ValueError('1971 West Bengal conflict provenance differs')
    with zipfile.ZipFile(prior) as release:
        with zipfile.ZipFile(io.BytesIO(release.read(f'correction-{EDITION}.zip'))) as inner:
            old_body = inner.read(f'election-archive/{EDITION}/extraction.json')
    if digest(old_body) != PREDECESSOR:
        raise ValueError('1971 West Bengal predecessor differs')
    before = json.loads(old_body)
    after = json.loads(old_body)
    if (before['kind'] != 'ac' or before['year'] != 1971 or len(before['records']) != 279
            or [row['code'] for row in before['records']] != [code for code in range(1, 281) if code != 129]):
        raise ValueError('1971 West Bengal archive shape differs')
    with fitz.open(source) as pdf:
        if len(pdf) != 339:
            raise ValueError('1971 West Bengal PDF page coverage differs')
        for row in after['records']:
            if row['code'] in SPECS:
                reconcile(row, pdf[SPECS[row['code']][1] - 1].get_text(sort=True), SPECS[row['code']])
    fields = {'previous_review_note', 'original_extraction_warning', 'error', 'source_warning_code',
              'summary_page', 'summary_source_file', 'summary_source_sha256', 'detail_source_file',
              'detail_source_sha256', 'official_source_url', 'official_summary_state',
              'official_summary_constituency_name', 'summary_totals', 'source_discrepancy', 'summary_result'}
    for old, new in zip(before['records'], after['records'], strict=True):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        if (old['code'] != new['code'] or old['candidates'] != new['candidates']
                or changed != (fields if old['code'] in SPECS else set())):
            raise ValueError(f'Unrelated 1971 West Bengal evidence changed: {old["code"]}')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{PREDECESSOR}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='ac-1971-west-bengal-conflicts-', dir=root / 'exports') as temporary:
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
                'scope': 'Three 1971 West Bengal declarations with conflicting voter totals',
                'edition': EDITION, 'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
                'source_sha256': SOURCE_SHA, 'previous_sha256': PREDECESSOR,
                'new_sha256': digest(new_body), 'conflict_codes': sorted(SPECS),
            }, indent=2))
            release.writestr('IMPORT.sh', multi_seat_import_script())
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'previous_sha256': PREDECESSOR,
            'new_sha256': digest(new_body), 'conflict_codes': sorted(SPECS)}


if __name__ == '__main__':
    print(json.dumps(build()))
