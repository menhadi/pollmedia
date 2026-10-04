"""Preserve only the two members printed for three-seat North Bengal in 1951."""

import hashlib
import io
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_ac_1951_reviewed_incomplete_members import reviewed_import_script
from build_pc_1951_multi_seat_declared_members import (
    DETAIL_FILE, DETAIL_SHA, EDITION, SOURCE_URL, SUMMARY_FILE, SUMMARY_SHA,
)
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
NAME = 'pollmedia-pc-1951-north-bengal-reviewed-members-20261004'
PREVIOUS = 'pollmedia-pc-1951-surguja-two-declared-members-20261004.zip'
PREVIOUS_ZIP_SHA = 'ff2c85ca1cc2754420862a41fd9ae4610b15a9cca8e0ff4d0c0b16e49b55b216'
PREVIOUS_SHA = '9ebcf9560db1e422140d27c387ede9ca202a299d23f58b5f6d13a0913bbc542e'
CODE = 277
PAGE = 281
MEMBERS = [('UPENDRA NATH BARMAN', 'INC', 177618),
           ('BIRENDRA NATH KATHAM', 'INC', 163604)]


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def previous_body(root: Path) -> bytes:
    archive = root / 'exports' / PREVIOUS
    if digest(archive.read_bytes()) != PREVIOUS_ZIP_SHA:
        raise ValueError('Prior 1951 PC release ZIP differs')
    with zipfile.ZipFile(archive) as outer:
        names = dict((name, sha) for sha, name in
                     (line.split() for line in outer.read('SHA256SUMS').decode('ascii').splitlines()))
        inner = outer.read(f'correction-{EDITION}.zip')
        audit = json.loads(outer.read('AUDIT.json'))
        if (digest(inner) != names[f'correction-{EDITION}.zip']
                or audit['edition'] != EDITION or audit['new_sha256'] != PREVIOUS_SHA):
            raise ValueError('Prior 1951 PC release audit differs')
    with zipfile.ZipFile(io.BytesIO(inner)) as correction:
        body = correction.read(f'election-archive/{EDITION}/extraction.json')
    if digest(body) != PREVIOUS_SHA:
        raise ValueError('Exact 1951 PC predecessor bytes differ')
    return body


