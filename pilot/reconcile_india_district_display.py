"""Reconcile display navigation against original SOI district IDs and existing crosswalks.
Only derived display/audit files are written; source geometry and identity tables are read-only.
"""
import argparse, collections, hashlib, json, re, sqlite3
from pathlib import Path
import shapefile
from pyproj import CRS, Transformer
from shapely.geometry import shape, mapping
from shapely.ops import transform


def build(source, root):
    root=Path(root); source=Path(source)
    display_path=root/'application/public/maps/india-district-display.json'
    old=json.loads((root/'tmp/india-map/original-district-display.json').read_text(encoding='utf-8'))
    previous=json.loads(display_path.read_text(encoding='utf-8'))
    source_hash=hashlib.sha256(source.read_bytes()).hexdigest()
    cached={f['properties'].get('source_properties',{}).get('OBJECTID'):f['geometry'] for f in previous['features']} if previous.get('metadata',{}).get('district_source',{}).get('shapefile_sha256')==source_hash else {}
    catalogue=json.loads((root/'application/storage/app/india-map/catalogue.json').read_text(encoding='utf-8'))
    layers={l['file']:l for l in catalogue['layers']+catalogue.get('context_layers',[])}
    db=sqlite3.connect(f"file:{(root/'application/storage/app/electoral-villages/links.sqlite').as_posix()}?mode=ro",uri=True)
    states=dict(db.execute('SELECT code,name FROM states'))
    names={(s,d):n for s,d,n in db.execute('SELECT a.state,a.district,MIN(d.district) FROM ancestry a JOIN directory d ON d.lgd=a.lgd AND d.state=a.state GROUP BY a.state,a.district')}
    for st,code,name in db.execute("SELECT state,entity_code,MIN(entity_name) FROM membership WHERE entity_type='District' GROUP BY state,entity_code"):names.setdefault((st,code),name)
    census=collections.defaultdict(set)
    for line in (root/'tmp/india-map/census-village-district-links.jsonl').open():
        s,d,v=json.loads(line);census[(s,str(v).zfill(6))].add(d)
    dashboards={}
    for row in json.loads((root/'tmp/india-map/district-dashboard-index.json').read_text()):dashboards[(row['state'],row['district'])]=row
    associations=collections.defaultdict(set)
    for st,dist,code in db.execute('SELECT a.state,a.district,d.census2011 FROM ancestry a JOIN directory d ON a.lgd=d.lgd AND a.state=d.state'):
        if not code or not str(code).isdigit() or int(code) in (0,999999):continue
        for cs in {'36':['28'],'37':['01'],'38':['25','26']}.get(st,[st.zfill(2)]):
            for cd in census.get((cs,str(code).zfill(6)),[]):associations[(st,dist)].add((cs,cd))
    navigation={}
    for key in set(names)|set(associations):
        state=states.get(key[0],'');slug=re.sub('[^a-z0-9]+','-',state.lower().replace('&','and').removeprefix('the ')).strip('-')
        links=[{'label':f'Open {state} state dashboard','url':f'https://pollmedia.org/india/state/{slug}'}]
        files=[]
        for ck in sorted(associations[key]):
            row=dashboards.get(ck)
            if row:
                links.append({'label':f"Open {row['name']} district dashboard",'url':row['url'].replace('/india/pilots/pilibhit-district','/india/districts/09/151/dashboard').replace('/india/pilots/rampur-district','/india/districts/09/136/dashboard')})
                if row.get('file') in layers:files.append(layers[row['file']]['id'])
        navigation[':'.join(key)]={'navigation':links,'source_layers':sorted(set(files)),'basis':'Existing LGD village Census 2011 codes and published Census district identities; historical district boundaries are not asserted to be current.'}
    reader=shapefile.Reader(str(source),encoding='utf-8')
    project=Transformer.from_crs(CRS.from_wkt(source.with_suffix('.prj').read_text()),4326,always_xy=True).transform
    features=[];seen=set();source_counts=collections.Counter()
    def normalize(text):
        value=re.sub('[^a-z0-9]','',text.lower().replace('>','a').replace('<','a').replace('@','u').replace('#','u').replace('|','i').replace(chr(92),'i')).removeprefix('the')
        aliases={'ramanagara':'bengalurusouth','ramanagar':'bengalurusouth','ysr':'ysrkadapa','allurisitaramaraju':'allurisitharamaraju','darang':'darrang','westkarbianaglong':'westkarbianglong','sibsagar':'sivasagar','kamruprural':'kamrup','southsalmara':'southsalmaramancachar','purbachamparan':'purbichamparan','kawardhakabirdham':'kabeerdham','balrampur':'balrampurramanujganj','dakshinbastardantewara':'dakshinbastardantewada','khairgarhchhuikhadangandai':'khairagarhchhuikhadangandai','mohlamanpurambagarhchowki':'mohlamanpurambagarhchouki','manendragarhchirmiribharatpur':'manendragarhchirmiribharatpurmcb','balodabazar':'balodabazarbhatapara','ahmadabad':'ahmedabad','charkidadri':'charkhidadri','bandipura':'bandipora','rajauri':'rajouri','riasi':'reasi','eastsinghbhum':'eastsinghbum','sahibganj':'sahebganj','bagalkot':'bagalkote','chamarajanagara':'chamarajanagar','kolara':'kolar','leh':'lehladakh','lakshadweepdistrict':'lakshadweep','khandwaeastnimar':'khandwa','khargonewestnimar':'khargone','kamjang':'kamjong','keonjharkendujhar':'kendujhar','balasore':'baleshwar','jagatsinghpur':'jagatsinghapur','deogarh':'debagada','nuaparha':'nuapada','rayagarha':'rayagada','sasnagarsahibzadaajitsinghnagar':'sasnagar','chittaurgarh':'chittorgarh','dhaulpur':'dholpur','jalor':'jalore','jhunjhunun':'jhunjhunu','thoothukudi':'thoothukkudi','tiruvallur':'thiruvallur','jagtial':'jagitial','jangaon':'jangoan','jayashankarbhupalpalli':'jayashankarbhupalapally','kumurambheem':'kumurambheemasifabad','mahbubnagar':'mahabubnagar','gomti':'gomati','puruliya':'purulia','paschimbarddhaman':'paschimbardhaman','purbabarddhaman':'purbabardhaman','hugli':'hooghly','haora':'howrah','northtwentyfourparganas':'north24parganas','maldah':'malda','darjiling':'darjeeling','kochbihar':'coochbehar'}
        return aliases.get(value,value)
    reverse=collections.defaultdict(set)
    for current,dated in associations.items():
        for census_key in dated:reverse[census_key].add(current)
    source_names=collections.defaultdict(set)
    for layer in layers.values():source_names[normalize(layer['state'])].add(normalize(layer['district']))
    for record in reader.iterShapeRecords():
        p=record.record.as_dict()
        if not str(p['STATE_LGD']).strip().isdigit() or not str(p['DIST_LGD']).strip().isdigit():continue
        st=str(int(p['STATE_LGD']));district=str(int(p['DIST_LGD']))
        if st=='26' and p['STATE_UT']=='DADRA & NAGAR HAVELI & DAMAN & DIU':st='38'
        key=(st,district)
        if st not in states or int(district)<=0:continue
        geometry=cached.get(p['OBJECTID'])
        if geometry is None:geometry=mapping(transform(project,shape(record.shape.__geo_interface__)).simplify(0.002,preserve_topology=True))
        census_state={'36':'28','37':'01'}.get(st,st.zfill(2))
        dated=dashboards.get((census_state,district.zfill(3)))
        matches=[]
        if dated and (normalize(dated['name'])==normalize(p['DISTRICT']) or (dated.get('file') in layers and normalize(layers[dated['file']]['district'])==normalize(p['DISTRICT']))):
            matches=[{'state':a,'district':b} for a,b in sorted(reverse[(census_state,district.zfill(3))]) if a==st]
        # A raw SOI field labelled LGD is never assumed to be a current LGD identifier.
        current_key=(st,district)
        if not matches and current_key in names and normalize(names[current_key])==normalize(p['DISTRICT']):matches=[{'state':st,'district':district}]
        same_name=[k for k,n in names.items() if k[0]==st and normalize(n)==normalize(p['DISTRICT'])]
        if len(same_name)==1:matches=[{'state':st,'district':same_name[0][1]}]
        if len(matches)==1 and dated and (normalize(dated['name'])==normalize(p['DISTRICT']) or (dated.get('file') in layers and normalize(layers[dated['file']]['district'])==normalize(p['DISTRICT']))):
            entry=navigation.get(st+':'+matches[0]['district'])
            if entry and not any(n['url']==dated['url'] for n in entry['navigation']):entry['navigation'].append({'label':f"Open {dated['name']} district dashboard",'url':dated['url']})
            if entry and dated.get('file') in layers:entry['source_layers']=sorted(set(entry['source_layers']+[layers[dated['file']]['id']]))
        for match in matches:seen.add((match['state'],match['district']))
        feature={'type':'Feature','id':f"soi-district-{p['OBJECTID']}",'properties':{'state':states[st],'name':p['DISTRICT'],'state_code':st,'district_code':matches[0]['district'] if len(matches)==1 else None,'lgd_districts':matches,'source_properties':p,'basis':'SOI district boundary, source temporal scope 2022–2025. Source codes retained verbatim; current district navigation uses existing code crosswalks or a unique same-state district label. This is navigation, not a village-data or boundary-equivalence join.'},'geometry':geometry}
        features.append(feature);source_counts[st]+=1
    unmapped=[]
    old['features']=features;old['dashboards']=navigation
    old['metadata']['navigation_name_sources']=[{'source_name':'Ramanagara','current_name':'Bengaluru South','url':'https://bengalurusouth.nic.in/en/history/'}]
    old['metadata']['district_source']={'url':'https://surveyofindia.gov.in/documents/State_District_Subdistrict_PAN%20INDIA.rar','previously_recorded_archive_sha256':'b8325e5d9dd0f04a6663d775363fe38cd2f23bd9dbae3fb7118b4e6e0ce0bcb7','shapefile_sha256':hashlib.sha256(source.read_bytes()).hexdigest(),'temporal_scope':'2022–2025','display_simplification_degrees':0.002}
    audit=[]
    for st,name in sorted(states.items(),key=lambda row:row[1]):
        current={k for k in names if k[0]==st};missing=sorted(current-seen)
        audit.append({'state_code':st,'state':name,'lgd_districts_with_village_records':len(current),'soi_district_features':source_counts[st],'current_district_navigation_unresolved':[{'code':k[1],'name':names[k]} for k in missing],'districts_with_published_dashboard_links':sum(any('Census 2011' in n['label'] for n in v['navigation']) for k,v in navigation.items() if k.startswith(st+':'))})
    old['metadata']['district_audit']=audit
    display_path.write_text(json.dumps(old,separators=(',',':'),ensure_ascii=False),encoding='utf-8')
    (root/'tmp/india-map/state-district-audit.json').write_text(json.dumps(audit,indent=2,ensure_ascii=False),encoding='utf-8')
    print(json.dumps({'soi_features':sum(source_counts.values()),'fallback_source_groups':len(unmapped),'states':len(audit),'district_navigation_unresolved':sum(len(r['current_district_navigation_unresolved']) for r in audit),'bytes':display_path.stat().st_size}))


if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('source');parser.add_argument('root');a=parser.parse_args();build(a.source,a.root)
