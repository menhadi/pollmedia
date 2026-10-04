"""Reconcile three 1996 J&K assembly summaries' general and postal votes."""

import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_pc_ac_zero_turnout_bundle import import_script
from extract_assembly_summary_totals import read_summary_pages
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
EDITION = 'b837e6720774f65f5f1b0934'
NAME = 'pollmedia-ac-jammu-kashmir-1996-postal-summary-results-20261004'
OLD_SHA = 'e0582524a7a3560d08ff2ccfcc07caf7a0d060d484f26efc549b4ae61f9cd2c7'
SOURCE_FILE = f'{EDITION}-8930.pdf'
SOURCE_SHA = '5a87a21648681a2eed44228cf28c098dc38f34d2ec59fc858acdf49e7bedb4ee'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3794-jammu-kashmir-1996/'
WARNING = ('Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.; '
           'Reported elector and voter totals are inconsistent.')
NOTE = ('The official summary lists general valid votes separately from postal votes. Their sum reconciles '
        'with the detailed candidate rows; total voters, winner and margin are shown with the original figures retained for review.')
# Code: name, summary page, detail page, electors, general voters, total voters,
# summary-printed general valid votes, postal voters, rejected votes, candidate count,
# winner/party/votes, runner/party/votes, official margin.
EXPECTED = {
    22: ('HABBAKADAL', 35, 104, 59329, 3184, 10188, 2998, 7004, 186, 5,
         'PIYARE LAL HANDOO', 'JKN', 5984, 'SARLA TAPLOO', 'BJP', 1969, 4015),
    23: ('AMIRAKADAL', 36, 104, 56462, 6501, 7141, 6025, 640, 476, 7,
         'MUHAMMED SHAFI BHAT', 'JKN', 4256, 'MOHD. ALTAF DAR', 'JD', 968, 3288),
    43: ('KOKERNAG', 56, 108, 58733, 22923, 23866, 21985, 943, 938, 7,
         'SYED ABDUL RASHID', 'JKN', 11436, 'ABDUL RASHID RATHER', 'JKAL', 4145, 7291),
}


