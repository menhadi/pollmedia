"""Package official 1992 Punjab PC results hidden by candidate serial gaps."""

import copy
import csv
import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


EDITION = '713d20479c067ed485ca5260'
NAME = 'pollmedia-pc-1992-summary-results-20261002'
TARGET_CODES = {1, 2, 3, 5, 8, 9, 10}
DETAIL_FILE = EDITION + '-9767.pdf'
SUMMARY_FILE = EDITION + '-9768.pdf'


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def normalized(text: str) -> str:
    return re.sub(r'[^a-z0-9]', '', text.casefold())


def printed_candidate(text: str, label: str, candidate: dict) -> bool:
    name = re.escape(candidate['candidate_name']).replace(r'\ ', r'\s+')
    pattern = (re.escape(label) + r'\s*:\s*' + re.escape(candidate['party_at_election'])
               + r'\s+' + name + r'\s+' + str(candidate['votes']) + r'(?=\s|$)')
    if re.search(pattern, text, re.I):
        return True
    # Some ECI summary tables wrap the last surname onto the line after votes.
    split_line = re.search(re.escape(label) + r'\s*:\s*' + re.escape(candidate['party_at_election'])
                           + r'\s+([^\n]*?)\s+' + str(candidate['votes']) + r'(?=\s|$)[^\n]*\n([^\n]+)',
                           text, re.I)
    return bool(split_line and normalized(split_line[1] + split_line[2]) == normalized(candidate['candidate_name']))


def verified_summary(text: str, record: dict) -> dict:
    identity = re.search(r'NO\s*:\s*(\d+)\s+CONSTITUENCY\s*:\s*([^\n]+)', text, re.I)
    state = re.search(r'STATE/UT\s*:\s*([^\n]+?)\s+CODE\s*:\s*([A-Z]\d+)', text, re.I)
    contested = re.search(r'4\. CONTESTED\s+(\d+)\s+(\d+)\s+(\d+)', text, re.I)
    electors = re.search(r'II\. ELECTORS\b(.*?)III\. ELECTORS WHO VOTED\b', text, re.I | re.S)
    voters = re.search(r'III\. ELECTORS WHO VOTED\b(.*?)IV\. VOTES\b', text, re.I | re.S)
    votes = re.search(r'IV\. VOTES\b(.*?)V\. POLLING STATIONS\b', text, re.I | re.S)
    margin = re.search(r'MARGIN\s*:\s*(\d+)', text, re.I)
    if not all((identity, state, contested, electors, voters, votes, margin)):
        raise ValueError('Official summary has an incomplete result')
    if (int(identity[1]) != record.get('official_pc_code', record['code'])
            or normalized(identity[2]) != normalized(record['constituency_name'])
            or normalized(state[1]) != normalized(record['state_name'])
            or state[2].upper() != record['state_code'].upper()):
        raise ValueError('Official summary constituency identity differs')
    if int(contested[1]) + int(contested[2]) != int(contested[3]) or int(contested[3]) != len(record['candidates']):
        raise ValueError('Official contested-candidate count differs')

    elector_total = re.search(r'3\. TOTAL\s+(\d+)\s+(\d+)\s+(\d+)', electors[1], re.I)
    voter_total = re.search(r'3\. TOTAL\s+(\d+)\s+(\d+)\s+(\d+)', voters[1], re.I)
    valid_total = re.search(r'2\. VALID\s+(\d+)', votes[1], re.I)
    if not all((elector_total, voter_total, valid_total)):
        raise ValueError('Official summary totals are incomplete')
    for parts in (elector_total, voter_total):
        if int(parts[1]) + int(parts[2]) != int(parts[3]):
            raise ValueError('Official source total components differ')
    if ((int(elector_total[3]), int(voter_total[3]), int(valid_total[1]))
            != (record['electors'], record['votes_polled'], record['valid_candidate_votes'])):
        raise ValueError('Official summary and archived turnout differ')

    candidates = record['candidates']
    if (not candidates or any(not isinstance(candidate['votes'], int) or candidate['votes'] < 0
                              or not candidate['candidate_name'] or not candidate['party_at_election']
                              for candidate in candidates)
            or sum(candidate['votes'] for candidate in candidates) != record['valid_candidate_votes']):
        raise ValueError('Detailed candidate votes do not reconcile')
    ranked = sorted(candidates, key=lambda candidate: candidate['votes'], reverse=True)
    winner, runner = ranked[:2]
    if (winner['votes'] <= runner['votes'] or not printed_candidate(text, 'Winner', winner)
            or not printed_candidate(text, 'Runner up', runner)
            or int(margin[1]) != winner['votes'] - runner['votes']):
        raise ValueError('Official winner or margin differs from candidate rows')
    return {
        'winner': winner['candidate_name'], 'winner_party': winner['party_at_election'],
        'winner_votes': winner['votes'], 'runner': runner['candidate_name'],
        'runner_party': runner['party_at_election'], 'runner_votes': runner['votes'],
        'margin': int(margin[1]),
    }


def live_audit_sha(path: Path, edition: str, target_codes: set[int]) -> str:
    matches = []
    with path.open(encoding='utf-8-sig', newline='') as source:
        for row in csv.DictReader(source):
            if row['edition_id'] == edition and row['issue'] == 'winner_hidden_with_candidate_votes':
                matches.append(row)
    if ({int(row['code']) for row in matches} != target_codes or len(matches) != len(target_codes)
            or len({row['extraction_sha256'] for row in matches}) != 1):
        raise ValueError('Live audit does not match the selected PC gaps')
    return matches[0]['extraction_sha256']


