#!/usr/bin/env python3
"""Ingest NWDP/SOI village-boundary GeoJSON into district display layers.

The source is the Survey of India Village Boundary dataset mirrored by the
National Water Data Portal. One state file is split into one display layer per
district, simplified to display tolerance, with the original source attributes
(name, village code, sub-district) preserved.

Only geometry and source attributes are written here. Identity linking to LGD
happens separately, with evidence, so nothing is inferred at ingest time.
"""
import argparse
import hashlib
import json
import math
import re
from datetime import datetime, timezone
from pathlib import Path

from shapely.geometry import mapping, shape
from shapely.ops import transform
from shapely.validation import explain_validity
from pyproj import CRS, Transformer

FOREST = re.compile(r'forest|\bR\.?\s?F\.?\b|\bP\.?\s?F\.?\b', re.I)


def slug(value):
    return re.sub(r'[^a-z0-9]+', '-', value.lower().replace('&', 'and')).strip('-')


def bbox(geometry):
    box = [math.inf, math.inf, -math.inf, -math.inf]
    polygons = [geometry['coordinates']] if geometry['type'] == 'Polygon' else geometry['coordinates']
    for polygon in polygons:
        for ring in polygon:
            for point in ring:
                box = [min(box[0], point[0]), min(box[1], point[1]), max(box[2], point[0]), max(box[3], point[1])]
    return box


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('application', type=Path)
    parser.add_argument('geojson', type=Path)
    parser.add_argument('--state-name', required=True)
    parser.add_argument('--source-url', required=True)
    parser.add_argument('--source-date', default=None)
    parser.add_argument('--crs-file', type=Path, required=True)
    parser.add_argument('--simplify-metres', type=float, default=15)
    args = parser.parse_args()

    source_crs = json.loads(args.crs_file.read_text())['source_crs']
    projector = Transformer.from_crs(CRS.from_wkt(source_crs), CRS.from_epsg(4326), always_xy=True)

    raw = args.geojson.read_bytes()
    digest = hashlib.sha256(raw).hexdigest()
    data = json.loads(raw)
    features = data['features']
    print(f'source features: {len(features)}  sha256: {digest[:16]}…')

    districts = {}
    invalid = []
    for index, feature in enumerate(features):
        properties = feature.get('properties') or {}
        district = str(properties.get('district') or '').strip()
        if not district:
            invalid.append({'index': index, 'reason': 'missing district'})
            continue
        districts.setdefault(district, []).append(feature)

    target = args.application / 'public/maps'
    written = []
    for district, rows in sorted(districts.items()):
        output_features = []
        for feature in rows:
            geometry = feature.get('geometry')
            if not geometry or geometry.get('type') not in ('Polygon', 'MultiPolygon'):
                invalid.append({'index': rows.index(feature), 'reason': 'not a polygon'})
                continue
            try:
                projected = shape(geometry)
                simplified = projected.simplify(args.simplify_metres, preserve_topology=True)
                simplified = transform(projector.transform, simplified)
            except Exception as error:
                invalid.append({'reason': f'simplify/transform failed: {error}'})
                continue
            if simplified.is_empty or not simplified.is_valid:
                invalid.append({'reason': f'empty or invalid after simplify: {explain_validity(simplified) if not simplified.is_empty else "empty"}'})
                if simplified.is_empty:
                    continue
            properties = feature.get('properties') or {}
            name = str(properties.get('village') or '').strip() or 'Unnamed source place'
            output_features.append({
                'type': 'Feature',
                'properties': {
                    'name': name,
                    'source_code': str(properties.get('vlcode') or '').strip(),
                    'subdistrict': str(properties.get('subdistric') or '').strip(),
                    'category': 'OTHERS' if FOREST.search(name) else 'RURAL',
                },
                'geometry': mapping(simplified),
            })

        if not output_features:
            continue

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
            'statuses': {'accepted': len(output_features), 'rejected': len(rows) - len(output_features)},
            'note': ('Survey of India Village Boundary (NWDP mirror). Names, village codes and '
                     'sub-district labels are source attributes; identity links are recorded separately.'),
        }
        payload = {'type': 'FeatureCollection', 'metadata': metadata, 'features': output_features}
        path = target / name
        path.write_text(json.dumps(payload, ensure_ascii=False, separators=(',', ':')), encoding='utf8')
        written.append({'file': f'maps/{name}', 'district': district, 'features': len(output_features),
                        'bytes': path.stat().st_size, 'sha256': hashlib.sha256(path.read_bytes()).hexdigest()})

    report = {'state': args.state_name, 'source_sha256': digest, 'simplification_metres': args.simplify_metres,
              'districts': len(written), 'features': sum(row['features'] for row in written),
              'invalid': len(invalid), 'layers': written}
    print(json.dumps({key: report[key] for key in ('state', 'districts', 'features', 'invalid')}, indent=1))
    for row in written:
        print(f"  {row['district']:28s} {row['features']:6d}  {row['bytes']/1048576:6.1f} MB  {row['file']}")
    if invalid:
        print('  invalid samples:', invalid[:5])


if __name__ == '__main__':
    main()
