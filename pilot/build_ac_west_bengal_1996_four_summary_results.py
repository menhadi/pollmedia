"""Publish four source-declared 1996 West Bengal results with detail discrepancies noted."""

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
EDITION = 'f554da4ae06da2fc264af3cb'
NAME = 'pollmedia-ac-west-bengal-1996-four-summary-results-20261004'
OLD_SHA = '8a5a9630c6f195100a7d743e21804542422563b54a00fa38182e46cba91d3336'
SOURCE_FILE = f'{EDITION}-7321.pdf'
SOURCE_SHA = '1d1e87b1c65ec5e45839dc8b8bb2c7ac380ee05899519ee50e95a311eadeb845'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3192-west-bengal-1996/'
WARNING = ('Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.; '
           'Reported elector and voter totals are inconsistent.')
NOTE = ('The official constituency summary establishes turnout, winner and margin. Its voter and valid-vote '
        'totals disagree with the detailed candidate table; original detail remains available for review.')
# Code: name, official summary page, detail page, summary electors/polled/valid, declared winner/runner/party/votes/margin.
EXPECTED = {
    20: ('JALPAIGURI', 43, 321, 133981, 114075, 107031,
         'ANUPAM SEN', 'INC', 48597, 'SUDHANSU MAJUMDER', 'FBL', 44889, 3708),
    46: ('ENGLISHBAZAR', 69, 327, 156094, 133451, 125998,
         'GOUTAM CHAKRAVARTTY', 'INC', 53653, 'ASHOK BHATTACHARJYA', 'CPM', 42992, 10661),
    148: ('ALIPORE', 171, 350, 131215, 89793, 84427,
          'SAUGATA ROY', 'INC', 51590, 'RATHINDRANATH ROYCHOWDHURY', 'CPM', 30879, 20711),
    205: ('SUTAHATA (SC)', 228, 361, 181586, 162337, 158286,
          'TUSHAR KANTI MANDAL', 'INC', 81461, 'NITYANANDA BERA', 'CPM', 76238, 5223),
}


