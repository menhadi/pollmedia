"""Review printed summary name truncations in the 1962 Madhya Pradesh AC report."""

import hashlib
import io
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_ac_1962_madras_reconciled_results import row_total
from build_ac_1962_rajasthan_reconciled_one_seat_results import norm, section, total
from build_pc_1951_multi_seat_declared_members import multi_seat_import_script
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
EDITION = 'd4f4dc0eb0e3d6961ecf2b4a'
NAME = 'pollmedia-ac-1962-madhya-pradesh-winner-name-layout-20261005'
PREDECESSOR = '701155d46431c727c7e14c87cea4d5fde5f3eef700189dc878bc322e66b5bfbe'
PRIOR_RELEASE = 'pollmedia-ac-1962-madhya-pradesh-13-voter-discrepancy-results-20261005'
PRIOR_RELEASE_SHA = 'e8000a61bf55d3bcf4bff974aeaf961467b85020b0cedcf296482d0c12ef9f88'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3729-madhya-pradesh-1962/'
SOURCE_FILE = f'{EDITION}-8774.pdf'
SOURCE_SHA = 'f1f45af916664175b214a4744f0975f27f01baab69465b8f647579a1c5163588'
PREVIOUS_ERROR = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.'
# code: (field, verbatim printed summary name, verbatim detailed candidate name)
NAMES = {
    219: ('winner', 'MAULANA INMAYATULLAH KHAN TARZI', 'MAULANA INMAYATULLAH KHAN TARZI MASHRIQI'),
}


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def review(row: dict, page: int, text: str) -> None:
    code = row['code']
    field, printed, detailed = NAMES[code]
    identity = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text, re.I)
    if (identity is None or int(identity[1]) != code or norm(identity[2]) != norm(row['name'])
            or 'LEGISLATIVE ASSEMBLY OF MADHYA PRADESH' not in text.upper()
            or 'CONSTITUENCY DATA - SUMMARY' not in text
            or row['state_name'] != 'Madhya Pradesh' or row['number_of_seats'] != 1
            or row['status'] != 'needs_review' or row['error'] != PREVIOUS_ERROR
            or row.get('summary_page') is not None or row.get('source_warning_code') is not None):
        raise ValueError(f'1962 Madhya Pradesh name-review identity differs: {code}')
    electors = total(section(text, r'II\. ELECTORS', r'III\. ELECTORS WHO VOTED'))
    voters = total(section(text, r'III\. ELECTORS WHO VOTED', r'IV\. VOTES'))
    votes = section(text, r'IV\. VOTES', r'V\. POLLING STATIONS')
    polled = row_total(votes, 1, 'POLLED')
    valid = row_total(votes, 2, 'VALID')
    rejected = row_total(votes, 3, 'REJECTED')
    missing = row_total(votes, 4, 'MISSING')
    result = section(text, r'VII\. RESULT', r'rptConstituencySummary')
    declared = re.findall(r'^[ \t]*(Winner|Runner up)[ \t]*:?[ \t]+(\S+)[ \t]+(.+?)[ \t]+(\d+)[ \t]*$', result, re.I | re.M)
    margin = re.search(r'MARGIN\s*:\s*(\d+)', result, re.I)
    ranked = sorted(row['candidates'], key=lambda candidate: candidate['votes'], reverse=True)
    if (electors != row['electors'] or voters != polled or voters != row['votes_polled'] + missing
            or missing != 0
            or not (0 < valid <= row['votes_polled'] <= voters <= electors)
            or valid != row['valid_candidate_votes'] or valid + rejected != row['votes_polled']
            or sum(candidate['votes'] for candidate in ranked) != valid
            or len(ranked) < 2 or ranked[0]['votes'] <= ranked[1]['votes']
            or len({(candidate['candidate_name'], candidate['party_at_election'], candidate['votes']) for candidate in ranked}) != len(ranked)
            or len(declared) != 2 or [part[0].lower() for part in declared] != ['winner', 'runner up']
            or margin is None or int(margin[1]) != ranked[0]['votes'] - ranked[1]['votes']):
        raise ValueError(f'1962 Madhya Pradesh name-review totals differ: {code}')
    for position, candidate in enumerate(ranked[:2]):
        source = declared[position]
        expected_name = printed if field == ('winner' if position == 0 else 'runner') else candidate['candidate_name']
        if ((source[2].strip(), source[1], int(source[3])) !=
                (expected_name, candidate['party_at_election'], candidate['votes'])):
            raise ValueError(f'1962 Madhya Pradesh name-review declaration differs: {code}')
    if ranked[0 if field == 'winner' else 1]['candidate_name'] != detailed:
        raise ValueError(f'1962 Madhya Pradesh detailed candidate differs: {code}')
    if re.search(r'Winner\s*:\s*INC\s+MAULANA INMAYATULLAH KHAN TARZI\s+13711\s+MASHRIQI\s+Runner up\s*:\s*JS\s+RAJMAL\s+12595\s+MARGIN\s*:\s*1116', result, re.I | re.S) is None:
        raise ValueError(f'1962 Madhya Pradesh wrapped winner name differs: {code}')
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
    row['source_warning_code'] = 'official_summary_turnout_only'
    row['official_summary_name_layout'] = {'field': field, 'before_vote_cell': printed,
                                            'after_vote_cell': 'MASHRIQI', 'detail_value': detailed}
    row['summary_totals'] = {'electors': electors, 'votes_polled': voters, 'valid_candidate_votes': valid}
    row['summary_result'] = {'winner': detailed, 'winner_party': ranked[0]['party_at_election'],
                             'winner_votes': ranked[0]['votes'], 'runner': declared[1][2].strip(),
                             'runner_party': ranked[1]['party_at_election'], 'runner_votes': ranked[1]['votes'],
                             'margin': int(margin[1])}
    row['winner'] = ranked[0]['candidate_name']
    row['margin'] = int(margin[1])
    row['error'] = (f'Official summary wraps {field} {detailed} after the vote cell; detailed candidate '
                    'row and summary party, votes, turnout and margin agree. The printed layout remains for review.')


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    source = folder / SOURCE_FILE
    prior_release = root / 'exports' / (PRIOR_RELEASE + '.zip')
    if (source.is_symlink() or digest(source.read_bytes()) != SOURCE_SHA
            or prior_release.is_symlink() or digest(prior_release.read_bytes()) != PRIOR_RELEASE_SHA):
        raise ValueError('1962 Madhya Pradesh predecessor or official PDF differs')
    with zipfile.ZipFile(prior_release) as release:
        with zipfile.ZipFile(io.BytesIO(release.read(f'correction-{EDITION}.zip'))) as prior:
            old_body = prior.read(f'election-archive/{EDITION}/extraction.json')
    if digest(old_body) != PREDECESSOR:
        raise ValueError('1962 Madhya Pradesh live predecessor bytes differ')
    before = json.loads(old_body)
    after = json.loads(old_body)
    if (before['kind'] != 'ac' or before['year'] != 1962 or len(before['records']) != 288
            or before['source_url'] != SOURCE_URL or before['source_file'] != SOURCE_FILE
            or before['source_sha256'] != SOURCE_SHA):
        raise ValueError('1962 Madhya Pradesh archive provenance differs')
    with fitz.open(source) as pdf:
        if len(pdf) != 352:
            raise ValueError('1962 Madhya Pradesh PDF page count differs')
        for code in NAMES:
            review(after['records'][code - 1], code + 18, pdf[code + 17].get_text(sort=True))
    shared = {'previous_review_note', 'original_extraction_warning', 'summary_page',
              'summary_source_file', 'summary_source_sha256', 'detail_source_file',
              'detail_source_sha256', 'official_source_url', 'official_summary_constituency_name',
              'official_summary_state', 'source_warning_code', 'official_summary_name_layout',
              'summary_totals', 'summary_result', 'winner', 'margin', 'error'}
    for old, new in zip(before['records'], after['records'], strict=True):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        expected = shared if old['code'] in NAMES else set()
        if old['candidates'] != new['candidates'] or changed != expected:
            raise ValueError(f'Unrelated 1962 Madhya Pradesh evidence changed: {old["code"]}: {changed ^ expected}')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{PREDECESSOR}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='ac-1962-madhya-pradesh-names-', dir=root / 'exports') as temporary:
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
                'scope': 'one printed runner-name difference in 1962 Madhya Pradesh AC summaries',
                'edition': EDITION, 'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
                'source_sha256': SOURCE_SHA, 'previous_sha256': PREDECESSOR,
                'new_sha256': digest(new_body), 'codes': sorted(NAMES),
            }, indent=2))
            release.writestr('IMPORT.sh', multi_seat_import_script())
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'previous_sha256': PREDECESSOR,
            'new_sha256': digest(new_body), 'name_reviews': len(NAMES)}


if __name__ == '__main__':
    print(json.dumps(build()))
