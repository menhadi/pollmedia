"""Preserve five archived by-election discrepancies without guessing missing values."""
import copy
from io import BytesIO
import json
from pathlib import Path
import zipfile

from bs4 import BeautifulSoup
from build_by_election_2018_cached_reviews import ROOT, PREFIX, build_package, digest

NAME = 'pollmedia-by-election-2000-2007-source-notes-20261009'
PREDECESSOR = 'pollmedia-by-election-ambala-unheld-review-20261009.zip'
PACKAGE_SHA = '03c2cdd2311357236079a8278ea9ac5011ca3a6d5ce91dfdf4473e4e91aa7508'
INDEX_SHA = '11e58e2ad142fa106f1295347ebdd48702444620949ac6252373626692a92bd9'
TARGETS = json.loads(Path(__file__).with_name('by_election_2000_2007_review_targets.json').read_text())


def verified_source(body, record, target):
    soup = BeautifulSoup(body, 'html5lib')
    tables = soup.find_all('table')
    for candidate in record['candidates']:
        table = tables[int(candidate['table'].split()[-1])-1]
        row = table.find_all('tr')[candidate['source_row']-1]
        cells = [' '.join(c.get_text(' ', strip=True).split()) for c in row.find_all(['td', 'th'], recursive=False)]
        if cells != [' '.join(str(c).split()) for c in candidate['source_cells']]:
            raise ValueError('Candidate source cells differ')
    totals = []
    for row in soup.find_all('tr'):
        cells = [' '.join(c.get_text(' ', strip=True).split()) for c in row.find_all(['td', 'th'], recursive=False)]
        if (any(label in cells for label in ['VALID', 'TOTAL POLLED', 'CONTESTED', 'TOTAL'])
                or any('uncontested' in cell.lower() for cell in cells)):
            totals.append(cells)
    if totals != target['source_summary_rows']:
        raise ValueError('Printed source totals differ')
    if record['constituency'] == 'Tyui (ST)' and 'Declared Elected Uncontested' not in soup.get_text(' ', strip=True):
        raise ValueError('Explicit unopposed declaration absent')


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
                                              'source_summary_rows': target['source_summary_rows']}
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
