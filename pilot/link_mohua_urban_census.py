"""Resolve historical town references using MoHUA body identifiers and LGD district crosswalks."""
import pathlib,json,sqlite3,collections,shutil,re,hashlib
from extend_urban_census_scopes import scoped_records

def norm(s):return re.sub(r'[^a-z0-9]','',s.lower().replace('&','and'))
def town_name(s):
    # Only remove Census administrative scope labels, not arbitrary disambiguators.
    allowed={'m','mcorp','mcorpog','mog','cmc','cmcog','tmc','tmcog','tp','ct','cb','np','npp','nac','nagarpanchayat','part','og','mcl','mc','mb','tc'}
    groups=re.findall(r'\(([^()]*)\)',s)
    if any(norm(g) not in allowed for g in groups):return None
    return norm(re.sub(r'\([^()]*\)','',s))

def build():
    root=pathlib.Path('/home/pollmedia/app');stage=pathlib.Path('/home/pollmedia/tmp/urban-official-20261102');raw=root/'pilot/raw'
    bodies=json.loads((stage/'pmay-ulbs.json').read_text());by_code=collections.defaultdict(list)
    for b in bodies:by_code[b['code']].append(b)
    districts=[]
    for f in (raw/'lgd-urban-parents-20261102').glob('districts-*.json'):districts+=json.loads(f.read_text())['records']
    historical_district_names=collections.defaultdict(set)
    for d in json.loads((stage/'published-districts-2011.json').read_text()):historical_district_names[(d['state_code'].zfill(2),d['district_code'].zfill(3))].add(norm(d['name']))
    def district_label_matches(d,label):
        if norm(d['district_name_english'])==norm(label):return True
        return norm(label) in historical_district_names.get((str(d['state_census2011_code']).zfill(2),str(d['district_census2011_code']).zfill(3)),set())
    towns=collections.defaultdict(list);by_census=collections.defaultdict(list)
    for line in (raw/'urban-census-links-20261102/published-urban-2011.jsonl').open():
        r=json.loads(line)
        if r['level']!='TOWN':continue
        r['geo']=json.loads(r['geography']);n=town_name(r['name'])
        if n:towns[(r['state_code'].zfill(2),r['district_code'].zfill(3),n)].append(r)
        by_census[(r['state_code'].zfill(2),str(r['geo'].get('Town/Village')))].append(r)
    old_ut_bodies=collections.defaultdict(list)
    for f in (raw/'lgd-urban-parents-20261102').glob('type-*.json'):
        for b in json.loads(f.read_text())['records']:
            if str(b['stateCode']) in ('37','38'):old_ut_bodies[(str(b['localBodyCode']),str(b['stateCode']))].append(b)
    postal=sqlite3.connect(root/'application/storage/app/postal-places/links.sqlite')
    places={(c,s):(n,sn) for c,s,n,sn in postal.execute("select distinct code,state,name,state_name from places where kind='local-body'")}
    base=root/'application/storage/app/postal-places/urban-census.sqlite';out=stage/'urban-census.sqlite';shutil.copy2(base,out);db=sqlite3.connect(out)
    linked={(c,s) for c,s in db.execute("select distinct code,state from links where kind='local-body'")};remaining=places.keys()-linked;added=[];reasons=collections.Counter()
    for key in sorted(remaining):
        code,state=key;name,state_name=places[key]
        if state in ('37','38') and key in old_ut_bodies:
            official_codes={str(b['localBodyCensus2011Code']).zfill(6) for b in old_ut_bodies[key]}
            states=('01',) if state=='37' else ('25','26')
            candidates=[r for oldstate in states for towncode in official_codes for r in by_census.get((oldstate,towncode),[])]
            if len(official_codes)==1 and len({(r['state_code'],r['geo'].get('Town/Village')) for r in candidates})==1:
                rs=scoped_records(candidates)
                if rs:
                    for r in rs:
                        source='https://ldma.ladakh.gov.in/aboutleh.html' if state=='37' else 'https://ddd.gov.in/introduction/'
                        db.execute('insert into links values (?,?,?,?,?,?,?)',('local-body',code,state,str(r['id']),'historical-ut-code',name,source))
                        added.append({'code':code,'state':state,'name':name,'record':r['id'],'record_name':r['name'],'lgd_record':old_ut_bodies[key][0],'census_geography':r['geo'],'basis':'Exact official LGD Census town code in pre-reorganisation 2011 state edition','history_source':source})
                    continue
        bs=[b for b in by_code[code] if norm(b['state'])==norm(state_name) and norm(b['name'].split(',')[0])==norm(name) and ',' in b['name'] and 'DEVELOPMENT AUTHORITY' not in b['name']]
        if len(bs)!=1:reasons['no_unique_mohua_body_identity']+=1;continue
        b=bs[0];ds=[d for d in districts if str(d['state_code'])==state and district_label_matches(d,b['district'])]
        if len(ds)!=1:reasons['no_unique_official_district']+=1;continue
        d=ds[0];cs=str(d['state_census2011_code']).zfill(2);cd=str(d['district_census2011_code']).zfill(3)
        if state=='37':cs='01'
        if state=='38':
            # Codes belong to different 2011 UT editions; only a unique state/district/name match is allowed.
            candidates=[r for oldstate in ('25','26') for r in towns.get((oldstate,cd,norm(name)),[])]
        else:
            if state=='36' and cs in ('00','36'):cs='28'
            candidates=towns.get((cs,cd,norm(name)),[])
        codes={str(r['geo'].get('Town/Village')) for r in candidates}
        if len(codes)!=1:reasons['no_unique_2011_town_in_crosswalk_district']+=1;continue
        rs=scoped_records(candidates)
        if not rs:reasons['conflicting_published_values']+=1;continue
        for r in rs:
            source='https://pmay-urban.gov.in/list-ulb.pdf#page='+str(b['page'])
            db.execute('insert into links values (?,?,?,?,?,?,?)',('local-body',code,state,str(r['id']),'official-town-reference',name,source))
            added.append({'code':code,'state':state,'name':name,'record':r['id'],'record_name':r['name'],'mohua_row':b,'lgd_district':d,'census_geography':r['geo'],'basis':'MoHUA exact body code, state and name; LGD district name and Census district crosswalk; unique 2011 Census town identity. Historical reference only; current-boundary equivalence unverified.'})
    db.commit();now={(c,s) for c,s in db.execute("select distinct code,state from links where kind='local-body'")}
    audit={'starting_unresolved':len(remaining),'newly_linked':len(now-linked),'remaining':len(places.keys()-now),'linked_postal_bodies':len(places.keys()&now),'new_record_links':len(added),'states':dict(collections.Counter(s for c,s in now-linked)),'exceptions':dict(reasons),'mohua_rows':len(bodies),'mohua_pdf_sha256':hashlib.sha256((stage/'pmay-list-ulb.pdf').read_bytes()).hexdigest(),'source':'https://pmay-urban.gov.in/list-ulb.pdf','policy':'Historical 2011 town references; no current-boundary equivalence, no fuzzy names or population aggregation'}
    db.execute('update metadata set json=?',(json.dumps(audit),));db.commit();db.close()
    for name,data in [('audit',audit),('added',added),('unresolved',[{'code':c,'state':s,'name':places[(c,s)][0]} for c,s in sorted(places.keys()-now)])]: (stage/(name+'.json')).write_text(json.dumps(data,indent=2))
    print(json.dumps(audit));print(json.dumps(added[:3]))
if __name__=='__main__':build()
