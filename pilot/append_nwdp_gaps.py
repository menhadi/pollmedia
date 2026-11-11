#!/usr/bin/env python3
"""Append newly available NWDP villages to existing district layers.

Existing features keep their exact order, so every recorded link (file,
feature_index) stays valid. New features are appended at the end with their
source attributes. A manifest records the appended indices for linking.
"""
import argparse
import hashlib
import json
import re
import sqlite3
import unicodedata
from pathlib import Path

from pyproj import CRS, Transformer
from shapely.geometry import mapping, shape
from shapely.ops import transform

FOREST = re.compile(r'forest|\bR\.?\s?F\.?\b|\bP\.?\s?F\.?\b', re.I)


def norm(value):
    text = unicodedata.normalize('NFKD', (value or '')).casefold()
    return ' '.join(re.sub(r'[^a-z0-9]+', ' ', text).split())


def code_key(value):
    text = str(value or '').strip()
    return str(int(text)) if text.isdigit() else text


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('application', type=Path)
    parser.add_argument('geojson', type=Path)
    parser.add_argument('--state-name', required=True)
    parser.add_argument('--state-code', required=True)
    parser.add_argument('--source-date', default=None)
    parser.add_argument('--crs-file', type=Path, required=True)
    parser.add_argument('--simplify-metres', type=float, default=15)
    parser.add_argument('--manifest', type=Path, required=True)
    args = parser.parse_args()

    app = args.application
    catalogue = json.loads((app / 'storage/app/india-map/catalogue.json').read_text())
    base_layers = {}
    for layer in catalogue['layers']:
        if layer['file'].startswith('maps/nwdp-'):
            continue
        base_layers[(layer['state'], layer['district'])] = layer

    links = sqlite3.connect(app / 'storage/app/electoral-villages/links.sqlite')
    directory = list(links.execute(
        'select lgd,district,subdistrict,name,census2011 from directory where state=?', (args.state_code,)))
    linked = set()
    for (value,) in links.execute('select lgd from shapes where lgd is not null'):
        linked.add(str(value))
    links.execute(f"ATTACH DATABASE '{app}/storage/app/electoral-villages/display-links.sqlite' AS dl")
    for (value,) in links.execute('select lgd from dl.shapes where lgd is not null'):
        linked.add(str(value))

    by_code = {}
    for lgd, district, subdistrict, name, census in directory:
        for code in {code_key(census), code_key(lgd)}:
            if code and code != '0':
                by_code.setdefault(code, []).append((lgd, district, subdistrict, name))

    source_crs = json.loads(args.crs_file.read_text())['source_crs']
    projector = Transformer.from_crs(CRS.from_wkt(source_crs), CRS.from_epsg(4326), always_xy=True)
    features = json.loads(args.geojson.read_text())['features']

    grouped = {}
    stats = {'matched_unlinked': 0, 'already_linked': 0, 'unmatched': 0, 'no_base_layer': 0, 'invalid': 0}
    for feature in features:
        properties = feature.get('properties') or {}
        candidates = by_code.get(code_key(properties.get('vlcode')), [])
        if len(candidates) > 1:
            candidates = [row for row in candidates if norm(row[3]) == norm(properties.get('village'))]
        if len(candidates) != 1:
            stats['unmatched'] += 1
            continue
        lgd, district, subdistrict, lgd_name = candidates[0]
        if str(lgd) in linked:
            stats['already_linked'] += 1
            continue
        if catalogued := base_layers.get((args.state_name, district)):
            pass
        else:
            stats['no_base_layer'] += 1
            continue
        geometry = feature.get('geometry')
        if not geometry or geometry.get('type') not in ('Polygon', 'MultiPolygon'):
            stats['invalid'] += 1
            continue
        try:
            simplified = transform(projector.transform, shape(geometry).simplify(args.simplify_metres, preserve_topology=True))
        except Exception:
            stats['invalid'] += 1
            continue
        if simplified.is_empty:
            stats['invalid'] += 1
            continue
        name = str(properties.get('village') or '').strip() or lgd_name
        grouped.setdefault(district, []).append({
            'type': 'Feature',
            'properties': {
                'name': name,
                'source_code': str(properties.get('vlcode') or '').strip(),
                'subdistrict': str(properties.get('subdistric') or subdistrict or '').strip(),
                'category': 'OTHERS' if FOREST.search(name) else 'RURAL',
            },
            'geometry': mapping(simplified),
        })
        stats['matched_unlinked'] += 1

    manifest = {'state': args.state_name, 'source_date': args.source_date, 'files': []}
    for district, rows in sorted(grouped.items()):
        layer = base_layers[(args.state_name, district)]
        path = app / 'public' / layer['file']
        if not path.exists():
            path = app / 'storage/app/india-map/snapshot' / layer['file']
        data = json.loads(path.read_text())
        start = len(data['features'])
        backup_dir = app / 'storage/app/india-map/backups/base-appends'
        backup_dir.mkdir(parents=True, exist_ok=True)
        (backup_dir / layer['file'].replace('/', '__')).write_bytes(path.read_bytes())
        data['features'].extend(rows)
        data.setdefault('metadata', {})
        data['metadata']['appended_nwdp'] = {
            'features': len(rows),
            'source_date': args.source_date,
            'first_index': start,
            'note': 'Appended from the NWDP/SOI village boundary edition; original features keep their order and identity.',
        }
        path.write_text(json.dumps(data, ensure_ascii=False, separators=(',', ':')), encoding='utf8')
        manifest['files'].append({
            'file': layer['file'],
            'old_features': start,
            'appended': list(range(start, start + len(rows))),
            'sha256': hashlib.sha256(path.read_bytes()).hexdigest(),
        })

    args.manifest.parent.mkdir(parents=True, exist_ok=True)
    args.manifest.write_text(json.dumps(manifest, indent=1))
    print(json.dumps({**stats, 'districts': len(manifest['files']),
                      'appended_features': sum(len(row['appended']) for row in manifest['files'])}, indent=1))


if __name__ == '__main__':
    main()
