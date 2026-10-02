"""Package eight source-reconciled 1971 PC results with retained review warnings."""

import csv
import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_pc_1992_summary_result_bundle import normalized, printed_candidate
from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


EDITION = 'a62b405d308af2536df91caa'
NAME = 'pollmedia-pc-1971-summary-results-20261002'
DETAIL_FILE = EDITION + '-9746.pdf'
SUMMARY_FILE = EDITION + '-9747.pdf'
TARGETS = {
    62: ('Bihar', 'MAHARAJGANJ', 'MAHARAJGANJ', 'Candidate count differs from the summary'),
    78: ('Bihar', 'KATIHAR', 'KATIHAR', 'Candidate count differs from the summary'),
    87: ('Bihar', 'BEGUSARAI', 'BEGUSARAI', 'Candidate count differs from the summary'),
    111: ('Gujarat', 'RAJKOT', 'RAJKOT', 'Candidate count differs from the summary'),
    184: ('Madhya Pradesh', 'DURG', 'DURG', 'Candidate count differs from the summary'),
    309: ('Punjab', 'BHATINDA (SC)', 'BHATINDA (SC)', 'Candidate count differs from the summary'),
    314: ('Rajasthan', 'JAIPUR', 'JAIPUR', 'Candidate count differs from the summary'),
    338: ('Tamil Nadu', 'VELLORE (SC)', 'VELLORE (SC)', 'Candidate count differs from the summary'),
}


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def source_totals(section: str) -> int:
    row = re.search(r'3\. TOTAL\s+(\d+)\s+(\d+)\s+(\d+)', section, re.I)
    if not row or int(row[1]) + int(row[2]) != int(row[3]):
        raise ValueError('Official summary total does not reconcile')
    return int(row[3])


def verified_summary(text: str, detail_text: str, record: dict, expected: tuple) -> dict:
    state = re.search(r'STATE/UT\s*:\s*([^\n]+?)\s+CODE\s*:\s*([A-Z]\d+)', text, re.I)
    seat = re.search(r'CONSTITUENCY\s*:\s*([^\n]+?)\s+NO\s*:\s*(\d+)', text, re.I)
    electors = re.search(r'II\. ELECTORS\b(.*?)III\. ELECTORS WHO VOTED\b', text, re.I | re.S)
    voters = re.search(r'III\. ELECTORS WHO VOTED\b(.*?)IV\. VOTES\b', text, re.I | re.S)
    votes = re.search(r'IV\. VOTES\b(.*?)V\. POLLING STATIONS\b', text, re.I | re.S)
    valid = re.search(r'2\. VALID\s+(\d+)', votes[1], re.I) if votes else None
    margin = re.search(r'MARGIN\s*:\s*(\d+)', text, re.I)
    detail_seat = re.search(r'Constituency\s*:\s*' + str(record['official_pc_code'])
                            + r'\s*\.\s*([^\n]+)', detail_text, re.I)
    if not all((state, seat, electors, voters, valid, margin, detail_seat)):
        raise ValueError('Official detailed or summary source is incomplete')
    state_name, detail_name, summary_name, _ = expected
    if (normalized(state[1]) != normalized(state_name) or state[2].upper() != record['state_code']
            or int(seat[2]) != record['official_pc_code']
            or normalized(seat[1]) != normalized(summary_name)
            or normalized(detail_seat[1]) != normalized(detail_name)):
        raise ValueError('Detailed and summary seat identity does not match the documented variants')
    if ((source_totals(electors[1]), source_totals(voters[1]), int(valid[1]))
            != (record['electors'], record['votes_polled'], record['valid_candidate_votes'])):
        raise ValueError('Official and archived turnout totals differ')
    candidates = record['candidates']
    if (len(candidates) < 2 or any(not isinstance(row['votes'], int) or row['votes'] < 0
                                   or not row['candidate_name'] or not row['party_at_election']
                                   for row in candidates)
            or sum(row['votes'] for row in candidates) != record['valid_candidate_votes']):
        raise ValueError('Detailed candidate votes do not reconcile to official valid votes')
    ranked = sorted(candidates, key=lambda row: row['votes'], reverse=True)
    winner, runner = ranked[:2]
    if (winner['votes'] <= runner['votes'] or not printed_candidate(text, 'Winner', winner)
            or not printed_candidate(text, 'Runner up', runner)
            or int(margin[1]) != winner['votes'] - runner['votes']):
        raise ValueError('Official winner or margin differs from candidate rows')
    return {'winner': winner['candidate_name'], 'winner_party': winner['party_at_election'],
            'winner_votes': winner['votes'], 'runner': runner['candidate_name'],
            'runner_party': runner['party_at_election'], 'runner_votes': runner['votes'],
            'margin': int(margin[1])}


