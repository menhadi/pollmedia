"""Audit deployed SOI display layers and build a bounded national search index.

Original files are read only. Counts describe source shapes, not unique villages.
No name-only Census, LGD or electoral joins are created.
"""
import argparse
import collections
import hashlib
import json
import math
import re
import sqlite3
from pathlib import Path
from datetime import datetime, timezone

STATES = ['Jammu and Kashmir','Himachal Pradesh','Punjab','Chandigarh','Uttarakhand','Haryana','Delhi','Rajasthan','Uttar Pradesh','Bihar','Sikkim','Arunachal Pradesh','Nagaland','Manipur','Mizoram','Tripura','Meghalaya','Assam','West Bengal','Jharkhand','Odisha','Chhattisgarh','Madhya Pradesh','Gujarat','Daman and Diu','Dadra and Nagar Haveli','Maharashtra','Andhra Pradesh','Karnataka','Goa','Lakshadweep','Kerala','Tamil Nadu','Puducherry','Andaman and Nicobar Islands','Telangana','Ladakh']
def normalize(value):
    return re.sub(r'[^a-z0-9]', '', value.lower())

def identify_state(path, metadata):
    text = normalize(path.as_posix() + ' ' + str(metadata.get('source', '')))
    if 'dnhdamandiu' in text:
        return 'Dadra and Nagar Haveli and Daman and Diu'
    aliases = {'Odisha':['orissa'], 'Jammu and Kashmir':['jammukashmir','soijk'], 'Goa':['soiga'], 'Assam':['soias'], 'Andaman and Nicobar Islands':['andamannicobar']}
    for name in sorted(STATES, key=len, reverse=True):
        if any(normalize(alias) in text for alias in [name] + aliases.get(name, [])):
            return name
    return None

def geometry_bounds(geometry):
    if not geometry or geometry.get('type') not in ('Polygon', 'MultiPolygon'):
        raise ValueError('Not a polygon')
    polygons = [geometry['coordinates']] if geometry['type'] == 'Polygon' else geometry['coordinates']
    box = [math.inf, math.inf, -math.inf, -math.inf]
    for polygon in polygons:
        if not polygon:
            raise ValueError('Empty polygon')
        for ring in polygon:
            if len(ring) < 4 or ring[0][:2] != ring[-1][:2]:
                raise ValueError('Unclosed or undersized ring')
            for point in ring:
                x, y = point[:2]
                if not (math.isfinite(x) and math.isfinite(y) and -180 <= x <= 180 and -90 <= y <= 90):
                    raise ValueError('Invalid WGS84 coordinate')
                box = [min(box[0], x), min(box[1], y), max(box[2], x), max(box[3], y)]
    if not all(math.isfinite(x) for x in box):
        raise ValueError('Empty geometry')
    return box

