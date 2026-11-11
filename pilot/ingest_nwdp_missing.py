#!/usr/bin/env python3
"""Ingest NWDP/SOI polygons only for districts that currently have no coverage.

Each source feature is resolved to an LGD village by its village code inside the
state (name equality required when a code repeats), and kept only when that
village's district has no linked polygon yet. Output layers are keyed by the LGD
district, so post-2011 districts (Amethi, Palghar, the new Rajasthan districts,
Vijayanagara, Malerkotla, ...) are emitted even though the source file still
labels them under their parent district.
"""
import argparse
import hashlib
import json
import math
import re
import sqlite3
import unicodedata
from datetime import datetime, timezone
from pathlib import Path

from pyproj import CRS, Transformer
from shapely.geometry import mapping, shape
from shapely.ops import transform
from shapely.validation import explain_validity

FOREST = re.compile(r'forest|\bR\.?\s?F\.?\b|\bP\.?\s?F\.?\b', re.I)


def slug(value):
    return re.sub(r'[^a-z0-9]+', '-', value.lower().replace('&', 'and')).strip('-')


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
    parser.add_argument('--source-url', required=True)
    parser.add_argument('--source-date', default=None)
    parser.add_argument('--crs-file', type=Path, required=True)
    parser.add_argument('--simplify-metres', type=float, default=15)
    args = parser.parse_args()

    app = args.application
    raw = args.geojson.read_bytes()
    digest = hashlib.sha256(raw).hexdigest()
    data = json.loads(raw)
    source_crs = json.loads(args.crs_file.read_text())['source_crs']
    projector = Transformer.from_crs(CRS.from_wkt(source_crs), CRS.from_epsg(4326), always_xy=True)

    links = sqlite3.connect(app / 'storage/app/electoral-villages/links.sqlite')
    directory = list(links.execute(
        'select lgd,district,subdistrict,name,census2011 from directory where state=?', (args.state_code,)))
    linked = set()
    for (value,) in links.execute('select lgd from shapes where lgd is not null'):
        linked.add(str(value))
    links.execute(f"ATTACH DATABASE '{app}/storage/app/electoral-villages/display-links.sqlite' AS dl")
    for (value,) in links.execute('select lgd from dl.shapes where lgd is not null'):
        linked.add(str(value))

    per_district = {}
    for lgd, district, subdistrict, name, census in directory:
        slot = per_district.setdefault(district, [0, 0])
        slot[0] += 1
        if str(lgd) in linked:
            slot[1] += 1
    zero = {district for district, (total, done) in per_district.items() if done == 0}
    print('districts without coverage:', len(zero), 'villages:', sum(per_district[d][0] for d in zero))

    by_code = {}
    for lgd, district, subdistrict, name, census in directory:
        for code in {code_key(census), code_key(lgd)}:
            if code and code != '0':
                by_code.setdefault(code, []).append((lgd, district, subdistrict, name))

    grouped = {}
    stats = {'matched': 0, 'already_covered': 0, 'unmatched': 0, 'invalid': 0}
    for feature in data['features']:
        properties = feature.get('properties') or {}
        code = code_key(properties.get('vlcode'))
        candidates = by_code.get(code, [])
        if len(candidates) > 1:
            same_name = [row for row in candidates if norm(row[3]) == norm(properties.get('village'))]
            candidates = same_name
        if len(candidates) != 1:
            stats['unmatched'] += 1
            continue
        lgd, district, subdistrict, lgd_name = candidates[0]
        if district not in zero:
            stats['already_covered'] += 1
            continue
        geometry = feature.get('geometry')
        if not geometry or geometry.get('type') not in ('Polygon', 'MultiPolygon'):
            stats['invalid'] += 1
            continue
        try:
            simplified = shape(geometry).simplify(args.simplify_metres, preserve_topology=True)
            simplified = transform(projector.transform, simplified)
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
        stats['matched'] += 1

    target = app / 'public/maps'
    written = []
    for district, features in sorted(grouped.items()):
        name = f"nwdp-{slug(args.state_name)}-{slug(district)}-villages.geojson"
        metadata = {
            'source': f'{args.source_url} · {args.state_name}',
            'source_sha256': digest,
            'source_edition': args.source_date or datetime.now(timezone.utc).date().isoformat(),
            'district_source_name': district,
            'state_source_name': args.state_name,
            'output_crs': 'EPSG:4326',
            'source_crs': 'Survey of India LCC_WGS84 (pilot/data/soi-lcc-wgs84-crs.json)',
            'simplification_metres': round(args.simplify_metres),
            'selection': 'Districts without any linked village polygon before this ingest; features assigned to the LGD district of their matched village.',
            'statuses': {'accepted': len(features)},
            'note': ('Survey of India Village Boundary (NWDP mirror). Names, village codes and '
                     'sub-district labels are source attributes; identity links are recorded separately.'),
        }
        payload = {'type': 'FeatureCollection', 'metadata': metadata, 'features': features}
        path = target / name
        path.write_text(json.dumps(payload, ensure_ascii=False, separators=(',', ':')), encoding='utf8')
        written.append({'file': f'maps/{name}', 'district': district, 'features': len(features),
                        'bytes': path.stat().st_size})

    print(json.dumps({'state': args.state_name, **stats, 'districts': len(written),
                      'features_written': sum(row['features'] for row in written)}, indent=1))
    for row in written:
        print(f"  {row['district']:30s} {row['features']:6d}  {row['bytes']/1048576:6.1f} MB  {row['file']}")


if __name__ == '__main__':
    main()
