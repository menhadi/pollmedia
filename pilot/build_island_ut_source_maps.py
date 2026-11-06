"""Build four official UT map source groups without repair, simplification or inferred joins."""
import sys,json,zipfile,hashlib,collections,re
from pathlib import Path
sys.path.insert(0,'D:/pollmedia/tmp/ac-pilot-deps')
import shapefile
from pyproj import CRS,Transformer
from shapely.geometry import shape,mapping
from shapely.ops import transform
ROOT=Path(__file__).resolve().parents[1]
raw=Path('D:/pollmedia/pilot/raw/island-ut-intake')
regions={'andaman-nicobar': ('Andaman & Nicobar Islands', '8ecd1271-1838-4413-9d9a-599b122a2e8a', '7cf648fe85c12295eb4b0d2e9a5c53634dd0e7f64cb5cf1b0da56a25b25dfd10'), 'lakshadweep': ('Lakshadweep', 'd9e5840a-76cc-4456-88e6-b5d5c4bbab6d', '63c232564bdd3cdac4bb0c967f4b088eb470596e60bd35ef7d54d5bf125ed3fb'), 'puducherry': ('Puducherry', 'dd9cd307-f03a-49e1-8409-bad2d5568afc', '9b21bec727dcda1481a84ee0ab1323f1ae6192c4d7b72ea9ac3ae3de7a8ee461')}
def write(path,data):
 path.parent.mkdir(parents=True,exist_ok=True);path.write_text(json.dumps(data,ensure_ascii=False,indent=2)+'\n',encoding='utf8')
def slug(s):return re.sub('[^a-z0-9]+','-',s.lower()).strip('-')
for region,(label,rid,digest) in regions.items():
 archive=raw/(rid+'.zip');assert hashlib.sha256(archive.read_bytes()).hexdigest()==digest
 with zipfile.ZipFile(archive) as z:
  assert z.testzip() is None;data=json.loads(z.read(next(n for n in z.namelist() if n.lower().endswith('.geojson'))))
 assert data['crs']['properties']['name']=='urn:ogc:def:crs:EPSG::7755'
 project=Transformer.from_crs(7755,4326,always_xy=True);groups=collections.defaultdict(list);invalid=collections.defaultdict(list);excluded=[]
 for i,f in enumerate(data['features']):
  p=f['properties'];name=p['village'].strip();district=p['district'].strip()
  if not name or 'available' in name.lower():excluded.append({'index':i,'district':district,'name':name});continue
  orig=shape(f['geometry']);g=transform(project.transform,orig)
  assert not g.is_empty and 70<g.bounds[0]<=g.bounds[2]<95 and 6<g.bounds[1]<=g.bounds[3]<18
  if not orig.is_valid or not g.is_valid:invalid[district].append(str(i))
  groups[district].append({'type':'Feature','id':str(i),'properties':{'name':name,'source_code':p['vlcode'],'source_district':district,'subdistrict':p['subdistric'],'status':'unlinked','source_residence':p.get('total_urban_rural\n','').strip(),'place_type':'urban' if p.get('total_urban_rural\n','').strip()=='Urban' else 'rural' if p.get('total_urban_rural\n','').strip()=='Rural' else 'unclassified','source_record_id':p['id'],'source_district_code':p['dtcode'],'source_subdistrict_code':p['sdcode']},'geometry':mapping(g)})
 counts=collections.Counter(f['properties']['source_code'] for fs in groups.values() for f in fs);manifest=[]
 for district,features in sorted(groups.items()):
  for f in features:
   code=f['properties']['source_code']
   if not code or code=='999999' or counts[code]>1:f['properties']['status']='ambiguous'
  meta={'source':'https://nwdp.nwic.gov.in/dataset/village-boundary/resource/'+rid,'archive_sha256':digest,'source_crs':'EPSG:7755','output_crs':'EPSG:4326','boundary_edition':'unverified; source date not established','simplification_metres':0,'geometry_repaired':False,'invalid_source_ids_retained':invalid[district],'census_lgd_join':'unverified','population_attributes':'withheld pending edition and correspondence checks; original archive retained'}
  path=ROOT/'application/public/maps'/(region+'-source-villages')/(slug(district)+'.geojson');write(path,{'type':'FeatureCollection','features':features,'metadata':meta})
  manifest.append({'source_name':district,'slug':slug(district),'file':path.relative_to(ROOT/'application/public').as_posix(),'features':len(features),'invalid_retained':len(invalid[district]),'ambiguous_shapes':sum(f['properties']['status']=='ambiguous' for f in features),'place_types':dict(collections.Counter(f['properties'].get('place_type','unclassified') for f in features)),'bytes':path.stat().st_size,'sha256':hashlib.sha256(path.read_bytes()).hexdigest()})
 result={'region':region,'label':label,'districts':manifest,'excluded_records':excluded,'metadata':meta}
 write(ROOT/'application/database/fixtures'/(region+'-source-villages.json'),result);write(ROOT/'pilot/data'/(region+'-source-village-manifest.json'),result)
 print(region,'village',[(d['slug'],d['features'],d['invalid_retained']) for d in manifest],'excluded',excluded)
