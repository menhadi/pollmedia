"""Preserve 48 official 1951 Madhya Pradesh AC two-seat declarations."""

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
EDITION = '30c60a850d2cb80646b7e138'
NAME = 'pollmedia-ac-1951-madhya-pradesh-multi-seat-declared-members-20261004'
LIVE_SHA = 'f71d8f03f966f62d1ba3138ab72d966a9cd2fb38734401eecfb509f64bcf22b5'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3727-madhya-pradesh-1951/'
SOURCE_FILE = EDITION + '-8770.pdf'
SOURCE_SHA = '8eefe54b7e972f56b8a362a3d7ed878417cdac535913c0c7e32af098f10c3650'
PREVIOUS_ERROR = ('Candidate rows transcribed from the detailed PDF; independent summary '
                  'reconciliation is pending.; Multi-member constituency; no single '
                  'winner or margin is inferred.')
NOTE = ('Official 1951 Madhya Pradesh constituency summary declares the two elected members '
        'listed separately. Reported votes across seats are not ordinary one-seat turnout; '
        'original extraction warnings and all candidate rows remain available for review.')


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def norm(value: str) -> str:
    value = re.sub(r'\s*\((?:SC|ST)\)', '', value, flags=re.I)
    return re.sub(r'[^a-z0-9]', '', value.casefold())


def summary_pages(pdf: fitz.Document) -> dict[int, tuple[int, str, re.Match]]:
    pages = {}
    for index in range(len(pdf)):
        text = pdf[index].get_text(sort=True)
        if 'CONSTITUENCY DATA - SUMMARY' not in text:
            continue
        seat = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*(.*?)\s+NUMBER OF SEATS\s*:\s*(\d+)',
                         text, re.I | re.S)
        if seat is None:
            continue
        code = int(seat[1])
        if code in pages:
            raise ValueError('Duplicate official 1951 Madhya Pradesh summary: ' + str(code))
        pages[code] = (index + 1, text, seat)
    if len(pages) != 184:
        raise ValueError('Official 1951 Madhya Pradesh summary coverage differs')
    return pages


def summary_total(text: str, section: str, following: str) -> int:
    section_text = re.search(section + r'\b(.*?)' + following, text, re.I | re.S)
    total = re.search(r'1\. TOTAL\s+(\d+)', section_text[1], re.I) if section_text else None
    if total is None:
        raise ValueError('Official 1951 Madhya Pradesh summary total is missing')
    return int(total[1])