def build(root: Path) -> dict:
    return build_edition(root, EDITION, NAME, TARGET_CODES, DETAIL_FILE, SUMMARY_FILE, 1992)


def build_edition(root: Path, edition: str, name: str, target_codes: set[int],
                  detail_file: str, summary_file: str, year: int) -> dict:
    exports = root / 'exports'
    output = exports / (name + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / edition
    old_body = (folder / 'extraction.json').read_bytes()
    old_sha = digest(old_body)
    if old_sha != live_audit_sha(exports / 'pc-ac-display-audit-after-bdd282d.csv', edition, target_codes):
        raise ValueError('Local and live election JSON checksums differ')
    data = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    if data['kind'] != 'pc' or data['year'] != year or manifest['url'] != data['source_url']:
        raise ValueError('Official election identity differs')
    files = {file['file']: file for file in manifest['files']}
    if detail_file not in files or summary_file not in files:
        raise ValueError('Official PDFs are missing from the edition')
    if data.get('source_file') is not None and (data['source_file'] != detail_file
                                                or files[detail_file]['sha256'] != data['source_sha256']):
        raise ValueError('Detailed source metadata differs')
    for source_name in (detail_file, summary_file):
        path = folder / source_name
        if not path.is_file() or path.is_symlink() or digest(path.read_bytes()) != files[source_name]['sha256']:
            raise ValueError('Official PDF checksum differs: ' + source_name)

    revised = copy.deepcopy(data)
    results = []
    with fitz.open(folder / summary_file) as source:
        for record in revised['records']:
            if record['code'] not in target_codes:
                continue
            if (not record.get('state_name') or record['number_of_seats'] != 1
                    or record['status'] != 'needs_review'
                    or not record['error'].startswith('Candidate serial numbers are incomplete')
                    or record.get('winner') is not None or record.get('margin') is not None
                    or not isinstance(record.get('summary_page'), int)
                    or not 1 <= record['summary_page'] <= len(source)):
                raise ValueError('Archived result state differs: ' + str(record['code']))
            result = verified_summary(source[record['summary_page'] - 1].get_text(sort=True), record)
            record['original_extraction_warning'] = record['error']
            record['error'] = ('Candidate serial numbers are incomplete in the detailed report. '
                               'The official summary confirms the candidate total, winner and margin.')
            record['source_warning_code'] = 'official_pc_summary_reconciled_serial_gap'
            record['summary_candidate_count'] = len(record['candidates'])
            record['summary_result'] = result
            record['summary_source_file'] = summary_file
            record['summary_source_sha256'] = files[summary_file]['sha256']
            results.append({'code': record['code'], 'name': record['constituency_name'],
                            'summary_page': record['summary_page'], 'winner': result['winner'],
                            'margin': result['margin']})
    if {result['code'] for result in results} != target_codes or len(revised['records']) != len(data['records']):
        raise ValueError('The selected constituency results were not all verified')
    for before, after in zip(data['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        permitted = ({'error', 'original_extraction_warning', 'source_warning_code', 'summary_candidate_count',
                      'summary_result', 'summary_source_file', 'summary_source_sha256'}
                     if before['code'] in target_codes else set())
        if before['code'] != after['code'] or not changed or changed != permitted:
            if before['code'] in target_codes or changed:
                raise ValueError('An unrelated archived value changed: ' + str(before['code']))
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    new_sha = digest(new_body)

    with tempfile.TemporaryDirectory(prefix='pc-1992-results-', dir=exports) as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{edition}/extraction-{old_sha}.json'
        revision = f'election-archive/{edition}/extraction.json'
        for relative, body in ((snapshot, old_body), (revision, new_body)):
            path = staged / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(body)
        inner = []
        for kind, relative in (('snapshot', snapshot), ('correction', revision)):
            bucket = hashlib.sha256(relative.encode('utf-8')).digest()[0] % 8
            path = packages / f'{kind}-{edition}.zip'
            package(staged, path, 'election-archive', bucket, 8, [relative],
                    old_sha if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
            inner.append(path)
        if digest((folder / 'extraction.json').read_bytes()) != old_sha:
            raise ValueError('Archived extraction changed during packaging')
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as archive:
            for path in inner:
                archive.write(path, path.name)
            archive.writestr('SHA256SUMS', ''.join(f'{digest(path.read_bytes())}  {path.name}\n' for path in inner))
            archive.writestr('ARCHIVES', edition + '\n')
            archive.writestr('AUDIT.json', json.dumps({
                'scope': f'{len(results)} {year} PC winners and margins reconciled to the official summary',
                'edition': edition, 'source_url': data['source_url'],
                'detail_file': detail_file, 'detail_sha256': files[detail_file]['sha256'],
                'summary_file': summary_file, 'summary_sha256': files[summary_file]['sha256'],
                'previous_sha256': old_sha, 'new_sha256': new_sha, 'records': results,
            }, indent=2))
            archive.writestr('IMPORT.sh', import_script([edition]))
        partial.replace(output)
    output.with_suffix('.sha256').write_bytes(
        f'{digest(output.read_bytes())}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'records': len(results), 'previous_sha256': old_sha,
            'new_sha256': new_sha, 'sha256': digest(output.read_bytes())}


if __name__ == '__main__':
    print(json.dumps(build(Path(__file__).resolve().parents[1])))
