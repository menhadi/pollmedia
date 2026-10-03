"""Check source-printed totals for the 1996–1999 PC detail-only gaps."""

import hashlib
import json
from bisect import bisect_right
from pathlib import Path
import re

import fitz


ROOT = Path(__file__).resolve().parents[1]
EDITIONS = {
    1996: '35f16085183f0c8bd7ef6124',
    1998: 'f3bccf66ec1c16f1f9c1ffba',
    1999: 'b7045310e2d656801a7a8bfe',
}
WARNING = 'Matching state/constituency summary is unavailable; Independent summary totals could not be reconciled'
ELECTORS = re.compile(r'ELECTORS\s*:\s*(\d+)', re.I)
VOTERS = re.compile(r'VOTERS\s*:\s*(\d+)', re.I)
VALID = re.compile(r'VALID VOTES\s*:?\s*(\d+)', re.I)


def source_check(year: int, edition: str) -> list[dict]:
    folder = ROOT / 'application/storage/app/private/election-archive' / edition
    data = json.loads((folder / 'extraction.json').read_text(encoding='utf-8'))
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    if data['year'] != year or data['kind'] != 'pc' or manifest['url'] != data['source_url']:
        raise ValueError(f'{year}: edition identity differs')
    matching = [item for item in manifest['files'] if item['file'] == data['source_file']]
    if len(matching) != 1:
        raise ValueError(f'{year}: detailed PDF not uniquely listed')
    pdf = folder / data['source_file']
    digest = hashlib.sha256(pdf.read_bytes()).hexdigest()
    if digest != matching[0]['sha256'] or digest != data['source_sha256']:
        raise ValueError(f'{year}: PDF checksum differs')
    targets = [record for record in data['records'] if record.get('error') == WARNING]
    if len(data['records']) != 543 or len(targets) != 15:
        raise ValueError(f'{year}: expected constituency coverage differs')
    verified = []
    with fitz.open(pdf) as document:
        for record in targets:
            start = record['detail_page']
            pages = [document[i].get_text() for i in range(start - 1, min(start + 3, len(document)))]
            offsets = [0]
            for page in pages[:-1]:
                offsets.append(offsets[-1] + len(page) + 1)
            source_text = '\n'.join(pages)
            if record['state_name'].casefold() not in pages[0].casefold():
                raise ValueError(f'{year} {record["code"]}: printed state heading differs')
            name = record['constituency_name']
            code = record['official_pc_code']
            heading = re.search(r'Constituency\s*:?\s*' + str(code) + r'\s*\.?\s*' + re.escape(name) + r'(?=\s)', source_text, re.I)
            if heading is None:
                raise ValueError(f'{year} {record["code"]}: seat heading not on expected page')
            next_heading = re.search(r'Constituency\s*:?\s*\d+\s*\.?\s*[^\n]+', source_text[heading.end():], re.I)
            section = source_text[heading.end():heading.end() + next_heading.start()] if next_heading else source_text[heading.end():]
            totals = [pattern.findall(section) for pattern in (ELECTORS, VOTERS, VALID)]
            if any(len(values) != 1 for values in totals):
                raise ValueError(f'{year} {record["code"]}: printed totals not found')
            electors, voters, valid = [int(values[0]) for values in totals]
            candidates = record['candidates']
            keys = [(candidate['candidate_name'].casefold(), candidate['party_at_election'].casefold(), candidate['votes']) for candidate in candidates]
            if ((electors, voters, valid) != (record['electors'], record['votes_polled'], record['valid_candidate_votes'])
                    or not 0 < valid <= voters <= electors or len(candidates) < 2
                    or sum(candidate['votes'] for candidate in candidates) != valid
                    or len(keys) != len(set(keys)) or record['number_of_seats'] != 1):
                raise ValueError(f'{year} {record["code"]}: turnout or candidate evidence differs')
            ranked = sorted(candidates, key=lambda candidate: candidate['votes'], reverse=True)
            if ranked[0]['votes'] <= ranked[1]['votes']:
                raise ValueError(f'{year} {record["code"]}: winner is tied')
            for candidate in ranked[:2]:
                pattern = re.escape(candidate['candidate_name']) + r'\s+[MF]\s+' + re.escape(candidate['party_at_election']) + r'\s+' + str(candidate['votes']) + r'\b'
                if re.search(pattern, section, re.I) is None:
                    raise ValueError(f'{year} {record["code"]}: top candidate source row differs')
            voter_at = heading.end() + section.index(VOTERS.search(section).group(0))
            printed_page = start + bisect_right(offsets, voter_at) - 1
            verified.append({'code': record['code'], 'name': name, 'heading_page': start,
                             'source_page': printed_page, 'electors': electors,
                             'votes_polled': voters, 'valid_candidate_votes': valid,
                             'result': {'winner': ranked[0]['candidate_name'],
                                        'winner_party': ranked[0]['party_at_election'],
                                        'winner_votes': ranked[0]['votes'],
                                        'runner': ranked[1]['candidate_name'],
                                        'runner_party': ranked[1]['party_at_election'],
                                        'runner_votes': ranked[1]['votes'],
                                        'margin': ranked[0]['votes'] - ranked[1]['votes']}})
    return verified


if __name__ == '__main__':
    for year, edition in EDITIONS.items():
        records = source_check(year, edition)
        print(year, len(records), ','.join(str(item['code']) for item in records))