def declared_members(text: str, seat: re.Match, record: dict) -> tuple[str, dict, list[dict]]:
    if ('LEGISLATIVE ASSEMBLY OF MADHYA PRADESH' not in text.upper()
            or record['state_name'] != 'Madhya Pradesh' or record['number_of_seats'] != 2
            or int(seat[1]) != record['code'] or int(seat[3]) != 2
            or norm(seat[2]) != norm(record['name'])):
        raise ValueError('1951 Madhya Pradesh multi-seat source identity differs: ' + str(record['code']))
    electors = summary_total(text, r'II\. ELECTORS', r'III\. ELECTORS WHO VOTED')
    voters = summary_total(text, r'III\. ELECTORS WHO VOTED', r'IV\. VOTES')
    vote_section = re.search(r'IV\. VOTES\b(.*?)VI\. DATES\b', text, re.I | re.S)
    polled = re.search(r'1\. POLLED\s+(\d+)', vote_section[1], re.I) if vote_section else None
    valid = re.search(r'2\. VALID\s+(\d+)', vote_section[1], re.I) if vote_section else None
    if (polled is None or valid is None
            or (electors, voters, int(polled[1]), int(valid[1]))
            != (record['electors'], record['votes_polled'], record['votes_polled'],
                record['valid_candidate_votes'])
            or sum(candidate['votes'] for candidate in record['candidates'])
            != record['valid_candidate_votes']):
        raise ValueError('1951 Madhya Pradesh multi-seat source totals differ: ' + str(record['code']))
    found = re.findall(r'^\s*Winner\s+(\d+)\s+(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
    if record['code'] == 7:
        continuation = re.search(
            r'^\s*Winner 1\s+INC\s+RAJA SAHAB ALIAS CHANDRA CHUR PRASAD\s+33341\s*\n'
            r'\s+SINGH DEO\s*$', text, re.I | re.M)
        if continuation is None or not found or found[0][2] != 'RAJA SAHAB ALIAS CHANDRA CHUR PRASAD':
            raise ValueError('1951 Dharamjaigarh wrapped winner name differs')
        found[0] = (found[0][0], found[0][1], found[0][2] + ' SINGH DEO', found[0][3])
    if len(found) != 2 or [int(member[0]) for member in found] != [1, 2]:
        raise ValueError('1951 Madhya Pradesh multi-seat declaration count differs: ' + str(record['code']))
    winners = []
    for _, party, name, votes in found:
        matching = [candidate for candidate in record['candidates']
                    if norm(candidate['candidate_name']) == norm(name)
                    and candidate['party_at_election'] == party
                    and candidate['votes'] == int(votes)]
        if len(matching) != 1:
            raise ValueError('1951 Madhya Pradesh member does not match detailed candidate row: '
                             + str(record['code']))
        winners.append({'name': matching[0]['candidate_name'], 'party': party, 'votes': int(votes)})
    if len({(winner['name'], winner['party'], winner['votes']) for winner in winners}) != 2:
        raise ValueError('Duplicate 1951 Madhya Pradesh declared member: ' + str(record['code']))
    return seat[2].strip(), {'electors': electors, 'votes_polled': voters,
                             'valid_candidate_votes': int(valid[1])}, winners


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    source = folder / SOURCE_FILE
    old_body = (folder / 'extraction.json').read_bytes()
    if source.is_symlink() or digest(source.read_bytes()) != SOURCE_SHA or digest(old_body) != LIVE_SHA:
        raise ValueError('Official 1951 Madhya Pradesh source or live extraction checksum differs')
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    files = {file['file']: file for file in manifest['files']}
    before = json.loads(old_body)
    after = json.loads(old_body)
    if (before['kind'] != 'ac' or before['year'] != 1951 or len(before['records']) != 184
            or before['source_url'] != SOURCE_URL or manifest['url'] != SOURCE_URL
            or before['source_file'] != SOURCE_FILE or before['source_sha256'] != SOURCE_SHA
            or files[SOURCE_FILE]['sha256'] != SOURCE_SHA):
        raise ValueError('Official 1951 Madhya Pradesh edition identity differs')
    verified = []
    with fitz.open(source) as pdf:
        pages = summary_pages(pdf)
        for record in after['records']:
            if record.get('number_of_seats', 1) < 2:
                continue
            code = record['code']
            if (record['status'] != 'needs_review' or record['error'] != PREVIOUS_ERROR
                    or record.get('summary_page') is not None
                    or record.get('source_warning_code') is not None
                    or record.get('official_multi_seat_winners') is not None):
                raise ValueError('Live 1951 Madhya Pradesh multi-seat review state differs: ' + str(code))
            page, text, seat = pages[code]
            name, totals, winners = declared_members(text, seat, record)
            record['previous_review_note'] = record['error']
            record['original_extraction_warning'] = record['error']
            record['error'] = NOTE
            record['source_warning_code'] = 'official_multi_seat_summary'
            record['summary_page'] = page
            record['summary_totals'] = totals
            record['summary_source_file'] = SOURCE_FILE
            record['summary_source_sha256'] = SOURCE_SHA
            record['detail_source_file'] = SOURCE_FILE
            record['detail_source_sha256'] = SOURCE_SHA
            record['official_summary_constituency_name'] = name
            record['official_multi_seat_winners'] = winners
            verified.append({'code': code, 'summary_page': page, 'number_of_seats': 2,
                             'winners': winners})
    if len(verified) != 48:
        raise ValueError('1951 Madhya Pradesh multi-seat declaration coverage differs')
    allowed = {'previous_review_note', 'original_extraction_warning', 'error', 'source_warning_code',
               'summary_page', 'summary_totals', 'summary_source_file', 'summary_source_sha256',
               'detail_source_file', 'detail_source_sha256', 'official_summary_constituency_name',
               'official_multi_seat_winners'}
    verified_codes = {row['code'] for row in verified}
    for old, new in zip(before['records'], after['records'], strict=True):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        if old['code'] != new['code'] or changed != (allowed if old['code'] in verified_codes else set()):
            raise ValueError('Unrelated 1951 Madhya Pradesh extraction evidence changed: ' + str(old['code']))
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    with tempfile.TemporaryDirectory(prefix='ac-1951-madhya-pradesh-multi-', dir=root / 'exports') as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{LIVE_SHA}.json'
        revision = f'election-archive/{EDITION}/extraction.json'
        inner = []
        for kind, relative, body in (('snapshot', snapshot, old_body), ('correction', revision, new_body)):
            file = staged / relative
            file.parent.mkdir(parents=True, exist_ok=True)
            file.write_bytes(body)
            bucket = hashlib.sha256(relative.encode()).digest()[0] % 8
            archive = packages / f'{kind}-{EDITION}.zip'
            package(staged, archive, 'election-archive', bucket, 8, [relative],
                    LIVE_SHA if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
            inner.append(archive)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as bundle:
            for archive in inner:
                bundle.write(archive, archive.name)
            bundle.writestr('SHA256SUMS', ''.join(f'{digest(archive.read_bytes())}  {archive.name}\n'
                                               for archive in inner))
            bundle.writestr('ARCHIVES', EDITION + '\n')
            bundle.writestr('AUDIT.json', json.dumps({
                'scope': '48 source-declared 1951 Madhya Pradesh AC two-seat member lists; no ordinary turnout',
                'edition': EDITION, 'source_url': SOURCE_URL,
                'source_file': SOURCE_FILE, 'source_sha256': SOURCE_SHA,
                'previous_sha256': LIVE_SHA, 'new_sha256': digest(new_body),
                'results': verified,
            }, indent=2))
            bundle.writestr('IMPORT.sh', multi_seat_import_script())
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'previous_sha256': LIVE_SHA,
            'new_sha256': digest(new_body), 'multi_seat_results': len(verified)}


if __name__ == '__main__':
    print(json.dumps(build()))
