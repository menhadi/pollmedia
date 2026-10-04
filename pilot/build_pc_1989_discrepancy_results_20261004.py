"""Preserve both official 1989 PC reports while showing two reconciled declarations."""

import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_pc_1989_summary_result_bundle import normalized, printed_candidate, source_totals
from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
EDITION = '92de082304013ee1f62be87a'
NAME = 'pollmedia-pc-1989-discrepant-official-results-20261004'
LIVE_SHA = 'ae67b0edd380591a8a305df15cf68776d532949cdcf2f710c544ab9bf18dfaf1'
DETAIL_FILE = EDITION + '-9761.pdf'
SUMMARY_FILE = EDITION + '-9762.pdf'
DETAIL_SHA = 'e67c8f9fa3058bbc51579dfe45c7665ea7eb5092378e412d85318d6350980b3b'
SUMMARY_SHA = '801ffa9db8ebe320968b17cc8195c83c94eefec94bc8368382392083ce5e15e3'
SOURCE_URL = 'https://old.eci.gov.in/files/file/4120-general-election-1989-vol-i-ii/'
NOTE = ('Official 1989 detailed candidate report and constituency summary disagree on some totals. '
        'Detailed turnout is shown; the declared winner and margin match both reports. Review the official source.')
TARGETS = {
    49: ('BIHAR', 'SIWAN', 5, 154, 55,
         (965656, 562288, 552892), (965656, 562244, 552798), 20,
         'Detailed and summary votes polled differ; Detailed and summary valid candidate votes differ'),
    138: ('HIMACHAL PRADESH', 'MANDI', 2, 185, 144,
          (756145, 470730, 464947), (756545, 470730, 464949), 7,
          'Detailed and summary electors differ; Detailed and summary valid candidate votes differ'),
}
ADDED_FIELDS = {'original_extraction_warning', 'error', 'source_warning_code',
                'official_source_url', 'detail_source_file', 'detail_source_sha256',
                'summary_source_file', 'summary_source_sha256', 'detailed_report_totals',
                'summary_result'}


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def totals(values: tuple[int, int, int]) -> dict:
    return dict(zip(('electors', 'votes_polled', 'valid_candidate_votes'), values, strict=True))


def guarded_import_script() -> str:
    script = import_script([EDITION])
    if script.count('exec 9> .import.lock') != 1 or script.count('check_disk\nwhile IFS=') != 1:
        raise ValueError('Election import guard template changed')
    script = script.replace('exec 9> .import.lock',
                            'exec 9> /home/pollmedia/tmp/.pollmedia-election-release.lock')
    script = script.replace('check_disk\nwhile IFS=',
                            "if pgrep -af 'archive:import-json|archive:index-constituencies|[e]lection.*[o]cr'; then\n"
                            "    echo 'Another election import, index, or OCR process is active' >&2\n"
                            "    exit 1\nfi\n"
                            "grep -Fq 'officialPc1989DiscrepancyResult' "
                            "/home/pollmedia/app/application/app/Services/HistoricalElectionAnalytics.php "
                            "|| { echo 'Pull the tested election code first' >&2; exit 1; }\n"
                            'check_disk\nwhile IFS=')
    return script


