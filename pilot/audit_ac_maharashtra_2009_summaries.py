"""Read the 288 official Maharashtra 2009 AC constituency summaries."""

import hashlib
import json
from pathlib import Path
import re

import fitz


ROOT = Path(__file__).resolve().parents[1]
EDITION = 'cc0185e917711e78c149abf4'
SOURCE_FILE = f'{EDITION}-8764.pdf'
EXTRACTION_SHA = '08d24c7c620450ff0a52c6afd002b85342b11168d7d773269f671c07ad0d7ec5'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3724-maharashtra-2009/'


def normalized(name: str) -> str:
    return re.sub(r'[^a-z0-9]+', '', name.casefold())


def number(pattern: str, text: str) -> int:
    match = re.search(pattern, text, re.I | re.M)
    if match is None:
        raise ValueError('Missing official summary number: ' + pattern)
    return int(match[1])


def summary(text: str, code: int, name: str) -> dict:
    heading = re.search(r'^\s*CONSTITUENCY\s*:\s*(\d+)\s*-\s*(.+?)\s*$', text, re.I | re.M)
    winner = re.search(r'^\s*WINNER\s+([A-Z()]+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
    runner = re.search(r'^\s*RUNNER-UP\s+([A-Z()]+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
    if (heading is None or int(heading[1]) != code or normalized(heading[2]) != normalized(name)
            or winner is None or runner is None):
        raise ValueError(f'Maharashtra 2009 summary heading or result differs: {code} {name}')
    try:
        elector_text = re.split(r'II\.\s*ELECTORS', text, maxsplit=1, flags=re.I)[1]
        elector_text = re.split(r'III\.\s*VOTERS', elector_text, maxsplit=1, flags=re.I)[0]
        voter_text = re.split(r'III\.\s*VOTERS', text, maxsplit=1, flags=re.I)[1]
        voter_text = re.split(r'IV\.\s*VOTES', voter_text, maxsplit=1, flags=re.I)[0]
        vote_text = re.split(r'IV\.\s*VOTES', text, maxsplit=1, flags=re.I)[1]
        vote_text = re.split(r'V\.\s*POLLING STATIONS', vote_text, maxsplit=1, flags=re.I)[0]
    except IndexError as error:
        raise ValueError(f'Maharashtra 2009 summary section differs: {code}') from error
    electors = number(r'^\s*3\. TOTAL\s+\d+\s+\d+\s+(\d+)\s*$', elector_text)
    voters = number(r'^\s*4\. TOTAL\s+(\d+)\s*$', voter_text)
    valid = number(r'^\s*3\. TOTAL VALID VOTES POLLED\s+(\d+)\s*$', vote_text)
    rejected = number(r'^\s*1\. REJECTED VOTES \(POSTAL\)\s+(\d+)\s*$', vote_text)
    not_retrieved = number(r'^\s*2\. VOTES NOT RETREIVED FROM EVM\s+(\d+)\s*$', vote_text)
    winner_votes, runner_votes = int(winner[3]), int(runner[3])
    margin = number(r'^\s*MARGIN\s+(\d+)\b', text)
    if (not 0 < runner_votes < winner_votes <= valid <= voters <= electors
            or voters != valid + rejected + not_retrieved):
        raise ValueError(f'Maharashtra 2009 summary arithmetic differs: {code}')
    return {'code': code, 'name': heading[2], 'electors': electors, 'votes_polled': voters,
            'valid_candidate_votes': valid, 'rejected_postal': rejected,
            'votes_not_retrieved': not_retrieved,
            'summary_page': code + 31, 'margin_reconciles': margin == winner_votes - runner_votes,
            'result': {'winner': winner[2].strip(), 'winner_party': winner[1].strip(),
                       'winner_votes': winner_votes, 'runner': runner[2].strip(),
                       'runner_party': runner[1].strip(), 'runner_votes': runner_votes,
                       'margin': margin}}


def audit(root: Path = ROOT) -> tuple[bytes, list[dict], dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    body = (folder / 'extraction.json').read_bytes()
    data = json.loads(body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    pdf_path = folder / SOURCE_FILE
    pdf_sha = hashlib.sha256(pdf_path.read_bytes()).hexdigest()
    if (hashlib.sha256(body).hexdigest() != EXTRACTION_SHA
            or data['kind'] != 'ac' or data['year'] != 2009 or len(data['records']) != 288
            or data['source_url'] != SOURCE_URL or data['source_file'] != SOURCE_FILE
            or data['source_sha256'] != pdf_sha or manifest['url'] != SOURCE_URL
            or len([row for row in manifest['files']
                    if row['file'] == SOURCE_FILE and row['sha256'] == pdf_sha]) != 1):
        raise ValueError('Maharashtra 2009 archived report identity differs')
    records = {record['code']: record for record in data['records']}
    if set(records) != set(range(1, 289)):
        raise ValueError('Maharashtra 2009 constituency inventory differs')
    summaries = []
    with fitz.open(pdf_path) as pdf:
        if len(pdf) != 468:
            raise ValueError('Maharashtra 2009 official report length differs')
        for code in range(1, 289):
            source = records[code]
            parsed = summary(pdf[code + 30].get_text(sort=True), code, source['name'])
            if parsed['electors'] != source['electors']:
                raise ValueError(f'Maharashtra 2009 elector count differs: {code}')
            summaries.append(parsed)
    return body, summaries, {'edition': EDITION, 'source_url': SOURCE_URL,
                             'source_file': SOURCE_FILE, 'source_sha256': pdf_sha}


if __name__ == '__main__':
    _, entries, evidence = audit()
    print(json.dumps({'editions': 1, 'summaries': len(entries), 'source_sha256': evidence['source_sha256'],
                      'total_electors': sum(row['electors'] for row in entries),
                      'total_voters': sum(row['votes_polled'] for row in entries),
                      'margin_discrepancy_codes': [row['code'] for row in entries if not row['margin_reconciles']]}))
