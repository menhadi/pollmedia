"""Publish the remaining 1962 PC results and source-reconciled turnout."""

import csv
import hashlib
import io
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_pc_1989_summary_result_bundle import verified_summary
from build_pc_1992_summary_result_bundle import normalized
from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


EDITION = '02f72a2ef53a457f8e97e539'
NAME = 'pollmedia-pc-1962-remaining-results-turnout-20261003'
PREVIOUS_BUNDLE = 'pollmedia-pc-1962-reserved-results-20261003'
DETAIL_FILE = EDITION + '-9740.pdf'
SUMMARY_FILE = EDITION + '-9741.pdf'
RESULT_CODES = {46, 83, 128, 130, 180, 187, 191, 196, 201, 227, 228, 374, 404, 461, 492}
PREVIOUS_SHA256 = '16c6e1c0420f6a845186d2f75a9dd6fca684e15095dab7d7a142a24f67272c06'


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def audit_codes(path: Path) -> set[int]:
    with path.open(encoding='utf-8-sig', newline='') as source:
        rows = [row for row in csv.DictReader(source) if row['edition_id'] == EDITION]
    grouped = {}
    for issue in ('winner_hidden_with_candidate_votes', 'source_turnout_hidden'):
        matching = [row for row in rows if row['issue'] == issue]
        if len({row['extraction_sha256'] for row in matching}) != 1:
            raise ValueError('Live audit edition checksum differs')
        grouped[issue] = {int(row['code']) for row in matching}
    if len(grouped['winner_hidden_with_candidate_votes']) != 112:
        raise ValueError('The earlier 1962 result audit scope differs')
    if len(grouped['source_turnout_hidden']) != 37:
        raise ValueError('The earlier 1962 turnout audit scope differs')
    return grouped['source_turnout_hidden']


def previous_body(exports: Path) -> bytes:
    path = exports / (PREVIOUS_BUNDLE + '.zip')
    expected = (exports / (PREVIOUS_BUNDLE + '.sha256')).read_text(encoding='ascii').split()[0]
    if digest(path.read_bytes()) != expected:
        raise ValueError('Previously imported bundle checksum differs')
    with zipfile.ZipFile(path) as outer:
        audit = json.loads(outer.read('AUDIT.json'))
        if audit['edition'] != EDITION or audit['new_sha256'] != PREVIOUS_SHA256:
            raise ValueError('Previously imported bundle identity differs')
        inner_name = 'correction-' + EDITION + '.zip'
        checksums = {filename: checksum for checksum, filename in
                     (line.split(None, 1) for line in outer.read('SHA256SUMS').decode('ascii').splitlines())}
        body = outer.read(inner_name)
        if digest(body) != checksums[inner_name]:
            raise ValueError('Previously imported inner package checksum differs')
        with zipfile.ZipFile(io.BytesIO(body)) as inner:
            extracted = inner.read(f'election-archive/{EDITION}/extraction.json')
    if digest(extracted) != PREVIOUS_SHA256:
        raise ValueError('Previously imported election JSON checksum differs')
    return extracted


def source_seat(text: str, record: dict) -> str:
    identity = re.search(r'CONSTITUENCY\s*:\s*([^\n]+?)\s+NO\s*:\s*(\d+)', text, re.I)
    state = re.search(r'STATE/UT\s*:\s*([^\n]+?)\s+CODE\s*:\s*([A-Z]\d+)', text, re.I)
    if not identity or not state or int(identity[2]) != record['official_pc_code'] \
            or normalized(state[1]) != normalized(record['state_name']) \
            or state[2].upper() != record['state_code'].upper():
        raise ValueError('Official summary constituency identity differs')
    summary_name = identity[1].strip()
    detail_base = re.sub(r'\s*\((?:SC|ST)\)\s*$', '', record['constituency_name'], flags=re.I)
    if (normalized(summary_name) != normalized(record['constituency_name'])
            and normalized(summary_name) != normalized(detail_base)
            and not (len(summary_name) >= 18
                     and normalized(detail_base).startswith(normalized(summary_name)))):
        raise ValueError('Official summary name is not a documented reserved or truncated variant')
    return summary_name


def detailed_section(document: fitz.Document, record: dict) -> str:
    first_page = record['detail_page'] - 1
    if first_page < 0 or first_page >= len(document):
        raise ValueError('Detailed report page is out of range')
    text = document[first_page].get_text(sort=True)
    if first_page + 1 < len(document):
        text += '\n' + document[first_page + 1].get_text(sort=True)
    name = re.escape(record['constituency_name']).replace(r'\ ', r'\s+')
    heading = re.search(r'Constituency\s*:\s*' + str(record['official_pc_code'])
                        + r'\s*\.\s*' + name + r'(?=\s|$)', text, re.I)
    if not heading:
        raise ValueError('Detailed report name and PC number differ')
    text = text[heading.start():]
    next_heading = re.search(r'Constituency\s*:\s*\d+\s*\.', text[heading.end() - heading.start():], re.I)
    if next_heading:
        text = text[:heading.end() - heading.start() + next_heading.start()]
    return text


