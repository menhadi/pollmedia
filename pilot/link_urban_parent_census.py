"""Resolve historical town references within explicit LGD parent crosswalks."""
import collections,json,pathlib,sqlite3,shutil
from link_mohua_urban_census import norm,town_name
from extend_urban_census_scopes import scoped_records

def parent_candidates(body, parents, towns):
    name=norm(body['localBodyNameEnglish'])
    matches={}
    for parent in parents:
        cs=str(parent['state_census2011_code']).zfill(2)
        if str(body['stateCode'])=='36' and cs in ('00','36'):cs='28'
        district=str(parent['district_census2011_code']).zfill(3)
        sub=str(parent.get('subdistrict_census2011_code','')).zfill(5)
        if not district.isdigit() or int(district)==0:continue
        if 'subdistrict_code' in parent and (not sub.isdigit() or int(sub)==0):continue
        for r in towns.get((cs,district,name),[]):
            if '(CB)' in r['name']:continue
            if 'subdistrict_code' in parent and str(r['geo'].get('Subdistt')).zfill(5)!=sub:continue
            matches[r['id']]=r
    codes={(r['state_code'],r['district_code'],r['geo'].get('Town/Village')) for r in matches.values()}
    return scoped_records(list(matches.values())) if len(codes)==1 else []

