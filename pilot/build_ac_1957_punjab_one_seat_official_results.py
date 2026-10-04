"""Corroborate 1957 Punjab AC one-seat declarations from each official PDF summary."""

import hashlib
import io
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_ac_1957_punjab_multi_seat_declared_members import norm, source_total, summary_pages
from build_pc_1951_multi_seat_declared_members import multi_seat_import_script
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
EDITION = 'f314a5fdc1b90616322346d0'
NAME = 'pollmedia-ac-1957-punjab-one-seat-official-results-20261004'
PREDECESSOR = '93e2b0f3c90f5100479b898bc1196667ef51d8b65e6145bc9adb6c76ab819277'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3443-punjab-1957/'
SOURCE_FILE = f'{EDITION}-7976.pdf'
SOURCE_SHA = '3cac3109e47bfc684ee13c15eef73d3b505bc5cacc65b7684226f1a51c5aa986'
PREVIOUS_ERROR = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.'
UNCONTESTED_ERROR = PREVIOUS_ERROR + '; Reported elector and voter totals are inconsistent.'
RESULT_NOTE = ('Official 1957 Punjab summary corroborates turnout, named winner, runner-up and margin; '
               'original detailed-PDF extraction warning remains for review.')
UNCONTESTED_NOTE = ('Official 1957 Punjab summary declares this candidate returned uncontested. '
                    'The report gives no voter or vote total; archived zero candidate/voter fields '
                    'are preserved as extraction bytes, not interpreted as measured votes or turnout.')


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def predecessor_bytes(root: Path) -> bytes:
    release = root / 'exports' / 'pollmedia-ac-1957-punjab-multi-seat-declared-members-20261004.zip'
    with zipfile.ZipFile(release) as outer:
        with zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{EDITION}.zip'))) as inner:
            body = inner.read(f'election-archive/{EDITION}/extraction.json')
    if digest(body) != PREDECESSOR:
        raise ValueError('Queued 1957 Punjab predecessor differs')
    return body