def official_turnout(summary_text: str, detail_text: str, record: dict) -> dict:
    summary_name = source_seat(summary_text, record)
    if normalized(summary_name) == '':
        raise ValueError('Official summary name is empty')
    detail = re.search(r'ELECTORS\s*:\s*(\d+)\s+VOTERS\s*:\s*(\d+).*?VALID VOTES\s*:\s*(\d+)',
                       detail_text, re.I | re.S)
    voters_section = re.search(r'III\. ELECTORS WHO VOTED\b(.*?)IV\. VOTES\b', summary_text, re.I | re.S)
    votes_section = re.search(r'IV\. VOTES\b(.*?)V\. POLLING STATIONS\b', summary_text, re.I | re.S)
    if not detail or not voters_section or not votes_section:
        raise ValueError('Official detailed or summary turnout row is missing')
    voters = re.search(r'3\. TOTAL\s+(\d+)\s+(\d+)\s+(\d+)', voters_section[1], re.I)
    components = {}
    for ordinal, label in ((1, 'POLLED'), (2, 'VALID'), (3, 'REJECTED'), (4, 'MISSING')):
        match = re.search(str(ordinal) + r'\. ' + label + r'\s+(\d+)', votes_section[1], re.I)
        if not match:
            raise ValueError('Official summary vote component is missing: ' + label)
        components[label] = int(match[1])
    if not voters or int(voters[1]) + int(voters[2]) != int(voters[3]):
        raise ValueError('Official summary voter components do not reconcile')
    electors, detailed_voters, valid_votes = map(int, detail.groups())
    summary_voters = int(voters[3])
    if (electors != record['electors'] or detailed_voters != record['votes_polled']
            or valid_votes != record['valid_candidate_votes']
            or summary_voters != record['summary_totals']['votes_polled']
            or valid_votes != record['summary_totals']['valid_candidate_votes']
            or components['POLLED'] != summary_voters
            or components['VALID'] != valid_votes
            or components['MISSING'] < 1
            or components['POLLED'] != components['VALID'] + components['REJECTED'] + components['MISSING']
            or detailed_voters + components['MISSING'] != summary_voters
            or summary_voters > electors):
        raise ValueError('Official detail and summary do not reconcile through missing votes')
    return {'detail_votes_polled': detailed_voters, 'summary_votes_polled': summary_voters,
            'summary_missing_votes': components['MISSING']}


