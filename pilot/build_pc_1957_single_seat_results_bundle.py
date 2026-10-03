"""Recover three 1957 single-seat PC results from official detailed and summary PDFs."""

import csv
import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_pc_1992_summary_result_bundle import normalized
from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


EDITION = 'a1b887ea9c50978fe4cf8e5c'
NAME = 'pollmedia-pc-1957-three-single-seat-results-20261003'
DETAIL_FILE = EDITION + '-9737.pdf'
SUMMARY_FILE = EDITION + '-9738.pdf'
TARGET_CODES = {37, 387, 388}


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def live_audit_sha(path: Path) -> str:
    with path.open(encoding='utf-8-sig', newline='') as source:
        rows = [row for row in csv.DictReader(source)
                if row['edition_id'] == EDITION and row['issue'] == 'winner_hidden_with_candidate_votes']
    if (len(rows) != 96 or len({row['extraction_sha256'] for row in rows}) != 1
            or not TARGET_CODES <= {int(row['code']) for row in rows}):
        raise ValueError('Live audit does not match the selected 1957 PC hidden results')
    return rows[0]['extraction_sha256']


def source_total(text: str, section: str) -> int:
    match = re.search(section + r'\b(.*?)(?=IV\. VOTES|III\. ELECTORS WHO VOTED)', text, re.I | re.S)
    if not match:
        raise ValueError('Official 1957 summary total section is missing')
    row = re.search(r'1\. TOTAL\s+(\d+)\s+(\d+)', match[1], re.I)
    if not row or int(row[1]) != int(row[2]):
        raise ValueError('Official 1957 summary total differs')
    return int(row[2])


def verified_summary(summary: str, detail: str, record: dict, *,
                     minimum_summary_prefix: int = 18,
                     allow_wrapped_detail_name: bool = False) -> tuple[str, dict]:
    state = re.search(r'STATE/UT\s*:\s*([^\n]+?)\s+CODE\s*:\s*([A-Z]\d+)', summary, re.I)
    seat = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*(.*?)\s+NUMBER OF SEATS\s*:\s*(\d+)', summary, re.I | re.S)
    if (not state or not seat or normalized(state[1]) != normalized(record['state_name'])
            or state[2].upper() != record['state_code'].upper()
            or int(seat[1]) != record['official_pc_code'] or int(seat[3]) != 1):
        raise ValueError('Official 1957 summary seat identity differs')
    summary_name = seat[2].strip()
    detail_base = re.sub(r'\s*\((?:SC|ST)\)\s*$', '', record['constituency_name'], flags=re.I)
    if not ((normalized(detail_base) == normalized(summary_name)
             or len(summary_name) >= minimum_summary_prefix)
            and normalized(detail_base).startswith(normalized(summary_name))):
        raise ValueError('Official 1957 abbreviated constituency name differs')
    detail_heading = re.search(r'Constituency\s+' + str(record['official_pc_code'])
                               + r'\s+' + re.escape(record['constituency_name']).replace(r'\ ', r'\s+')
                               + r'\s+NUMBER OF SEATS\s+1', detail, re.I)
    if not detail_heading and allow_wrapped_detail_name:
        detail_heading = re.search(r'Constituency\s+' + str(record['official_pc_code'])
                                   + r'\s+(.+?)\s+NUMBER OF SEATS\s+1', detail, re.I | re.S)
        if detail_heading:
            candidate_row = re.search(r'\n\s*\d+\s*\.\s+\S', detail[detail_heading.end():])
            continuation = detail[detail_heading.end():detail_heading.end() + candidate_row.start()] if candidate_row else ''
            if normalized(detail_heading[1] + continuation) != normalized(record['constituency_name']):
                detail_heading = None
    if not detail_heading:
        raise ValueError('Official 1957 detailed seat identity differs')
    detailed = detail[detail_heading.end():]
    following = re.search(r'Constituency\s+\d+\s+', detailed, re.I)
    if following:
        detailed = detailed[:following.start()]
    totals = re.search(r'ELECTORS\s*:\s*(\d+)\s+VOTERS\s*:\s*(\d+).*?VALID VOTES\s+(\d+)',
                       detailed, re.I | re.S)
    if not totals or tuple(map(int, totals.groups())) != (record['electors'], record['votes_polled'],
                                                           record['valid_candidate_votes']):
        raise ValueError('Official 1957 detailed turnout differs')
    if (source_total(summary, r'II\. ELECTORS') != record['electors']
            or source_total(summary, r'III\. ELECTORS WHO VOTED') != record['votes_polled']):
        raise ValueError('Official 1957 summary turnout differs')
    votes_section = re.search(r'IV\. VOTES\b(.*?)VI\. DATES\b', summary, re.I | re.S)
    if not votes_section:
        raise ValueError('Official 1957 summary votes are missing')
    polled = re.search(r'1\. POLLED\s+(\d+)', votes_section[1], re.I)
    valid = re.search(r'2\. VALID\s+(\d+)', votes_section[1], re.I)
    if not polled or not valid or (int(polled[1]), int(valid[1])) != (record['votes_polled'],
                                                                    record['valid_candidate_votes']):
        raise ValueError('Official 1957 summary votes differ')
    candidates = record['candidates']
    if (len(candidates) < 2 or sum(candidate['votes'] for candidate in candidates) != record['valid_candidate_votes']
            or any(not candidate['candidate_name'] or not candidate['party_at_election']
                   or not isinstance(candidate['votes'], int) or candidate['votes'] < 0
                   for candidate in candidates)):
        raise ValueError('Official 1957 detailed candidate votes do not reconcile')
    for candidate in candidates:
        pattern = re.escape(candidate['candidate_name']).replace(r'\ ', r'\s+') \
                  + r'\s+' + re.escape(candidate['party_at_election']) \
                  + r'\s+' + str(candidate['votes']) + r'(?=\s|$)'
        if not re.search(pattern, detailed, re.I):
            raise ValueError('Official 1957 candidate row differs')
    ranked = sorted(candidates, key=lambda candidate: candidate['votes'], reverse=True)
    winner, runner = ranked[:2]
    result = re.search(r'VII\. RESULT\b(.*)', summary, re.I | re.S)
    if not result:
        raise ValueError('Official 1957 summary result is missing')
    for label, candidate in (('Winner', winner), ('Runner up', runner)):
        row = re.search(r'^\s*' + label + r'\s+([A-Z]+)\s+(.+?)\s+(\d+)\s*$', result[1], re.I | re.M)
        if not row or row[1].upper() != candidate['party_at_election'].upper() \
                or int(row[3]) != candidate['votes'] \
                or normalized(re.sub(r'\s*\((?:SC|ST)\)$', '', row[2], flags=re.I)) != normalized(candidate['candidate_name']):
            raise ValueError('Official 1957 winner or runner-up differs')
    margin = re.search(r'MARGIN\s*:\s*(\d+)', result[1], re.I)
    if not margin or winner['votes'] <= runner['votes'] or int(margin[1]) != winner['votes'] - runner['votes']:
        raise ValueError('Official 1957 winning margin differs')
    return summary_name, {'winner': winner['candidate_name'], 'winner_party': winner['party_at_election'],
                          'winner_votes': winner['votes'], 'runner': runner['candidate_name'],
                          'runner_party': runner['party_at_election'], 'runner_votes': runner['votes'],
                          'margin': int(margin[1])}


