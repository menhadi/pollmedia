"""Preserve the 91 official 1957 PC two-seat elected-member declarations."""

import hashlib
import io
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_pc_1951_multi_seat_declared_members import multi_seat_import_script
from build_pc_1957_single_seat_results_bundle import source_total
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
EDITION = 'a1b887ea9c50978fe4cf8e5c'
NAME = 'pollmedia-pc-1957-multi-seat-declared-members-20261004'
PREVIOUS_BUNDLE = 'pollmedia-pc-1957-two-uncontested-results-20261004.zip'
PREVIOUS_SHA = '826e1fbc7ffeebbd542c3c1c4e1227cbbe2b91f57c118dcf1e055f1dca417729'
SOURCE_URL = 'https://old.eci.gov.in/files/file/4112-general-election-1957-vol-i-ii/'
DETAIL_FILE = EDITION + '-9737.pdf'
DETAIL_SHA = '71c6ed66b152cb247d9feedf09743f38eaa9af21e4cf6779eb5e2ee9e206eb02'
SUMMARY_FILE = EDITION + '-9738.pdf'
SUMMARY_SHA = 'c9ab7014c3399762f85a1fc7fc0d001061b675ec83c482a29f8ddc77f85b7726'
NOTE = ('Official 1957 constituency summary declares the two elected members listed separately. '
        'Reported votes across seats are not ordinary one-seat turnout; original extraction '
        'warnings and all candidate rows remain available for review.')


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def norm(value: str) -> str:
    value = re.sub(r'\s*\((?:SC|ST)\)', '', value, flags=re.I)
    return re.sub(r'[^a-z0-9]', '', value.casefold())


def predecessor(root: Path) -> bytes:
    path = root / 'exports' / PREVIOUS_BUNDLE
    expected = path.with_suffix('.sha256').read_text(encoding='ascii').split()[0]
    if digest(path.read_bytes()) != expected:
        raise ValueError('Earlier 1957 unopposed release checksum differs')
    with zipfile.ZipFile(path) as outer:
        inner = outer.read(f'correction-{EDITION}.zip')
    with zipfile.ZipFile(io.BytesIO(inner)) as correction:
        body = correction.read(f'election-archive/{EDITION}/extraction.json')
    if digest(body) != PREVIOUS_SHA:
        raise ValueError('Earlier 1957 revised extraction checksum differs')
    return body