def build(root: Path) -> dict:
    exports = root / 'exports'
    output = exports / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    turnout_codes = audit_codes(exports / 'pc-ac-display-audit-after-bdd282d.csv')
    prior_body = previous_body(exports)
    prior = json.loads(prior_body)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    if prior['kind'] != 'pc' or prior['year'] != 1962 or prior['source_url'] != manifest['url']:
        raise ValueError('Official election identity differs')
    files = {file['file']: file for file in manifest['files']}
    for filename in (DETAIL_FILE, SUMMARY_FILE):
        path = folder / filename
        if path.is_symlink() or digest(path.read_bytes()) != files[filename]['sha256']:
            raise ValueError('Official PDF checksum differs: ' + filename)

    revised = json.loads(prior_body)
    results = []
    turnout = []
    with fitz.open(folder / DETAIL_FILE) as detail, fitz.open(folder / SUMMARY_FILE) as summary:
        for record in revised['records']:
            needs_result = record['code'] in RESULT_CODES
            needs_turnout = record['code'] in turnout_codes
            if not needs_result and not needs_turnout:
                continue
            if (record['status'] != 'needs_review' or record.get('source_warning_code') is not None
                    or record.get('summary_result') is not None or record['number_of_seats'] != 1
                    or not 1 <= record['summary_page'] <= len(summary)):
                raise ValueError('Archived reviewed row differs: ' + str(record['code']))
            summary_text = summary[record['summary_page'] - 1].get_text(sort=True)
            detail_text = detailed_section(detail, record)
            name = source_seat(summary_text, record)
            original_warning = record['error']
            record['original_extraction_warning'] = original_warning
            if needs_turnout:
                if 'Detailed and summary votes polled differ' not in original_warning:
                    raise ValueError('The original turnout warning differs')
                totals = official_turnout(summary_text, detail_text, record)
                record['detail_votes_polled'] = totals['detail_votes_polled']
                record['votes_polled'] = totals['summary_votes_polled']
                record['source_discrepancy'] = {'field': 'votes_polled', **totals}
                record['error'] = ('The official summary includes ' + str(totals['summary_missing_votes'])
                                   + ' missing votes in turnout; the detailed report excludes them. '
                                   'Turnout uses the summary total. See both official pages.')
                turnout.append({'code': record['code'], 'name': record['name'], **totals})
            if needs_result:
                if record.get('winner') is not None or record.get('margin') is not None:
                    raise ValueError('The originally hidden result is already present')
                result = verified_summary(summary_text, detail_text, record,
                                          (record['state_name'], record['constituency_name'],
                                           name, original_warning))
                record['source_warning_code'] = 'official_pc_summary_reconciled_detail_warning'
                record['detail_candidate_count'] = len(record['candidates'])
                record['summary_result'] = result
                record['official_summary_constituency_name'] = name
                if needs_turnout:
                    record['error'] = ('The detailed and summary constituency names differ. '
                                       'The summary includes ' + str(totals['summary_missing_votes'])
                                       + ' missing votes in turnout; the detailed report excludes them. '
                                       'The official summary confirms the winner and margin. See both pages.')
                results.append({'code': record['code'], 'name': record['name'],
                                'summary_name': name, 'winner': result['winner'],
                                'margin': result['margin']})
            record['summary_source_file'] = SUMMARY_FILE
            record['summary_source_sha256'] = files[SUMMARY_FILE]['sha256']
            record['detail_source_file'] = DETAIL_FILE
            record['detail_source_sha256'] = files[DETAIL_FILE]['sha256']
    if ({row['code'] for row in results} != RESULT_CODES or len(results) != 15
            or {row['code'] for row in turnout} != turnout_codes or len(turnout) != 37):
        raise ValueError('The remaining source-verified 1962 scope differs')
    shared = {'original_extraction_warning', 'summary_source_file', 'summary_source_sha256',
              'detail_source_file', 'detail_source_sha256'}
    turnout_fields = {'detail_votes_polled', 'votes_polled', 'source_discrepancy', 'error'}
    result_fields = {'source_warning_code', 'detail_candidate_count', 'summary_result',
                     'official_summary_constituency_name'}
    for before, after in zip(prior['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        wanted = (shared | (turnout_fields if before['code'] in turnout_codes else set())
                  | (result_fields if before['code'] in RESULT_CODES else set())) \
                 if before['code'] in turnout_codes | RESULT_CODES else set()
        if before['code'] != after['code'] or changed != wanted:
            raise ValueError('An unrelated election value changed: ' + str(before['code']))
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    new_sha = digest(new_body)

    with tempfile.TemporaryDirectory(prefix='pc-1962-followup-', dir=exports) as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{PREVIOUS_SHA256}.json'
        revision = f'election-archive/{EDITION}/extraction.json'
        for relative, body in ((snapshot, prior_body), (revision, new_body)):
            path = staged / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(body)
        inner = []
        for kind, relative in (('snapshot', snapshot), ('correction', revision)):
            bucket = hashlib.sha256(relative.encode('utf-8')).digest()[0] % 8
            path = packages / f'{kind}-{EDITION}.zip'
            package(staged, path, 'election-archive', bucket, 8, [relative],
                    PREVIOUS_SHA256 if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
            inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as archive:
            for path in inner:
                archive.write(path, path.name)
            archive.writestr('SHA256SUMS', ''.join(f'{digest(path.read_bytes())}  {path.name}\n' for path in inner))
            archive.writestr('ARCHIVES', EDITION + '\n')
            archive.writestr('AUDIT.json', json.dumps({
                'scope': '15 remaining 1962 PC results and 37 turnout rows reconciled to official PDFs',
                'edition': EDITION, 'source_url': prior['source_url'],
                'detail_file': DETAIL_FILE, 'detail_sha256': files[DETAIL_FILE]['sha256'],
                'summary_file': SUMMARY_FILE, 'summary_sha256': files[SUMMARY_FILE]['sha256'],
                'previous_sha256': PREVIOUS_SHA256, 'new_sha256': new_sha,
                'results': results, 'turnout': turnout,
            }, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    output.with_suffix('.sha256').write_bytes(
        f'{digest(output.read_bytes())}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'results': len(results), 'turnout': len(turnout),
            'previous_sha256': PREVIOUS_SHA256, 'new_sha256': new_sha,
            'sha256': digest(output.read_bytes())}


if __name__ == '__main__':
    print(json.dumps(build(Path(__file__).resolve().parents[1])))
