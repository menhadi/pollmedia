"""Package Eluru's 1980 PC result with the official candidate-count discrepancy retained."""

import csv
import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_pc_1989_summary_result_bundle import source_totals
from build_pc_1992_summary_result_bundle import normalized, printed_candidate
from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


EDITION = 'e6e3a615a5fc51328d154069'
NAME = 'pollmedia-pc-1980-eluru-result-20261002'
DETAIL_FILE = EDITION + '-9752.pdf'
SUMMARY_FILE = EDITION + '-9753.pdf'
TARGET_CODE = 11


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def verified_result(summary_text: str, heading_text: str, candidate_text: str, record: dict) -> tuple[dict, int]:
    state = re.search(r'STATE/UT\s*:\s*([^\n]+?)\s+CODE\s*:\s*([A-Z]\d+)', summary_text, re.I)
    seat = re.search(r'NO\s*:\s*(\d+)\s+CONSTITUENCY\s*:\s*([^\n]+)', summary_text, re.I)
    contested = re.search(r'4\. CONTESTED\s+(\d+)\s+(\d+)\s+(\d+)', summary_text, re.I)
    electors = re.search(r'II\. ELECTORS\b(.*?)III\. ELECTORS WHO VOTED\b', summary_text, re.I | re.S)
    voters = re.search(r'III\. ELECTORS WHO VOTED\b(.*?)IV\. VOTES\b', summary_text, re.I | re.S)
    votes = re.search(r'IV\. VOTES\b(.*?)V\. POLLING STATIONS\b', summary_text, re.I | re.S)
    valid = re.search(r'2\. VALID\s+(\d+)', votes[1], re.I) if votes else None
    margin = re.search(r'MARGIN\s*:\s*(\d+)', summary_text, re.I)
    heading = re.search(r'Constituency\s*:\s*11\s*\.\s*([^\n]+)', heading_text, re.I)
    if not all((state, seat, contested, electors, voters, valid, margin, heading)):
        raise ValueError('Official detailed or summary source is incomplete')
    if (normalized(state[1]) != normalized('ANDHRA PRADESH') or state[2] != record['state_code']
            or int(seat[1]) != record['official_pc_code'] or normalized(seat[2]) != normalized('ELURU')
            or normalized(heading[1]) != normalized('ELURU')):
        raise ValueError('Official Eluru seat identity differs')
    summary_count = int(contested[3])
    if int(contested[1]) + int(contested[2]) != summary_count or summary_count != 8:
        raise ValueError('Official candidate count differs from the documented discrepancy')
    if ((source_totals(electors[1]), source_totals(voters[1]), int(valid[1]))
            != (record['electors'], record['votes_polled'], record['valid_candidate_votes'])):
        raise ValueError('Official and archived turnout totals differ')

    candidates = record['candidates']
    if (len(candidates) != 9 or sum(row['votes'] for row in candidates) != record['valid_candidate_votes']):
        raise ValueError('Detailed candidate rows do not reconcile')
    candidate_section = candidate_text.split('ELECTORS :', 1)[0]
    for candidate in candidates:
        name = re.escape(candidate['candidate_name']).replace(r'\ ', r'\s+')
        pattern = (name + r'\s+(?:[MF]\s+)?' + re.escape(candidate['party_at_election'])
                   + r'\s+' + str(candidate['votes']) + r'\b')
        if not re.search(pattern, candidate_section, re.I):
            raise ValueError('An archived candidate row is absent from the detailed PDF')
    detail_totals = re.search(r'ELECTORS\s*:\s*(\d+)\s+VOTERS\s*:\s*(\d+).*?VALID VOTES\s*:\s*(\d+)',
                              candidate_text, re.I | re.S)
    if not detail_totals or tuple(int(part) for part in detail_totals.groups()) != (
            record['electors'], record['votes_polled'], record['valid_candidate_votes']):
        raise ValueError('Detailed PDF totals differ from the official summary')

    ranked = sorted(candidates, key=lambda row: row['votes'], reverse=True)
    winner, runner = ranked[:2]
    if (winner['votes'] <= runner['votes'] or not printed_candidate(summary_text, 'Winner', winner)
            or not printed_candidate(summary_text, 'Runner up', runner)
            or int(margin[1]) != winner['votes'] - runner['votes']):
        raise ValueError('Official winner or margin differs from candidate rows')
    result = {'winner': winner['candidate_name'], 'winner_party': winner['party_at_election'],
              'winner_votes': winner['votes'], 'runner': runner['candidate_name'],
              'runner_party': runner['party_at_election'], 'runner_votes': runner['votes'],
              'margin': int(margin[1])}
    return result, summary_count


