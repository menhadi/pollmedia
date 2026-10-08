"""Retain conflicting printed totals and explain five archived source discrepancies."""
import copy
from io import BytesIO
import json
import zipfile

from build_by_election_2018_cached_reviews import ROOT, PREFIX, build_package, digest
from extract_assembly_modern import load_cells

NAME = 'pollmedia-by-election-2009-2010-discrepancy-notes-20261009'
PREDECESSOR = 'pollmedia-by-election-uncontested-notes-20261009.zip'
PACKAGE_SHA = '4ff981c35b4eff57a499b8305a85c57d3eb80b0cac2c82dd3531dec65f635c85'
INDEX_SHA = '02c7e5a0d769b3832f4b35f0a0acf02ccec4fc4b3d6ef6061827a34b74bbcef8'
TARGETS = {
    'f02e4fa5f222dfe74f2da6f8': ('0f2008d9ff5fbee94aa48dee663b1519e4357c880687378863efbd2c12c2942e', 130913, 130940, 130940),
    '58a7518c8d8bf04202abe2ba': ('2c070eec4b4fbaf3a70a0dee96e474dc7816eafe04e881f52a908e74e764ac96', 124245, 124214, 124214),
    'e6840b78fd53ac6f1de311e7': ('5530391d5b110b2d83514aa0b6c28ea9d624375186384dc9ca4274cf71deb817', 201627, 201927, 201936),
    'ef020344cd792cfc35453717': ('88ef5150606d13ebdf8074adaee6ca8bdff88864945357a9aadc53d5be4cbf10', 134866, 134869, 134869),
    '5bddc677ae80b1b3fb48502f': ('5455ec8ae476187b2c65607a29e3fa4849aa54f07b88d9fe2c4a9d8ad6f7f156', 132442, 132422, 132425),
}


def verify_rows(record, rows, expected_sum, expected_valid, expected_polled):
    for candidate in record['candidates']:
        if (rows[candidate['source_row']-1] != candidate['source_cells']
                or candidate['votes'] != candidate['raw_votes'] or candidate['votes'] is None):
            raise ValueError('Candidate source cells differ')
    if (sum(c['votes'] for c in record['candidates'] if not c.get('nota')) != expected_sum
            or record['reported_candidate_total'] != expected_valid
            or rows[28][4] != expected_valid or rows[27][4] != expected_polled
            or rows[25][4] != expected_polled):
        raise ValueError('Printed discrepancy differs')
    totals = [r for r in rows if len(r) > 3 and str(r[1]).strip().lower() == 'total' and r[3] == expected_valid]
    if len(totals) != 1:
        raise ValueError('Detailed candidate total not uniquely matched')


def revised_files(root=ROOT):
    path = root / 'exports' / PREDECESSOR
    if digest(path.read_bytes()) != PACKAGE_SHA:
        raise ValueError('Predecessor bundle differs')
    with zipfile.ZipFile(path) as outer, zipfile.ZipFile(BytesIO(outer.read('correction-index.zip'))) as inner:
        old = inner.read(PREFIX + 'index.json')
    if digest(old) != INDEX_SHA:
        raise ValueError('Predecessor index differs')
    index = json.loads(old)
    originals, additions, reviews = {}, {}, []
    folder = root / 'application/storage/app/private'
    for rid, (prior, candidate_sum, valid, polled) in TARGETS.items():
        matches = [e for e in index['records'] if e['id'] == rid]
        if len(matches) != 1 or matches[0]['sha256'] != prior:
            raise ValueError('Target index differs')
        entry = matches[0]
        prior_path = PREFIX + entry['file']
        body = (folder / prior_path).read_bytes()
        if digest(body) != prior:
            raise ValueError('Target record differs')
        record = json.loads(body)
        source = folder / 'election-by-elections' / record['edition'] / record['source_file']
        if source.is_symlink() or digest(source.read_bytes()) != record['source_sha256']:
            raise ValueError('Official source checksum differs')
        sheet = record['candidates'][0]['table']
        book = load_cells(source)
        try:
            rows = next(s.values for s in book if s.title == sheet)
            verify_rows(record, rows, candidate_sum, valid, polled)
        finally:
            book.close()
        revised = copy.deepcopy(record)
        note = (f'Official worksheet {sheet}: the preserved candidate rows sum to {candidate_sum:,}, '
                f'while both the printed valid-vote total and detailed-result total are {valid:,}; '
                f'printed votes polled are {polled:,}. The difference is present in the official workbook. '
                'All source values are retained; no balancing votes, corrected winner or margin are inferred.')
        if candidate_sum > polled:
            note += f' The candidate sum also exceeds printed votes polled by {candidate_sum-polled:,}.'
        revised['notes'].append(note)
        revised['source_total_review'] = {'sheet': sheet, 'source_sha256': record['source_sha256'], 'prior_sha256': prior,
            'candidate_sum': candidate_sum, 'printed_valid': valid, 'printed_polled': polled,
            'source_rows': {str(i): rows[i-1] for i in [26,28,29]}}
        new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode()
        sha = digest(new_body)
        entry.update(file=rid+'-'+sha[:16]+'.json', sha256=sha, note_count=len(revised['notes']))
        new_path = PREFIX + entry['file']
        originals[prior_path], additions[new_path] = body, new_body
        reviews.append({'id': rid, 'prior_path': prior_path, 'prior_sha256': prior, 'path': new_path, 'sha256': sha})
    return old, json.dumps(index, ensure_ascii=False, indent=2).encode(), originals, additions, reviews


def build(root=ROOT):
    return build_package(root, NAME, *revised_files(root))


if __name__ == '__main__':
    print(json.dumps(build()))
