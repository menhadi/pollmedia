"""Separate an explicit unheld election from unassigned historical source rows."""
import copy
from io import BytesIO
import json
import zipfile

import openpyxl

from build_by_election_2018_cached_reviews import ROOT, PREFIX, build_package, digest

NAME = 'pollmedia-by-election-ambala-unheld-review-20261009'
PREDECESSOR = 'pollmedia-by-election-historical-placeholder-reviews-20261009.zip'
PACKAGE_SHA = '32b3d3ef2002f15b87375ed2758c9945fc9c4ffdf8c48b55768f7427f198b0fb'
INDEX_SHA = '3ecb490fde33b1288946c40f5ba55dc68bdce5e7b2f53833d88ad7d98b1b2169'
RID = '1869d3231155486cf9a1c778'
PRIOR_SHA = '8c11639f0abdf3ae3c25d42263c59f3b5e5b808c09244bf13628f20790231648'
SOURCE_SHA = 'd2471c1cb7fe962eeb56791f2025463656696fc7d0a08ffe4a254be089bfa680'


def reviewed_record(record, rows):
    first, second, third, fourth = rows
    if first[:10] != ['Haryana', 1, 1995, '1-Ambala ', None, None, 'Election not held.', None, None, None]:
        raise ValueError('Explicit unheld-election source statement differs')
    if [second[0], third[0], fourth[0]] != ['Bihar', 'Maharashtra', None]:
        raise ValueError('Following state labels differ')
    if any(row[3] is not None for row in rows[1:]):
        raise ValueError('Previously unassigned rows now have constituency identity')
    if len(record['candidates']) != 4:
        raise ValueError('Prior candidate rows differ')
    for candidate, row, number in zip(record['candidates'], rows, range(1400, 1404), strict=True):
        if (candidate['source_cells'] != row or candidate['source_row'] != number
                or candidate['table'] != 'Lok sabha' or candidate['votes'] is not None):
            raise ValueError('Original extraction differs from source rows')
    revised = copy.deepcopy(record)
    revised['original_candidate_rows'] = copy.deepcopy(record['candidates'])
    revised['original_candidate_count'] = record['candidate_count']
    revised.update(candidates=[], candidate_count=0)
    revised['notes'].append(
        'Official historical workbook, Lok sabha row 1400: Haryana, 1995, 1-Ambala, '
        '"Election not held." This is a source statement, not a candidate or winner. '
        'Rows 1401-1403 contain Lovely Singh (Bihar), Baba Saheb Thide '
        '(Maharashtra) and M.D. Raosulke (blank state), with no constituency or vote values. '
        'Those rows cannot establish Ambala candidates or winners and remain unassigned. '
        'All original extracted rows and warnings are preserved. No winner, turnout, margin '
        'or accepted contest is inferred.')
    revised['unheld_election_source_review'] = {
        'source_sha256': SOURCE_SHA, 'prior_sha256': PRIOR_SHA,
        'source_statement': first[6],
        'source_rows': {str(number): row for number, row in zip(range(1400, 1404), rows, strict=True)},
        'unassigned_source_rows': copy.deepcopy(record['candidates'][1:]),
    }
    return revised


def revised_files(root=ROOT):
    path = root/'exports'/PREDECESSOR
    if digest(path.read_bytes()) != PACKAGE_SHA:
        raise ValueError('Predecessor package differs')
    with zipfile.ZipFile(path) as outer, zipfile.ZipFile(BytesIO(outer.read('correction-index.zip'))) as inner:
        old = inner.read(PREFIX+'index.json')
    if digest(old) != INDEX_SHA:
        raise ValueError('Predecessor index differs')
    index = json.loads(old)
    entries = [e for e in index['records'] if e['id'] == RID]
    if len(entries) != 1 or entries[0]['sha256'] != PRIOR_SHA:
        raise ValueError('Prior index differs')
    entry = entries[0]
    folder = root/'application/storage/app/private'
    prior_path = PREFIX+entry['file']
    body = (folder/prior_path).read_bytes()
    if digest(body) != PRIOR_SHA:
        raise ValueError('Prior extraction differs')
    record = json.loads(body)
    source = folder/'election-by-elections'/record['edition']/record['source_file']
    if source.is_symlink() or digest(source.read_bytes()) != SOURCE_SHA or record['source_sha256'] != SOURCE_SHA:
        raise ValueError('Official workbook checksum differs')
    book = openpyxl.load_workbook(source, read_only=True, data_only=False)
    try:
        rows = [list(row) for row in book['Lok sabha'].iter_rows(min_row=1400, max_row=1403, values_only=True)]
    finally:
        book.close()
    revised = reviewed_record(record, rows)
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode()
    sha = digest(new_body)
    entry.update(file=RID+'-'+sha[:16]+'.json', sha256=sha, candidate_count=0, note_count=len(revised['notes']))
    new_path = PREFIX+entry['file']
    return old, json.dumps(index, ensure_ascii=False, indent=2).encode(), {prior_path: body}, {new_path: new_body}, [
        {'id': RID, 'prior_path': prior_path, 'prior_sha256': PRIOR_SHA, 'path': new_path, 'sha256': sha}]


def build(root=ROOT):
    return build_package(root, NAME, *revised_files(root))


if __name__ == '__main__':
    print(json.dumps(build()))