archive=Path('D:/pollmedia/pilot/raw/assam-intake/soi-pan-india-abdb.rar');digest=hashlib.sha256(archive.read_bytes()).hexdigest();assert digest=='b8325e5d9dd0f04a6663d775363fe38cd2f23bd9dbae3fb7118b4e6e0ce0bcb7'
shp=next(Path('D:/pollmedia/pilot/raw/assam-intake/abdb-7zip').rglob('District Boundary.shp'));crs=CRS.from_wkt(shp.with_suffix('.prj').read_text());project=Transformer.from_crs(crs,4326,always_xy=True);reader=shapefile.Reader(str(shp));outlines=collections.defaultdict(list)
for i,record in enumerate(reader.iterRecords()):
 p=record.as_dict()
 if p['STATE_UT'] not in ['ANDAMAN AND NICOBAR ISLANDS','LAKSHADWEEP','PUDUCHERRY']:continue
 region={'ANDAMAN AND NICOBAR ISLANDS':'andaman-nicobar','LAKSHADWEEP':'lakshadweep','PUDUCHERRY':'puducherry'}[p['STATE_UT']];name=p['DISTRICT'].strip();orig=shape(reader.shape(i).__geo_interface__);g=transform(project.transform,orig)
 assert not g.is_empty and 70<g.bounds[0]<=g.bounds[2]<95 and 6<g.bounds[1]<=g.bounds[3]<18
 meta={'source':'https://surveyofindia.gov.in/documents/State_District_Subdistrict_PAN%20INDIA.rar','archive_sha256':digest,'dataset_identifier':'SOI/ABDB/VECTOR/50000/2025/DISTRICT/INDIA','publication_date':'2026-05-06','temporal_scope':'2022-2025','source_crs':crs.to_string(),'output_crs':'EPSG:4326','source_geometry_valid':orig.is_valid,'geometry_repaired':False,'simplification_metres':0,'census_lgd_join':'unverified'}
 path=ROOT/'application/public/maps'/(region+'-source-districts')/(slug(name)+'.geojson');write(path,{'type':'FeatureCollection','metadata':meta,'features':[{'type':'Feature','id':str(p['OBJECTID']),'properties':{'name':name,'source_code':p['DIST_LGD'],'status':'unlinked','source_properties':p},'geometry':mapping(g)}]})
 outlines[region].append({'source_name':name,'slug':slug(name),'file':path.relative_to(ROOT/'application/public').as_posix(),'source_geometry_valid':orig.is_valid,'bytes':path.stat().st_size,'sha256':hashlib.sha256(path.read_bytes()).hexdigest()})
assert {r:len(ds) for r,ds in outlines.items()}=={'andaman-nicobar':3,'lakshadweep':1,'puducherry':2}
for region,ds in outlines.items():
 result={'region':region,'label':regions[region][0],'districts':ds,'metadata':meta};write(ROOT/'application/database/fixtures'/(region+'-source-district-outlines.json'),result);write(ROOT/'pilot/data'/(region+'-source-district-outline-manifest.json'),result)
 print(region,'outlines',[(d['slug'],d['source_geometry_valid']) for d in ds])


import runpy
runpy.run_path(str(ROOT / "pilot/integrate_island_contexts.py"))
