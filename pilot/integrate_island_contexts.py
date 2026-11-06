from pathlib import Path
import json,hashlib,collections,re
root=Path('.'); regions={'31':'lakshadweep','34':'puducherry','35':'andaman-nicobar'}
rows=json.loads(Path('pilot/data/island-published-district-sources.json').read_text(encoding='utf-8-sig')); towns=json.loads(Path('pilot/data/island-published-town-contexts.json').read_text(encoding='utf-8-sig'));idx=json.loads(Path('pilot/data/island-constituency-index.json').read_text(encoding='utf-8-sig'))
def write(p,d):Path(p).write_bytes((json.dumps(d,ensure_ascii=False,indent=2)+'\n').encode())
for state,region in regions.items():
 ds={r['district_code']:{'code':r['district_code'],'name':r['name'],'state':state} for r in rows if r['year']==2011 and r['state_code']==state};write('application/database/fixtures/'+region+'-published-census-districts.json',list(ds.values()))
pcs=[];acs=[]
for r in idx:
 if r['kind']=='pc' and r['year']==2024:
  region={'Puducherry':'puducherry','Lakshadweep':'lakshadweep','Andaman & Nicobar Islands':'andaman-nicobar'}[r['state_label']];pcs.append({'region':region,'number':1,'name':r['constituency_name'],'archive_name':r['constituency_name'],'state':r['state_label'],'edition':r['edition_id'],'code':r['record_code'],'year':r['year']})
 if r['kind']=='ac' and r['year']==2026:
  n=int(r['record_code']);group='pondicherry' if n<=23 else 'karaikal' if n<=28 else 'mahe' if n==29 else 'yanam';acs.append({'region':'puducherry','number':n,'name':r['constituency_name'],'state':r['state_label'],'edition':r['edition_id'],'code':n,'year':2026,'group':group,'outline':'karaikal' if group=='karaikal' else 'puducherry'})
assert len(pcs)==3 and len(acs)==30
write('application/database/fixtures/island-electoral-context.json',{'pcs':pcs,'acs':sorted(acs,key=lambda x:x['number']),'source_ac':'https://ceopuducherry.py.gov.in/ac_wise_electrol.php','source_ac_date':'2026-09-16','source_regions':'https://ceopuducherry.py.gov.in/maps.php','source_pc':'https://elections24.eci.gov.in/docs/press-note-no-23.pdf','source_institutions':'https://www.mha.gov.in/web/sites/default/files/MHAARE_22042022%5B1%5D.pdf'})
alias={'lakshadweep-district':'587','pondicherry':'635','puducherry':'635','karaikal':'637','mahe':'636','yanam':'634','nicobars':'638','north-and-middle-andaman':'639','south-andamans':'640'}
ledger=[]
for region in regions.values():
 for path in Path('application/public/maps').glob(region+'-source-villages/*.geojson'):
  data=json.loads(path.read_text(encoding='utf8'));context=next(t for t in towns if t['code']==alias[path.stem]);
  for f in data['features']:
   p=f['properties']
   if p['place_type']!='urban':continue
   matches=[t for t in context['towns'] if str(t['code'])==str(p['source_code'])]
   if len(matches)==1:
    t=matches[0];p['census_context']={'id':t['id'],'name':t['name'],'code':t['code'],'year':2011,'url':'/india/census/places/'+str(t['id']),'source_url':t['source_url'],'edition_id':t['edition_id'],'match_basis':'same source and Census code; context only, boundary correspondence unverified','boundary_match':'unverified'}
   ledger.append({'region':region,'district':path.stem,'name':p['name'],'source_code':p['source_code'],'linked':len(matches)==1})
  path.write_bytes(json.dumps(data,ensure_ascii=False,separators=(',',':')).encode())
  for mp in ['application/database/fixtures/'+region+'-source-villages.json','pilot/data/'+region+'-source-village-manifest.json']:
   m=json.loads(Path(mp).read_text(encoding='utf8'));entry=next(d for d in m['districts'] if d['slug']==path.stem);entry.update(bytes=path.stat().st_size,sha256=hashlib.sha256(path.read_bytes()).hexdigest(),census_town_context_links=sum('census_context' in f['properties'] for f in data['features']));write(mp,m)
write('pilot/data/island-urban-context-ledger.json',ledger);print('urban',len(ledger),'linked',sum(r['linked'] for r in ledger));print([r for r in ledger if not r['linked']])