def build(public, output, extra_public=None):
    output.mkdir(parents=True, exist_ok=True)
    database = output / 'villages.sqlite'
    if database.exists():
        raise FileExistsError('Use a fresh output directory; previous evidence is preserved')
    db = sqlite3.connect(database)
    db.executescript('''
        CREATE TABLE places(id TEXT PRIMARY KEY, layer TEXT, feature_index INTEGER, source_id TEXT,
          name TEXT, code TEXT, subdistrict TEXT, state TEXT, district TEXT, bbox TEXT, properties TEXT);
        CREATE INDEX places_layer ON places(layer);
        CREATE TABLE feature_aliases(layer TEXT, feature_index INTEGER, place_id TEXT, PRIMARY KEY(layer,feature_index));
        CREATE VIRTUAL TABLE search USING fts5(name, code, subdistrict, district, state, content='places', content_rowid='rowid');
    ''')
    layers, failures, duplicates, unknown, retained = [], [], [], [], []
    seen_files, seen_shapes = {}, {}
    state_totals = collections.Counter()
    candidates = [(path, public) for path in sorted(set((public / 'maps').glob('*-villages.geojson')) | set((public / 'maps').glob('*source-villages/*.geojson')))]
    if extra_public:
        for path in sorted((extra_public / 'maps').glob('*-villages.geojson')):
            if (public / path.relative_to(extra_public)).exists():
                raise ValueError('Extra layer would overwrite a deployed source layer')
            candidates.append((path, extra_public))
    for path, source_root in candidates:
        relative = path.relative_to(source_root)
        raw = path.read_bytes()
        digest = hashlib.sha256(raw).hexdigest()
        if digest in seen_files:
            duplicates.append({'file': str(relative), 'same_as': seen_files[digest], 'basis': 'identical file SHA256'})
            continue
        try:
            data = json.loads(raw)
            if data.get('type') != 'FeatureCollection':
                raise ValueError('Not a FeatureCollection')
            metadata = data.get('metadata') or {}
            state = identify_state(relative, metadata)
            if not state:
                unknown.append(str(relative)); continue
            source = str(metadata.get('source', ''))
            if not source.startswith(('https://surveyofindia.gov.in/', 'https://nwdp.nwic.gov.in/')):
                unknown.append(str(relative)); continue
            seen_files[digest] = str(relative)
            key = hashlib.sha256(relative.as_posix().encode()).hexdigest()[:20]
            district = str(metadata.get('district_source_name') or metadata.get('source_district') or path.stem.replace('-villages', '').replace('-', ' ').title())
            bounds = [math.inf, math.inf, -math.inf, -math.inf]
            count = duplicate_count = 0
            declared_invalid = metadata.get('invalid_source_indices_retained', metadata.get('invalid_source_ids_retained', []))
            for index, feature in enumerate(data['features']):
                try:
                    box = geometry_bounds(feature.get('geometry'))
                except (ValueError, TypeError, KeyError, IndexError) as error:
                    failures.append({'file': relative.as_posix(), 'index': index, 'error': str(error)}); continue
                properties = feature.get('properties') or {}
                shape_hash = hashlib.sha256(json.dumps([state, properties.get('source_code'), feature['geometry']], sort_keys=True, separators=(',', ':')).encode()).hexdigest()
                if shape_hash in seen_shapes:
                    db.execute('INSERT INTO feature_aliases VALUES (?,?,?)', (key,index,seen_shapes[shape_hash]))
                    duplicate_count += 1; continue
                source_id = str(feature.get('id', index))
                identity = hashlib.sha256((relative.as_posix() + ':' + source_id + ':' + str(index)).encode()).hexdigest()[:32]
                seen_shapes[shape_hash] = identity
                db.execute('INSERT INTO places VALUES (?,?,?,?,?,?,?,?,?,?,?)', (identity,key,index,source_id,str(properties.get('name') or 'Unnamed source place'),str(properties.get('source_code') or ''),str(properties.get('subdistrict') or properties.get('source_subdistrict') or ''),state,district,json.dumps(box),json.dumps(properties,ensure_ascii=False)))
                bounds = [min(bounds[0],box[0]), min(bounds[1],box[1]), max(bounds[2],box[2]), max(bounds[3],box[3])]
                count += 1
            if count:
                layers.append({'id':key,'file':relative.as_posix(),'state':state,'district':district,'bbox':bounds,'shapes':count,'file_shapes':len(data['features']),'duplicate_shapes':duplicate_count,'sha256':digest,'bytes':len(raw),'source':source,'metadata':metadata})
                state_totals[state] += count
                if declared_invalid:
                    retained.append({'file':relative.as_posix(),'declared_invalid_retained':len(declared_invalid)})
            else:
                duplicates.append({'file':relative.as_posix(),'basis':'all shapes duplicate or structurally unusable'})
            db.commit()
        except (ValueError, TypeError, KeyError) as error:
            failures.append({'file':relative.as_posix(),'error':str(error)})
    db.execute("INSERT INTO search(search) VALUES ('rebuild')")
    db.commit()
    db.execute('VACUUM')
    db.close()
    catalogue = {'schema_version':1,'built_at':datetime.now(timezone.utc).isoformat(),'layers':layers,'states':[{'name':name,'shapes':state_totals[name],'layers':sum(x['state']==name for x in layers),'status':'source shapes available' if state_totals[name] else 'no deployed SOI village layer found'} for name in STATES if name not in ['Daman and Diu','Dadra and Nagar Haveli']]}
    merged_name = 'Dadra and Nagar Haveli and Daman and Diu'
    merged_shapes = sum(state_totals[name] for name in [merged_name,'Daman and Diu','Dadra and Nagar Haveli'])
    catalogue['states'].append({'name':merged_name,'shapes':merged_shapes,'layers':sum(x['state'] in [merged_name,'Daman and Diu','Dadra and Nagar Haveli'] for x in layers),'status':'source shapes available' if merged_shapes else 'no deployed SOI village layer found'})
    catalogue['context_layers'] = []
    for slug, state in [('himachal-pradesh','Himachal Pradesh'), ('ladakh','Ladakh')]:
        if state_totals[state]:
            continue
        for path in sorted((public / 'maps' / f'{slug}-source-districts').glob('*.geojson')):
            raw = path.read_bytes()
            data = json.loads(raw)
            for index, feature in enumerate(data['features']):
                relative = path.relative_to(public).as_posix()
                key = hashlib.sha256(relative.encode()).hexdigest()[:20]
                properties = feature['properties']
                bounds = geometry_bounds(feature['geometry'])
                name = properties['name']
                place_id = hashlib.sha256(f'{relative}:{index}:district-context'.encode()).hexdigest()[:32]
                url = f'https://pollmedia.org/india/districts/source/{slug}/{path.stem}/dashboard'
                catalogue['context_layers'].append({'id':key,'kind':'district','file':relative,'state':state,'district':name,'bbox':bounds,'shapes':0,'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest(),'source':data['metadata']['source'],'metadata':data['metadata'],'context_place':{'id':place_id,'layer':key,'feature_index':index,'source_id':str(feature.get('id',index)),'name':name,'code':str(properties.get('source_code','')),'subdistrict':'District outline only; village polygons unavailable','district':name,'state':state,'bbox':bounds,'properties':properties,'url':url,'kind':'district'}})
        row = next(row for row in catalogue['states'] if row['name']==state)
        row['district_outline_files'] = sum(layer['state']==state for layer in catalogue['context_layers'])
        if row['district_outline_files']:
            row['status'] = 'District outlines available; SOI village polygons unavailable'
    catalogue['audit'] = {'candidate_files':len(candidates),'indexed_shapes':sum(x['shapes'] for x in layers),'duplicate_files':duplicates,'structural_errors':failures,'unclassified_files':unknown,'invalid_retained':retained,'topology_validation':'Not rerun; source-declared invalid geometry flags retained. Structural WGS84 checks only.','scope':'Deployed source coverage plus explicitly supplied local layers, not completeness against current LGD or Census village inventories. Layers may include towns. No new geographic joins.','discovery_pattern':'maps/*-villages.geojson and maps/*source-villages/*.geojson; district outlines, urban-region layers and historical non-SOI layers excluded.'}
    (output / 'catalogue.json').write_text(json.dumps(catalogue,ensure_ascii=False,separators=(',',':')),encoding='utf8')
    print(json.dumps({'layers':len(layers),'shapes':catalogue['audit']['indexed_shapes'],'errors':len(failures),'unclassified':unknown,'states':catalogue['states']}))
    return catalogue

if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--public', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--extra-public', type=Path)
    args = parser.parse_args()
    build(args.public, args.output, args.extra_public)