def sha(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def declared_result(text: str) -> tuple:
    winner = re.search(r'^\s*Winner\s*:\s*(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
    runner = re.search(r'^\s*Runner up\s*:\s*(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
    margin = re.search(r'^\s*MARGIN\s*:\s*(\d+)\b', text, re.I | re.M)
    if not all((winner, runner, margin)):
        raise ValueError('Source declaration could not be read')
    return (winner[2].strip(), winner[1], int(winner[3]), runner[2].strip(), runner[1],
            int(runner[3]), int(margin[1]))


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    old_body = (folder / 'extraction.json').read_bytes()
    data = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    source_path = folder / SOURCE_FILE
    if (sha(old_body) != OLD_SHA or data['kind'] != 'ac' or data['year'] != 1996
            or len(data['records']) != 294 or data['source_url'] != SOURCE_URL
            or data['source_file'] != SOURCE_FILE or data['source_sha256'] != SOURCE_SHA
            or manifest['url'] != SOURCE_URL
            or len([item for item in manifest['files'] if item['file'] == SOURCE_FILE
                    and item['sha256'] == SOURCE_SHA]) != 1 or sha(source_path.read_bytes()) != SOURCE_SHA):
        raise ValueError('Official West Bengal 1996 source identity differs')
    summaries = read_summary_pages(source_path)
    if len(summaries) != 294:
        raise ValueError('Official summary coverage differs')
    revised = json.loads(old_body)
    audit_rows = []
    with fitz.open(source_path) as pdf:
        for code, expected in EXPECTED.items():
            (name, page, detail_page, electors, polled, valid, winner, winner_party, winner_votes,
             runner, runner_party, runner_votes, margin) = expected
            record = next(row for row in revised['records'] if row['code'] == code)
            summary = summaries[code]
            ranked = sorted(record['candidates'], key=lambda candidate: -candidate['votes'])
            candidate_sum = sum(candidate['votes'] for candidate in record['candidates'])
            text = pdf[page - 1].get_text(sort=True)
            detail_text = pdf[detail_page - 1].get_text(sort=True)
            if (record['name'] != name or record['state_name'] != 'West Bengal'
                    or record['status'] != 'needs_review' or record['error'] != WARNING
                    or record['number_of_seats'] != 1 or record['detail_page'] != detail_page
                    or record.get('summary_result') is not None
                    or (summary['name'], summary['electors'], summary['votes_polled'],
                        summary['valid_candidate_votes'], summary['summary_page']) != (name, electors, polled, valid, page)
                    or record['electors'] != electors or record['valid_candidate_votes'] != candidate_sum
                    or record['votes_polled'] >= polled or candidate_sum <= valid
                    or candidate_sum > record['electors']
                    or re.search(rf'Field7:CONSTITUENCY\s*:\s*{code}\s*-\s*{re.escape(name)}(?:\s|$)', text, re.I) is None
                    or name.split(' (')[0] not in detail_text.upper()
                    or declared_result(text) != (winner, winner_party, winner_votes,
                                                  runner, runner_party, runner_votes, margin)
                    or [(ranked[i]['candidate_name'], ranked[i]['party_at_election'], ranked[i]['votes'])
                        for i in range(2)] != [(winner, winner_party, winner_votes),
                                                (runner, runner_party, runner_votes)]
                    or winner_votes - runner_votes != margin):
                raise ValueError(f'West Bengal 1996 {code} source evidence differs')
            original = {'electors': record['electors'], 'votes_polled': record['votes_polled'],
                        'valid_candidate_votes': record['valid_candidate_votes'], 'candidate_sum': candidate_sum}
            record['original_detail_totals'] = original
            record['original_extraction_warning'] = record['error']
            record['error'] = NOTE
            record['source_warning_code'] = 'official_summary_turnout_only'
            record['votes_polled'] = polled
            record['valid_candidate_votes'] = valid
            record['summary_page'] = page
            record['summary_totals'] = {'electors': electors, 'votes_polled': polled,
                                        'valid_candidate_votes': valid}
            record['summary_result'] = {'winner': winner, 'winner_party': winner_party,
                                        'winner_votes': winner_votes, 'runner': runner,
                                        'runner_party': runner_party, 'runner_votes': runner_votes,
                                        'margin': margin}
            record['summary_source_file'] = SOURCE_FILE
            record['summary_source_sha256'] = SOURCE_SHA
            record['candidate_source_discrepancy'] = {'candidate_sum': candidate_sum,
                                                       'printed_valid_votes': valid,
                                                       'difference': candidate_sum - valid}
            audit_rows.append({'code': code, 'name': name, 'summary_page': page,
                               'detail_page': detail_page, 'candidate_count': len(record['candidates']),
                               'detail_totals': original, 'summary_totals': record['summary_totals'],
                               'winner': winner, 'margin': margin})
    changed_fields = {'original_detail_totals', 'original_extraction_warning', 'error', 'source_warning_code',
                      'votes_polled', 'valid_candidate_votes', 'summary_page', 'summary_totals',
                      'summary_result', 'summary_source_file', 'summary_source_sha256',
                      'candidate_source_discrepancy'}
    for before, after in zip(data['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if before['code'] != after['code'] or changed != (changed_fields if before['code'] in EXPECTED else set()):
            raise ValueError('Unrelated West Bengal 1996 source evidence changed')
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
    with tempfile.TemporaryDirectory(prefix='ac-wb-1996-', dir=output.parent) as temporary:
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
            archive.writestr('AUDIT.json', json.dumps({'scope': 'Four 1996 West Bengal summary-declared AC results with detail discrepancies retained', **audit}, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    bundle_sha = sha(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{bundle_sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': bundle_sha, **audit}


if __name__ == '__main__':
    print(json.dumps(build()))
