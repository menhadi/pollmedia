from pathlib import Path
import sys,json,hashlib,zipfile,collections
sys.path.insert(0,'D:/pollmedia/tmp/ac-pilot-deps')
import shapefile
from shapely.geometry import shape,mapping,box
from shapely.ops import transform,unary_union
from pyproj import CRS,Transformer
root=Path(__file__).resolve().parents[1]
raw=Path('D:/pollmedia/pilot/raw/assam-intake/abdb-7zip');shp=next(raw.rglob('State Boundary.shp'));reader=shapefile.Reader(str(shp));project=Transformer.from_crs(CRS.from_wkt(shp.with_suffix('.prj').read_text()),4326,always_xy=True).transform
tamil=None
for i,r in enumerate(reader.iterRecords()):
 if r.as_dict()['STATE']=='TAMIL NADU':tamil=transform(project,shape(reader.shape(i).__geo_interface__))
assert tamil is not None and tamil.is_valid
archive=Path('D:/pollmedia/pilot/raw/island-ut-intake/dd9cd307-f03a-49e1-8409-bad2d5568afc.zip')
with zipfile.ZipFile(archive) as z:original=json.loads(z.read(next(n for n in z.namelist() if n.lower().endswith('.geojson'))))
projection=Transformer.from_crs(7755,4326,always_xy=True).transform
reports=[]
for slug,outline in [('pondicherry','puducherry'),('karaikal','karaikal')]:
 p=root/'application/public/maps/puducherry-source-villages'/f'{slug}.geojson';d=json.loads(p.read_text(encoding='utf8'));source=[(str(i),f) for i,f in enumerate(original['features']) if f['properties']['district'].lower()==slug];byid={f['id']:f for f in d['features']};assert set(byid)==set(i for i,f in source)
 assert all(transform(projection,shape(f['geometry'])).equals_exact(shape(byid[i]['geometry']),1e-12) for i,f in source)
 villages=unary_union([shape(f['geometry']) for f in d['features']]);w,s,e,n=villages.bounds;frame=box(w-.012,s-.012,e+.012,n+.012)
 district=shape(json.loads((root/'application/public/maps/puducherry-source-districts'/f'{outline}.geojson').read_text())['features'][0]['geometry']).intersection(frame)
 tn=tamil.intersection(frame);gap=district.difference(villages);overlap=villages.intersection(tn)
 layers=[('tamil-nadu','Tamil Nadu · SOI administrative source',tn),('uncovered','SOI Puducherry outline without settlement coverage · unresolved',gap),('overlap','Settlement / Tamil Nadu source overlap · unresolved',overlap),('outline','Puducherry district outline · separate SOI edition',district)]
 features=[{'type':'Feature','id':key,'properties':{'kind':key,'name':name},'geometry':mapping(g)} for key,name,g in layers if not g.is_empty]
 meta={'source':'https://surveyofindia.gov.in/documents/State_District_Subdistrict_PAN%20INDIA.rar','archive_sha256':'b8325e5d9dd0f04a6663d775363fe38cd2f23bd9dbae3fb7118b4e6e0ce0bcb7','temporal_scope':'2022–2025; district catalogue published 2026-05-06','reference_map':'https://ceopuducherry.py.gov.in/images/overall.jpg','reference_map_date':'not stated on map; retrieved 2026-10-23','operations':'reprojection; viewport intersection; source comparisons only. No repair, simplification or village creation.','settlement_sha256':hashlib.sha256(p.read_bytes()).hexdigest()}
 out=root/'application/public/maps'/f'puducherry-{slug}-boundary-context.geojson';out.write_bytes(json.dumps({'type':'FeatureCollection','features':features,'metadata':meta},ensure_ascii=False,separators=(',',':')).encode())
 report={'group':slug,'original_features':len(source),'published_features':len(byid),'geometry_identical':True,'added_village_polygons':0,'context_sha256':hashlib.sha256(out.read_bytes()).hexdigest(),'classification':'Tamil Nadu uses actual SOI state polygon; uncovered areas and overlaps are unresolved differences, not missing-village identities.'};reports.append(report)
(root/'pilot/data/puducherry-gap-verification.json').write_bytes((json.dumps({'date':'2026-10-23','groups':reports,'official_references':['https://ceopuducherry.py.gov.in/images/overall.jpg','https://sec.py.gov.in/puducherry-ward-maps','https://lad.py.gov.in/sites/default/files/g.o.ms.no.22-dt-15.11.2024.pdf'],'finding':'All original settlement records retained. No additional georeferenced village polygon verified from the reviewed official references. Ward, revenue-village and source settlement editions must not be conflated.'},indent=2)+'\n').encode())
print(reports)
