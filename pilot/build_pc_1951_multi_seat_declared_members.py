"""Preserve official declared members for 85 reconcilable 1951 PC multi-seat tables."""

import hashlib
import io
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_pc_1951_pepsu_three_summary_results import guarded_import_script
from build_pc_1957_single_seat_results_bundle import source_total
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
EDITION = '9a57af51e71e6ff194d3f409'
NAME = 'pollmedia-pc-1951-multi-seat-declared-members-20261004'
PREVIOUS_BUNDLE = 'pollmedia-pc-1951-pepsu-three-summary-results-20261004.zip'
PREVIOUS_SHA = '7edd7dfac35bdbce1ddef3d75e4edf78b89ca74c1d00710d36db4afdd8183a58'
SOURCE_URL = 'https://old.eci.gov.in/files/file/4111-general-election-1951-vol-i-ii/'
DETAIL_FILE = EDITION + '-9734.pdf'
DETAIL_SHA = 'ddc824406b6b49a01b0d9b73394827bd04945772471906d77c76addce5f197e2'
SUMMARY_FILE = EDITION + '-9735.pdf'
SUMMARY_SHA = '5edb3b4145da98e57b4032430cf0fa846a882d22278a41fa491b9a5d14c93718'
UNRESOLVED = {92, 277}
NOTE = ('Official 1951 constituency summary declares the elected members listed separately. '
        'Reported votes across seats are not ordinary one-seat turnout; the original extraction '
        'warning and all candidate rows remain available for review.')


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def norm(value: str) -> str:
    return re.sub(r'[^a-z0-9]', '', value.casefold())


def predecessor(root: Path) -> bytes:
    path = root / 'exports' / PREVIOUS_BUNDLE
    release_sha = path.with_suffix('.sha256').read_text(encoding='ascii').split()[0]
    if digest(path.read_bytes()) != release_sha:
        raise ValueError('Earlier PEPSU release checksum differs')
    with zipfile.ZipFile(path) as outer:
        inner = outer.read(f'correction-{EDITION}.zip')
    with zipfile.ZipFile(io.BytesIO(inner)) as correction:
        body = correction.read(f'election-archive/{EDITION}/extraction.json')
    if digest(body) != PREVIOUS_SHA:
        raise ValueError('Earlier PEPSU revised extraction checksum differs')
    return body


def multi_seat_import_script() -> str:
    script = guarded_import_script()
    marker = 'check_disk\nwhile IFS='
    if script.count(marker) != 1:
        raise ValueError('Guarded election import template changed')
    return script.replace(marker,
                          "grep -Fq 'multiSeatDeclaredWinners' "
                          "/home/pollmedia/app/application/app/Services/HistoricalElectionAnalytics.php "
                          "|| { echo 'Pull tested multi-seat election code first' >&2; exit 1; }\n"
                          + marker)


