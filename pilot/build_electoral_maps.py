"""Preserve every pinned national boundary record in state-sized preview files."""
import collections
import hashlib
import json
import re
import sys
from datetime import datetime, timezone
from pathlib import Path

import shapefile
from shapely.geometry import shape, mapping
from shapely.validation import explain_validity

REVISION = 'b3fbbde595310b397a55d718e0958ce249a4fa1f'
PRE_DELIMITATION = {'Jammu & Kashmir', 'Jharkhand', 'Assam', 'Manipur', 'Nagaland', 'Arunachal Pradesh'}
ALIASES = {'orissa': 'Odisha', 'uttarkhand': 'Uttarakhand', 'andaman & nicobar': 'Andaman & Nicobar Islands',
           'dadra & nagar haveli': 'Dadra & Nagar Haveli and Daman & Diu',
           'daman & diu': 'Dadra & Nagar Haveli and Daman & Diu'}


def state_name(value):
    return ALIASES.get(value.lower(), value.title().replace(' & ', ' & '))


def slug(value):
    return re.sub(r'[^a-z0-9]+', '-', value.lower().replace('&', 'and')).strip('-')


def build(source, output):
    prepared_at = datetime.now(timezone.utc).isoformat()
    pc = json.loads((source / 'india_pc_2019_simplified.geojson').read_text(encoding='utf-8'))
    ac = shapefile.Reader(str(source / 'India_AC'))
    records = []
    for index, feature in enumerate(pc['features']):
        p = feature['properties']
        name = state_name(p['st_name'])
        if p['st_name'] == 'Jammu & Kashmir' and p['pc_name'].lower() == 'ladakh':
            name = 'Ladakh'
        records.append(('pc', f'pc-{index}', name, p['pc_name'], p['pc_no'], p, feature['geometry']))
    for record in ac.iterRecords():
        p = record.as_dict()
        records.append(('ac', f'ac-{record.oid}', state_name(p['ST_NAME']), p['AC_NAME'], p['AC_NO'], p, ac.shape(record.oid).__geo_interface__))
    duplicates = collections.Counter((kind, state, code) for kind, _, state, _, code, _, _ in records)
    name_codes = collections.defaultdict(set)
    for kind, _, state, name, code, _, _ in records:
        if name and name.strip():
            name_codes[(kind, state, name.strip().lower())].add(code)
    groups = collections.defaultdict(list)
    for kind, identifier, state, name, code, raw, geometry in records:
        geom = shape(geometry)
        warnings = []
        geometry_warning = None if geom.is_valid and not geom.is_empty else explain_validity(geom)
        if geometry_warning:
            warnings.append(f'Geometry: {geometry_warning}')
        if not name or not name.strip():
            warnings.append('Source constituency name is missing')
        if not code:
            warnings.append('Source seat code is missing or zero')
        if duplicates[(kind, state, code)] > 1:
            warnings.append('Multiple source records share this seat code; kept separately')
        if name and len(name_codes[(kind, state, name.strip().lower())]) > 1:
            warnings.append('The same source name appears under multiple seat codes; geographic match unverified')
        if kind == 'ac' and (state in PRE_DELIMITATION or raw.get('STATUS') == 'Pre delimitation'):
            warnings.append('Source indicates older or pre-delimitation boundaries')
        if state in {'Jammu & Kashmir', 'Ladakh', 'Assam', 'Dadra & Nagar Haveli and Daman & Diu'} and kind == 'pc':
            warnings.append('2019 source predates later delimitation or territory changes')
        if kind == 'ac' and state == 'Andhra Pradesh':
            warnings.append('Combined Andhra Pradesh / Telangana source; present-day state assignment unverified')
        if not geometry_warning:
            geom = geom.simplify(.002, preserve_topology=True)
        groups[slug(state)].append({'type': 'Feature', 'id': identifier,
            'properties': {'kind': kind, 'code': code, 'name': name, 'source_state': raw.get('st_name', raw.get('ST_NAME')),
                'state': state, 'parent_pc_code': raw.get('PC_NO') if kind == 'ac' else None,
                'join_status': 'unverified', 'geometry_status': 'flagged' if geometry_warning else 'passed',
                'geometry_warning': geometry_warning, 'review_status': 'flagged' if warnings else 'unverified',
                'review_notes': warnings, 'source_attributes': raw}, 'geometry': geometry if geometry_warning else mapping(geom)})
    sources = []
    for filename in ['india_pc_2019_simplified.geojson', 'India_AC.shp', 'India_AC.shx', 'India_AC.dbf', 'India_AC.prj']:
        folder = 'parliamentary-constituencies' if filename.startswith('india_pc') else 'assembly-constituencies'
        sources.append({'url': f'https://raw.githubusercontent.com/datameet/maps/{REVISION}/{folder}/{filename}',
            'sha256': hashlib.sha256((source / filename).read_bytes()).hexdigest()})
    common = {'revision': REVISION, 'prepared_at': prepared_at, 'sources': sources,
        'pc_license': 'CC0 1.0', 'ac_license': 'CC BY 2.5 India', 'pc_reference_year': 2019,
        'ac_reference_year': None, 'simplification_degrees': .002,
        'flagged_geometry_policy': 'Original coordinates; no repair or simplification',
        'boundary_status': 'Community source; official geometry and record joins unverified'}
    output.mkdir(parents=True, exist_ok=True)
    catalogue = {'metadata': common, 'totals': {'pc': len(pc['features']), 'ac': len(ac),
        'geometry_flagged': sum(bool(f['properties']['geometry_warning']) for g in groups.values() for f in g),
        'review_flagged': sum(f['properties']['review_status'] == 'flagged' for g in groups.values() for f in g)}, 'states': {}}
    for key, features in sorted(groups.items()):
        counts = {kind: sum(f['properties']['kind'] == kind for f in features) for kind in ['pc', 'ac']}
        data = {'type': 'FeatureCollection', 'metadata': common | {'state': features[0]['properties']['state'], 'source_counts': counts}, 'features': features}
        filename = f'{key}.json'
        payload = json.dumps(data, separators=(',', ':'), ensure_ascii=False).encode('utf-8')
        (output / filename).write_bytes(payload)
        catalogue['states'][key] = {'slug': key, 'name': features[0]['properties']['state'], 'file': filename,
            'sha256': hashlib.sha256(payload).hexdigest(), 'counts': counts,
            'geometry_flagged': sum(bool(f['properties']['geometry_warning']) for f in features),
            'review_flagged': sum(f['properties']['review_status'] == 'flagged' for f in features)}
    assert sum(s['counts']['pc'] for s in catalogue['states'].values()) == len(pc['features'])
    assert sum(s['counts']['ac'] for s in catalogue['states'].values()) == len(ac)
    assert len({f['id'] for g in groups.values() for f in g}) == len(records)
    (output / 'catalogue.json').write_text(json.dumps(catalogue, ensure_ascii=False, indent=2), encoding='utf-8')
    print(json.dumps({'states': len(groups), 'totals': catalogue['totals']}))


if __name__ == '__main__':
    build(Path(sys.argv[1]), Path(__file__).resolve().parents[1] / 'application/public/maps/electoral')
