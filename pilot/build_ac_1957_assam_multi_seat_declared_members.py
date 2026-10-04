"""Preserve 14 source-declared 1957 Assam AC two-seat member lists."""

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
EDITION = '54620c434b54e06715dc86f1'
NAME = 'pollmedia-ac-1957-assam-multi-seat-declared-members-20261004'
LIVE_SHA = '54ae94f68f49fce8a777ede1a6b0e7a0e901f3823684defe916c422ff8b80fcc'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3991-assam-1957/'
SOURCE_FILE = EDITION + '-9462.pdf'
SOURCE_SHA = 'c5aec9280a02fb8ca19663128a1c1caca27f4db0aac7cd667d2ac68562c2cc7e'
PREVIOUS_ERROR = ('Candidate rows transcribed from the detailed PDF; independent summary '
                  'reconciliation is pending.; Multi-member constituency; no single '
                  'winner or margin is inferred.')
NOTE = ('The official 1957 Assam constituency summary names both elected members. '
        'Reported votes across two seats are not ordinary one-seat turnout; original '
        'candidate rows and extraction warning remain for review.')


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def norm(name: str) -> str:
    return re.sub(r'[^a-z0-9]', '', name.casefold())


def summary_pages(pdf: fitz.Document) -> dict[int, tuple[int, str, re.Match]]:
    pages = {}
    for index in range(12, 106):
        text = pdf[index].get_text(sort=True)
        if 'CONSTITUENCY DATA - SUMMARY' not in text:
            raise ValueError('Official 1957 Assam summary page is missing: ' + str(index + 1))
        seat = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*(.*?)\s+NUMBER OF SEATS\s*:\s*(\d+)',
                         text, re.I | re.S)
        if seat is None or int(seat[1]) in pages:
            raise ValueError('Official 1957 Assam summary identity differs: ' + str(index + 1))
        pages[int(seat[1])] = (index + 1, text, seat)
    if set(pages) != set(range(1, 95)):
        raise ValueError('Official 1957 Assam 94-summary coverage differs')
    return pages


def source_total(text: str, section: str, following: str) -> int:
    match = re.search(section + r'\b(.*?)' + following, text, re.I | re.S)
    line = re.search(r'^\s*1\. TOTAL[^\n]*$', match[1], re.I | re.M) if match else None
    values = re.findall(r'\d+', line[0]) if line else []
    if not values:
        raise ValueError('Official 1957 Assam summary total missing')
    return int(values[-1])


