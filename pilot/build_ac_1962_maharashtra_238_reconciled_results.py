"""Review one-seat 1962 Maharashtra declarations against the archived ECI report."""

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
NAME = 'pollmedia-ac-1962-maharashtra-238-reconciled-results-20261005'
PREDECESSOR = 'b626b1b8cc6be518f6289724f280a2347b6865acf4e9ccf280351523cd10c488'
PRIOR_RELEASE = 'pollmedia-ac-maharashtra-1962-mahad-declared-tie-20261004'
PRIOR_RELEASE_SHA = '11c24d6a8981279d235f1222e196c6b1e0e33bc9440bef87e6f09e78cccd001b'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3714-maharashtra-1962/'
SOURCE_FILE = f'{EDITION}-8744.pdf'
SOURCE_SHA = '3587499768cc77889d6613a4b5a61ec5c55dd196cb1dba9bc98137ce898f7a88'
PREVIOUS_ERROR = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.'
NAME_REVIEW_CODES = {3, 35, 65, 75, 165, 180, 189, 230, 260}
EXISTING_TIE_CODES = {43}
UNCONTESTED_CODES = set()
EXPECTED_MISSING_CODES = {26, 30, 39, 41, 72, 73, 74, 79, 80, 101, 102, 138, 196, 197, 206, 262}


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def parse_summary(record: dict, page: int, text: str) -> tuple[int, int, int, int, list, int, str]:
    code = record['code']
    identity = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text, re.I)
    if (identity is None or int(identity[1]) != code or norm(identity[2]) != norm(record['name'])
            or 'CONSTITUENCY DATA - SUMMARY' not in text
            or 'LEGISLATIVE ASSEMBLY OF MAHARASHTRA' not in text.upper()
            or record['number_of_seats'] != 1 or record['state_name'] != 'Maharashtra'
            or record['status'] != 'needs_review' or record['error'] != PREVIOUS_ERROR
            or record.get('summary_page') is not None or record.get('source_warning_code') is not None
            or len(record['candidates']) < 2 or 'Uncontested' in text):
        raise ValueError(f'1962 Maharashtra summary identity differs: {code}')
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
    ranked = sorted(record['candidates'], key=lambda candidate: candidate['votes'], reverse=True)
    if (electors != record['electors'] or voters != polled or voters != record['votes_polled']
            or not (0 < valid <= voters <= electors) or valid != record['valid_candidate_votes']
            or valid + rejected != voters or missing != 0
            or sum(candidate['votes'] for candidate in ranked) != valid
            or len({(candidate['candidate_name'], candidate['party_at_election'], candidate['votes']) for candidate in ranked}) != len(ranked)
            or len(declared) != 2 or [part[0].lower() for part in declared] != ['winner', 'runner up']
            or margin is None or ranked[0]['votes'] <= ranked[1]['votes']
            or int(margin[1]) != ranked[0]['votes'] - ranked[1]['votes']):
        raise ValueError(f'1962 Maharashtra summary totals or result differ: {code}')
    for source, candidate in zip(declared, ranked[:2], strict=True):
        if (source[2].strip(), source[1], int(source[3])) != (
                candidate['candidate_name'], candidate['party_at_election'], candidate['votes']):
            raise ValueError(f'1962 Maharashtra declared candidate differs: {code}')
    return electors, voters, valid, missing, ranked, int(margin[1]), identity[2].strip()


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    source = folder / SOURCE_FILE
    prior_release = root / 'exports' / (PRIOR_RELEASE + '.zip')
    if (source.is_symlink() or digest(source.read_bytes()) != SOURCE_SHA
            or prior_release.is_symlink() or digest(prior_release.read_bytes()) != PRIOR_RELEASE_SHA):
        raise ValueError('1962 Maharashtra precursor or official PDF differs')
    with zipfile.ZipFile(prior_release) as release:
        with zipfile.ZipFile(io.BytesIO(release.read(f'correction-{EDITION}.zip'))) as prior:
            old_body = prior.read(f'election-archive/{EDITION}/extraction.json')
    if digest(old_body) != PREDECESSOR:
        raise ValueError('1962 Maharashtra live precursor bytes differ')
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    before = json.loads(old_body)
    after = json.loads(old_body)
    if (manifest['url'] != SOURCE_URL
            or next(file for file in manifest['files'] if file['file'] == SOURCE_FILE)['sha256'] != SOURCE_SHA
            or before['kind'] != 'ac' or before['year'] != 1962 or len(before['records']) != 264
            or before['source_url'] != SOURCE_URL or before['source_file'] != SOURCE_FILE
            or before['source_sha256'] != SOURCE_SHA):
        raise ValueError('1962 Maharashtra archive provenance differs')
    seen = []
    missing_codes = []
    with fitz.open(source) as pdf:
        if len(pdf) != 323:
            raise ValueError('1962 Maharashtra PDF page count differs')
        for record in after['records']:
            code = record['code']
            page = code + 18
            text = pdf[page - 1].get_text(sort=True)
            identity = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text, re.I)
            if (identity is None or int(identity[1]) != code or norm(identity[2]) != norm(record['name'])
                    or 'LEGISLATIVE ASSEMBLY OF MAHARASHTRA' not in text.upper()):
                raise ValueError(f'1962 Maharashtra summary page identity differs: {code}')
            if code in UNCONTESTED_CODES:
                if 'Uncontested' not in text or len(record['candidates']) != 1:
                    raise ValueError(f'1962 Maharashtra uncontested exception differs: {code}')
                continue
            if code in EXISTING_TIE_CODES:
                if (record.get('source_warning_code') != 'official_declared_tie'
                        or record.get('summary_page') != page):
                    raise ValueError(f'1962 Maharashtra existing declared tie differs: {code}')
                continue
            if code in NAME_REVIEW_CODES:
                if 'Uncontested' in text:
                    raise ValueError(f'1962 Maharashtra name-review result kind differs: {code}')
                continue
            vote_section = section(text, r'IV\. VOTES', r'V\. POLLING STATIONS')
            missing = row_total(vote_section, 4, 'MISSING')
            if missing:
                if missing < 0:
                    raise ValueError(f'1962 Maharashtra missing-vote count differs: {code}')
                missing_codes.append(code)
                continue
            electors, voters, valid, unused, ranked, margin, source_name = parse_summary(record, page, text)
            record['previous_review_note'] = record['error']
            record['original_extraction_warning'] = record['error']
            record['summary_page'] = page
            record['summary_source_file'] = SOURCE_FILE
            record['summary_source_sha256'] = SOURCE_SHA
            record['detail_source_file'] = SOURCE_FILE
            record['detail_source_sha256'] = SOURCE_SHA
            record['official_source_url'] = SOURCE_URL
            record['official_summary_constituency_name'] = source_name
            record['official_summary_state'] = 'Maharashtra'
            record['source_warning_code'] = 'official_summary_turnout_only'
            record['summary_totals'] = {'electors': electors, 'votes_polled': voters,
                                        'valid_candidate_votes': valid}
            record['summary_result'] = {'winner': ranked[0]['candidate_name'],
                                        'winner_party': ranked[0]['party_at_election'],
                                        'winner_votes': ranked[0]['votes'],
                                        'runner': ranked[1]['candidate_name'],
                                        'runner_party': ranked[1]['party_at_election'],
                                        'runner_votes': ranked[1]['votes'], 'margin': margin}
            record['error'] = ('Official 1962 Maharashtra summary corroborates turnout, named winner, '
                               'runner-up and margin; original detailed-PDF extraction warning remains for review.')
            seen.append({'code': code, 'summary_page': page})
    if (len(seen) != 238 or set(missing_codes) != EXPECTED_MISSING_CODES
            or set(missing_codes) & (NAME_REVIEW_CODES | UNCONTESTED_CODES | EXISTING_TIE_CODES)
            or set(range(1, 265)) != ({item['code'] for item in seen} | set(missing_codes)
                                      | NAME_REVIEW_CODES | UNCONTESTED_CODES | EXISTING_TIE_CODES)):
        raise ValueError('1962 Maharashtra clean-summary coverage differs')
    expected = {'previous_review_note', 'original_extraction_warning', 'summary_page',
                'summary_source_file', 'summary_source_sha256', 'detail_source_file',
                'detail_source_sha256', 'official_source_url', 'official_summary_constituency_name',
                'official_summary_state', 'source_warning_code', 'summary_totals',
                'summary_result', 'error'}
    reviewed = {item['code'] for item in seen}
    for old, new in zip(before['records'], after['records'], strict=True):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        if (old['candidates'] != new['candidates']
                or changed != (expected if old['code'] in reviewed else set())):
            raise ValueError(f'Unrelated 1962 Maharashtra evidence changed: {old["code"]}')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{PREDECESSOR}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='ac-1962-maharashtra-', dir=root / 'exports') as temporary:
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
                'scope': '238 source-reconciled contested 1962 Maharashtra AC one-seat declarations',
                'edition': EDITION, 'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
                'source_sha256': SOURCE_SHA, 'previous_sha256': PREDECESSOR,
                'new_sha256': digest(new_body), 'results': seen,
                'withheld_missing_vote_codes': missing_codes,
                'withheld_name_review_codes': sorted(NAME_REVIEW_CODES),
                'withheld_uncontested_codes': sorted(UNCONTESTED_CODES),
                'preserved_declared_tie_codes': sorted(EXISTING_TIE_CODES),
            }, indent=2))
            release.writestr('IMPORT.sh', multi_seat_import_script())
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'previous_sha256': PREDECESSOR,
            'new_sha256': digest(new_body), 'reviewed': len(seen),
            'withheld_missing_votes': len(missing_codes), 'withheld_names': len(NAME_REVIEW_CODES),
            'withheld_uncontested': len(UNCONTESTED_CODES)}


if __name__ == '__main__':
    print(json.dumps(build()))
