"""Keep verified workbook values alongside unresolved printed discrepancies."""
import copy
from io import BytesIO
import json
from pathlib import Path
import zipfile

import openpyxl
from build_by_election_2018_cached_reviews import ROOT, PREFIX, build_package, digest

NAME = 'pollmedia-by-election-2017-2018-source-notes-20261009'
PREDECESSOR = 'pollmedia-by-election-2000-2007-source-notes-20261009.zip'
PACKAGE_SHA = 'b15d3a456a5ec0c42a87a42a2a3fb9cdf838c095c1fdc01b3f79f54393e7efad'
INDEX_SHA = '2c9a63cb8e3f09ecb5ee1d744adec98043955afff09aa94f2c0cf9be84faf527'
TARGETS = json.loads(Path(__file__).with_name('by_election_2017_2018_review_targets.json').read_text())


def verified_source(body, record, target):
    book = openpyxl.load_workbook(BytesIO(body), read_only=True, data_only=False)
    try:
        rows = [list(row) for row in book[target['sheet']].values]
        for candidate in record['candidates']:
            if candidate['table'] != target['sheet'] or rows[candidate['source_row']-1] != candidate['source_cells']:
                raise ValueError('Candidate source cells differ')
        for number, expected in target['source_review_rows'].items():
            actual = json.loads(json.dumps(rows[int(number)-1], default=str))
            if actual != expected:
                raise ValueError('Printed source review row differs')
    finally:
        book.close()


def revised_files(root=ROOT):
    path = root/'exports'/PREDECESSOR
    if digest(path.read_bytes()) != PACKAGE_SHA:
        raise ValueError('Predecessor package differs')
    with zipfile.ZipFile(path) as outer, zipfile.ZipFile(BytesIO(outer.read('correction-index.zip'))) as inner:
        old = inner.read(PREFIX+'index.json')
    if digest(old) != INDEX_SHA:
        raise ValueError('Predecessor index differs')
    index = json.loads(old)
    originals, additions, reviews = {}, {}, []
    folder = root/'application/storage/app/private'
    for target in TARGETS:
        rid, prior = target['id'], target['prior_sha256']
        entries = [e for e in index['records'] if e['id'] == rid]
        if len(entries) != 1 or entries[0]['sha256'] != prior:
            raise ValueError('Prior index differs')
        entry = entries[0]
        prior_path = PREFIX+entry['file']
        body = (folder/prior_path).read_bytes()
        if digest(body) != prior:
            raise ValueError('Prior extraction differs')
        record = json.loads(body)
        source = folder/'election-by-elections'/record['edition']/record['source_file']
        source_body = source.read_bytes()
        if source.is_symlink() or digest(source_body) != target['source_sha256'] or record['source_sha256'] != target['source_sha256']:
            raise ValueError('Official source checksum differs')
        verified_source(source_body, record, target)
        revised = copy.deepcopy(record)
        revised['notes'].append(target['note'])
        revised['source_discrepancy_review'] = {'prior_sha256': prior, 'source_sha256': target['source_sha256'],
                                              'sheet': target['sheet'], 'source_review_rows': target['source_review_rows']}
        new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode()
        sha = digest(new_body)
        entry.update(file=rid+'-'+sha[:16]+'.json', sha256=sha, note_count=len(revised['notes']))
        new_path = PREFIX+entry['file']
        originals[prior_path], additions[new_path] = body, new_body
        reviews.append({'id': rid, 'prior_path': prior_path, 'prior_sha256': prior, 'path': new_path, 'sha256': sha})
    return old, json.dumps(index, ensure_ascii=False, indent=2).encode(), originals, additions, reviews


def build(root=ROOT):
    return build_package(root, NAME, *revised_files(root))


if __name__ == '__main__':
    print(json.dumps(build()))
