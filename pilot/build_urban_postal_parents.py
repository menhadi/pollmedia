import json,sqlite3,pathlib,collections,hashlib,sys
root=pathlib.Path(sys.argv[1]); postal=sqlite3.connect(sys.argv[2]); villages=sqlite3.connect(sys.argv[3]); out=pathlib.Path(sys.argv[4])
def pages(prefix):
    files=sorted(root.glob(prefix+'-*.json'),key=lambda p:int(p.stem.rsplit('-',1)[1])); rows=[]; total=None; date=None
    for f in files:
        j=json.loads(f.read_text()); offset=int(f.stem.rsplit('-',1)[1]); assert offset==len(rows),(prefix,'gap')
        if total is not None: assert total==int(j['total']) and date==j['updated_date']
        total=int(j['total']); date=j['updated_date'];rows+=j['records']
    assert total is not None and len(rows)==total,(prefix,'incomplete')
    return rows,date
D,ddate=pages('districts'); S,sdate=pages('subdistricts')
districts={str(r['district_code']):r for r in D}; subs={str(r['subdistrict_code']):r for r in S}
urban={(str(c),str(s)):n for c,s,n in postal.execute("select distinct code,state,name from places where kind='local-body'")}
vmap={str(c):(str(s),str(d),str(t)) for c,s,d,t in villages.execute('select lgd,state,district,subdistrict from ancestry')}
parents={}; exceptions=collections.Counter(); found=set(); sources={}; types={}
for typ in range(4,16):
    try: rows,date=pages('type-'+str(typ))
    except AssertionError: exceptions['incomplete_type_'+str(typ)]+=1;continue
    sources[str(typ)]=date
    for r in rows:
        code,state=str(r['localBodyCode']),str(r['stateCode']); key=(code,state)
        if key not in urban:continue
        found.add(key);types[str(typ)]=r['localBodyTypeName']; entity=str(r['entityCode']); kind=r['entityType'].lower(); cover=r['coverageType'];d=None;t=None
        if kind=='district': d=entity
        elif kind in ('subdistrict','subistrict'):t=entity;d=str(subs[t]['district_code']) if t in subs else None
        elif kind=='village':
            v=vmap.get(entity)
            if v and v[0]==state:d,t=v[1:]
        else:exceptions['entity_'+kind]+=1
        if not d or d not in districts or str(districts[d]['state_code'])!=state:exceptions['unresolved_district']+=1;continue
        p=(code,state,'district',d,d,districts[d]['district_name_english']);parents.setdefault(p,set()).add(cover)
        if t:
            if t in subs and str(subs[t]['state_code'])==state and str(subs[t]['district_code'])==d:
                p=(code,state,'subdistrict',t,d,subs[t]['subdistrict_name_english']);parents.setdefault(p,set()).add(cover)
            else:exceptions['unresolved_subdistrict']+=1
if out.exists():out.unlink()
db=sqlite3.connect(out);db.execute('create table parents(body text,state text,level text,code text,district text,name text,coverage text)')
db.executemany('insert into parents values (?,?,?,?,?,?,?)',[(*p,', '.join(sorted(c))) for p,c in sorted(parents.items())]);db.execute('create index parent_identity on parents(body,state)');db.execute('create table metadata(json text)')
audit={'source':'https://www.data.gov.in/resource/local-government-directory-lgd-local-bodies','dates':sources,'district_date':ddate,'subdistrict_date':sdate,'postal_bodies':len(urban),'source_matched_bodies':len(found),'bodies_with_district':len({p[:2] for p in parents if p[2]=='district'}),'bodies_with_subdistrict':len({p[:2] for p in parents if p[2]=='subdistrict'}),'relationships':len(parents),'exceptions':dict(exceptions),'types':types,'archives':{p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in root.glob('*.json') if not p.name.startswith('bodies-')}}
db.execute('insert into metadata values (?)',(json.dumps(audit),));db.commit();db.close();out.with_suffix('.audit.json').write_text(json.dumps(audit,indent=2));print(json.dumps({k:v for k,v in audit.items() if k!='archives'}))
