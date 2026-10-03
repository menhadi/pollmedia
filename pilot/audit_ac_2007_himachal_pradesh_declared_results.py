"""Verify Himachal Pradesh 2007 AC declarations hidden by incomplete candidate detail."""

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
EDITION = 'e1372f39c9e60519335a29f8'
PRIOR_PACKAGES = tuple(f'pollmedia-ac-summary-corrections-20261001-v{i}.zip' for i in range(2, 8)) + tuple(
    f'pollmedia-pc-ac-zero-turnout-corrections-20261001-v{i}.zip' for i in range(1, 4))
PRIOR_SHA256 = '25e41c583d23fbe6e1a48ed2acbd26d55aee494386398df0815cf23b8533574f'
SOURCE_NOTES = ('Official constituency summary supplies electors and voters; detailed candidate rows remain under review.',
                'Official summary confirms constituency turnout and candidate-vote total; detailed candidate text still needs review. Some candidate text could not be parsed; see the original PDF.')


def audit(root: Path = ROOT) -> tuple[bytes, list[dict], dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    revisions = correction_index(root, PRIOR_PACKAGES)
    if len(revisions.get(EDITION, [])) != 5:
        raise ValueError('Prior 2007 Himachal Pradesh revisions are missing')
    old_body = effective_body(folder / 'extraction.json', revisions[EDITION])
    if hashlib.sha256(old_body).hexdigest() != PRIOR_SHA256:
        raise ValueError('Prior 2007 Himachal Pradesh extraction checksum differs')
    data = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    files = [row for row in manifest['files'] if row['file'] == data['source_file']]
    pdf_path = folder / data['source_file']
    if (data['kind'] != 'ac' or data['year'] != 2007 or data['source_url'] != manifest['url']
            or len(files) != 1 or files[0]['sha256'] != data['source_sha256']
            or hashlib.sha256(pdf_path.read_bytes()).hexdigest() != files[0]['sha256']):
        raise ValueError('Official 2007 Himachal Pradesh source identity differs')
    with (root / 'exports/pc-ac-display-audit-after-bdd282d.csv').open(encoding='utf-8-sig', newline='') as source:
        audit_rows = [row for row in csv.DictReader(source)
                      if row['edition_id'] == EDITION and row['issue'] == 'winner_hidden_with_candidate_votes']
    codes = {int(row['code']) for row in audit_rows}
    if len(audit_rows) != 68 or len(codes) != 68 or {row['extraction_sha256'] for row in audit_rows} != {PRIOR_SHA256}:
        raise ValueError('Live 2007 Himachal Pradesh winner-gap inventory differs')
    summaries = read_summary_pages(pdf_path)
    if len(summaries) != len(data['records']) or set(summaries) != {row['code'] for row in data['records']}:
        raise ValueError('Official 2007 Himachal Pradesh summary coverage differs')
    results, held, discrepancies, cross_page = [], [], [], []
    with fitz.open(pdf_path) as pdf:
        for record in data['records']:
            if record['code'] not in codes:
                continue
            summary = summaries[record['code']]
            if (record['state_name'] != 'Himachal Pradesh' or record['number_of_seats'] != 1
                    or not any(record['error'].startswith(note) for note in SOURCE_NOTES)
                    or record['source_warning_code'] not in ('official_summary_turnout_only', 'summary_turnout_with_detail_warnings')
                    or record['summary_page'] != summary['summary_page']
                    or record['summary_totals'] != {key: summary[key] for key in ('electors', 'votes_polled', 'valid_candidate_votes')}
                    or (record.get('summary_source_sha256') != files[0]['sha256']
                        if record['source_warning_code'] == 'official_summary_turnout_only'
                        else record.get('summary_source_sha256') is not None)
                    or record.get('summary_result') is not None):
                raise ValueError('Reviewed 2007 Himachal Pradesh record differs: ' + str(record['code']))
            text = pdf[summary['summary_page'] - 1].get_text(sort=True)
            heading = re.search(r'CONSTITUENCY\s+(\d+)\s*-\s*([^\n]+)', text, re.I)
            winner = re.search(r'^\s*WINNER\s+(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
            runner = re.search(r'^\s*RUNNER-UP\s+(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
            margin = re.search(r'^\s*MARGIN\s+(\d+)\b', text, re.I | re.M)
            if (not all((heading, winner, runner, margin))
                    or 'legislative assembly of  Himachal Pradesh' not in text
                    or int(heading[1]) != record['code']
                    or normalized(heading[2]) != normalized(record['name'])
                    or normalized(summary['name']) != normalized(record['name'])):
                raise ValueError('Official 2007 Himachal Pradesh declaration differs: ' + str(record['code']))
            winner_votes, runner_votes, margin_votes = int(winner[3]), int(runner[3]), int(margin[1])
            difference = winner_votes - runner_votes
            reconciled_general_votes = None
            if difference != margin_votes and record['code'] in {41, 67}:
                candidates = record['candidates'][:2]
                if (len(candidates) == 2
                        and all(candidate['votes'] == candidate['general_votes'] + candidate['postal_votes']
                                for candidate in candidates)
                        and normalized(candidates[0]['candidate_name']) == normalized(winner[2])
                        and normalized(candidates[1]['candidate_name']) == normalized(runner[2])
                        and candidates[0]['party_at_election'] == winner[1]
                        and candidates[1]['party_at_election'] == runner[1]
                        and candidates[0]['general_votes'] == winner_votes
                        and candidates[1]['general_votes'] == runner_votes
                        and candidates[0]['votes'] - candidates[1]['votes'] == margin_votes):
                    reconciled_general_votes = {'winner': winner_votes, 'runner': runner_votes}
                    winner_votes, runner_votes = candidates[0]['votes'], candidates[1]['votes']
                    difference = winner_votes - runner_votes
                    cross_page.append({'code': record['code'], 'winner_general_votes': reconciled_general_votes['winner'],
                                       'runner_general_votes': reconciled_general_votes['runner'],
                                       'winner_total_votes': winner_votes, 'runner_total_votes': runner_votes,
                                       'printed_margin': margin_votes})
            if (not 0 <= runner_votes < winner_votes <= summary['valid_candidate_votes']
                    or not 0 < summary['valid_candidate_votes'] <= summary['votes_polled'] <= summary['electors']
                    or abs(difference - margin_votes) > 1):
                held.append({'code': record['code'], 'printed_margin': margin_votes,
                             'candidate_difference': difference})
                continue
            if difference != margin_votes:
                discrepancies.append({'code': record['code'], 'printed_margin': margin_votes,
                                      'candidate_difference': difference})
            results.append({'code': record['code'], 'name': record['name'], 'summary_page': summary['summary_page'],
                            'general_vote_line': reconciled_general_votes,
                            'result': {'winner': winner[2].strip(), 'winner_party': winner[1].strip(),
                                       'winner_votes': winner_votes, 'runner': runner[2].strip(),
                                       'runner_party': runner[1].strip(), 'runner_votes': runner_votes,
                                       'margin': difference}, 'reported_margin': margin_votes})
    if len(results) + len(held) != 68:
        raise ValueError('Official 2007 Himachal Pradesh result coverage differs')
    return old_body, results, {'source_url': data['source_url'], 'source_file': data['source_file'],
                               'source_sha256': data['source_sha256'], 'previous_sha256': PRIOR_SHA256,
                               'held': held, 'discrepancies': discrepancies,
                               'cross_page_reconciliations': cross_page}


if __name__ == '__main__':
    _, results, source = audit()
    print(json.dumps({'verified': len(results), 'held': source['held'],
                      'discrepancies': source['discrepancies'],
                      'cross_page_reconciliations': source['cross_page_reconciliations']}))
