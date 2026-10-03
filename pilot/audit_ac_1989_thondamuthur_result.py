"""Verify the declared 1989 Thondamuthur result despite impossible printed turnout."""

import hashlib
import json
from pathlib import Path
import re

import fitz

from build_pc_1989_summary_result_bundle import source_totals
from build_pc_1992_summary_result_bundle import printed_candidate


ROOT = Path(__file__).resolve().parents[1]
EDITION = '3250a94d4b625ec2bea29016'
PDF = EDITION + '-7702.pdf'
CODE = 103
SUMMARY_PAGE = 120
OLD_SHA256 = 'b51fd8b0093c5e6587e371c07d12ef4a479964a58a69a89184cd1852b1ef5ff7'
WARNING = ('Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.; '
           'Reported elector and voter totals are inconsistent.')


def audit(root: Path = ROOT) -> dict:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    old_body = (folder / 'extraction.json').read_bytes()
    data = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    matches = [item for item in manifest['files'] if item['file'] == PDF]
    source_path = folder / PDF
    if (hashlib.sha256(old_body).hexdigest() != OLD_SHA256 or data['kind'] != 'ac' or data['year'] != 1989
            or data['source_url'] != manifest['url'] or data['source_file'] != PDF
            or len(matches) != 1 or data['source_sha256'] != matches[0]['sha256']
            or hashlib.sha256(source_path.read_bytes()).hexdigest() != matches[0]['sha256']):
        raise ValueError('Official 1989 Tamil Nadu source differs')
    record = next(item for item in data['records'] if item['code'] == CODE)
    if (record['name'] != 'THONDAMUTHUR' or record['state_name'] != 'Tamil Nadu'
            or record['number_of_seats'] != 1 or record['status'] != 'needs_review'
            or record['error'] != WARNING or record['electors'] != 123266
            or record['votes_polled'] != 151869 or record['valid_candidate_votes'] != 148168
            or record['detail_page'] != 285 or record.get('summary_page') is not None):
        raise ValueError('Archived reviewed constituency differs')
    with fitz.open(source_path) as document:
        summary_text = document[SUMMARY_PAGE - 1].get_text(sort=True)
        successful_text = document[7].get_text(sort=True)
        detail_text = document[record['detail_page'] - 1].get_text(sort=True)
    heading = re.search(r'CONSTITUENCY\s*:\s*103\s*-\s*THONDAMUTHUR\b', summary_text, re.I)
    electors = re.search(r'II\. ELECTORS\b(.*?)III\. ELECTORS WHO VOTED\b', summary_text, re.I | re.S)
    voters = re.search(r'III\. ELECTORS WHO VOTED\b(.*?)IV\. VOTES\b', summary_text, re.I | re.S)
    valid = re.search(r'2\. VALID\s+(\d+)', summary_text, re.I)
    contested = re.search(r'4\. CONTESTED\s+\d+\s+\d+\s+(\d+)', summary_text, re.I)
    margin = re.search(r'MARGIN\s*:\s*(\d+)', summary_text, re.I)
    if ('State Election, 1989 to the Legislative Assembly of TAMIL NADU' not in summary_text
            or not all((heading, electors, voters, valid, contested, margin))
            or not re.search(r'103\.\s+THONDAMUTHUR\s+VELLINGIRI, U\.K\.\s+M\s+CPM', successful_text)
            or not re.search(r'Constituency\s+103\s+THONDAMUTHUR\b', detail_text)):
        raise ValueError('Official declaration or constituency locator differs')
    totals = (source_totals(electors[1]), source_totals(voters[1]), int(valid[1]))
    candidates = record['candidates']
    ranked = sorted(candidates, key=lambda row: row['votes'], reverse=True)
    if (totals != (record['electors'], record['votes_polled'], record['valid_candidate_votes'])
            or not 0 < totals[0] < totals[1] or totals[2] > totals[1]
            or int(contested[1]) != len(candidates) or len(candidates) != 16
            or sum(row['votes'] for row in candidates) != totals[2]
            or ranked[0]['votes'] <= ranked[1]['votes']
            or int(margin[1]) != ranked[0]['votes'] - ranked[1]['votes']
            or not printed_candidate(summary_text, 'Winner', ranked[0])
            or not printed_candidate(summary_text, 'Runner up', ranked[1])):
        raise ValueError('Printed 1989 result or voter totals differ')
    return {'source_file': PDF, 'source_sha256': matches[0]['sha256'], 'source_url': data['source_url'],
            'official_state': 'Tamil Nadu', 'summary_page': SUMMARY_PAGE, 'detail_page': record['detail_page'],
            'electors': totals[0], 'votes_polled': totals[1], 'valid_candidate_votes': totals[2],
            'result': {'winner': ranked[0]['candidate_name'], 'winner_party': ranked[0]['party_at_election'],
                       'winner_votes': ranked[0]['votes'], 'runner': ranked[1]['candidate_name'],
                       'runner_party': ranked[1]['party_at_election'], 'runner_votes': ranked[1]['votes'],
                       'margin': int(margin[1])}}


if __name__ == '__main__':
    print(json.dumps(audit()))
