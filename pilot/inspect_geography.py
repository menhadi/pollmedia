"""Inspect official DBF attributes without assuming LGD/Census equivalence."""
import json,zipfile,struct,collections
import openpyxl
from pathlib import Path
ROOT=Path(__file__).resolve().parent
source=next(x for x in json.loads((ROOT/'acquisition.json').read_text()) if x['id']=='soi-up-villages')
with zipfile.ZipFile(ROOT/source['path']) as z:
    dbf=z.read(next(n for n in z.namelist() if n.endswith('.dbf')))
    projection=z.read(next(n for n in z.namelist() if n.endswith('.prj'))).decode()
count=struct.unpack_from('<I',dbf,4)[0]
header,record=struct.unpack_from('<HH',dbf,8)
fields=[]
for off in range(32,header-1,32):
    f=dbf[off:off+32]
    if f[0]==13: break
    fields.append((f[:11].split(b'\0')[0].decode(),chr(f[11]),f[16]))
matches=[]
for i in range(count):
    r=dbf[header+i*record:header+(i+1)*record]
    if r[:1]==b'*': continue
    values={}; pos=1
    for name,typ,length in fields:
        values[name]=r[pos:pos+length].decode('utf-8',errors='replace').strip();pos+=length
    if values.get('District','').lower()=='pilibhit': matches.append(values)
result=dict(source=source['url'],sha256=source['sha256'],records=count,fields=fields,projection=projection,pilibhit_matches=len(matches),sample=matches[:20])
(ROOT/'data'/'geography-inspection.json').write_text(json.dumps(result,indent=2),encoding='utf-8')
cs=next(x for x in json.loads((ROOT/'acquisition.json').read_text()) if x['id']=='census-pilibhit-2011')
ws=openpyxl.load_workbook(ROOT/cs['path'],read_only=True,data_only=True).worksheets[0]
it=iter(ws.values); header=next(it)
census=[dict(zip(header,r)) for r in it]
census=[r for r in census if r['Level'] in ('VILLAGE','TOWN')]
by_code=collections.defaultdict(list)
for r in matches: by_code[r['Vill_LGD']].append(r)
joined=[]
for r in census:
    candidates=by_code[r['Town/Village']]
    joined.append(dict(census_code=r['Town/Village'],census_name=r['Name'],census_subdistrict=r['Subdistt'],geometry_matches=len(candidates),geometry_name=candidates[0]['Vill_name'] if len(candidates)==1 else None,geometry_subdistrict=candidates[0]['Subdis_LGD'] if len(candidates)==1 else None))
summary=dict(census_places=len(census),geometry_records=len(matches),unique_code_matches=sum(r['geometry_matches']==1 for r in joined),unmatched=sum(r['geometry_matches']==0 for r in joined),ambiguous=sum(r['geometry_matches']>1 for r in joined),subdistrict_changes=sum(r['geometry_subdistrict']!=r['census_subdistrict'] for r in joined if r['geometry_matches']==1),status='Candidate code crosswalk; current LGD export and geometry validity not verified',rows=joined)
(ROOT/'data'/'crosswalk.json').write_text(json.dumps(summary,indent=2),encoding='utf-8')
print(json.dumps({k:v for k,v in summary.items() if k!='rows'},indent=2))
