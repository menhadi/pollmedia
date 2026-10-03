"""Classify 1999 PC detail/summary name warnings against both official PDFs."""

import hashlib
import json
from pathlib import Path
import re

import fitz

from build_pc_1989_summary_result_bundle import source_totals
from build_pc_1992_summary_result_bundle import printed_candidate
from extract_pc_legacy import summary_identity


ROOT = Path(__file__).resolve().parents[1]
EDITION = 'b7045310e2d656801a7a8bfe'
DETAIL = EDITION + '-9776.pdf'
SUMMARY = EDITION + '-9777.pdf'
WARNING = 'Detailed and summary constituency names differ'


def simple(name: str) -> str:
    return re.sub(r'[^a-z0-9]', '', name.casefold())


def without_reservation(name: str) -> str:
    return re.sub(r'\s*\((?:SC|ST)\)\s*$', '', name, flags=re.I)


def printed_result(text: str, label: str, candidate: dict) -> bool:
    name = re.escape(candidate['candidate_name']).replace(r'\ ', r'\s+')
    pattern = (re.escape(label) + r'\s*:?\s+' + re.escape(candidate['party_at_election'])
               + r'\s+' + name + r'\s+' + str(candidate['votes']) + r'(?=\s|$)')
    return bool(re.search(pattern, text, re.I) or printed_candidate(text, label, candidate))


def verified_result(record: dict, summary_text: str, detail_text: str) -> dict:
    blocks = [re.search(pattern, summary_text, re.I | re.S) for pattern in (
        r'II\. ELECTORS\b(.*?)III\. ELECTORS WHO VOTED\b',
        r'III\. ELECTORS WHO VOTED\b(.*?)IV\. VOTES\b',
        r'IV\. VOTES\b(.*?)V\. POLLING STATIONS\b',
    )]
    valid = re.search(r'2\. VALID\s+(\d+)', blocks[2][1], re.I) if blocks[2] else None
    contested = re.search(r'4\. CONTESTED\s+(\d+)\s+(\d+)\s+(\d+)', summary_text, re.I)
    margin = re.search(r'MARGIN\s*:\s*(\d+)', summary_text, re.I)
    name = record['constituency_name']
    heading = re.search(r'Constituency\s*:?\s*' + str(record['official_pc_code']) + r'\s*\.?\s*'
                        + re.escape(name) + r'(?=\s)', detail_text, re.I)
    if not all(blocks) or not valid or not margin or not contested or not heading \
            or record['state_name'].casefold() not in detail_text.casefold():
        raise ValueError('Official detailed or summary result is incomplete')
    if ((source_totals(blocks[0][1]), source_totals(blocks[1][1]), int(valid[1]))
            != (record['electors'], record['votes_polled'], record['valid_candidate_votes'])):
        raise ValueError('Official totals differ from preserved detail')
    candidates = record['candidates']
    if len(candidates) < 2 or int(contested[1]) + int(contested[2]) != int(contested[3]) \
            or int(contested[3]) != len(candidates) \
            or sum(row['votes'] for row in candidates) != record['valid_candidate_votes'] \
            or any(not row['candidate_name'] or not row['party_at_election'] or type(row['votes']) is not int
                   or row['votes'] < 0 for row in candidates):
        raise ValueError('Candidate rows do not reconcile with valid votes')
    keys = {(row['candidate_name'].casefold(), row['party_at_election'].casefold(), row['votes']) for row in candidates}
    if len(keys) != len(candidates):
        raise ValueError('Duplicate candidate identity')
    ranked = sorted(candidates, key=lambda row: row['votes'], reverse=True)
    winner, runner = ranked[:2]
    if winner['votes'] <= runner['votes'] or int(margin[1]) != winner['votes'] - runner['votes'] \
            or not printed_result(summary_text, 'Winner', winner) \
            or not printed_result(summary_text, 'Runner up', runner):
        raise ValueError('Official winner or margin differs from candidate rows')
    return {'winner': winner['candidate_name'], 'winner_party': winner['party_at_election'],
            'winner_votes': winner['votes'], 'runner': runner['candidate_name'],
            'runner_party': runner['party_at_election'], 'runner_votes': runner['votes'],
            'margin': int(margin[1])}


def audit(root: Path = ROOT) -> list[dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    data = json.loads((folder / 'extraction.json').read_text(encoding='utf-8'))
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    if data['kind'] != 'pc' or data['year'] != 1999 or manifest['url'] != data['source_url']:
        raise ValueError('1999 PC edition identity differs')
    by_file = {item['file']: item for item in manifest['files']}
    for filename in (DETAIL, SUMMARY):
        if filename not in by_file or hashlib.sha256((folder / filename).read_bytes()).hexdigest() != by_file[filename]['sha256']:
            raise ValueError('Official PDF checksum differs: ' + filename)
    targets = [record for record in data['records'] if record.get('error') == WARNING]
    if len(data['records']) != 543 or len(targets) != 118:
        raise ValueError('1999 PC name-warning coverage differs')
    rows = []
    with fitz.open(folder / DETAIL) as detail, fitz.open(folder / SUMMARY) as summary:
        for record in targets:
            if record['number_of_seats'] != 1 or record['status'] != 'needs_review' \
                    or not 1 <= record['summary_page'] <= len(summary) \
                    or not 1 <= record['detail_page'] <= len(detail):
                raise ValueError('Record identity or locator differs')
            text = summary[record['summary_page'] - 1].get_text()
            state, state_code, number, summary_name = summary_identity(text)
            if (simple(state) != simple(record['state_name']) or state_code != record['state_code']
                    or number != record['official_pc_code']):
                raise ValueError(f'Summary jurisdiction or seat code differs: {record["code"]}')
            detail_name = record['constituency_name']
            reservation_only = simple(without_reservation(detail_name)) == simple(summary_name)
            printed_truncation = (record['code'] == 253 and state == 'MAHARASHTRA'
                                  and detail_name == 'MUMBAI SOUTH CENTRAL'
                                  and summary_name == 'Mumbai South Centra')
            result = None
            problem = None
            if reservation_only or printed_truncation:
                try:
                    result = verified_result(record, summary[record['summary_page'] - 1].get_text(sort=True),
                                             detail[record['detail_page'] - 1].get_text())
                except ValueError as error:
                    problem = str(error)
            rows.append({'code': record['code'], 'state': state, 'detail': detail_name,
                         'summary': summary_name, 'reservation_only': reservation_only,
                         'printed_truncation': printed_truncation, 'result': result, 'problem': problem})
    if len([row for row in rows if row['result'] is not None]) != 118 \
            or [row['code'] for row in rows if row['printed_truncation']] != [253]:
        raise ValueError('1999 PC name-warning classification changed')
    return rows


if __name__ == '__main__':
    rows = audit()
    reserved = [row for row in rows if row['reservation_only']]
    print('1999 PC name warnings:', len(rows), 'reserved suffix only:', len(reserved),
          'verified results:', sum(row['result'] is not None for row in rows),
          'printed name truncation:', sum(row['printed_truncation'] for row in rows))
    for row in rows:
        if not row['result']:
            print(row['code'], row['state'], '|', row['detail'], '|', row['summary'], '|', row['problem'])