def build(root: Path) -> dict:
    exports = root / 'exports'
    output = exports / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    old_body = (folder / 'extraction.json').read_bytes()
    old_sha = digest(old_body)
    with (exports / 'pc-ac-display-audit-after-bdd282d.csv').open(encoding='utf-8-sig', newline='') as live_csv:
        matches = [row for row in csv.DictReader(live_csv)
                   if row['edition_id'] == EDITION and row['issue'] == 'winner_hidden_with_candidate_votes']
    if len(matches) != 1 or int(matches[0]['code']) != TARGET_CODE or matches[0]['extraction_sha256'] != old_sha:
        raise ValueError('Live audit and local 1980 extraction differ')
    old = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    if old['kind'] != 'pc' or old['year'] != 1980 or old['source_url'] != manifest['url']:
        raise ValueError('Official 1980 election identity differs')
    files = {item['file']: item for item in manifest['files']}
    for name in (DETAIL_FILE, SUMMARY_FILE):
        path = folder / name
        if path.is_symlink() or digest(path.read_bytes()) != files[name]['sha256']:
            raise ValueError('Official PDF checksum differs: ' + name)

    new = json.loads(old_body)
    targets = [row for row in new['records'] if row['code'] == TARGET_CODE]
    if len(targets) != 1:
        raise ValueError('Eluru record is missing or duplicated')
    record = targets[0]
    if (record['state_name'] != 'ANDHRA PRADESH' or record['constituency_name'] != 'ELURU'
            or record['error'] != 'Candidate count differs from the summary'
            or record['status'] != 'needs_review' or record['number_of_seats'] != 1
            or record.get('winner') is not None or record.get('margin') is not None):
        raise ValueError('Archived Eluru review state differs')
    with fitz.open(folder / DETAIL_FILE) as detail, fitz.open(folder / SUMMARY_FILE) as summary:
        if not 1 <= record['detail_page'] < len(detail) or not 1 <= record['summary_page'] <= len(summary):
            raise ValueError('Official Eluru PDF pages are out of range')
        result, summary_count = verified_result(summary[record['summary_page'] - 1].get_text(sort=True),
                                                detail[record['detail_page'] - 1].get_text(sort=True),
                                                detail[record['detail_page']].get_text(sort=True), record)
    record['original_extraction_warning'] = record['error']
    record['source_warning_code'] = 'official_pc_summary_reconciled_detail_warning'
    record['detail_candidate_count'] = len(record['candidates'])
    record['official_summary_candidate_count'] = summary_count
    record['detail_candidate_page'] = record['detail_page'] + 1
    record['summary_result'] = result
    record['summary_source_file'] = SUMMARY_FILE
    record['summary_source_sha256'] = files[SUMMARY_FILE]['sha256']
    record['detail_source_file'] = DETAIL_FILE
    record['detail_source_sha256'] = files[DETAIL_FILE]['sha256']
    allowed = {'original_extraction_warning', 'source_warning_code', 'detail_candidate_count',
               'official_summary_candidate_count', 'detail_candidate_page', 'summary_result',
               'summary_source_file', 'summary_source_sha256', 'detail_source_file', 'detail_source_sha256'}
    for before, after in zip(old['records'], new['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if before['code'] != after['code'] or changed != (allowed if before['code'] == TARGET_CODE else set()):
            raise ValueError('An unrelated election value changed')
    new_body = json.dumps(new, ensure_ascii=False, indent=2).encode('utf-8')
    new_sha = digest(new_body)

    with tempfile.TemporaryDirectory(prefix='pc-1980-eluru-', dir=exports) as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{old_sha}.json'
        revision = f'election-archive/{EDITION}/extraction.json'
        for relative, body in ((snapshot, old_body), (revision, new_body)):
            path = staged / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(body)
        inner = []
        for kind, relative in (('snapshot', snapshot), ('correction', revision)):
            bucket = hashlib.sha256(relative.encode('utf-8')).digest()[0] % 8
            path = packages / f'{kind}-{EDITION}.zip'
            package(staged, path, 'election-archive', bucket, 8, [relative],
                    old_sha if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
            inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as archive:
            for path in inner:
                archive.write(path, path.name)
            archive.writestr('SHA256SUMS', ''.join(f'{digest(path.read_bytes())}  {path.name}\n' for path in inner))
            archive.writestr('ARCHIVES', EDITION + '\n')
            archive.writestr('AUDIT.json', json.dumps({
                'scope': '1980 Eluru PC official result with detailed nine-versus-summary eight candidate warning',
                'edition': EDITION, 'source_url': old['source_url'],
                'detail_file': DETAIL_FILE, 'detail_sha256': files[DETAIL_FILE]['sha256'],
                'summary_file': SUMMARY_FILE, 'summary_sha256': files[SUMMARY_FILE]['sha256'],
                'previous_sha256': old_sha, 'new_sha256': new_sha,
                'detail_candidate_count': len(record['candidates']),
                'summary_candidate_count': summary_count, 'result': result,
            }, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    output.with_suffix('.sha256').write_bytes(
        f'{digest(output.read_bytes())}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'previous_sha256': old_sha,
            'new_sha256': new_sha, 'sha256': digest(output.read_bytes())}


if __name__ == '__main__':
    print(json.dumps(build(Path(__file__).resolve().parents[1])))
