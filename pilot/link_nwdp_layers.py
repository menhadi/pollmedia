#!/usr/bin/env python3
"""Link ingested NWDP/SOI village polygons to LGD villages with evidence.

Strict order: unique village code inside the same state and district, then a
unique normalized name inside the same sub-district. No fuzzy, proximity or
cross-district matching. Every link records its evidence and basis.
"""
import argparse
import hashlib
import json
import re
import sqlite3
import unicodedata
from pathlib import Path

ALIASES = {
    'lahul and spiti': 'lahaul and spiti',
    'leh ladakh': 'leh ladakh',
}


def norm(value):
    text = unicodedata.normalize('NFKD', (value or '')).casefold()
    return ' '.join(re.sub(r'[^a-z0-9]+', ' ', text).split())


def code_key(value):
    text = str(value or '').strip()
    return str(int(text)) if text.isdigit() else text


def district_key(name):
    key = norm(name)
    return ALIASES.get(key, key)


STATE_ALIASES = {
    'dadra and nagar haveli': '38',
    'daman and diu': '38',
    'dadra and nagar haveli and daman and diu': '38',
}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('application', type=Path)
    parser.add_argument('--states', help='comma separated catalogue state names')
    parser.add_argument('--manifest', type=Path, help='append manifest produced by append_nwdp_gaps.py')
    parser.add_argument('--prefix', default='maps/nwdp-')
    args = parser.parse_args()

    app = args.application
    catalogue = json.loads((app / 'storage/app/india-map/catalogue.json').read_text())
    if args.manifest:
        manifest = json.loads(args.manifest.read_text())
        by_file = {layer['file']: layer for layer in catalogue['layers']}
        plan = [(by_file[entry['file']], entry['appended'], entry['sha256']) for entry in manifest['files']]
    else:
        wanted = {name.strip() for name in (args.states or '').split(',') if name.strip()}
        plan = [(layer, None, layer['sha256']) for layer in catalogue['layers']
                if layer['file'].startswith(args.prefix) and layer['state'] in wanted]
    if not plan:
        raise SystemExit('no matching layers')

    links = sqlite3.connect(app / 'storage/app/electoral-villages/links.sqlite')
    states = {row[1]: row[0] for row in links.execute('select code,name from states')}
    directory = list(links.execute('select lgd,state,district,subdistrict,name,census2011 from directory'))
    linked_lgds = set()
    for (lgd,) in links.execute('select lgd from shapes where lgd is not null'):
        linked_lgds.add(str(lgd))
    supplement = sqlite3.connect(app / 'storage/app/electoral-villages/display-links.sqlite')
    for (lgd,) in supplement.execute('select lgd from shapes where lgd is not null'):
        linked_lgds.add(str(lgd))

    by_code = {}
    by_name = {}
    for lgd, state, district, subdistrict, name, census in directory:
        key_district = district_key(district)
        for code in {code_key(census), code_key(lgd)}:
            if code and code != '0':
                by_code.setdefault((state, key_district, code), []).append((lgd, name, subdistrict))
        by_name.setdefault((state, key_district, norm(subdistrict), norm(name)), []).append((lgd, name))

    def state_code(name):
        if norm(name) in STATE_ALIASES:
            return STATE_ALIASES[norm(name)]
        for state_name, code in states.items():
            if norm(state_name) == norm(name):
                return code
        return None

    written = {'code': 0, 'name': 0, 'skipped_linked': 0, 'unmatched': 0}
    for layer, indices, sha in plan:
        state = state_code(layer['state'])
        if state is None:
            print('skip state', layer['state'])
            continue
        file = layer['file']
        path = app / 'storage/app/india-map/snapshot' / file
        if not path.exists():
            path = app / 'public' / file
        geometry = json.loads(path.read_text())
        supplement.execute('INSERT OR REPLACE INTO layers VALUES (?,?,?,?)',
                           (file, sha, layer['source'], (layer.get('metadata') or {}).get('source_edition')))
        features = geometry['features']
        targets = range(len(features)) if indices is None else [i for i in indices if 0 <= i < len(features)]
        for index in targets:
            feature = features[index]
            properties = feature['properties']
            code = str(properties.get('source_code') or '')
            district = district_key(properties.get('district') or layer['district'])
            candidates = by_code.get((state, district, code_key(code)), []) if code.isdigit() else []
            method = 'unique village code within district'
            if len(candidates) != 1:
                candidates = by_name.get((state, district, norm(properties.get('subdistrict')), norm(properties.get('name'))), [])
                method = 'unique normalized name within sub-district'
            if len(candidates) != 1:
                written['unmatched'] += 1
                continue
            lgd = str(candidates[0][0])
            if lgd in linked_lgds:
                written['skipped_linked'] += 1
                continue
            basis = (f'NWDP/SOI village boundary linked by {method}: village code {code}, '
                     f"source name '{properties.get('name')}', source sub-district '{properties.get('subdistrict')}'. "
                     'Boundary edition unverified.')
            supplement.execute('INSERT OR REPLACE INTO shapes VALUES (?,?,?,?,?,?)',
                               (file, index, lgd, state, basis, sha))
            proof = {
                'source_url': (layer.get('metadata') or {}).get('source'),
                'source_sha256': (layer.get('metadata') or {}).get('source_sha256'),
                'layer_sha256': sha,
                'match_method': method,
                'source_feature': {'name': properties.get('name'), 'code': code, 'subdistrict': properties.get('subdistrict')},
                'lgd_village': {'lgd': lgd, 'name': candidates[0][1]},
                'basis': basis,
            }
            supplement.execute('INSERT OR REPLACE INTO evidence VALUES (?,?,?)', (file, index, json.dumps(proof)))
            linked_lgds.add(lgd)
            written['code' if 'code' in method else 'name'] += 1
        print(f"{file}: {len(geometry['features'])} features")

    metadata = json.loads(supplement.execute('SELECT json FROM metadata').fetchone()[0])
    metadata.setdefault('nwdp_ingested_layers', [])
    metadata['nwdp_ingested_layers'] = sorted({*metadata['nwdp_ingested_layers'], *[layer['file'] for layer, _, _ in plan]})
    metadata['nwdp_linked_shapes'] = metadata.get('nwdp_linked_shapes', 0) + written['code'] + written['name']
    metadata['total_display_shapes'] = supplement.execute('select count(*) from shapes').fetchone()[0]
    supplement.execute('DELETE FROM metadata')
    supplement.execute('INSERT INTO metadata VALUES (?)', (json.dumps(metadata),))
    supplement.commit()
    supplement.close()
    print(json.dumps(written, indent=1))


if __name__ == '__main__':
    main()
