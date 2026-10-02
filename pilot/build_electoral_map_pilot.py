"""Create a bounded UP preview from pinned DataMeet files, without repairing geometry."""
import hashlib
import json
import sys
from datetime import datetime, timezone
from pathlib import Path

import shapefile
from shapely.geometry import shape, mapping
from shapely.validation import explain_validity

REVISION = 'b3fbbde595310b397a55d718e0958ce249a4fa1f'
source = Path(sys.argv[1])
output = Path(__file__).resolve().parents[1] / 'application/public/maps/up-pilot.json'
features, flagged = [], []

def accept(kind, properties, geometry):
    name_key, code_key = ('pc_name', 'pc_no') if kind == 'pc' else ('AC_NAME', 'AC_NO')
    name, code = properties[name_key], int(properties[code_key])
    geom = shape(geometry)
    if geom.is_empty:
        raise ValueError(f'Empty source geometry: {name}')
    geometry_warning = None if geom.is_valid else explain_validity(geom)
    if geometry_warning:
        flagged.append({'kind': kind, 'code': code, 'name': name, 'reason': geometry_warning})
    if not (76 < geom.bounds[0] < geom.bounds[2] < 85 and 23 < geom.bounds[1] < geom.bounds[3] < 32):
        raise ValueError(f'Unexpected UP extent: {name}')
    if not geometry_warning:
        geom = geom.simplify(.002, preserve_topology=True)
    features.append({'type': 'Feature', 'properties': {'kind': kind, 'code': code, 'name': name,
        'parent_pc_code': int(properties['PC_NO']) if kind == 'ac' else None,
        'join_status': 'unverified', 'geometry_status': 'flagged' if geometry_warning else 'passed',
        'geometry_warning': geometry_warning, 'source_attributes': properties}, 'geometry': mapping(geom)})

pc = json.loads((source / 'india_pc_2019_simplified.geojson').read_text(encoding='utf-8'))
for feature in pc['features']:
    if feature['properties']['st_name'] == 'Uttar Pradesh':
        accept('pc', feature['properties'], feature['geometry'])
reader = shapefile.Reader(str(source / 'India_AC'))
for record in reader.iterRecords():
    properties = record.as_dict()
    if properties['ST_NAME'].lower() == 'uttar pradesh':
        accept('ac', properties, reader.shape(record.oid).__geo_interface__)

sources = []
for filename in ['india_pc_2019_simplified.geojson', 'India_AC.shp', 'India_AC.shx', 'India_AC.dbf', 'India_AC.prj']:
    folder = 'parliamentary-constituencies' if filename.startswith('india_pc') else 'assembly-constituencies'
    sources.append({'url': f'https://raw.githubusercontent.com/datameet/maps/{REVISION}/{folder}/{filename}',
        'sha256': hashlib.sha256((source / filename).read_bytes()).hexdigest()})
metadata = {'revision': REVISION, 'prepared_at': datetime.now(timezone.utc).isoformat(), 'sources': sources, 'pc_license': 'CC0 1.0', 'ac_license': 'CC BY 2.5 India',
    'identity_checks': [
        {'kind': 'pc', 'code': 26, 'name': 'Pilibhit', 'reference_year': 2024,
         'url': 'https://www.eci.gov.in/EBooks/atlas-2024/files/basic-html/page19.html', 'scope': 'Name and seat code only; not geometry'},
        {'kind': 'ac', 'code': 127, 'name': 'Pilibhit', 'reference_year': 2021,
         'url': 'https://pilibhit.nic.in/documents/', 'scope': 'Name and seat code only; not geometry'},
    ],
    'pc_reference_year': 2019, 'ac_reference_year': None, 'boundary_status': 'Community preview; official boundary verification pending',
    'source_counts': {'pc': 80, 'ac': 403}, 'flagged': flagged, 'simplification_degrees': .002,
    'flagged_geometry_policy': 'Included with original coordinates; not simplified or repaired'}
output.parent.mkdir(parents=True, exist_ok=True)
output.write_text(json.dumps({'type': 'FeatureCollection', 'metadata': metadata, 'features': features}, separators=(',', ':')), encoding='utf-8')
print(json.dumps({'included': {kind: sum(f['properties']['kind'] == kind for f in features) for kind in ['pc', 'ac']}, 'flagged': flagged}))