def build(root: Path) -> dict:
    exports = root / 'exports'
    output = exports / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    old_body = (folder / 'extraction.json').read_bytes()
    old_sha = digest(old_body)
    if old_sha != live_audit_sha(exports / 'pc-ac-display-audit-after-bdd282d.csv'):
        raise ValueError('Local and last live 1957 election JSON checksums differ')
    old = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    if old['kind'] != 'pc' or old['year'] != 1957 or old['source_url'] != manifest['url']:
        raise ValueError('Official 1957 election identity differs')
    files = {file['file']: file for file in manifest['files']}
    for filename in (DETAIL_FILE, SUMMARY_FILE):
        path = folder / filename
        if path.is_symlink() or digest(path.read_bytes()) != files[filename]['sha256']:
            raise ValueError('Official 1957 PDF checksum differs: ' + filename)

    revised = json.loads(old_body)
    results = []
    with fitz.open(folder / DETAIL_FILE) as detail, fitz.open(folder / SUMMARY_FILE) as summary:
        for record in revised['records']:
            if record['code'] not in TARGET_CODES:
                continue
            if (record['status'] != 'needs_review' or record['number_of_seats'] != 1
                    or record['error'] != 'Detailed and summary constituency names differ'
                    or record.get('winner') is not None or record.get('margin') is not None
                    or not 1 <= record['detail_page'] <= len(detail)
                    or not 1 <= record['summary_page'] <= len(summary)):
                raise ValueError('Archived 1957 reviewed record differs: ' + str(record['code']))
            name, result = verified_summary(summary[record['summary_page'] - 1].get_text(sort=True),
                                            detail[record['detail_page'] - 1].get_text(sort=True), record)
            record['original_extraction_warning'] = record['error']
            record['source_warning_code'] = 'official_pc_summary_reconciled_detail_warning'
            record['detail_candidate_count'] = len(record['candidates'])
            record['summary_result'] = result
            record['official_summary_constituency_name'] = name
            record['summary_source_file'] = SUMMARY_FILE
            record['summary_source_sha256'] = files[SUMMARY_FILE]['sha256']
            record['detail_source_file'] = DETAIL_FILE
            record['detail_source_sha256'] = files[DETAIL_FILE]['sha256']
            results.append({'code': record['code'], 'name': record['name'],
                            'summary_name': name, 'winner': result['winner'], 'margin': result['margin']})
    if {row['code'] for row in results} != TARGET_CODES:
        raise ValueError('The three official 1957 results were not all verified')
    allowed = {'original_extraction_warning', 'source_warning_code', 'detail_candidate_count',
               'summary_result', 'official_summary_constituency_name', 'summary_source_file',
               'summary_source_sha256', 'detail_source_file', 'detail_source_sha256'}
    for before, after in zip(old['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if before['code'] != after['code'] or changed != (allowed if before['code'] in TARGET_CODES else set()):
            raise ValueError('An unrelated 1957 election value changed: ' + str(before['code']))
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    new_sha = digest(new_body)

    with tempfile.TemporaryDirectory(prefix='pc-1957-single-', dir=exports) as temporary:
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
                'scope': 'Three 1957 single-seat PC winners and margins verified from official PDFs',
                'edition': EDITION, 'source_url': old['source_url'],
                'detail_file': DETAIL_FILE, 'detail_sha256': files[DETAIL_FILE]['sha256'],
                'summary_file': SUMMARY_FILE, 'summary_sha256': files[SUMMARY_FILE]['sha256'],
                'previous_sha256': old_sha, 'new_sha256': new_sha, 'records': results,
            }, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    output.with_suffix('.sha256').write_bytes(
        f'{digest(output.read_bytes())}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'results': len(results),
            'previous_sha256': old_sha, 'new_sha256': new_sha, 'sha256': digest(output.read_bytes())}


if __name__ == '__main__':
    print(json.dumps(build(Path(__file__).resolve().parents[1])))