def declared_members(text: str, seat: re.Match, record: dict) -> tuple[str, dict, list[dict], bool]:
    code = record['code']
    if ('LEGISLATIVE ASSEMBLY OF ASSAM' not in text.upper()
            or record['state_name'] != 'Assam' or record['number_of_seats'] != 2
            or int(seat[1]) != code or int(seat[3]) != 2
            or norm(seat[2]) != norm(record['name'])):
        raise ValueError('Official 1957 Assam two-seat identity differs: ' + str(code))
    electors = source_total(text, r'II\. ELECTORS', r'III\. ELECTORS WHO VOTED')
    voters = source_total(text, r'III\. ELECTORS WHO VOTED', r'IV\. VOTES')
    vote_section = re.search(r'IV\. VOTES\b(.*?)VI\. DATES\b', text, re.I | re.S)
    polled = re.search(r'1\. POLLED\s+(\d+)', vote_section[1]) if vote_section else None
    valid = re.search(r'2\. VALID\s+(\d+)', vote_section[1]) if vote_section else None
    if (polled is None or valid is None
            or (electors, voters, int(polled[1]), int(valid[1]))
            != (record['electors'], record['votes_polled'], record['votes_polled'],
                record['valid_candidate_votes'])
            or sum(row['votes'] for row in record['candidates']) != record['valid_candidate_votes']):
        raise ValueError('Official 1957 Assam two-seat totals differ: ' + str(code))
    result = re.search(r'VII\. RESULT\b(.*?)rptConstituencySummary', text, re.I | re.S)
    found = re.findall(r'^\s*Winner\s+(\d+)\s+(\S+)\s+(.+?)\s+(\d+)\s*$',
                       result[1] if result else '', re.I | re.M)
    if len(found) != 2 or [int(item[0]) for item in found] != [1, 2]:
        raise ValueError('Official 1957 Assam two-member list differs: ' + str(code))
    members = []
    for _, party, name, votes in found:
        matches = [row for row in record['candidates']
                   if (row['candidate_name'], row['party_at_election'], row['votes'])
                   == (name.strip(), party, int(votes))]
        if len(matches) != 1:
            raise ValueError('Official 1957 Assam member/detail conflict: ' + str(code))
        members.append({'name': matches[0]['candidate_name'], 'party': party, 'votes': int(votes)})
    if len({(member['name'], member['party'], member['votes']) for member in members}) != 2:
        raise ValueError('Duplicate 1957 Assam declaration: ' + str(code))
    totals = {'electors': electors, 'votes_polled': voters, 'valid_candidate_votes': int(valid[1])}
    return seat[2].strip(), totals, members, voters > electors


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    source = folder / SOURCE_FILE
    old_body = (folder / 'extraction.json').read_bytes()
    if source.is_symlink() or digest(source.read_bytes()) != SOURCE_SHA or digest(old_body) != LIVE_SHA:
        raise ValueError('Official 1957 Assam source or live checksum differs')
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    source_files = {file['file']: file for file in manifest['files']}
    before = json.loads(old_body)
    after = json.loads(old_body)
    if (manifest['url'] != SOURCE_URL or source_files[SOURCE_FILE]['sha256'] != SOURCE_SHA
            or before['kind'] != 'ac' or before['year'] != 1957
            or before['source_url'] != SOURCE_URL or before['source_file'] != SOURCE_FILE
            or before['source_sha256'] != SOURCE_SHA or len(before['records']) != 94):
        raise ValueError('Official 1957 Assam source provenance differs')
    verified = []
    excess = []
    with fitz.open(source) as pdf:
        pages = summary_pages(pdf)
        for record in after['records']:
            if record.get('number_of_seats', 1) != 2:
                continue
            code = record['code']
            if (record['status'] != 'needs_review' or record['error'] != PREVIOUS_ERROR
                    or record.get('summary_page') is not None
                    or record.get('source_warning_code') is not None
                    or record.get('official_multi_seat_winners') is not None):
                raise ValueError('1957 Assam unresolved review state differs: ' + str(code))
            page, text, seat = pages[code]
            name, totals, members, voters_exceed_electors = declared_members(text, seat, record)
            record['previous_review_note'] = record['error']
            record['original_extraction_warning'] = record['error']
            record['error'] = (NOTE + (f" Official report prints {totals['votes_polled']:,} voters "
                                       f"against {totals['electors']:,} electors; "
                                       'no ordinary turnout is inferred.' if voters_exceed_electors else ''))
            record['source_warning_code'] = 'official_multi_seat_summary'
            record['summary_page'] = page
            record['summary_totals'] = totals
            record['summary_source_file'] = SOURCE_FILE
            record['summary_source_sha256'] = SOURCE_SHA
            record['detail_source_file'] = SOURCE_FILE
            record['detail_source_sha256'] = SOURCE_SHA
            record['official_summary_constituency_name'] = name
            record['official_multi_seat_winners'] = members
            verified.append({'code': code, 'summary_page': page, 'winners': members})
            if voters_exceed_electors:
                excess.append(code)
    if len(verified) != 14 or excess != [4, 33, 69]:
        raise ValueError('1957 Assam two-seat/discrepancy coverage differs')
    allowed = {'previous_review_note', 'original_extraction_warning', 'error', 'source_warning_code',
               'summary_page', 'summary_totals', 'summary_source_file', 'summary_source_sha256',
               'detail_source_file', 'detail_source_sha256', 'official_summary_constituency_name',
               'official_multi_seat_winners'}
    codes = {row['code'] for row in verified}
    for old, new in zip(before['records'], after['records'], strict=True):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        if old['code'] != new['code'] or changed != (allowed if old['code'] in codes else set()):
            raise ValueError('Unrelated 1957 Assam evidence changed: ' + str(old['code']))
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{LIVE_SHA}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='ac-1957-assam-multi-', dir=root / 'exports') as temporary:
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
                    LIVE_SHA if kind == 'correction' else None,
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
                'scope': '14 source-declared 1957 Assam AC two-seat member lists',
                'edition': EDITION, 'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
                'source_sha256': SOURCE_SHA, 'previous_sha256': LIVE_SHA,
                'new_sha256': digest(new_body), 'results': verified,
                'official_voters_exceed_electors': excess,
            }, indent=2))
            release.writestr('IMPORT.sh', multi_seat_import_script())
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'previous_sha256': LIVE_SHA,
            'new_sha256': digest(new_body), 'members': len(verified), 'excess_voters': excess}


if __name__ == '__main__':
    print(json.dumps(build()))
