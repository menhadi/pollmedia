"""Locate unchanged urban polygons for display, without inferring LGD town membership."""
import argparse
import hashlib
import json
from pathlib import Path
from shapely.geometry import shape


def context(geometry, districts):
    polygon = shape(geometry)
    if not polygon.is_valid or polygon.is_empty:
        return []
    parents = {parent for parent, boundary in districts if boundary.is_valid and boundary.covers(polygon)}
    return list(parents) if len(parents) == 1 else []


def attach(index, geometries, boundaries, output):
    data = json.loads(index.read_bytes())
    directory = json.loads(boundaries.read_bytes())
    states = {}
    districts = {}
    for feature in directory['features']:
        p = feature['properties']
        states[p['state']] = p['state_code']
        if len(p['lgd_districts']) == 1:
            parent = p['lgd_districts'][0]
            districts.setdefault(p['state_code'], []).append(((parent['state'],parent['district']), shape(feature['geometry'])))
    added = held = 0
    for line in geometries.open(encoding='utf-8'):
        source = json.loads(line)
        layer = data['layers'][source['layer']]
        if layer['sha256'] != source['geometry_sha256']:
            raise ValueError('Original urban geometry edition changed')
        record = layer['records'][str(source['index'])]
        if record['current_districts'] or record.get('display_context'):
            continue
        matches = context(source['geometry'], districts.get(states[layer['state']], []))
        if not matches:
            held += 1
            continue
        state,district = matches[0]
        record['display_context'] = {'state':state,'district':district,'basis':'The unchanged source polygon is fully covered by one existing LGD-associated SOI district display boundary. Geographic display context only; no LGD municipality, ward membership or boundary-edition equivalence inferred.'}
        key=state+':'+district
        entries=data['district_layers'].setdefault(key,[])
        entry=next((e for e in entries if e['id']==source['layer']),None)
        if entry is None:
            entry={'id':source['layer'],'indices':[]};entries.append(entry)
        if source['index'] not in entry['indices']:
            entry['indices'].append(source['index'])
        added+=1
    data['display_context_boundary_sha256']=hashlib.sha256(boundaries.read_bytes()).hexdigest()
    data['geographic_context_shapes']=sum(bool(r.get('display_context')) for layer in data['layers'].values() for r in layer['records'].values())
    data['unplaced_urban_shapes']=sum(not r['current_districts'] and not r.get('display_context') for layer in data['layers'].values() for r in layer['records'].values())
    output.write_bytes(json.dumps(data,separators=(',',':')).encode())
    print(json.dumps({'urban_shapes':sum(data['counts'].values()),'geographic_context_shapes':added,'unplaced_shapes':held,'districts':len(data['district_layers'])}),flush=True)


if __name__=='__main__':
    p=argparse.ArgumentParser()
    for name in ['index','geometries','boundaries','output']:
        p.add_argument(name,type=Path)
    a=p.parse_args();attach(a.index,a.geometries,a.boundaries,a.output)
