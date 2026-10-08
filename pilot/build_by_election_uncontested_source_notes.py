"""Explain two explicit uncontested source rows without assigning numeric votes."""
import copy
from io import BytesIO
import json
import zipfile

from build_by_election_2018_cached_reviews import ROOT, PREFIX, build_package, digest
from extract_assembly_modern import load_cells

NAME = 'pollmedia-by-election-uncontested-notes-20261009'
PREDECESSOR = 'pollmedia-by-election-piravom-source-review-20261009.zip'
PACKAGE_SHA = '55b05f20fea4f9cb23e25141c6499639961141b9a3d5cbe5ef3c391a45489519'
INDEX_SHA = 'c41b2738ab66bdb19cc6d0a24d74653311d238b6ee5e9e375aa7d68fc29a1f5b'
TARGETS = {
    '25594d70893ef627f5b3e5f7': ('4d18577a011663339c8174ca6c44da75b4f867c888c3733afe31e9a93551fb76',
        'fb6bf2b93c21c864b43dab4ed0db2f276a753e3a7033f6c5cac7769e9da0defc', ' 134-Allagadda', 41, 'Bhuma Akhila Priya', 'Y.S.R. (CP)'),
    '4c30ccb282b28c675687ba83': ('4b74c493e5243e7e710ea40069018875f33734b3dd8e8100fa100bd7bb111292',
        'f5fba0d11e868b7311379a2a9a384546916f5c7a6dd01a3b9fb95780ef45928f', '3', 37, 'Pema Khandu', 'INC'),
}


def verify_candidate(candidate, cells, row, sheet, name, party):
    if (candidate['name'] != name or candidate['party'] != party or candidate['votes'] is not None
            or candidate['raw_votes'] != 'Uncontested' or candidate['source_row'] != row
            or candidate['table'] != sheet or cells[1:4] != [name, party, 'Uncontested']):
        raise ValueError('Explicit uncontested candidate/source row differs')


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
    for rid, (prior, source_sha, sheet, row, name, party) in TARGETS.items():
        entries = [e for e in index['records'] if e['id'] == rid]
        if len(entries) != 1 or entries[0]['sha256'] != prior:
            raise ValueError('Target prior index differs')
        entry = entries[0]
        prior_path = PREFIX + entry['file']
        body = (folder / prior_path).read_bytes()
        if digest(body) != prior:
            raise ValueError('Target extraction differs')
        record = json.loads(body)
        source = folder / 'election-by-elections' / record['edition'] / record['source_file']
        if source.is_symlink() or record['source_sha256'] != source_sha or digest(source.read_bytes()) != source_sha:
            raise ValueError('Official source checksum differs')
        if len(record['candidates']) != 1:
            raise ValueError('Candidate inventory differs')
        book = load_cells(source)
        try:
            cells = next(s.values for s in book if s.title == sheet)[row-1]
            verify_candidate(record['candidates'][0], cells, row, sheet, name, party)
        finally:
            book.close()
        revised = copy.deepcopy(record)
        revised['notes'].append(f'The official workbook explicitly marks {name} ({party}) as Uncontested in worksheet {sheet.strip()}, row {row}. The missing numeric vote value represents this source label, not zero votes. No ordinary turnout or winning margin is inferred; original cells and extraction warning are preserved.')
        revised['uncontested_source_review'] = {'sheet': sheet, 'row': row, 'source_sha256': source_sha,
            'prior_sha256': prior, 'source_cells': cells, 'candidate': name, 'party_as_printed': party}
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
