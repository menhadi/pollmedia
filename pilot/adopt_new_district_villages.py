#!/usr/bin/env python3
"""Adopt post-2011 village codes into polygons that already exist.

For a new district, the official parent district(s) are taken from the
reorganisation notification. A new LGD village is only adopted when:
  * its name is unique among the parent districts' 2011 villages;
  * that 2011 village already has a polygon;
  * the polygon's own source name matches the village name.
The link is recorded as a code alias: the polygon is not duplicated.
"""
import argparse
import json
import re
import sqlite3
import unicodedata
from datetime import datetime, timezone
from pathlib import Path


def norm(value):
    text = unicodedata.normalize('NFKD', (value or '')).casefold()
    return ' '.join(re.sub(r'[^a-z0-9]+', ' ', text).split())


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('application', type=Path)
    parser.add_argument('parents', type=Path)
    parser.add_argument('--district', required=True)
    args = parser.parse_args()

    app = args.application
    storage = app / 'storage/app'
    config = json.loads(args.parents.read_text())
    entry = next((row for row in config['districts'] if row['district'] == args.district), None)
    if entry is None:
        raise SystemExit(f'no parent entry for {args.district}')
    state = entry['state_code']
    parents = set(entry['parents']) | {entry['district']}

    catalogue = json.loads((storage / 'india-map/catalogue.json').read_text())
    file_by_id = {layer['id']: layer['file'] for layer in catalogue['layers']}
    places = sqlite3.connect(storage / 'india-map/villages.sqlite')
    places.execute('PRAGMA query_only = ON')

    links = sqlite3.connect(storage / 'electoral-villages/links.sqlite')
    links.execute(f"ATTACH DATABASE '{storage}/electoral-villages/display-links.sqlite' AS dl")
    linked = set()
    shapes_by_lgd = {}
    for file, index, lgd in links.execute('select file,feature_index,lgd from shapes where lgd is not null'):
        linked.add(str(lgd))
        shapes_by_lgd.setdefault(str(lgd), []).append((file, int(index)))
    for file, index, lgd in links.execute('select file,feature_index,lgd from dl.shapes where lgd is not null'):
        linked.add(str(lgd))
        shapes_by_lgd.setdefault(str(lgd), []).append((file, int(index)))

    def polygon_names(code):
        names = []
        for file, index in shapes_by_lgd.get(str(code), []):
            layer_id = next((key for key, value in file_by_id.items() if value == file), None)
            if not layer_id:
                continue
            row = places.execute('select name from places where layer=? and feature_index=?', (layer_id, index)).fetchone()
            if row:
                names.append(row[0])
        return names

    rows = list(links.execute('select lgd,district,subdistrict,name,census2011 from directory where state=?', (state,)))
    pool = [row for row in rows if row[1] in parents and (row[4] or '0') != '0']
    by_name = {}
    by_name_sub = {}
    for row in pool:
        by_name.setdefault(norm(row[3]), []).append(row)
        by_name_sub.setdefault((norm(row[2]), norm(row[3])), []).append(row)

    targets = [row for row in rows if row[1] == entry['district'] and (row[4] or '0') == '0' and str(row[0]) not in linked]
    if targets:
        placeholders = ','.join(['?'] * len(targets))
        links.execute('DELETE FROM code_aliases WHERE new_lgd IN (' + placeholders + ')',
                      [str(row[0]) for row in targets])
    decisions = []
    stats = {'targets': len(targets), 'matched': 0, 'adopted': 0, 'name_mismatch': 0,
             'parent_unlinked': 0, 'ambiguous': 0, 'no_namesake': 0}
    links.execute('CREATE TABLE IF NOT EXISTS code_aliases(new_lgd TEXT PRIMARY KEY, canonical_lgd TEXT NOT NULL, '
                  'basis TEXT, source_url TEXT, created_at TEXT)')
    for lgd, district, subdistrict, name, census in targets:
        hits = by_name_sub.get((norm(subdistrict), norm(name)), [])
        method = 'unique name and sub-district inside the official parent districts'
        if len(hits) != 1:
            hits = by_name.get(norm(name), [])
            method = 'unique name inside the official parent districts'
        if len(hits) > 1:
            stats['ambiguous'] += 1
            continue
        if len(hits) == 0:
            stats['no_namesake'] += 1
            continue
        canonical = hits[0]
        stats['matched'] += 1
        row = {
            'new_lgd': str(lgd), 'name': name, 'subdistrict': subdistrict,
            'canonical_lgd': str(canonical[0]), 'canonical_name': canonical[3],
            'canonical_district': canonical[1], 'canonical_census2011': str(canonical[4]),
            'method': method, 'canonical_linked': str(canonical[0]) in linked,
        }
        decisions.append(row)
        if not row['canonical_linked']:
            stats['parent_unlinked'] += 1
            continue
        names = polygon_names(canonical[0])
        if not any(norm(polygon) == norm(canonical[3]) or norm(polygon) == norm(name) for polygon in names):
            stats['name_mismatch'] += 1
            row['polygon_names'] = names
            continue
        basis = (f"Adopted by official district reorganisation: {entry['district']} formed from {', '.join(entry['parents'])} "
                 f"({entry['notification']['title']}). New LGD code {lgd} matches 2011 village {canonical[0]} "
                 f"'{canonical[3]}' in {canonical[1]} by {method}; the polygon name matches. "
                 'The polygon already exists under the 2011 code.')
        links.execute('INSERT OR REPLACE INTO code_aliases VALUES (?,?,?,?,?)',
                      (str(lgd), str(canonical[0]), basis, entry['notification']['url'],
                       datetime.now(timezone.utc).isoformat()))
        stats['adopted'] += 1

    links.commit()
    links.close()

    supplement_path = storage / 'electoral-villages/display-links.sqlite'
    supplement = sqlite3.connect(supplement_path)
    metadata = json.loads(supplement.execute('SELECT json FROM metadata').fetchone()[0])
    metadata['original_links_sha256'] = __import__('hashlib').sha256((storage / 'electoral-villages/links.sqlite').read_bytes()).hexdigest()
    supplement.execute('DELETE FROM metadata')
    supplement.execute('INSERT INTO metadata VALUES (?)', (json.dumps(metadata),))
    supplement.commit()
    supplement.close()
    output = app.parent / 'pilot/data' / f"adoptions-{entry['district'].lower().replace(' ', '-')}.json"
    output.write_text(json.dumps({'district': entry['district'], 'parents': entry['parents'],
                                  'notification': entry['notification'], 'stats': stats,
                                  'decisions': decisions}, indent=1))
    print(json.dumps(stats, indent=1))
    print('written', output)


if __name__ == '__main__':
    main()