def source_members(source: str, record: dict) -> tuple[str, dict, list[dict]]:
    state = re.search(r'STATE/UT\s*:\s*(.*?)\s+CODE\s*:\s*(S\d+)', source, re.I | re.S)
    seat = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*(.*?)\s+NUMBER OF SEATS\s*:\s*(\d+)',
                     source, re.I | re.S)
    if (state is None or state[1].strip() != 'West Bengal' or state[2] != 'S09'
            or seat is None or int(seat[1]) != 1 or seat[2].strip().upper() != 'NORTH BENGAL'
            or int(seat[3]) != 3 or record['official_pc_code'] != 1
            or record['state_name'] != 'West Bengal'
            or record['constituency_name'] != 'NORTH BENGAL'):
        raise ValueError('North Bengal official constituency identity differs')
    electors = re.search(r'II\. ELECTORS\b(.*?)III\. ELECTORS WHO VOTED', source, re.I | re.S)
    voters = re.search(r'III\. ELECTORS WHO VOTED\b(.*?)IV\. VOTES', source, re.I | re.S)
    votes = re.search(r'IV\. VOTES\b(.*?)VI\. DATES', source, re.I | re.S)
    if not all((electors, voters, votes)):
        raise ValueError('North Bengal summary total sections differ')
    first = re.search(r'1\. TOTAL\s+(\d+)', electors[1])
    second = re.search(r'1\. TOTAL\s+(\d+)', voters[1])
    polled = re.search(r'1\. POLLED\s+(\d+)', votes[1])
    valid = re.search(r'2\. VALID\s+(\d+)', votes[1])
    if not all((first, second, polled, valid)):
        raise ValueError('North Bengal official totals missing')
    totals = (int(first[1]), int(second[1]), int(polled[1]), int(valid[1]))
    if (totals != (931845, 862056, 862056, 862056)
            or totals != (record['electors'], record['votes_polled'],
                          record['votes_polled'], record['valid_candidate_votes'])
            or sum(row['votes'] for row in record['candidates']) != totals[3]):
        raise ValueError('North Bengal official and candidate totals differ')
    found = re.findall(r'^\s*Winner\s+(\d+)\s+(\S+)\s+(.+?)\s+(\d+)\s*$',
                       source, re.I | re.M)
    named = [(name.strip(), party, int(count)) for _, party, name, count in found]
    if ([int(row[0]) for row in found] != [1, 2] or named != MEMBERS
            or re.search(r'^\s*Winner\s+3\b', source, re.I | re.M)):
        raise ValueError('North Bengal printed elected members differ')
    for name, party, count in named:
        matching = [row for row in record['candidates']
                    if (row['candidate_name'], row['party_at_election'], row['votes'])
                    == (name, party, count)]
        if len(matching) != 1:
            raise ValueError('North Bengal detailed candidate match differs')
    return seat[2].strip(), {
        'electors': totals[0], 'votes_polled': totals[1], 'valid_candidate_votes': totals[3],
    }, [{'name': name, 'party': party, 'votes': count} for name, party, count in named]


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body = previous_body(root)
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
        raise ValueError('Official 1951 PC source provenance differs')
    for filename, sha in ((DETAIL_FILE, DETAIL_SHA), (SUMMARY_FILE, SUMMARY_SHA)):
        source = folder / filename
        if source.is_symlink() or digest(source.read_bytes()) != sha:
            raise ValueError('Official 1951 PC PDF checksum differs: ' + filename)
    record = next(row for row in after['records'] if row['code'] == CODE)
    if (record['status'] != 'needs_review' or record['number_of_seats'] != 3
            or record['summary_page'] != PAGE
            or record.get('source_warning_code') is not None
            or record.get('official_multi_seat_winners') is not None
            or record.get('official_multi_seat_review_members') is not None
            or record['error'] != 'Multi-member constituency: individual winners require review; '
                                  'Summary label missing or ambiguous: Winner'):
        raise ValueError('North Bengal unresolved extraction state differs')
    with fitz.open(folder / SUMMARY_FILE) as pdf:
        source = pdf[PAGE - 1].get_text(sort=True)
    name, totals, members = source_members(source, record)
    record['previous_review_note'] = record['error']
    record['original_extraction_warning'] = record['error']
    record['error'] = ('The official summary names only two elected members for this three-seat '
                       'constituency. No third member is inferred. Reported votes across seats '
                       'are not ordinary one-seat turnout; candidate rows are unchanged.')
    record['source_warning_code'] = 'official_multi_seat_reviewed_declaration'
    record['summary_totals'] = totals
    record['summary_source_file'] = SUMMARY_FILE
    record['summary_source_sha256'] = SUMMARY_SHA
    record['official_summary_constituency_name'] = name
    record['official_multi_seat_review_members'] = members
    record['official_multi_seat_review_reason'] = 'incomplete_official_list'
    allowed = {'previous_review_note', 'original_extraction_warning', 'error', 'source_warning_code',
               'summary_totals', 'summary_source_file', 'summary_source_sha256',
               'official_summary_constituency_name', 'official_multi_seat_review_members',
               'official_multi_seat_review_reason'}
    for old, new in zip(before['records'], after['records'], strict=True):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        if old['code'] != new['code'] or changed != (allowed if old['code'] == CODE else set()):
            raise ValueError('Unrelated 1951 PC extraction evidence changed')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{PREVIOUS_SHA}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='pc-1951-north-bengal-', dir=root / 'exports') as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        archives = []
        for kind, relative, body in (('snapshot', snapshot, old_body),
                                     ('correction', revision, new_body)):
            path = staged / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(body)
            bucket = hashlib.sha256(relative.encode()).digest()[0] % 8
            archive = packages / f'{kind}-{EDITION}.zip'
            package(staged, archive, 'election-archive', bucket, 8, [relative],
                    PREVIOUS_SHA if kind == 'correction' else None,
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
                'scope': 'Two source-named members of incomplete three-seat North Bengal 1951 PC',
                'edition': EDITION, 'source_url': SOURCE_URL, 'summary_file': SUMMARY_FILE,
                'summary_sha256': SUMMARY_SHA, 'previous_sha256': PREVIOUS_SHA,
                'new_sha256': digest(new_body), 'code': CODE, 'number_of_seats': 3,
                'summary_page': PAGE, 'members_named': members,
                'review_reason': 'incomplete_official_list',
            }, indent=2))
            release.writestr('IMPORT.sh', reviewed_import_script())
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'previous_sha256': PREVIOUS_SHA,
            'new_sha256': digest(new_body), 'members_named': members}


if __name__ == '__main__':
    print(json.dumps(build()))
