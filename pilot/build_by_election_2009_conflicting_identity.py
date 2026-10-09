"""Withhold a conflicting constituency identity while preserving the source claim."""
import copy
from io import BytesIO
import json
import zipfile

from extract_assembly_modern import load_cells

from build_by_election_2018_cached_reviews import ROOT, PREFIX, build_package, digest

NAME = 'pollmedia-by-election-2009-conflicting-identity-20261009'
PREDECESSOR = 'pollmedia-by-election-2017-2018-source-notes-20261009.zip'
PACKAGE_SHA = 'e8d2f28ada4f09925dd35ed36f0afa80f74380a2b580d8e5c666b6b4c0971c4c'
INDEX_SHA = 'b3d0f07d7b0e34864c89dd8166f6bb0f16759ed515f79474ce152ca0661b2573'
RID = 'f3c9ec8d6a92a6f296a79384'
PRIOR_SHA = 'ed0beaaecfbfb4692b663418d97b5ca726860890196330cb310d3aaabe336a60'
SOURCE_SHA = '087b21fb1eb56c368f6667010974d5440afa6f1d9f57ce8ce48e51ee58b438b7'


def reviewed_record(record, sheets):
    for name in ['105bh', '111AC']:
        rows = sheets[name]
        if (' '.join(rows[3][0].split()) != 'Legislative Assembly of- Bihar'
                or ' '.join(rows[4][0].split()) != 'Number and name Assembly Constituency - 105-Begusarai'):
            raise ValueError('Conflicting printed identity headings differ')
    if sheets['105bh'][11][-1] != 11 or sheets['105bh'][28][-1] != 88540:
        raise ValueError('Comparison sheet totals differ')
    if sheets['111AC'][11][2:] != [4, 0, 2] or sheets['111AC'][28][-1] != 146935:
        raise ValueError('Conflicting sheet totals differ')
    for candidate in record['candidates']:
        if candidate['table'] != '111AC' or candidate['source_cells'] != sheets['111AC'][candidate['source_row']-1]:
            raise ValueError('Candidate source cells differ')
    revised = copy.deepcopy(record)
    revised['original_identity_fields'] = {key: record[key] for key in ['state', 'constituency', 'code']}
    revised.update(state=None, constituency=None, code=None)
    revised['notes'].append(
        'Source identity conflict: worksheets 105bh and 111AC both print Bihar / 105-Begusarai, '
        'but contain different candidate lists and totals. Sheet 105bh reports 11 candidates and '
        '88,540 valid votes; this sheet, 111AC, lists four candidates summing to 146,935, while '
        'its contested total is 2 (male 4, female 0). Its claimed identity is preserved separately '
        'and withheld from constituency grouping pending an official correction. All candidate '
        'rows and prior warnings remain unchanged. No alternate jurisdiction, winner, margin '
        'or accepted contest is inferred from worksheet labels or candidate names.')
    revised['conflicting_identity_review'] = {
        'prior_sha256': PRIOR_SHA, 'source_sha256': SOURCE_SHA,
        'comparison_record_id': 'f454d5825de6d74bf762eada',
        'source_rows': {name: {str(i): sheets[name][i-1] for i in [4, 5, 12, 29]}
                        for name in ['105bh', '111AC']},
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
    book = load_cells(source)
    try:
        sheets = {sheet.title: list(sheet.values) for sheet in book if sheet.title in ['105bh', '111AC']}
    finally:
        book.close()
    revised = reviewed_record(record, sheets)
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode()
    sha = digest(new_body)
    entry.update(file=RID+'-'+sha[:16]+'.json', sha256=sha, state=None, constituency=None, note_count=len(revised['notes']))
    new_path = PREFIX+entry['file']
    return old, json.dumps(index, ensure_ascii=False, indent=2).encode(), {prior_path: body}, {new_path: new_body}, [
        {'id': RID, 'prior_path': prior_path, 'prior_sha256': PRIOR_SHA, 'path': new_path, 'sha256': sha}]


def build(root=ROOT):
    return build_package(root, NAME, *revised_files(root))


if __name__ == '__main__':
    print(json.dumps(build()))
