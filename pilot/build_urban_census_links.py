import json,sqlite3,pathlib,collections,sys,re,hashlib
root=pathlib.Path('/home/pollmedia/app');stage=pathlib.Path('/home/pollmedia/tmp/urban-census-20261102');raw=root/'pilot/raw/lgd-urban-parents-20261102'
rows={int(r['id']):r for r in map(json.loads,(stage/'published-urban-2011.jsonl').read_text().splitlines())};towns=collections.defaultdict(list)
for r in rows.values():
 g=json.loads(r['geography']);r['geo']=g
 if r['level']=='TOWN':
  code=str(g.get('Town/Village',''));state=str(r['state_code']).zfill(2)
  if re.fullmatch(r'\d{6}',code):towns[(state,code)].append(r)
def match(key):
 rs=towns.get(key,[])
 if not rs:return None
 identities={(r['district_code'],str(r['geo'].get('Subdistt')),json.dumps(json.loads(r['values']),sort_keys=True)) for r in rs}
 return max(rs,key=lambda r:int(r['id'])) if len(identities)==1 else None
postal=sqlite3.connect(root/'application/storage/app/postal-places/links.sqlite');urban={(c,s) for c,s in postal.execute("select distinct code,state from places where kind='local-body'")}
db=sqlite3.connect(root/'application/storage/app/electoral-villages/links.sqlite');villages={c:(s,cc,n) for c,s,cc,n in db.execute('select lgd,state,census2011,name from directory')}
bodyrows=collections.defaultdict(list)
for f in raw.glob('type-*.json'):
 for r in json.loads(f.read_text())['records']:
  if str(r['localBodyTypeCode']) in ('4','5','6','7'):bodyrows[(str(r['localBodyCode']),str(r['stateCode']))].append(r)
links={};exceptions=collections.Counter();states=collections.defaultdict(collections.Counter);unresolved=[]
def add(kind,code,state,r,basis,label,source):
 links[(kind,code,state,str(r['id']))]=(kind,code,state,str(r['id']),basis,label,source)
for key,rs in bodyrows.items():
 keys={(str(r['stateCensus2011Code']).zfill(2),str(r['localBodyCensus2011Code']).zfill(6)) for r in rs}
 candidate=match(next(iter(keys))) if len(keys)==1 else None
 if not candidate:
  why='multiple_codes' if len(keys)>1 else ('missing_code' if next(iter(keys))[1] in ('000000','0000NA','999999') else 'no_unique_published_town')
  exceptions[why]+=1;unresolved.append({'kind':'local-body','code':key[0],'state':key[1],'name':rs[0]['localBodyNameEnglish'],'reason':why});continue
 add('local-body',*key,candidate,'lgd-town-code',rs[0]['localBodyNameEnglish'],'https://www.data.gov.in/resource/local-government-directory-lgd-local-bodies')
 for r in rs:
  if r['entityType'].lower()!='village':continue
  code=str(r['entityCode']);v=villages.get(code)
  if v and v[0]==key[1] and (not v[1].isdigit() or int(v[1]) in (0,999999)):
   add('village',code,v[0],candidate,'coverage-only',r['localBodyNameEnglish'],'https://www.data.gov.in/resource/local-government-directory-lgd-local-bodies')
for code,(state,cc,name) in villages.items():
 historical={'36':'28','37':'01'}.get(state,state.zfill(2));keys=[(historical,str(cc).zfill(6))]
 if state=='38':keys=[('25',str(cc).zfill(6)),('26',str(cc).zfill(6))]
 candidates=[r for k in keys if (r:=match(k))]
 if len(candidates)==1:add('village',code,state,candidates[0],'lgd-town-code',name,'https://lgdirectory.gov.in/downloadDirectory.do')
k=[r for r in rows.values() if r['level']=='WARD' and r['state_code']=='29' and r['district_code']=='572' and r['geo'].get('Town/Village')=='803162' and r['geo'].get('Ward')=='0159']
assert k and len({r['values'] for r in k})==1
add('village','938441','29',max(k,key=lambda r:r['id']),'named-ward','Kengeri — BBMP Ward 159','https://site.bbmp.gov.in/zonalclassification.html')
out=stage/'urban-census.sqlite';out.unlink(missing_ok=True);target=sqlite3.connect(out)
target.execute('create table links(kind text,code text,state text,record text,basis text,label text,source text)');target.executemany('insert into links values(?,?,?,?,?,?,?)',links.values());target.execute('create index identity_lookup on links(kind,code,state)')
for x in links.values():states[x[2]][x[0]+':'+x[4]]+=1
missing=sum(not v[1].isdigit() or int(v[1]) in (0,999999) for v in villages.values())
audit={'published_urban_records':len(rows),'town_keys':len(towns),'lgd_local_bodies_audited':len(bodyrows),'postal_local_bodies':len(urban),'villages_audited':len(villages),'villages_without_census_code':missing,'linked_local_bodies':len({x[1:3] for x in links.values() if x[0]=='local-body'}),'linked_postal_bodies':len({x[1:3] for x in links.values() if x[0]=='local-body' and x[1:3] in urban}),'village_town_code_links':sum(x[0]=='village' and x[4]=='lgd-town-code' for x in links.values()),'coverage_only_villages':len({x[1:3] for x in links.values() if x[4]=='coverage-only'}),'named_ward_links':1,'exceptions':dict(exceptions),'states':{s:dict(v) for s,v in states.items()},'published_export_sha256':hashlib.sha256((stage/'published-urban-2011.jsonl').read_bytes()).hexdigest()}
target.execute('create table metadata(json text)');target.execute('insert into metadata values(?)',(json.dumps(audit),));target.commit();target.close();(stage/'audit.json').write_text(json.dumps(audit,indent=2));(stage/'unresolved.json').write_text(json.dumps(unresolved));print(json.dumps(audit))
