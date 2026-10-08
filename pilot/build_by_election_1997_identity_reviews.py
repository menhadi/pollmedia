"""Restore printed state identity without interpreting collection labels."""
import copy
from io import BytesIO
import json
import zipfile

from bs4 import BeautifulSoup
from build_by_election_2018_cached_reviews import ROOT, PREFIX, build_package, digest
from extract_by_elections import index_card

NAME = 'pollmedia-by-election-1997-identity-reviews-20261009'
PREDECESSOR = 'pollmedia-by-election-2002-html-recovery-20261009.zip'
PACKAGE_SHA = '3261d38b36280596573ea7c493bed6ad3d38d84e524b7d66d12127e9fe02275d'
INDEX_SHA = '4cfb55ef37d04a6bcdc9e741cd0b0aa1c93687b728232f6bf93fa9e5f18a6dbb'


def verified_identity(body, record):
    tables = []
    for number, table in enumerate(BeautifulSoup(body, 'html.parser').find_all('table'), 1):
        rows = [{'row': i, 'cells': [c.get_text(' ', strip=True) for c in tr.find_all(['td', 'th'], recursive=False)]}
                for i, tr in enumerate(table.find_all('tr'), 1)]
        tables.append({'name': f'HTML table {number}', 'rows': rows})
    parsed = index_card(tables, 1997)
    if (not parsed or not parsed.get('source_identity_heading') or not parsed.get('source_state_code')
            or parsed['kind'] != 'ac' or not parsed['state'] or parsed['candidates'] != record['candidates']
            or parsed['constituency'] != record['constituency'] or parsed.get('code') != record.get('code')):
        raise ValueError('Explicit source identity or candidate preservation differs')
    return parsed


def revised_files(root=ROOT):
    path = root/'exports'/PREDECESSOR
    if digest(path.read_bytes()) != PACKAGE_SHA:
        raise ValueError('Predecessor package differs')
    with zipfile.ZipFile(path) as outer, zipfile.ZipFile(BytesIO(outer.read('correction-index.zip'))) as inner:
        old = inner.read(PREFIX+'index.json')
    if digest(old) != INDEX_SHA:
        raise ValueError('Predecessor index differs')
    index = json.loads(old)
    targets = [e for e in index['records'] if e.get('year') == 1997 and e.get('state') is None]
    if len(targets) != 16:
        raise ValueError('Expected sixteen exact predecessor identity gaps')
    originals, additions, reviews = {}, {}, []
    folder = root/'application/storage/app/private'
    for entry in targets:
        rid, prior = entry['id'], entry['sha256']
        prior_path = PREFIX+entry['file']
        body = (folder/prior_path).read_bytes()
        if digest(body) != prior:
            raise ValueError('Prior record checksum differs')
        record = json.loads(body)
        source = folder/'election-by-elections'/record['edition']/record['source_file']
        source_body = source.read_bytes()
        if source.is_symlink() or digest(source_body) != record['source_sha256']:
            raise ValueError('Official HTML checksum differs')
        if record['year'] != 1997 or record['state'] is not None:
            raise ValueError('Prior record identity differs')
        parsed = verified_identity(source_body, record)
        after = copy.deepcopy(record)
        after['original_identity'] = {k: record.get(k) for k in ['kind', 'state']}
        after.update(state=parsed['state'], kind='ac', source_state_code=parsed['source_state_code'],
                     source_identity_heading=parsed['source_identity_heading'])
        after['notes'].append('The official source body explicitly states: '+parsed['source_identity_heading']+'. This resolves the earlier missing identity. Printed spelling and historical state labels are retained; directory names and present-day jurisdictions are not used to infer identity. Original warnings and candidate rows are preserved.')
        after['identity_source_review'] = {'source_sha256': record['source_sha256'], 'prior_sha256': prior}
        new_body = json.dumps(after, ensure_ascii=False, indent=2).encode()
        sha = digest(new_body)
        entry.update(state=after['state'], kind='ac', file=rid+'-'+sha[:16]+'.json', sha256=sha, note_count=len(after['notes']))
        new_path = PREFIX+entry['file']
        originals[prior_path], additions[new_path] = body, new_body
        reviews.append({'id': rid, 'prior_path': prior_path, 'prior_sha256': prior, 'path': new_path, 'sha256': sha})
    return old, json.dumps(index, ensure_ascii=False, indent=2).encode(), originals, additions, reviews


def build(root=ROOT):
    return build_package(root, NAME, *revised_files(root))


if __name__ == '__main__':
    print(json.dumps(build()))