def live_audit_sha(path: Path) -> str:
    with path.open(encoding='utf-8-sig', newline='') as source:
        matches = [row for row in csv.DictReader(source)
                   if row['edition_id'] == EDITION and row['issue'] == 'winner_hidden_with_candidate_votes']
    if ({int(row['code']) for row in matches} != set(TARGETS) or len(matches) != len(TARGETS)
            or len({row['extraction_sha256'] for row in matches}) != 1):
        raise ValueError('Live audit does not match the eight 1971 PC gaps')
    return matches[0]['extraction_sha256']


def build(root: Path) -> dict:
    exports = root / 'exports'
    output = exports / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    old_body = (folder / 'extraction.json').read_bytes()
    old_sha = digest(old_body)
    if old_sha != live_audit_sha(exports / 'pc-ac-display-audit-after-bdd282d.csv'):
        raise ValueError('Local and live 1989 election JSON checksums differ')
    old = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    if old['kind'] != 'pc' or old['year'] != 1971 or old['source_url'] != manifest['url']:
        raise ValueError('Official 1989 election identity differs')
    files = {item['file']: item for item in manifest['files']}
    for name in (DETAIL_FILE, SUMMARY_FILE):
        path = folder / name
        if path.is_symlink() or digest(path.read_bytes()) != files[name]['sha256']:
            raise ValueError('Official PDF checksum differs: ' + name)

    new = json.loads(old_body)
    results = []
    with fitz.open(folder / DETAIL_FILE) as detail, fitz.open(folder / SUMMARY_FILE) as summary:
        for record in new['records']:
            expected = TARGETS.get(record['code'])
            if expected is None:
                continue
            if ((record['state_name'], record['constituency_name'], record['error'])
                    != (expected[0], expected[1], expected[3])
                    or record['status'] != 'needs_review' or record['number_of_seats'] != 1
                    or record.get('winner') is not None or record.get('margin') is not None
                    or not 1 <= record['detail_page'] <= len(detail)
                    or not 1 <= record['summary_page'] <= len(summary)):
                raise ValueError('Archived reviewed record differs: ' + str(record['code']))
            result = verified_summary(summary[record['summary_page'] - 1].get_text(sort=True),
                                      detail[record['detail_page'] - 1].get_text(sort=True),
                                      record, expected)
            record['original_extraction_warning'] = record['error']
            record['source_warning_code'] = 'official_pc_summary_reconciled_detail_warning'
            record['detail_candidate_count'] = len(record['candidates'])
            record['summary_result'] = result
            record['summary_source_file'] = SUMMARY_FILE
            record['summary_source_sha256'] = files[SUMMARY_FILE]['sha256']
            record['detail_source_file'] = DETAIL_FILE
            record['detail_source_sha256'] = files[DETAIL_FILE]['sha256']
            record['official_summary_constituency_name'] = expected[2]
            results.append({'code': record['code'], 'detail_name': expected[1],
                            'summary_name': expected[2], 'winner': result['winner'],
                            'margin': result['margin']})
    if {row['code'] for row in results} != set(TARGETS):
        raise ValueError('The eight official 1971 results were not all verified')
    for before, after in zip(old['records'], new['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        allowed = ({'original_extraction_warning', 'source_warning_code', 'detail_candidate_count',
                    'summary_result', 'summary_source_file', 'summary_source_sha256',
                    'detail_source_file', 'detail_source_sha256', 'official_summary_constituency_name'}
                   if before['code'] in TARGETS else set())
        if before['code'] != after['code'] or changed != allowed:
            raise ValueError('An unrelated election value changed')
    new_body = json.dumps(new, ensure_ascii=False, indent=2).encode('utf-8')
    new_sha = digest(new_body)

    with tempfile.TemporaryDirectory(prefix='pc-1971-results-', dir=exports) as temporary:
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
                'scope': 'Eight 1971 PC results verified from official summary and detail PDFs',
                'edition': EDITION, 'source_url': old['source_url'],
                'detail_file': DETAIL_FILE, 'detail_sha256': files[DETAIL_FILE]['sha256'],
                'summary_file': SUMMARY_FILE, 'summary_sha256': files[SUMMARY_FILE]['sha256'],
                'previous_sha256': old_sha, 'new_sha256': new_sha, 'records': results,
            }, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    output.with_suffix('.sha256').write_bytes(
        f'{digest(output.read_bytes())}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'records': len(results), 'previous_sha256': old_sha,
            'new_sha256': new_sha, 'sha256': digest(output.read_bytes())}


if __name__ == '__main__':
    print(json.dumps(build(Path(__file__).resolve().parents[1])))
