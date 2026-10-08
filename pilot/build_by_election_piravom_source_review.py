"""Correct a misclassified blank 2012 source form without losing its extraction."""
import copy
from io import BytesIO
import json
from pathlib import Path
import zipfile

from build_by_election_2018_cached_reviews import ROOT, PREFIX, build_package, digest
from extract_assembly_modern import load_cells
from extract_by_elections import index_card

NAME = 'pollmedia-by-election-piravom-source-review-20261009'
PREDECESSOR = 'pollmedia-by-election-2018-cached-reviews-20261009.zip'
PACKAGE_SHA = '210eed36988535926d008a1ba0b5e88e74ff99357539aa1c7ee7297ff88558e5'
INDEX_SHA = 'b65e0fbaf8e9ac3a01502c73e4064c24c0cd33845792ca532136ef16c947dcb2'
RID = '0383d684a1d03aacd068d89e'
PRIOR_SHA = 'aced9b41b14ac9716c758dd9d44461f97207d4ad5461b574b32a457cbe3ff2cd'
SOURCE_SHA = '37bc6822236d9d8d1f5c2c269bed0ba84c2fdccedc41bb06ce8610af221a0dae'
EDITION = '346e18e568df1c725387c03e'


def verified_sheet(rows):
    required = {(2, 1): 'BYE- ELECTION- 2012', (3, 3): 'KERALA STATE',
                (6, 1): 'Assembly Constituency- 85-Piravom LAC',
                (13, 5): 9, (18, 5): 183486, (23, 1): '17-3-2012',
                (23, 2): '21-3-2012', (27, 5): 158261,
                (40, 2): 'Candidate', (48, 1): 'Election Commission of India'}
    for (r, c), value in required.items():
        if rows[r-1][c-1] != value:
            raise ValueError('Piravom source identity or totals differ')
    if any(v not in [None, ''] for row in rows[40:43] for v in row[1:]):
        raise ValueError('Candidate result slots are no longer blank')
    table = {'name': 'Sheet1', 'rows': [{'row': i, 'cells': row} for i, row in enumerate(rows, 1)]}
    if index_card([table], 2009) is not None:
        raise ValueError('Blank source result was mapped as candidates')


def revised_files(root=ROOT):
    bundle = root / 'exports' / PREDECESSOR
    if digest(bundle.read_bytes()) != PACKAGE_SHA:
        raise ValueError('Held predecessor package differs')
    with zipfile.ZipFile(bundle) as outer, zipfile.ZipFile(BytesIO(outer.read('correction-index.zip'))) as inner:
        old = inner.read(PREFIX + 'index.json')
    if digest(old) != INDEX_SHA:
        raise ValueError('Predecessor index differs')
    index = json.loads(old)
    entries = [e for e in index['records'] if e['id'] == RID]
    if len(entries) != 1 or entries[0]['sha256'] != PRIOR_SHA:
        raise ValueError('Piravom predecessor reference differs')
    entry = entries[0]
    folder = root / 'application/storage/app/private'
    prior_path = PREFIX + entry['file']
    body = (folder / prior_path).read_bytes()
    if digest(body) != PRIOR_SHA:
        raise ValueError('Piravom preserved extraction differs')
    record = json.loads(body)
    if record['source_sha256'] != SOURCE_SHA or record['edition'] != EDITION or record['year'] != 2009:
        raise ValueError('Piravom archived source differs')
    source = folder / 'election-by-elections' / EDITION / (SOURCE_SHA + '.xls')
    if source.is_symlink() or digest(source.read_bytes()) != SOURCE_SHA:
        raise ValueError('Piravom workbook checksum differs')
    book = load_cells(source)
    try:
        rows = next(s.values for s in book if s.title == 'Sheet1')
        verified_sheet(rows)
    finally:
        book.close()
    if [c['name'] for c in record['candidates']] != ['NOMINATED', 'REJECTED', 'WITHDRAWN', 'CONTESTED', 'FORFEITED', 'GENERAL', 'SERVICE']:
        raise ValueError('Unexpected prior pseudo-candidates')
    after = copy.deepcopy(record)
    after['original_candidate_rows'] = copy.deepcopy(record['candidates'])
    after['original_extraction_fields'] = {k: record[k] for k in ['year', 'state', 'period', 'candidate_count', 'reported_contested']}
    after.update(year=2012, state='Kerala', period='Source sheet 2012; collection labelled Jan-Dec 2009',
                 candidates=[], candidate_count=0, reported_contested=9)
    after['notes'] += ['The workbook is filed under a 2009 collection, but this worksheet explicitly says 2012 and Kerala. Its candidate result slots are blank. Seven labels from a repeated blank form were previously extracted as candidate names; those original rows are preserved separately. No winner, margin or complete candidate list is established.',
                       'The source reports nine contested candidates, 183,486 electors and 158,261 voters. Polling is printed as 17-3-2012 and counting as 21-3-2012; these values are retained as printed, without silently correcting dates.']
    after['source_identity_review'] = {'sheet': 'Sheet1', 'source_sha256': SOURCE_SHA, 'prior_sha256': PRIOR_SHA,
                                      'source_rows': {str(i): rows[i-1] for i in [2,3,6,13,18,22,23,27,40,41,42,43,48]}}
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode()
    new_sha = digest(new_body)
    entry.update(year=2012, state='Kerala', period=after['period'], candidate_count=0,
                 note_count=len(after['notes']), file=RID+'-'+new_sha[:16]+'.json', sha256=new_sha)
    new_path = PREFIX + entry['file']
    return old, json.dumps(index, ensure_ascii=False, indent=2).encode(), {prior_path: body}, {new_path: new_body}, [
        {'id': RID, 'prior_path': prior_path, 'prior_sha256': PRIOR_SHA, 'path': new_path, 'sha256': new_sha}]


def build(root=ROOT):
    return build_package(root, NAME, *revised_files(root))


if __name__ == '__main__':
    print(json.dumps(build()))