def declared_members(text: str, record: dict) -> tuple[str, dict, list[dict]]:
    state = re.search(r'STATE/UT\s*:\s*(.*?)\s+CODE\s*:\s*([SU]\d+)', text, re.I | re.S)
    seat = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*(.*?)\s+NUMBER OF SEATS\s*:\s*(\d+)',
                     text, re.I | re.S)
    if (state is None or seat is None or int(seat[1]) != record['official_pc_code']
            or int(seat[3]) != record['number_of_seats'] or int(seat[3]) != 2
            or state[2] != record['state_code']
            or norm(state[1]) != norm(record['state_name'])
            or not norm(record['constituency_name']).startswith(norm(seat[2]))):
        raise ValueError('1957 multi-seat source identity differs: ' + str(record['code']))
    electors = source_total(text, r'II\. ELECTORS')
    voters = source_total(text, r'III\. ELECTORS WHO VOTED')
    vote_section = re.search(r'IV\. VOTES\b(.*?)VI\. DATES\b', text, re.I | re.S)
    polled = re.search(r'1\. POLLED\s+(\d+)', vote_section[1], re.I) if vote_section else None
    valid = re.search(r'2\. VALID\s+(\d+)', vote_section[1], re.I) if vote_section else None
    if (polled is None or valid is None
            or (electors, voters, int(polled[1]), int(valid[1]))
            != (record['electors'], record['votes_polled'], record['votes_polled'],
                record['valid_candidate_votes'])
            or sum(candidate['votes'] for candidate in record['candidates'])
            != record['valid_candidate_votes']):
        raise ValueError('1957 multi-seat source totals differ: ' + str(record['code']))
    found = re.findall(r'^\s*Winner\s+(\d+)\s+(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
    if (len(found) != 2 or [int(member[0]) for member in found] != [1, 2]):
        raise ValueError('1957 multi-seat declaration count differs: ' + str(record['code']))
    if record['code'] == 186:
        continuation = re.search(
            r'^\s*Winner\s+1\s+INC\s+SINGH MAHARAJKUMAR CHANDIKESHWAR\s+151100\s*\n'
            r'\s+SHARAN SINGH JU DEO\s*$', text, re.I | re.M)
        if continuation is None or found[0][2] != 'SINGH MAHARAJKUMAR CHANDIKESHWAR':
            raise ValueError('1957 Surguja wrapped winner name differs')
        found[0] = (found[0][0], found[0][1],
                    found[0][2] + ' SHARAN SINGH JU DEO', found[0][3])
    winners = []
    for _, party, name, votes in found:
        matching = [candidate for candidate in record['candidates']
                    if norm(candidate['candidate_name']) == norm(name)
                    and candidate['party_at_election'] == party
                    and candidate['votes'] == int(votes)]
        if len(matching) != 1:
            raise ValueError('1957 declared member does not match detailed candidate row: '
                             + str(record['code']))
        winners.append({'name': matching[0]['candidate_name'], 'party': party, 'votes': int(votes)})
    if len({(winner['name'], winner['party'], winner['votes']) for winner in winners}) != 2:
        raise ValueError('Duplicate declared 1957 member: ' + str(record['code']))
    return seat[2].strip(), {'electors': electors, 'votes_polled': voters,
                             'valid_candidate_votes': int(valid[1])}, winners


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body = predecessor(root)
    before = json.loads(old_body)
    after = json.loads(old_body)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    files = {file['file']: file for file in manifest['files']}
    if (before['kind'] != 'pc' or before['year'] != 1957 or len(before['records']) != 403
            or before['source_url'] != SOURCE_URL or manifest['url'] != SOURCE_URL
            or before['source_file'] != DETAIL_FILE or before['source_sha256'] != DETAIL_SHA
            or files[DETAIL_FILE]['sha256'] != DETAIL_SHA
            or files[SUMMARY_FILE]['sha256'] != SUMMARY_SHA):
        raise ValueError('Official 1957 Vol I/II identity differs')
    for filename, expected in ((DETAIL_FILE, DETAIL_SHA), (SUMMARY_FILE, SUMMARY_SHA)):
        path = folder / filename
        if path.is_symlink() or digest(path.read_bytes()) != expected:
            raise ValueError('Official 1957 PDF checksum differs: ' + filename)
    verified = []
    with fitz.open(folder / SUMMARY_FILE) as summary:
        for record in after['records']:
            if record.get('number_of_seats', 1) < 2:
                continue
            code = record['code']
            page = record.get('summary_page')
            if (page is None or record['status'] != 'needs_review'
                    or 'Multi-member constituency: individual winners require review' not in record['error']
                    or record.get('source_warning_code') is not None
                    or record.get('official_multi_seat_winners') is not None):
                raise ValueError('Live 1957 multi-seat review state differs: ' + str(code))
            name, totals, winners = declared_members(summary[page - 1].get_text(sort=True), record)
            record['previous_review_note'] = record['error']
            record['original_extraction_warning'] = record['error']
            record['error'] = NOTE + (' The extracted detailed and summary constituency names differ; '
                                      'the official summary identity is recorded for review.'
                                      if code == 113 else '')
            record['source_warning_code'] = 'official_multi_seat_summary'
            record['summary_totals'] = totals
            record['summary_source_file'] = SUMMARY_FILE
            record['summary_source_sha256'] = SUMMARY_SHA
            record['detail_source_file'] = DETAIL_FILE
            record['detail_source_sha256'] = DETAIL_SHA
            record['official_summary_constituency_name'] = name
            record['official_multi_seat_winners'] = winners
            verified.append({'code': code, 'official_pc_code': record['official_pc_code'],
                             'state': record['state_name'], 'summary_page': page,
                             'number_of_seats': 2, 'winners': winners})
    if len(verified) != 91:
        raise ValueError('1957 multi-seat declaration coverage differs')
    allowed = {'previous_review_note', 'original_extraction_warning', 'error', 'source_warning_code',
               'summary_totals', 'summary_source_file', 'summary_source_sha256', 'detail_source_file',
               'detail_source_sha256', 'official_summary_constituency_name', 'official_multi_seat_winners'}
    verified_codes = {row['code'] for row in verified}
    for old, new in zip(before['records'], after['records'], strict=True):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        if old['code'] != new['code'] or changed != (allowed if old['code'] in verified_codes else set()):
            raise ValueError('Unrelated 1957 extraction evidence changed: ' + str(old['code']))
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    with tempfile.TemporaryDirectory(prefix='pc-1957-multi-', dir=root / 'exports') as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{PREVIOUS_SHA}.json'
        revision = f'election-archive/{EDITION}/extraction.json'
        inner = []
        for kind, relative, body in (('snapshot', snapshot, old_body), ('correction', revision, new_body)):
            file = staged / relative
            file.parent.mkdir(parents=True, exist_ok=True)
            file.write_bytes(body)
            bucket = hashlib.sha256(relative.encode()).digest()[0] % 8
            archive = packages / f'{kind}-{EDITION}.zip'
            package(staged, archive, 'election-archive', bucket, 8, [relative],
                    PREVIOUS_SHA if kind == 'correction' else None,
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
                'scope': '91 source-declared 1957 PC two-seat member lists; no single winner or ordinary turnout',
                'edition': EDITION, 'source_url': SOURCE_URL,
                'detail_file': DETAIL_FILE, 'detail_sha256': DETAIL_SHA,
                'summary_file': SUMMARY_FILE, 'summary_sha256': SUMMARY_SHA,
                'previous_sha256': PREVIOUS_SHA, 'new_sha256': digest(new_body),
                'results': verified,
            }, indent=2))
            bundle.writestr('IMPORT.sh', multi_seat_import_script())
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'previous_sha256': PREVIOUS_SHA,
            'new_sha256': digest(new_body), 'multi_seat_results': len(verified)}


if __name__ == '__main__':
    print(json.dumps(build()))
