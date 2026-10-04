"""Review printed summary name truncations in the 1962 Maharashtra AC report."""

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
EDITION = 'c6fecebc52d31001b62978a5'
NAME = 'pollmedia-ac-1962-maharashtra-nine-name-review-results-20261005'
PREDECESSOR = 'c38e167fe8a7911dfbe562de7ec1f04ff9d3f993935b5f243d502ff70677b05a'
PRIOR_RELEASE = 'pollmedia-ac-1962-maharashtra-16-voter-discrepancy-results-20261005'
PRIOR_RELEASE_SHA = '8f4b7685b8920ad9f3a598c2543062eead338bf12aa27d0ace82a2b290594cbd'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3714-maharashtra-1962/'
SOURCE_FILE = f'{EDITION}-8744.pdf'
SOURCE_SHA = '3587499768cc77889d6613a4b5a61ec5c55dd196cb1dba9bc98137ce898f7a88'
PREVIOUS_ERROR = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.'
# code: (field, verbatim printed summary name, verbatim detailed candidate name)
NAMES = {
    3: ('runner', 'GULAM MAHMUD HAJI NOOR MOHAMED', 'GULAM MAHMUD HAJI NOOR MOHAMED BANATWALLA'),
    35: ('winner', 'PARCHARAM KEWALRAM AILANI (ALIAS', 'PARCHARAM KEWALRAM AILANI (ALIAS VIDYARTHI)'),
    65: ('winner', 'JAYARAM BALKRISHNA SHETYE ALIAS BHAI', 'JAYARAM BALKRISHNA SHETYE ALIAS BHAI SHETYE'),
    75: ('winner', 'NALAWADE PANDURANG ALIAS APPASAHEB', 'NALAWADE PANDURANG ALIAS APPASAHEB RAMRAO'),
    165: ('winner', 'ANNASAHEB DESHMUKH ALIAS SHANKARRAO', 'ANNASAHEB DESHMUKH ALIAS SHANKARRAO VITHALRAO'),
    180: ('runner', 'WAMANRAO DESHMUKH ALIAS SUDAN', 'WAMANRAO DESHMUKH ALIAS SUDAN DATTATRAYA'),
    189: ('runner', 'ARDHENDU BHUSHAN HEMENDRA KUMAR', 'ARDHENDU BHUSHAN HEMENDRA KUMAR BARDHAN'),
    230: ('winner', 'MAHALINGAPPA ALIAS APPASAHEB BASLINGAPP', 'MAHALINGAPPA ALIAS APPASAHEB BASLINGAPPA'),
    260: ('runner', 'KASHINATH (BABANRAO ) PRALHADRAO', 'KASHINATH (BABANRAO ) PRALHADRAO KULKARNI'),
}


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def review(row: dict, page: int, text: str) -> None:
    code = row['code']
    field, printed, detailed = NAMES[code]
    identity = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text, re.I)
    if (identity is None or int(identity[1]) != code or norm(identity[2]) != norm(row['name'])
            or 'LEGISLATIVE ASSEMBLY OF MAHARASHTRA' not in text.upper()
            or 'CONSTITUENCY DATA - SUMMARY' not in text
            or row['state_name'] != 'Maharashtra' or row['number_of_seats'] != 1
            or row['status'] != 'needs_review' or row['error'] != PREVIOUS_ERROR
            or row.get('summary_page') is not None or row.get('source_warning_code') is not None):
        raise ValueError(f'1962 Maharashtra name-review identity differs: {code}')
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
            or missing < 0 or (missing > 0) != (code == 75)
            or not (0 < valid <= row['votes_polled'] <= voters <= electors)
            or valid != row['valid_candidate_votes'] or valid + rejected != row['votes_polled']
            or sum(candidate['votes'] for candidate in ranked) != valid
            or len(ranked) < 2 or ranked[0]['votes'] <= ranked[1]['votes']
            or len({(candidate['candidate_name'], candidate['party_at_election'], candidate['votes']) for candidate in ranked}) != len(ranked)
            or len(declared) != 2 or [part[0].lower() for part in declared] != ['winner', 'runner up']
            or margin is None or int(margin[1]) != ranked[0]['votes'] - ranked[1]['votes']):
        raise ValueError(f'1962 Maharashtra name-review totals differ: {code}')
    for position, candidate in enumerate(ranked[:2]):
        source = declared[position]
        expected_name = printed if field == ('winner' if position == 0 else 'runner') else candidate['candidate_name']
        if ((source[2].strip(), source[1], int(source[3])) !=
                (expected_name, candidate['party_at_election'], candidate['votes'])):
            raise ValueError(f'1962 Maharashtra name-review declaration differs: {code}')
    if ranked[0 if field == 'winner' else 1]['candidate_name'] != detailed:
        raise ValueError(f'1962 Maharashtra detailed candidate differs: {code}')
    row['previous_review_note'] = row['error']
    row['original_extraction_warning'] = row['error']
    row['summary_page'] = page
    row['summary_source_file'] = SOURCE_FILE
    row['summary_source_sha256'] = SOURCE_SHA
    row['detail_source_file'] = SOURCE_FILE
    row['detail_source_sha256'] = SOURCE_SHA
    row['official_source_url'] = SOURCE_URL
    row['official_summary_constituency_name'] = identity[2].strip()
    row['official_summary_state'] = 'Maharashtra'
    row['source_warning_code'] = 'official_summary_turnout_only'
    row['source_name_discrepancy'] = {'field': field, 'summary_value': printed, 'detail_value': detailed}
    row['summary_totals'] = {'electors': electors, 'votes_polled': voters, 'valid_candidate_votes': valid}
    row['summary_result'] = {'winner': declared[0][2].strip(), 'winner_party': ranked[0]['party_at_election'],
                             'winner_votes': ranked[0]['votes'], 'runner': declared[1][2].strip(),
                             'runner_party': ranked[1]['party_at_election'], 'runner_votes': ranked[1]['votes'],
                             'margin': int(margin[1])}
    row['winner'] = ranked[0]['candidate_name']
    row['margin'] = int(margin[1])
    if code == 75:
        row['source_discrepancy'] = {'field': 'votes_polled', 'detail_value': row['votes_polled'],
                                     'summary_value': voters, 'valid_detail_value': valid,
                                     'valid_summary_value': valid, 'missing_votes': missing}
        row['error'] = (f'Official summary prints {field} {printed}; detailed candidate row prints {detailed}. '
                        f'Party and votes agree. Summary voters {voters} exceed detailed voters '
                        f'{row["votes_polled"]} by {missing}, printed as MISSING. Review both source figures.')
    else:
        row['error'] = (f'Official summary prints {field} {printed}; detailed candidate row prints {detailed}. '
                        'Party, votes, turnout and margin agree. Both source names remain for review.')


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    source = folder / SOURCE_FILE
    prior_release = root / 'exports' / (PRIOR_RELEASE + '.zip')
    if (source.is_symlink() or digest(source.read_bytes()) != SOURCE_SHA
            or prior_release.is_symlink() or digest(prior_release.read_bytes()) != PRIOR_RELEASE_SHA):
        raise ValueError('1962 Maharashtra predecessor or official PDF differs')
    with zipfile.ZipFile(prior_release) as release:
        with zipfile.ZipFile(io.BytesIO(release.read(f'correction-{EDITION}.zip'))) as prior:
            old_body = prior.read(f'election-archive/{EDITION}/extraction.json')
    if digest(old_body) != PREDECESSOR:
        raise ValueError('1962 Maharashtra live predecessor bytes differ')
    before = json.loads(old_body)
    after = json.loads(old_body)
    if (before['kind'] != 'ac' or before['year'] != 1962 or len(before['records']) != 264
            or before['source_url'] != SOURCE_URL or before['source_file'] != SOURCE_FILE
            or before['source_sha256'] != SOURCE_SHA):
        raise ValueError('1962 Maharashtra archive provenance differs')
    with fitz.open(source) as pdf:
        if len(pdf) != 323:
            raise ValueError('1962 Maharashtra PDF page count differs')
        for code in NAMES:
            review(after['records'][code - 1], code + 18, pdf[code + 17].get_text(sort=True))
    shared = {'previous_review_note', 'original_extraction_warning', 'summary_page',
              'summary_source_file', 'summary_source_sha256', 'detail_source_file',
              'detail_source_sha256', 'official_source_url', 'official_summary_constituency_name',
              'official_summary_state', 'source_warning_code', 'source_name_discrepancy',
              'summary_totals', 'summary_result', 'winner', 'margin', 'error'}
    for old, new in zip(before['records'], after['records'], strict=True):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        expected = shared | ({'source_discrepancy'} if old['code'] == 75 else set()) if old['code'] in NAMES else set()
        if old['candidates'] != new['candidates'] or changed != expected:
            raise ValueError(f'Unrelated 1962 Maharashtra evidence changed: {old["code"]}: {changed ^ expected}')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{PREDECESSOR}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='ac-1962-maharashtra-names-', dir=root / 'exports') as temporary:
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
                'scope': 'nine printed candidate-name differences in 1962 Maharashtra AC summaries',
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