def build():
    root=pathlib.Path('/home/pollmedia/app');raw=root/'pilot/raw/lgd-urban-parents-20261102';stage=pathlib.Path('/home/pollmedia/tmp/urban-ancestry-followup-20261102');stage.mkdir(exist_ok=True)
    parents=collections.defaultdict(list)
    for prefix,kind,field in [('districts','district','district_code'),('subdistricts','subistrict','subdistrict_code')]:
        for f in raw.glob(prefix+'-*.json'):
            for p in json.loads(f.read_text())['records']:parents[(str(p['state_code']),kind,str(p[field]))].append(p)
    towns=collections.defaultdict(list)
    for line in (root/'pilot/raw/urban-census-links-20261102/published-urban-2011.jsonl').open():
        r=json.loads(line)
        if r['level']!='TOWN':continue
        r['geo']=json.loads(r['geography']);n=town_name(r['name'])
        if n:towns[(r['state_code'].zfill(2),r['district_code'].zfill(3),n)].append(r)
    postal=sqlite3.connect(root/'application/storage/app/postal-places/links.sqlite');places={(c,s):n for c,s,n in postal.execute("select distinct code,state,name from places where kind='local-body'")}
    out=stage/'urban-census.sqlite';shutil.copy2(root/'application/storage/app/postal-places/urban-census.sqlite',out);db=sqlite3.connect(out);old={(c,s) for c,s in db.execute("select distinct code,state from links where kind='local-body'")}
    bodies=collections.defaultdict(list)
    for f in raw.glob('type-*.json'):
        for b in json.loads(f.read_text())['records']:
            key=(str(b['localBodyCode']),str(b['stateCode']))
            if key in places and key not in old and b['entityType'].lower() in ('district','subistrict','subdistrict'):bodies[key].append(b)
    # Resolve covered villages through the archived LGD ancestry, never polygon proximity.
    ancestry=sqlite3.connect(root/'application/storage/app/electoral-villages/links.sqlite')
    village_parents={str(c):(str(s),str(d),str(t)) for c,s,d,t in ancestry.execute('select lgd,state,district,subdistrict from ancestry')}
    for f in raw.glob('type-*.json'):
        for b in json.loads(f.read_text())['records']:
            key=(str(b['localBodyCode']),str(b['stateCode']))
            if key not in places or key in old or b['entityType'].lower()!='village':continue
            v=village_parents.get(str(b['entityCode']))
            if not v or v[0]!=key[1]:continue
            bodies[key].append({**b,'entityType':'Subistrict','entityCode':v[2],'covered_village':b['entityCode']})
    added=[]
    name_counts=collections.Counter((s,norm(n)) for (c,s),n in places.items())
    for key,bs in bodies.items():
        if name_counts[(key[1],norm(places[key]))]!=1:continue
        candidates={};evidence=[]
        if any(norm(b['localBodyNameEnglish'])!=norm(places[key]) for b in bs):continue
        for b in bs:
            kind=b['entityType'].lower().replace('subdistrict','subistrict');ps=parents.get((key[1],kind,str(b['entityCode'])),[])
            # Conflicting parent crosswalks are not resolved by choosing one.
            if len(ps)!=1:continue
            rs=parent_candidates(b,ps,towns)
            if rs:
                evidence.append({'body':b,'parent':ps[0]})
                for r in rs:candidates[r['id']]=r
        if len({(r['state_code'],r['geo'].get('Town/Village')) for r in candidates.values()})!=1:continue
        for r in candidates.values():
            db.execute('insert into links values(?,?,?,?,?,?,?)',('local-body',*key,str(r['id']),'lgd-parent-reference',places[key],'https://www.data.gov.in/resource/local-government-directory-lgd-local-bodies'))
            added.append({'code':key[0],'state':key[1],'name':places[key],'record':r['id'],'record_name':r['name'],'geography':r['geo'],'evidence':evidence})
    # Explicit government rename evidence, restricted to Karnataka and the official district.
    aliases={'vijayapura':'bijapur','vijapura':'bijapur','chikkamagaluru':'chikmagalur','hosapete':'hospet','kalaburagi':'gulbarga','ballari':'bellary','shivamogga':'shimoga','tumakuru':'tumkur','belagavi':'belgaum','mangaluru':'mangalore','bengaluru':'bangalore','hubballi':'hubli'}
    mohua=json.loads((root/'pilot/raw/urban-official-links-20261102/pmay-ulbs.json').read_text())
    historical=json.loads((root/'pilot/raw/urban-official-links-20261102/published-districts-2011.json').read_text())
    for key,name in places.items():
        if key in old or key[1]!='29' or norm(name) not in aliases:continue
        bs=[b for b in mohua if b['code']==key[0] and norm(b['state'])=='karnataka' and norm(b['name'].split(',')[0]) in (norm(name),aliases[norm(name)])]
        if len(bs)!=1:continue
        district_label=norm(bs[0]['district']);district_label=aliases.get(district_label,district_label)
        ds={d['district_code'].zfill(3) for d in historical if d['state_code']=='29' and norm(d['name'])==district_label}
        # Hosapete's post-2011 district is identified by the official district history.
        if key==('251922','29') and district_label=='vijaynagar':ds={d['district_code'].zfill(3) for d in historical if d['state_code']=='29' and norm(d['name'])=='bellary'}
        if len(ds)!=1:continue
        rs=towns.get(('29',next(iter(ds)),aliases[norm(name)]),[])
        if len({r['geo'].get('Town/Village') for r in rs})!=1:continue
        for r in scoped_records(rs):
            source='https://www.pib.gov.in/newsite/PrintRelease.aspx?lang=2&reg=48&relid=113433'
            db.execute('insert into links values(?,?,?,?,?,?,?)',('local-body',*key,str(r['id']),'official-rename-reference',name,source))
            added.append({'code':key[0],'state':key[1],'name':name,'record':r['id'],'record_name':r['name'],'geography':r['geo'],'mohua_row':bs[0],'rename_source':source,'district_history_source':'https://vijayanagara.nic.in/en/history/' if key[0]=='251922' else None})
    db.commit();now={(c,s) for c,s in db.execute("select distinct code,state from links where kind='local-body'")};remaining=[{'code':c,'state':s,'name':places[(c,s)]} for c,s in sorted(places.keys()-now)]
    audit={'starting_unresolved':len(places.keys()-old),'newly_linked':len(now-old),'remaining':len(remaining),'new_record_links':len(added),'states':dict(collections.Counter(s for c,s in now-old)),'policy':'Exact name within explicit LGD parent crosswalk, including covered-village ancestry, or official rename plus district evidence; unique historical town; no current extent equivalence or aggregation'}
    db.execute('update metadata set json=?',(json.dumps(audit),));db.commit();db.close()
    for n,data in [('audit',audit),('added',added),('unresolved',remaining)]:(stage/(n+'.json')).write_text(json.dumps(data,indent=2))
    print(json.dumps(audit));print(json.dumps([{k:v for k,v in a.items() if k!='evidence'} for a in added[:15]]))
if __name__=='__main__':build()