def reconcile(record: dict, page: int, text: str, seat: re.Match) -> str:
    code = record['code']
    if (record['number_of_seats'] != 1 or record['state_name'] != 'Punjab'
            or record['status'] != 'needs_review' or record.get('summary_page') is not None
            or record.get('source_warning_code') is not None
            or int(seat[1]) != code or int(seat[3]) != 1
            or norm(seat[2]) != norm(record['name'])
            or 'LEGISLATIVE ASSEMBLY OF PUNJAB' not in text.upper()):
        raise ValueError(f'1957 Punjab one-seat identity differs: {code}')
    electors = source_total(text, r'II\. ELECTORS', r'III\. ELECTORS WHO VOTED')
    if electors != record['electors']:
        raise ValueError(f'1957 Punjab electors differ: {code}')
    section = re.search(r'VII\. RESULT\b(.*?)rptConstituencySummary', text, re.I | re.S)
    if section is None:
        raise ValueError(f'1957 Punjab result absent: {code}')
    record['previous_review_note'] = record['error']
    record['original_extraction_warning'] = record['error']
    record['summary_page'] = page
    record['summary_source_file'] = SOURCE_FILE
    record['summary_source_sha256'] = SOURCE_SHA
    record['detail_source_file'] = SOURCE_FILE
    record['detail_source_sha256'] = SOURCE_SHA
    record['official_summary_constituency_name'] = seat[2].strip()

    if 'Uncontested' in text:
        if (record['error'] != UNCONTESTED_ERROR or len(record['candidates']) != 1
                or record['votes_polled'] != 0 or record['valid_candidate_votes'] != 0
                or record['candidates'][0]['votes'] != 0):
            raise ValueError(f'1957 Punjab uncontested extraction differs: {code}')
        declaration = re.search(r'Winner\s+(\S+)\s+(.+?)\s+Returned\s+Uncontested',
                                section[1], re.I | re.S)
        if declaration is None or (declaration[2].strip(), declaration[1]) != (
                record['candidates'][0]['candidate_name'], record['candidates'][0]['party_at_election']):
            raise ValueError(f'1957 Punjab uncontested declaration differs: {code}')
        if (re.search(r'(?m)^[ \t]*1\. TOTAL[ \t]+\d+[^\n]*$',
                      re.search(r'III\. ELECTORS WHO VOTED(.*?)IV\. VOTES', text, re.I | re.S)[1])
                or re.search(r'(?m)^[ \t]*1\. POLLED[ \t]+\d+[^\n]*$',
                             re.search(r'IV\. VOTES(.*?)VI\. DATES', text, re.I | re.S)[1])):
            raise ValueError(f'1957 Punjab uncontested page prints a vote total: {code}')
        record['error'] = UNCONTESTED_NOTE
        record['source_warning_code'] = 'official_uncontested_summary'
        record['summary_source_rows'] = [f"Winner {declaration[1]} {declaration[2].strip()} Returned Uncontested"]
        record['summary_totals'] = {'electors': electors, 'votes_polled': None,
                                    'valid_candidate_votes': None}
        return 'uncontested'

    if record['error'] != PREVIOUS_ERROR or len(record['candidates']) < 2:
        raise ValueError(f'1957 Punjab contested extraction differs: {code}')
    voters = source_total(text, r'III\. ELECTORS WHO VOTED', r'IV\. VOTES')
    vote_section = re.search(r'IV\. VOTES\b(.*?)VI\. DATES\b', text, re.I | re.S)
    polled = re.search(r'1\. POLLED\s+(\d+)', vote_section[1]) if vote_section else None
    valid = re.search(r'2\. VALID\s+(\d+)', vote_section[1]) if vote_section else None
    if (not polled or not valid or voters < 1 or voters > electors
            or (electors, voters, int(polled[1]), int(valid[1])) !=
            (record['electors'], record['votes_polled'], record['votes_polled'],
             record['valid_candidate_votes'])):
        raise ValueError(f'1957 Punjab contested totals differ: {code}')
    rows = re.findall(r'^\s*(Winner|Runner up)\s+(\S+)\s+(.+?)\s+(\d+)\s*$',
                      section[1], re.I | re.M)
    margin = re.search(r'MARGIN\s*:\s*(\d+)', section[1], re.I)
    if (len(rows) != 2 or [row[0].lower() for row in rows] != ['winner', 'runner up']
            or margin is None or int(rows[0][3]) <= int(rows[1][3])
            or int(rows[0][3]) - int(rows[1][3]) != int(margin[1])):
        raise ValueError(f'1957 Punjab declared result differs: {code}')
    ranked = sorted(record['candidates'], key=lambda row: row['votes'], reverse=True)
    if (sum(row['votes'] for row in ranked) != int(valid[1])
            or len({(row['candidate_name'], row['party_at_election'], row['votes']) for row in ranked}) != len(ranked)
            or ranked[0]['votes'] <= ranked[1]['votes']):
        raise ValueError(f'1957 Punjab detailed candidate rows differ: {code}')
    for source, candidate in zip(rows, ranked[:2], strict=True):
        if (source[2].strip(), source[1], int(source[3])) != (
                candidate['candidate_name'], candidate['party_at_election'], candidate['votes']):
            raise ValueError(f'1957 Punjab summary/detailed candidate conflict: {code}')
    record['error'] = RESULT_NOTE
    record['source_warning_code'] = 'official_summary_turnout_only'
    record['summary_totals'] = {'electors': electors, 'votes_polled': voters,
                                'valid_candidate_votes': int(valid[1])}
    record['summary_result'] = {'winner': ranked[0]['candidate_name'],
                                'winner_party': ranked[0]['party_at_election'],
                                'winner_votes': ranked[0]['votes'],
                                'runner': ranked[1]['candidate_name'],
                                'runner_party': ranked[1]['party_at_election'],
                                'runner_votes': ranked[1]['votes'], 'margin': int(margin[1])}
    return 'contested'


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    source = folder / SOURCE_FILE
    old_body = predecessor_bytes(root)
    if source.is_symlink() or digest(source.read_bytes()) != SOURCE_SHA:
        raise ValueError('1957 Punjab official source differs')
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    before = json.loads(old_body)
    after = json.loads(old_body)
    if (manifest['url'] != SOURCE_URL
            or next(file for file in manifest['files'] if file['file'] == SOURCE_FILE)['sha256'] != SOURCE_SHA
            or before['kind'] != 'ac' or before['year'] != 1957
            or before['source_url'] != SOURCE_URL or before['source_file'] != SOURCE_FILE
            or before['source_sha256'] != SOURCE_SHA or len(before['records']) != 121):
        raise ValueError('1957 Punjab archive provenance differs')
    seen = {'contested': [], 'uncontested': []}
    with fitz.open(source) as pdf:
        pages = summary_pages(pdf)
        for record in after['records']:
            if record['number_of_seats'] != 1:
                continue
            code = record['code']
            page, text, seat = pages[code]
            seen[reconcile(record, page, text, seat)].append({'code': code, 'summary_page': page})
    if len(seen['contested']) != 87 or [item['code'] for item in seen['uncontested']] != [43]:
        raise ValueError('1957 Punjab one-seat coverage differs')
    for old, new in zip(before['records'], after['records'], strict=True):
        if old['code'] != new['code'] or old['candidates'] != new['candidates']:
            raise ValueError('1957 Punjab original candidate rows changed')
        if old['number_of_seats'] == 2 and old != new:
            raise ValueError('1957 Punjab queued two-seat correction changed')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{PREDECESSOR}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='ac-1957-punjab-one-', dir=root / 'exports') as temporary:
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
                'scope': '87 contested and 1 uncontested source-declared 1957 Punjab AC one-seat results',
                'edition': EDITION, 'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
                'source_sha256': SOURCE_SHA, 'previous_sha256': PREDECESSOR,
                'new_sha256': digest(new_body), 'results': seen,
            }, indent=2))
            release.writestr('IMPORT.sh', multi_seat_import_script())
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'previous_sha256': PREDECESSOR,
            'new_sha256': digest(new_body), 'contested': 87, 'uncontested': 1}


if __name__ == '__main__':
    print(json.dumps(build()))
