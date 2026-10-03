"""Verify the declared result where the 1969 official turnout is impossible."""

import hashlib
import json
from pathlib import Path
import re

import fitz

from build_pc_1989_summary_result_bundle import source_totals
from build_pc_1992_summary_result_bundle import printed_candidate


ROOT = Path(__file__).resolve().parents[1]
EDITION = '7ce40cf47befc2b48ff776e3'
PDF = EDITION + '-7475.pdf'
CODE = 315
WARNING = 'Electorate and voter totals are inconsistent'


def audit(root: Path = ROOT) -> dict:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    data = json.loads((folder / 'extraction.json').read_text(encoding='utf-8'))
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    source = [item for item in manifest['files'] if item['file'] == PDF]
    if data['kind'] != 'ac' or data['year'] != 1969 or manifest['url'] != data['source_url'] \
            or len(source) != 1 or hashlib.sha256((folder / PDF).read_bytes()).hexdigest() != source[0]['sha256']:
        raise ValueError('Official 1969 assembly source identity differs')
    record = next(item for item in data['records'] if item['code'] == CODE)
    if (record['name'] != 'CHHIBRAMAU' or record['number_of_seats'] != 1
            or record['status'] != 'needs_review' or record['error'] != WARNING
            or record['electors'] != 73524 or record['votes_polled'] != 80269
            or record['valid_candidate_votes'] != 78013):
        raise ValueError('Archived reviewed constituency differs')
    with fitz.open(folder / PDF) as document:
        summary_text = document[record['summary_page'] - 1].get_text(sort=True)
        detail_text = document[record['detail_page'] - 1].get_text()
    title = 'State Election, 1969 to the Legislative Assembly of Uttar Pradesh'
    heading = re.search(r'CONSTITUENCY\s*:\s*315\s*-\s*CHHIBRAMAU\b', summary_text, re.I)
    detail_heading = re.search(r'Constituency\s*:\s*315\s*\.\s*CHHIBRAMAU\b', detail_text, re.I)
    electors = re.search(r'II\. ELECTORS\b(.*?)III\. ELECTORS WHO VOTED\b', summary_text, re.I | re.S)
    voters = re.search(r'III\. ELECTORS WHO VOTED\b(.*?)IV\. VOTES\b', summary_text, re.I | re.S)
    valid = re.search(r'2\. VALID\s+(\d+)', summary_text, re.I)
    margin = re.search(r'MARGIN\s*:\s*(\d+)', summary_text, re.I)
    if title not in summary_text or not all((heading, detail_heading, electors, voters, valid, margin)):
        raise ValueError('Official 1969 summary or detail locator differs')
    printed = (source_totals(electors[1]), source_totals(voters[1]), int(valid[1]))
    if printed != (record['electors'], record['votes_polled'], record['valid_candidate_votes']) \
            or not 0 < record['electors'] < record['votes_polled'] \
            or record['valid_candidate_votes'] > record['votes_polled']:
        raise ValueError('Printed turnout inconsistency differs')
    candidates = record['candidates']
    if len(candidates) < 2 or sum(row['votes'] for row in candidates) != record['valid_candidate_votes']:
        raise ValueError('Preserved candidate rows do not reconcile')
    ranked = sorted(candidates, key=lambda row: row['votes'], reverse=True)
    winner, runner = ranked[:2]
    if winner['votes'] <= runner['votes'] or int(margin[1]) != winner['votes'] - runner['votes'] \
            or not printed_candidate(summary_text, 'Winner', winner) \
            or not printed_candidate(summary_text, 'Runner up', runner):
        raise ValueError('Official declared winner or margin differs')
    return {'source_file': PDF, 'source_sha256': source[0]['sha256'],
            'source_url': data['source_url'], 'summary_page': record['summary_page'],
            'detail_page': record['detail_page'], 'official_state': 'Uttar Pradesh',
            'electors': printed[0], 'votes_polled': printed[1], 'valid_candidate_votes': printed[2],
            'result': {'winner': winner['candidate_name'], 'winner_party': winner['party_at_election'],
                       'winner_votes': winner['votes'], 'runner': runner['candidate_name'],
                       'runner_party': runner['party_at_election'], 'runner_votes': runner['votes'],
                       'margin': int(margin[1])}}


if __name__ == '__main__':
    print(json.dumps(audit(), ensure_ascii=False))
