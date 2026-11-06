"""Build display-only administrative outlines from existing hashed village layers.
No original geometry, identifier, LGD link or source table is modified.
"""
import argparse, concurrent.futures, hashlib, json, sqlite3, tarfile
from collections import defaultdict
from pathlib import Path
from shapely import make_valid, union_all
from shapely.geometry import shape, mapping, MultiPolygon


def polygonal(geometry):
    if geometry.geom_type in ('Polygon', 'MultiPolygon'):
        return geometry
    parts=[]
    for item in getattr(geometry,'geoms',[]):
        child=polygonal(item)
        if child is not None and not child.is_empty:parts.append(child)
    polygons=[]
    for part in parts:
        polygons.extend(part.geoms if part.geom_type=='MultiPolygon' else [part])
    return (polygons[0] if len(polygons)==1 else MultiPolygon(polygons)) if polygons else None


def outline(parts):
    if not parts:
        return None
    result = union_all(parts, grid_size=0.001).simplify(0.002, preserve_topology=True)
    return polygonal(result) if not result.is_empty else None


def build(archive_path, links_path, output):
    with tarfile.open(archive_path) as archive:
        catalogue = json.load(archive.extractfile('catalogue.json'))
        inputs = [(layer, archive.extractfile(layer['id']+'.json').read()) for layer in catalogue['layers']+catalogue.get('context_layers', [])]
    database = sqlite3.connect(f'file:{Path(links_path).resolve().as_posix()}?mode=ro', uri=True)
    links = defaultdict(dict)
    for file, sha, index, state, district, subdistrict, district_name, subdistrict_name in database.execute('SELECT s.file,l.sha256,s.feature_index,a.state,a.district,a.subdistrict,d.district,d.subdistrict FROM shapes s JOIN layers l ON l.file=s.file JOIN ancestry a ON a.lgd=s.lgd AND a.state=s.state JOIN directory d ON d.lgd=s.lgd AND d.state=s.state'):
        links[(file,sha)][index] = (state,district,subdistrict,district_name,subdistrict_name)
    database.close()
    def process(item):
        layer, raw = item
        assert hashlib.sha256(raw).hexdigest() == layer['sha256']
        source=json.loads(raw);parts=[];groups=defaultdict(list);identities={}
        for index, feature in enumerate(source['features']):
            if not feature.get('geometry'):continue
            geometry=shape(feature['geometry'])
            if geometry.geom_type not in ('Polygon','MultiPolygon'):continue
            geometry=make_valid(geometry).simplify(0.0005,preserve_topology=True)
            if geometry.is_empty:continue
            parts.append(geometry)
            identity=links.get((layer['file'],layer['sha256']),{}).get(index)
            if identity:
                key=identity[:3];groups[key].append(geometry);identities[key]=identity
        whole=outline(parts)
        if whole is None:return None,[]
        districts=sorted(set((i[0],i[1]) for i in identities.values()))
        feature={'type':'Feature','id':layer['id'],'properties':{'layer':layer['id'],'state':layer['state'],'name':layer['district'],'sha256':layer['sha256'],'lgd_districts':[{'state':st,'district':d} for st,d in districts],'basis':'Existing source district coverage; boundary edition unverified'},'geometry':mapping(whole)}
        return feature,[(key,identities[key],outline(parts)) for key,parts in groups.items()]
    districts=[];subgroups=defaultdict(list);names={}
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        for i,(feature,groups) in enumerate(pool.map(process,inputs)):
            if feature:districts.append(feature)
            for key,identity,geometry in groups:
                if geometry is not None:subgroups[key].append(geometry);names[key]=identity
            if i%50==0:print(f'{i}/{len(inputs)} source groups',flush=True)
    subdistricts=[]
    for key,parts in subgroups.items():
        geometry=outline(parts)
        if geometry is None:continue
        subdistricts.append({'type':'Feature','id':':'.join(key),'properties':{'state_code':key[0],'district_code':key[1],'subdistrict_code':key[2],'name':names[key][4],'basis':'Display footprint of verified LGD-linked village polygons; not a certified subdistrict boundary'},'geometry':mapping(geometry)})
    result={'type':'FeatureCollection','features':districts,'subdistricts':{'type':'FeatureCollection','features':subdistricts},'metadata':{'purpose':'Display and navigation only. Original source files and LGD/SOI/Census identifiers remain unchanged.','simplification_degrees':0.002,'canonical_catalogue_sha256':hashlib.sha256(json.dumps(catalogue,sort_keys=True).encode()).hexdigest(),'lgd_links_sha256':hashlib.sha256(Path(links_path).read_bytes()).hexdigest()}}
    Path(output).write_text(json.dumps(result,separators=(',',':')),encoding='utf-8')
    print(f'Complete: {len(districts)} source district outlines; {len(subdistricts)} linked subdistrict footprints',flush=True)

def split_display(output):
    path=Path(output);data=json.loads(path.read_text(encoding='utf-8'))
    if 'source_catalogue_sha256' in data['metadata']:data['metadata']['canonical_catalogue_sha256']=data['metadata'].pop('source_catalogue_sha256')
    def clean(feature):
        geometry=polygonal(shape(feature['geometry']))
        if geometry is None:return None
        feature['geometry']=mapping(geometry)
        return feature
    data['features']=[result for feature in data['features'] if (result:=clean(feature))]
    groups=defaultdict(list)
    for feature in data.pop('subdistricts')['features']:
        feature=clean(feature)
        if feature:groups[feature['properties']['state_code']].append(feature)
    data['subdistrict_files']={}
    for state,features in groups.items():
        raw=json.dumps({'type':'FeatureCollection','features':features},separators=(',',':')).encode()
        name='india-subdistrict-display-'+state+'-'+hashlib.sha256(raw).hexdigest()[:12]+'.json'
        (path.parent/name).write_bytes(raw);data['subdistrict_files'][state]=name
    path.write_text(json.dumps(data,separators=(',',':')),encoding='utf-8')
    print('Overview bytes:',path.stat().st_size,'; states loaded on demand:',len(groups),flush=True)

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('archive');parser.add_argument('links');parser.add_argument('output');args=parser.parse_args();build(args.archive,args.links,args.output);split_display(args.output)
