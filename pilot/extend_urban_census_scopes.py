"""Add verified 2011 town scopes without conflating municipalities, outgrowths or parts."""
import collections,json,pathlib,sqlite3,shutil,hashlib

def scoped_records(records):
    groups=collections.defaultdict(list)
    for r in records:
        g=r['geo']
        key=(r['state_code'],r['district_code'],g.get('Subdistt'),g.get('Town/Village'),g.get('Ward'),r['name'])
        groups[key].append(r)
    result=[]
    for group in groups.values():
        if len({json.dumps(json.loads(r['values']),sort_keys=True) for r in group})==1:
            result.append(max(group,key=lambda r:int(r['id'])))
    return result

def build():
    root=pathlib.Path('/home/pollmedia/app');stage=pathlib.Path('/home/pollmedia/tmp/urban-scopes-20261102');stage.mkdir(exist_ok=True)
    source=root/'pilot/raw/urban-census-links-20261102/published-urban-2011.jsonl'
    towns=collections.defaultdict(list)
    for line in source.open():
        r=json.loads(line)
        if r['level']!='TOWN':continue
        r['geo']=json.loads(r['geography']);towns[(r['state_code'].zfill(2),str(r['geo'].get('Town/Village')))].append(r)
    scoped={k:scoped_records(rs) for k,rs in towns.items()}
    bodies=collections.defaultdict(list)
    for f in (root/'pilot/raw/lgd-urban-parents-20261102').glob('type-*.json'):
        for r in json.loads(f.read_text())['records']:
            if str(r['localBodyTypeCode']) in ('4','5','6','7'):bodies[(str(r['localBodyCode']),str(r['stateCode']))].append(r)
    directory=sqlite3.connect(root/'application/storage/app/electoral-villages/links.sqlite')
    villages={c:(s,cc) for c,s,cc in directory.execute('select lgd,state,census2011 from directory')}
    post=sqlite3.connect(root/'application/storage/app/postal-places/links.sqlite');postal={(c,s):n for c,s,n in post.execute("select distinct code,state,name from places where kind='local-body'")}
    current=root/'application/storage/app/postal-places/urban-census.sqlite';out=stage/'urban-census.sqlite';shutil.copy2(current,out);db=sqlite3.connect(out)
    existing={(c,s) for c,s in db.execute("select distinct code,state from links where kind='local-body'")};added=[]
    def add(key,r,basis,label):
        row=('local-body',*key,str(r['id']),basis,label,'https://www.data.gov.in/resource/local-government-directory-lgd-local-bodies')
        db.execute('insert into links values(?,?,?,?,?,?,?)',row);added.append({'code':key[0],'state':key[1],'record':r['id'],'basis':basis,'name':label,'record_name':r['name'],'geography':r['geo']})
    for key,rs in bodies.items():
        if key in existing:continue
        candidates={}
        for r in rs:
            state=str(r['stateCensus2011Code']).zfill(2)
            if key[1]=='36' and state in ('00','36'):state='28'
            code=str(r['localBodyCensus2011Code']).zfill(6)
            for c in scoped.get((state,code),[]):candidates[c['id']]=c
        if candidates:
            for c in candidates.values():add(key,c,'lgd-town-scopes',rs[0]['localBodyNameEnglish'])
            continue
        # Coverage is an association, never evidence of identical extents.
        for r in rs:
            if r['entityType'].lower()!='village':continue
            v=villages.get(str(r['entityCode']))
            if not v or v[0]!=key[1]:continue
            states=['28'] if v[0]=='36' else [v[0].zfill(2)]
            for state in states:
                for c in scoped.get((state,str(v[1]).zfill(6)),[]):candidates[c['id']]=c
        for c in candidates.values():add(key,c,'coverage-only',rs[0]['localBodyNameEnglish'])
    db.commit()
    now={(c,s) for c,s in db.execute("select distinct code,state from links where kind='local-body'")}
    related={(c,s) for c,s in db.execute("select distinct code,state from links where kind='local-body' and basis='coverage-only'")}
    missing=[{'code':c,'state':s,'name':n,'reason':'No verified available published town link from official codes or village coverage' if (c,s) in bodies else 'No matching body in imported official coverage source'} for (c,s),n in postal.items() if (c,s) not in now]
    audit={'postal_bodies_audited':len(postal),'previous_linked_postal_bodies':len(existing&postal.keys()),'newly_linked_postal_bodies':len((now-existing)&postal.keys()),'linked_postal_bodies':len(now&postal.keys()),'remaining_postal_bodies':len(missing),'new_scope_records':len(added),'new_related_only_postal_bodies':len((related-existing)&postal.keys()),'states_new_bodies':dict(collections.Counter(s for c,s in (now-existing)&postal.keys())),'source_sha256':hashlib.sha256(source.read_bytes()).hexdigest(),'policy':'Separate 2011 source scopes; no summation; exact codes only; coverage-only references never become current-area totals','telangana_2011_state_basis':'https://www.telangana.gov.in/departments/legislative-affairs/'}
    db.execute('update metadata set json=?',(json.dumps(audit),));db.commit();db.close()
    for name,data in [('audit',audit),('added',added),('unresolved',missing)]: (stage/(name+'.json')).write_text(json.dumps(data,indent=2))
    print(json.dumps(audit))
if __name__=='__main__':build()
