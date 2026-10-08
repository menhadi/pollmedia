"""Recover malformed official HTML tables with two-parser and source-total checks."""
import copy
from io import BytesIO
import json
import zipfile

from bs4 import BeautifulSoup
from build_by_election_2018_cached_reviews import ROOT, PREFIX, build_package, digest

NAME = 'pollmedia-by-election-2002-html-recovery-20261009'
PREDECESSOR = 'pollmedia-by-election-2009-2010-discrepancy-notes-20261009.zip'
PACKAGE_SHA = '3cbab24e7be555b08be274a6f521c10a7d2d4d3a15ddeee9eb1d4328ed793ad8'
INDEX_SHA = 'f566f9cb510c99122331470a81103e2d018aa20250585743ff6cf68eff7adcff'
TARGETS = {
    'f120f4af3ef683a1f934aeef': ('11fa9c3c3a3662c95d89ada19006a886080fd031fd3b1b3638d3dac6a6c91fb4', 'afad5fe1701613fd32f07429283fedc8a607018fe9716ae961af0bebafdf7d92', 13, 1000925, 563664, 563660),
    '93cf70091d737fe40eecfde2': ('9ce1c2e77a61a2110b801f6a6d0d523d9a7dbf43d86a3ee9cdc6ea6b2f0ec03f', '8aef1ed8fc25f592f3d671296a0d2667d3313cd015cd82dc7410e8138edc771a', 26, 248003, 129433, 129433),
}


def parse_source(body, count, electors, polled, valid):
    parsed = {}
    for parser in ['lxml', 'html5lib']:
        soup = BeautifulSoup(body, parser)
        tables = soup.find_all('table')
        if len(tables) != 4:
            raise ValueError('Source table count differs')
        rows = [[' '.join(c.get_text(' ', strip=True).split()) for c in tr.find_all(['td', 'th'], recursive=False)]
                for tr in tables[3].find_all('tr')]
        if rows[1] != ['S.No.', 'Candidate', 'Sex', 'Party', 'Votes', '% of votes']:
            raise ValueError('Candidate heading differs')
        candidates = []
        for row_number, cells in enumerate(rows[2:], 3):
            if len(cells) < 5 or not cells[0].isdigit() or not cells[4].isdigit():
                raise ValueError('Candidate identity or numeric vote unavailable')
            candidates.append({'serial': int(cells[0]), 'name': cells[1], 'party': cells[3], 'votes': int(cells[4]),
                               'row': row_number, 'cells': cells})
        if [c['serial'] for c in candidates] != list(range(1, count+1)) or sum(c['votes'] for c in candidates) != valid:
            raise ValueError('Candidate count or valid-vote reconciliation differs')
        parsed[parser] = candidates
        summary = [[' '.join(c.get_text(' ', strip=True).split()) for c in tr.find_all(['td', 'th'], recursive=False)]
                   for tr in tables[2].find_all('tr')]
        for label, value in [('TOTAL POLLED', polled), ('VALID', valid)]:
            matches = [r for r in summary if label in r]
            if len(matches) != 1 or matches[0][-1] != str(value):
                raise ValueError('Printed source total differs')
        totals = [r[-1] for r in summary if 'TOTAL' in r]
        if str(electors) not in totals or str(polled) not in totals:
            raise ValueError('Electors or voters missing from source summary')
    keys = ['serial', 'name', 'party', 'votes']
    if [[c[k] for k in keys] for c in parsed['lxml']] != [[c[k] for k in keys] for c in parsed['html5lib']]:
        raise ValueError('Independent HTML parsers disagree')
    return parsed['html5lib']


def revised_files(root=ROOT):
    path = root / 'exports' / PREDECESSOR
    if digest(path.read_bytes()) != PACKAGE_SHA:
        raise ValueError('Predecessor bundle differs')
    with zipfile.ZipFile(path) as outer, zipfile.ZipFile(BytesIO(outer.read('correction-index.zip'))) as inner:
        old = inner.read(PREFIX+'index.json')
    if digest(old) != INDEX_SHA:
        raise ValueError('Predecessor index differs')
    index = json.loads(old)
    originals, additions, reviews = {}, {}, []
    folder = root / 'application/storage/app/private'
    for rid, (prior, source_sha, count, electors, polled, valid) in TARGETS.items():
        entries = [e for e in index['records'] if e['id'] == rid]
        if len(entries) != 1 or entries[0]['sha256'] != prior:
            raise ValueError('Prior index entry differs')
        entry = entries[0]
        prior_path = PREFIX+entry['file']
        body = (folder/prior_path).read_bytes()
        if digest(body) != prior:
            raise ValueError('Prior extraction differs')
        record = json.loads(body)
        source = folder/'election-by-elections'/record['edition']/record['source_file']
        source_body = source.read_bytes()
        if source.is_symlink() or digest(source_body) != source_sha or record['source_sha256'] != source_sha:
            raise ValueError('Source HTML checksum differs')
        recovered = parse_source(source_body, count, electors, polled, valid)
        if record['reported_contested'] != count:
            raise ValueError('Prior reported candidate count differs')
        for previous, candidate in zip(record['candidates'], recovered):
            if (previous['name'] != candidate['name'] or previous['party'] != candidate['party']
                    or previous['votes'] is not None and previous['votes'] != candidate['votes']):
                raise ValueError('Recovered rows conflict with previously readable values')
        after = copy.deepcopy(record)
        after['original_candidate_rows'] = copy.deepcopy(record['candidates'])
        after['original_candidate_count'] = record['candidate_count']
        after['candidates'] = [{'name': c['name'], 'party': c['party'], 'votes': c['votes'],
            'raw_votes': c['cells'][4], 'source_row': c['row'], 'table': 'HTML table 4', 'source_cells': c['cells'], 'nota': False} for c in recovered]
        after['candidate_count'] = count
        after['notes'].append(f'Malformed official HTML truncated the previous extraction to {record["candidate_count"]} candidate rows. Two independent HTML parsers agree on all {count} source candidate names, parties and votes after whitespace normalization. Their vote sum {valid:,} matches the printed valid-vote total. The source reports {electors:,} electors and {polled:,} votes polled. Original truncated rows and warnings remain preserved; this review does not establish an accepted contest.')
        after['html_source_review'] = {'source_sha256': source_sha, 'prior_sha256': prior,
            'parsers': ['lxml', 'html5lib'], 'electors': electors, 'votes_polled': polled, 'valid_candidate_votes': valid}
        new_body = json.dumps(after, ensure_ascii=False, indent=2).encode()
        sha = digest(new_body)
        entry.update(file=rid+'-'+sha[:16]+'.json', sha256=sha, candidate_count=count, note_count=len(after['notes']))
        new_path = PREFIX+entry['file']
        originals[prior_path], additions[new_path] = body, new_body
        reviews.append({'id': rid, 'prior_path': prior_path, 'prior_sha256': prior, 'path': new_path, 'sha256': sha})
    return old, json.dumps(index, ensure_ascii=False, indent=2).encode(), originals, additions, reviews


def build(root=ROOT):
    return build_package(root, NAME, *revised_files(root))


if __name__ == '__main__':
    print(json.dumps(build()))