def sha(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def declaration(text: str) -> tuple:
    winner = re.search(r'^\s*Winner\s*:\s*(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
    runner = re.search(r'^\s*Runner up\s*:\s*(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
    margin = re.search(r'^\s*MARGIN\s*:\s*(\d+)\b', text, re.I | re.M)
    if not all((winner, runner, margin)):
        raise ValueError('Official declaration could not be read')
    return (winner[2].strip(), winner[1], int(winner[3]), runner[2].strip(), runner[1],
            int(runner[3]), int(margin[1]))


def vote_component(text: str, pattern: str) -> int:
    match = re.search(pattern, text, re.I | re.M)
    if match is None:
        raise ValueError('Official postal/rejected vote component could not be read')
    return int(match[1])


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    old_body = (folder / 'extraction.json').read_bytes()
    data = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    source_path = folder / SOURCE_FILE
    if (sha(old_body) != OLD_SHA or data['kind'] != 'ac' or data['year'] != 1996
            or len(data['records']) != 87 or data['source_url'] != SOURCE_URL
            or data['source_file'] != SOURCE_FILE or data['source_sha256'] != SOURCE_SHA
            or manifest['url'] != SOURCE_URL
            or len([item for item in manifest['files'] if item['file'] == SOURCE_FILE
                    and item['sha256'] == SOURCE_SHA]) != 1 or sha(source_path.read_bytes()) != SOURCE_SHA):
        raise ValueError('Official J&K 1996 source identity differs')
    summaries = read_summary_pages(source_path)
    if len(summaries) != 87:
        raise ValueError('Official J&K 1996 summary coverage differs')
    revised = json.loads(old_body)
    audit_rows = []
    with fitz.open(source_path) as pdf:
        for code, expected in EXPECTED.items():
            (name, page, detail_page, electors, general_voters, total_voters, general_valid,
             postal, rejected, candidate_count, winner, winner_party, winner_votes,
             runner, runner_party, runner_votes, margin) = expected
            record = next(row for row in revised['records'] if row['code'] == code)
            summary = summaries[code]
            text = pdf[page - 1].get_text(sort=True)
            detail_text = pdf[detail_page - 1].get_text(sort=True)
            ranked = sorted(record['candidates'], key=lambda candidate: -candidate['votes'])
            candidate_sum = sum(candidate['votes'] for candidate in record['candidates'])
            printed_postal = vote_component(text, r'^\s*2\. POSTAL\s+\d+\s+\d+\s+(\d+)\s*$')
            printed_rejected = vote_component(text, r'^\s*3\. REJECTED\s+(\d+)\b')
            if (record['name'] != name or record['state_name'] != 'Jammu & Kashmir'
                    or record['status'] != 'needs_review' or record['error'] != WARNING
                    or record['number_of_seats'] != 1 or record['detail_page'] != detail_page
                    or len(record['candidates']) != candidate_count or record.get('summary_result') is not None
                    or (record['electors'], record['votes_polled'], record['valid_candidate_votes']) !=
                    (electors, general_voters, general_valid + postal)
                    or (summary['name'], summary['electors'], summary['votes_polled'],
                        summary['valid_candidate_votes'], summary['summary_page']) !=
                    (name, electors, total_voters, general_valid, page)
                    or printed_postal != postal or printed_rejected != rejected
                    or general_voters + postal != total_voters
                    or general_valid + postal + rejected != total_voters
                    or candidate_sum != general_valid + postal
                    or re.search(rf'Field7:CONSTITUENCY\s*:\s*{code}\s*-\s*{re.escape(name)}(?:\s|$)', text, re.I) is None
                    or name not in detail_text.upper()
                    or declaration(text) != (winner, winner_party, winner_votes,
                                             runner, runner_party, runner_votes, margin)
                    or [(ranked[i]['candidate_name'], ranked[i]['party_at_election'], ranked[i]['votes'])
                        for i in range(2)] != [(winner, winner_party, winner_votes),
                                                (runner, runner_party, runner_votes)]
                    or winner_votes - runner_votes != margin):
                raise ValueError(f'J&K 1996 {code} source evidence differs')
            original = {'electors': electors, 'votes_polled': general_voters,
                        'valid_candidate_votes': general_valid + postal}
            record['original_detail_totals'] = original
            record['original_extraction_warning'] = record['error']
            record['error'] = NOTE
            record['source_warning_code'] = 'official_general_valid_plus_postal'
            record['votes_polled'] = total_voters
            record['summary_page'] = page
            record['summary_totals'] = {'electors': electors, 'votes_polled': total_voters,
                                        'valid_candidate_votes': general_valid,
                                        'postal_votes': postal, 'rejected_votes': rejected}
            record['summary_result'] = {'winner': winner, 'winner_party': winner_party,
                                        'winner_votes': winner_votes, 'runner': runner,
                                        'runner_party': runner_party, 'runner_votes': runner_votes,
                                        'margin': margin}
            record['summary_source_file'] = SOURCE_FILE
            record['summary_source_sha256'] = SOURCE_SHA
            record['candidate_source_discrepancy'] = {'candidate_sum': candidate_sum,
                                                       'printed_general_valid_votes': general_valid,
                                                       'postal_votes': postal}
            audit_rows.append({'code': code, 'name': name, 'summary_page': page, 'detail_page': detail_page,
                               'candidate_count': candidate_count, 'general_valid_votes': general_valid,
                               'postal_votes': postal, 'rejected_votes': rejected,
                               'candidate_sum': candidate_sum, 'total_voters': total_voters,
                               'winner': winner, 'margin': margin})
    changed_fields = {'original_detail_totals', 'original_extraction_warning', 'error',
                      'source_warning_code', 'votes_polled', 'summary_page', 'summary_totals',
                      'summary_result', 'summary_source_file', 'summary_source_sha256',
                      'candidate_source_discrepancy'}
    for before, after in zip(data['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if before['code'] != after['code'] or changed != (changed_fields if before['code'] in EXPECTED else set()):
            raise ValueError('Unrelated J&K 1996 source evidence changed')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    return old_body, new_body, {'edition': EDITION, 'year': 1996, 'source_url': SOURCE_URL,
                                'source_file': SOURCE_FILE, 'source_sha256': SOURCE_SHA,
                                'previous_sha256': sha(old_body), 'new_sha256': sha(new_body),
                                'corrected': audit_rows}


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / f'{NAME}.zip'
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body, new_body, audit = revised_edition(root)
    with tempfile.TemporaryDirectory(prefix='ac-jk-1996-', dir=output.parent) as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{audit["previous_sha256"]}.json'
        revision = f'election-archive/{EDITION}/extraction.json'
        inner = []
        for kind, relative, body in (('snapshot', snapshot, old_body), ('correction', revision, new_body)):
            target = staged / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(body)
            bucket = hashlib.sha256(relative.encode('utf-8')).digest()[0] % 8
            path = packages / f'{kind}-{EDITION}.zip'
            package(staged, path, 'election-archive', bucket, 8, [relative],
                    audit['previous_sha256'] if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
            inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as archive:
            for path in inner:
                archive.write(path, path.name)
            archive.writestr('SHA256SUMS', ''.join(f'{sha(path.read_bytes())}  {path.name}\n' for path in inner))
            archive.writestr('ARCHIVES', EDITION + '\n')
            archive.writestr('AUDIT.json', json.dumps({'scope': 'Three 1996 J&K postal-inclusive AC results', **audit}, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    bundle_sha = sha(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{bundle_sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': bundle_sha, **audit}


if __name__ == '__main__':
    print(json.dumps(build()))
