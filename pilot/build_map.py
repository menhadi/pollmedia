"""Build a local display layer, preserving SOI identities and validation evidence.

Requires pyshp, pyproj, shapely. No inferred district outline or Census join.
"""
import hashlib
import io
import json
import zipfile
from pathlib import Path

import shapefile
from pyproj import CRS, Transformer
from shapely.geometry import shape, mapping
from shapely.ops import transform
from shapely.validation import explain_validity

ROOT = Path(__file__).resolve().parent
source = next(s for s in json.loads((ROOT / 'acquisition.json').read_text()) if s['id'] == 'soi-up-villages')
archive = ROOT / source['path']
assert hashlib.sha256(archive.read_bytes()).hexdigest() == source['sha256']
features, rejected = [], []
with zipfile.ZipFile(archive) as z:
    names = z.namelist()
    read = lambda suffix: z.read(next(n for n in names if n.endswith(suffix)))
    crs = CRS.from_wkt(read('.prj').decode())
    projector = Transformer.from_crs(crs, 'EPSG:4326', always_xy=True)
    reader = shapefile.Reader(shp=io.BytesIO(read('.shp')), shx=io.BytesIO(read('.shx')), dbf=io.BytesIO(read('.dbf')))
    for record in reader.iterRecords():
        values = record.as_dict()
        if values['District'].strip().lower() != 'pilibhit':
            continue
        key = str(values['OBJECTID'])
        geom = shape(reader.shape(record.oid).__geo_interface__)
        if not geom.is_valid or geom.is_empty:
            rejected.append({'id': key, 'reason': explain_validity(geom)})
            continue
        # Simplify in source metres for screen display; do not silently repair.
        geom = transform(projector.transform, geom.simplify(15, preserve_topology=True))
        west, south, east, north = geom.bounds
        if not geom.is_valid or not (78 < west < east < 82 and 27 < south < north < 30):
            rejected.append({'id': key, 'reason': 'Transformed geometry failed validity or regional extent check'})
            continue
        features.append({'type': 'Feature', 'id': int(values['OBJECTID']), 'properties': {
            'name': values['Vill_name'].strip(), 'source_code': values['Vill_LGD'].strip(),
            'subdistrict': values['Sub_dist'].strip(), 'category': values['Vill_Cat'].strip(),
        }, 'geometry': mapping(geom), 'bbox': list(geom.bounds)})

assert features, 'No usable source geometry'
bounds = [min(f['bbox'][0] for f in features), min(f['bbox'][1] for f in features),
          max(f['bbox'][2] for f in features), max(f['bbox'][3] for f in features)]
metadata = {'source': source['url'], 'sha256': source['sha256'], 'source_crs': crs.to_wkt(),
            'output_crs': 'EPSG:4326', 'simplification_metres': 15, 'accepted': len(features),
            'rejected': rejected, 'bbox': bounds,
            'status': 'Source village geometries; source edition and current LGD correspondence unverified. Not an official district or electoral outline.'}
output = ROOT.parent / 'application' / 'storage' / 'app' / 'maps'
output.mkdir(parents=True, exist_ok=True)
(output / 'pilibhit-villages.geojson').write_text(json.dumps({'type': 'FeatureCollection', 'features': features, 'bbox': bounds, 'metadata': metadata}, separators=(',', ':')), encoding='utf-8')
(ROOT / 'data' / 'map-validation.json').write_text(json.dumps(metadata, indent=2), encoding='utf-8')
print(json.dumps(metadata, indent=2))
