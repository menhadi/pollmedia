"""Verify Kerala 2006 AC declarations hidden by incomplete candidate detail."""

import csv
import hashlib
import json
from pathlib import Path
import re

import fitz

from audit_pc_ac_zero_values import correction_index, effective_body
from build_pc_1992_summary_result_bundle import normalized
from extract_assembly_summary_totals import read_summary_pages


ROOT = Path(__file__).resolve().parents[1]
EDITION = 'fb76cebe74b1c87526369724'
PRIOR_PACKAGES = tuple(f'pollmedia-ac-summary-corrections-20261001-v{i}.zip' for i in range(2, 8)) + tuple(
    f'pollmedia-pc-ac-zero-turnout-corrections-20261001-v{i}.zip' for i in range(1, 4))
PRIOR_SHA256 = '2018bb2f2eee688fc7af367a9fba0eaf0cadcb0a11976e5941366ade45dac2c1'
SOURCE_NOTE = 'Official constituency summary supplies electors and voters; detailed candidate rows remain under review.'


def audit(root: Path = ROOT) -> tuple[bytes, list[dict], dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    revisions = correction_index(root, PRIOR_PACKAGES)
    if len(revisions.get(EDITION, [])) != 9:
        raise ValueError('Prior 2006 Kerala revisions are missing')
    old_body = effective_body(folder / 'extraction.json', revisions[EDITION])
    if hashlib.sha256(old_body).hexdigest() != PRIOR_SHA256:
        raise ValueError('Prior 2006 Kerala extraction checksum differs')
    data = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    files = [row for row in manifest['files'] if row['file'] == data['source_file']]
    pdf_path = folder / data['source_file']
    if (data['kind'] != 'ac' or data['year'] != 2006 or data['source_url'] != manifest['url']
            or len(files) != 1 or files[0]['sha256'] != data['source_sha256']
            or hashlib.sha256(pdf_path.read_bytes()).hexdigest() != files[0]['sha256']):
        raise ValueError('Official 2006 Kerala source identity differs')
    with (root / 'exports/pc-ac-display-audit-after-bdd282d.csv').open(encoding='utf-8-sig', newline='') as source:
        audit_rows = [row for row in csv.DictReader(source)
                      if row['edition_id'] == EDITION and row['issue'] == 'winner_hidden_with_candidate_votes']
    codes = {int(row['code']) for row in audit_rows}
    if len(audit_rows) != 3 or len(codes) != 3 or {row['extraction_sha256'] for row in audit_rows} != {PRIOR_SHA256}:
        raise ValueError('Live 2006 Kerala winner-gap inventory differs')
    summaries = read_summary_pages(pdf_path)
    if len(summaries) != len(data['records']) or set(summaries) != {row['code'] for row in data['records']}:
        raise ValueError('Official 2006 Kerala summary coverage differs')
    results, held = [], []
    with fitz.open(pdf_path) as pdf:
        for record in data['records']:
            if record['code'] not in codes:
                continue
            summary = summaries[record['code']]
            if (record['state_name'] != 'Kerala' or record['number_of_seats'] != 1
                    or not record['error'].startswith(SOURCE_NOTE)
                    or record['source_warning_code'] != 'official_summary_turnout_only'
                    or record['summary_page'] != summary['summary_page']
                    or record['summary_totals'] != {key: summary[key] for key in ('electors', 'votes_polled', 'valid_candidate_votes')}
                    or record['summary_source_sha256'] != files[0]['sha256']
                    or record.get('summary_result') is not None):
                raise ValueError('Reviewed 2006 Kerala record differs: ' + str(record['code']))
            text = pdf[summary['summary_page'] - 1].get_text(sort=True)
            heading = re.search(r'CONSTITUENCY\s+(\d+)\s*-\s*([^\n]+)', text, re.I)
            winner = re.search(r'^\s*WINNER\s+(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
            runner = re.search(r'^\s*RUNNER-UP\s+(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
            margin = re.search(r'^\s*MARGIN\s+(\d+)\b', text, re.I | re.M)
            if (not all((heading, winner, runner, margin))
                    or 'legislative assembly of  Kerala' not in text
                    or int(heading[1]) != record['code']
                    or normalized(heading[2]) != normalized(record['name'])
                    or normalized(summary['name']) != normalized(record['name'])):
                raise ValueError('Official 2006 Kerala declaration differs: ' + str(record['code']))
            winner_votes, runner_votes, margin_votes = int(winner[3]), int(runner[3]), int(margin[1])
            if (not 0 <= runner_votes < winner_votes <= summary['valid_candidate_votes']
                    or winner_votes - runner_votes != margin_votes
                    or not 0 < summary['valid_candidate_votes'] <= summary['votes_polled'] <= summary['electors']):
                held.append({'code': record['code'], 'printed_margin': margin_votes,
                             'candidate_difference': winner_votes - runner_votes})
                continue
            results.append({'code': record['code'], 'name': record['name'], 'summary_page': summary['summary_page'],
                            'result': {'winner': winner[2].strip(), 'winner_party': winner[1].strip(),
                                       'winner_votes': winner_votes, 'runner': runner[2].strip(),
                                       'runner_party': runner[1].strip(), 'runner_votes': runner_votes,
                                       'margin': margin_votes}})
    if len(results) + len(held) != 3:
        raise ValueError('Official 2006 Kerala result coverage differs')
    return old_body, results, {'source_url': data['source_url'], 'source_file': data['source_file'],
                               'source_sha256': data['source_sha256'], 'previous_sha256': PRIOR_SHA256,
                               'held': held}


if __name__ == '__main__':
    _, results, source = audit()
    print(json.dumps({'verified': len(results), 'held': source['held'], 'codes': [item['code'] for item in results]}))