def declared_members(text: str, record: dict) -> tuple[str, dict, list[dict]]:
    state = re.search(r'STATE/UT\s*:\s*(.*?)\s+CODE\s*:\s*(S\d+)', text, re.I | re.S)
    seat = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*(.*?)\s+NUMBER OF SEATS\s*:\s*(\d+)',
                     text, re.I | re.S)
    if (state is None or seat is None or int(seat[1]) != record['official_pc_code']
            or int(seat[3]) != record['number_of_seats']
            or state[2] != (record.get('state_code') or 'S13')
            or (record['code'] == 345 and norm(state[1]) != norm('Patiala And East Punj'))
            or (record['code'] != 345 and not norm(record['state_name']).startswith(norm(state[1])))
            or len(norm(seat[2])) < 5
            or not norm(record['constituency_name']).startswith(norm(seat[2]))):
        raise ValueError('1951 multi-seat source identity differs: ' + str(record['code']))
    electors = source_total(text, r'II\. ELECTORS')
    voters = source_total(text, r'III\. ELECTORS WHO VOTED')
    vote_section = re.search(r'IV\. VOTES\b(.*?)VI\. DATES\b', text, re.I | re.S)
    polled = re.search(r'1\. POLLED\s+(\d+)', vote_section[1], re.I) if vote_section else None
    valid = re.search(r'2\. VALID\s+(\d+)', vote_section[1], re.I) if vote_section else None
    if (polled is None or valid is None
            or (electors, voters, int(polled[1]), int(valid[1]))
            != (record['electors'], record['votes_polled'], record['votes_polled'],
                record['valid_candidate_votes'])
            or sum(candidate['votes'] for candidate in record['candidates']) != record['valid_candidate_votes']):
        raise ValueError('1951 multi-seat source totals differ: ' + str(record['code']))
    found = re.findall(r'^\s*Winner\s+(\d+)\s+(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
    if (len(found) != record['number_of_seats']
            or [int(member[0]) for member in found] != list(range(1, record['number_of_seats'] + 1))):
        raise ValueError('1951 multi-seat declaration count differs: ' + str(record['code']))
    winners = []
    for _, party, name, votes in found:
        matching = [candidate for candidate in record['candidates']
                    if norm(candidate['candidate_name']) == norm(name)
                    and candidate['party_at_election'] == party
                    and candidate['votes'] == int(votes)]
        if len(matching) != 1:
            raise ValueError('1951 declared member does not match detailed candidate row: '
                             + str(record['code']))
        winners.append({'name': matching[0]['candidate_name'], 'party': party, 'votes': int(votes)})
    if len({(winner['name'], winner['party'], winner['votes']) for winner in winners}) != len(winners):
        raise ValueError('Duplicate declared 1951 member: ' + str(record['code']))
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
    if (before['kind'] != 'pc' or before['year'] != 1951 or len(before['records']) != 401
            or before['source_url'] != SOURCE_URL or manifest['url'] != SOURCE_URL
            or before['source_file'] != DETAIL_FILE or before['source_sha256'] != DETAIL_SHA
            or files[DETAIL_FILE]['sha256'] != DETAIL_SHA
            or files[SUMMARY_FILE]['sha256'] != SUMMARY_SHA):
        raise ValueError('Official 1951 Vol I/II identity differs')
    for filename, expected in ((DETAIL_FILE, DETAIL_SHA), (SUMMARY_FILE, SUMMARY_SHA)):
        path = folder / filename
        if path.is_symlink() or digest(path.read_bytes()) != expected:
            raise ValueError('Official 1951 PDF checksum differs: ' + filename)
    verified = []
    with fitz.open(folder / SUMMARY_FILE) as summary:
        for record in after['records']:
            seats = record.get('number_of_seats', 1)
            if seats < 2:
                continue
            code = record['code']
            if code in UNRESOLVED:
                continue
            page = record.get('summary_page') or (349 if code == 345 else None)
            if (page is None or record['status'] != 'needs_review'
                    or 'Multi-member constituency: individual winners require review' not in record['error']
                    or record.get('source_warning_code') is not None
                    or record.get('official_multi_seat_winners') is not None):
                raise ValueError('Live multi-seat review state differs: ' + str(code))
            name, totals, winners = declared_members(summary[page - 1].get_text(sort=True), record)
            record['previous_review_note'] = record['error']
            record['original_extraction_warning'] = record['error']
            record['error'] = (NOTE + ' The official summary explicitly prints zero votes for one '
                               'declared member; this is shown as printed.'
                               if any(winner['votes'] == 0 for winner in winners) else NOTE)
            record['source_warning_code'] = 'official_multi_seat_summary'
            record['summary_page'] = page
            record['summary_totals'] = totals
            record['summary_source_file'] = SUMMARY_FILE
            record['summary_source_sha256'] = SUMMARY_SHA
            record['detail_source_file'] = DETAIL_FILE
            record['detail_source_sha256'] = DETAIL_SHA
            record['official_summary_constituency_name'] = name
            record['official_multi_seat_winners'] = winners
            verified.append({'code': code, 'official_pc_code': record['official_pc_code'],
                             'state': record['state_name'], 'summary_page': page,
                             'number_of_seats': seats, 'winners': winners})
    if len(verified) != 85 or {row['code'] for row in verified} & UNRESOLVED:
        raise ValueError('1951 multi-seat declaration coverage differs')
    allowed = {'previous_review_note', 'original_extraction_warning', 'error', 'source_warning_code',
               'summary_totals', 'summary_source_file', 'summary_source_sha256', 'detail_source_file',
               'detail_source_sha256', 'official_summary_constituency_name', 'official_multi_seat_winners'}
    for old, new in zip(before['records'], after['records'], strict=True):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        expected = allowed | ({'summary_page'} if old['code'] == 345 else set()) if old['code'] in {row['code'] for row in verified} else set()
        if old['code'] != new['code'] or changed != expected:
            raise ValueError('Unrelated 1951 extraction evidence changed: ' + str(old['code']))
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    with tempfile.TemporaryDirectory(prefix='pc-1951-multi-', dir=root / 'exports') as temporary:
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
                'scope': '85 source-declared 1951 PC multi-seat member lists; no single winner or ordinary turnout',
                'edition': EDITION, 'source_url': SOURCE_URL,
                'detail_file': DETAIL_FILE, 'detail_sha256': DETAIL_SHA,
                'summary_file': SUMMARY_FILE, 'summary_sha256': SUMMARY_SHA,
                'previous_sha256': PREVIOUS_SHA, 'new_sha256': digest(new_body),
                'unresolved_codes': sorted(UNRESOLVED), 'results': verified,
            }, indent=2))
            bundle.writestr('IMPORT.sh', multi_seat_import_script())
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'previous_sha256': PREVIOUS_SHA,
            'new_sha256': digest(new_body), 'multi_seat_results': len(verified)}


if __name__ == '__main__':
    print(json.dumps(build()))