def verified_result(detail_text: str, summary_text: str, record: dict, source: tuple) -> dict:
    state, seat, seat_code, _, _, detailed, summarized, candidate_count, _ = source
    match = re.search(r'Constituency\s*:\s*' + str(seat_code) + r'\s*\.\s*' + re.escape(seat)
                      + r'\b(.*?)(?=\nConstituency\s*:|\Z)', detail_text, re.I | re.S)
    detail_totals = re.search(r'ELECTORS\s*:\s*(\d+)\s+VOTERS\s*:\s*(\d+)'
                              r'.*?VALID VOTES\s*:\s*(\d+)', match[1], re.I | re.S) if match else None
    summary_state = re.search(r'STATE/UT\s*:\s*([^\n]+?)\s+CODE\s*:\s*([A-Z]\d+)', summary_text, re.I)
    summary_seat = re.search(r'CONSTITUENCY\s*:\s*([^\n]+?)\s+NO\s*:\s*(\d+)', summary_text, re.I)
    electors = re.search(r'II\. ELECTORS\b(.*?)III\. ELECTORS WHO VOTED\b', summary_text, re.I | re.S)
    voters = re.search(r'III\. ELECTORS WHO VOTED\b(.*?)IV\. VOTES\b', summary_text, re.I | re.S)
    votes = re.search(r'IV\. VOTES\b(.*?)V\. POLLING STATIONS\b', summary_text, re.I | re.S)
    valid = re.search(r'2\. VALID\s+(\d+)', votes[1], re.I) if votes else None
    margin = re.search(r'MARGIN\s*:\s*(\d+)', summary_text, re.I)
    if not all((match, detail_totals, summary_state, summary_seat, electors, voters, valid, margin)):
        raise ValueError('Official 1989 report page is incomplete: ' + seat)
    if (tuple(map(int, detail_totals.groups())) != detailed
            or normalized(summary_state[1]) != normalized(state)
            or summary_state[2].upper() != record['state_code']
            or normalized(summary_seat[1]) != normalized(seat)
            or int(summary_seat[2]) != seat_code
            or (source_totals(electors[1]), source_totals(voters[1]), int(valid[1])) != summarized):
        raise ValueError('Official 1989 report identity or totals differ: ' + seat)
    candidates = record['candidates']
    if (len(candidates) != candidate_count
            or any(type(row.get('votes')) is not int or row['votes'] < 0
                   or not row.get('candidate_name') or not row.get('party_at_election') for row in candidates)
            or sum(row['votes'] for row in candidates) != detailed[2]):
        raise ValueError('Preserved candidate votes do not reconcile: ' + seat)
    ranked = sorted(candidates, key=lambda row: row['votes'], reverse=True)
    winner, runner = ranked[:2]
    if (winner['votes'] <= runner['votes']
            or not printed_candidate(summary_text, 'Winner', winner)
            or not printed_candidate(summary_text, 'Runner up', runner)
            or int(margin[1]) != winner['votes'] - runner['votes']):
        raise ValueError('Declared 1989 winner or margin differs: ' + seat)
    return {'winner': winner['candidate_name'], 'winner_party': winner['party_at_election'],
            'winner_votes': winner['votes'], 'runner': runner['candidate_name'],
            'runner_party': runner['party_at_election'], 'runner_votes': runner['votes'],
            'margin': winner['votes'] - runner['votes']}


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    old_body = (root / 'tmp/pc-1989-live-20261004.json').read_bytes()
    if digest(old_body) != LIVE_SHA:
        raise ValueError('Verified live 1989 predecessor differs')
    old = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    if (old['kind'], old['year'], old['source_url'], manifest['url']) != ('pc', 1989, SOURCE_URL, SOURCE_URL):
        raise ValueError('Official 1989 edition identity differs')
    files = {row['file']: row for row in manifest['files']}
    for name, sha in ((DETAIL_FILE, DETAIL_SHA), (SUMMARY_FILE, SUMMARY_SHA)):
        path = folder / name
        if path.is_symlink() or files[name]['sha256'] != sha or digest(path.read_bytes()) != sha:
            raise ValueError('Official 1989 PDF checksum differs: ' + name)

    new = json.loads(old_body)
    results = []
    with fitz.open(folder / DETAIL_FILE) as detail, fitz.open(folder / SUMMARY_FILE) as summary:
        for record in new['records']:
            source = TARGETS.get(record['code'])
            if source is None:
                continue
            state, seat, seat_code, detail_page, summary_page, detailed, summarized, count, warning = source
            if (record['state_name'], record['constituency_name'], record['official_pc_code'],
                    record['detail_page'], record['summary_page'], record['number_of_seats'],
                    record['status'], record['error']) != (state, seat, seat_code, detail_page,
                                                           summary_page, 1, 'needs_review', warning):
                raise ValueError('Live 1989 record identity or warning differs: ' + seat)
            if ((record['electors'], record['votes_polled'], record['valid_candidate_votes']) != detailed
                    or record['summary_totals'] != totals(summarized)
                    or record.get('winner') is not None or record.get('margin') is not None
                    or record.get('source_warning_code') is not None):
                raise ValueError('Live 1989 totals or review state differ: ' + seat)
            result = verified_result(detail[detail_page - 1].get_text(sort=True),
                                     summary[summary_page - 1].get_text(sort=True), record, source)
            record['original_extraction_warning'] = warning
            record['error'] = NOTE
            record['source_warning_code'] = 'official_pc_1989_report_discrepancy'
            record['official_source_url'] = SOURCE_URL
            record['detail_source_file'] = DETAIL_FILE
            record['detail_source_sha256'] = DETAIL_SHA
            record['summary_source_file'] = SUMMARY_FILE
            record['summary_source_sha256'] = SUMMARY_SHA
            record['detailed_report_totals'] = totals(detailed)
            record['summary_result'] = result
            results.append({'code': record['code'], 'state': state, 'seat': seat,
                            'detailed': totals(detailed), 'summary': totals(summarized),
                            'winner': result['winner'], 'margin': result['margin']})
    if {row['code'] for row in results} != set(TARGETS):
        raise ValueError('Both 1989 discrepancies were not verified')
    for before, after in zip(old['records'], new['records'], strict=True):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if before['code'] != after['code'] or changed != (ADDED_FIELDS if before['code'] in TARGETS else set()):
            raise ValueError('An unrelated 1989 value changed')
    new_body = json.dumps(new, ensure_ascii=False, indent=2).encode('utf-8')
    new_sha = digest(new_body)

    with tempfile.TemporaryDirectory(prefix='pc-1989-discrepancy-', dir=root / 'exports') as temp:
        temp = Path(temp)
        staged = temp / 'archive'
        packages = temp / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{LIVE_SHA}.json'
        revision = f'election-archive/{EDITION}/extraction.json'
        for relative, body in ((snapshot, old_body), (revision, new_body)):
            path = staged / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(body)
        inner = []
        for kind, relative in (('snapshot', snapshot), ('correction', revision)):
            bucket = hashlib.sha256(relative.encode()).digest()[0] % 8
            path = packages / f'{kind}-{EDITION}.zip'
            package(staged, path, 'election-archive', bucket, 8, [relative],
                    LIVE_SHA if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
            inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as bundle:
            for path in inner:
                bundle.write(path, path.name)
            bundle.writestr('SHA256SUMS', ''.join(f'{digest(path.read_bytes())}  {path.name}\n' for path in inner))
            bundle.writestr('ARCHIVES', EDITION + '\n')
            bundle.writestr('AUDIT.json', json.dumps({
                'scope': 'Two source-discrepant 1989 PC results from official detail and summary reports',
                'edition': EDITION, 'source_url': SOURCE_URL,
                'detail_file': DETAIL_FILE, 'detail_sha256': DETAIL_SHA,
                'summary_file': SUMMARY_FILE, 'summary_sha256': SUMMARY_SHA,
                'previous_sha256': LIVE_SHA, 'new_sha256': new_sha, 'records': results,
            }, indent=2))
            bundle.writestr('IMPORT.sh', guarded_import_script())
        partial.replace(output)
    output.with_suffix('.sha256').write_bytes(f'{digest(output.read_bytes())}  {output.name}\n'.encode())
    return {'bundle': str(output), 'sha256': digest(output.read_bytes()),
            'previous_sha256': LIVE_SHA, 'new_sha256': new_sha, 'records': len(results)}


if __name__ == '__main__':
    print(json.dumps(build()))
