import sys,json,hashlib,collections
from pathlib import Path
sys.path.insert(0,str(Path('pilot/tmp/python-libs').resolve()))
import xlrd
raw=Path('pilot/raw/pilibhit-pca-2001.xls');s=xlrd.open_workbook(raw).sheet_by_index(0);h=s.row_values(0);rows=[dict(zip(h,s.row_values(i))) for i in range(1,s.nrows)]
areas=[dict(code=r['SUB-DISTT'],name=r['NAME'].strip()) for r in rows if r['LEVEL']=='TEHSIL' and r['TRU']=='Total'];vs=[]
for i,r in enumerate(rows,2):
 if r['LEVEL']!='VILLAGE':continue
 assert r['TOT_P']==r['TOT_M']+r['TOT_F']
 vs.append(dict(code=r['TOWN_VILL'],name=r['NAME'].strip(),subdistrict_code=r['SUB-DISTT'],population=int(r['TOT_P']),households=int(r['No_HH']),literate=int(r['P_LIT']),population_0_6=int(r['P_06']),source_row=i))
assert len(vs)==len(set(v['code'] for v in vs))
for a in areas:
 rural=next(r for r in rows if r['LEVEL']=='TEHSIL' and r['TRU']=='Rural' and r['SUB-DISTT']==a['code'])
 assert sum(v['population'] for v in vs if v['subdistrict_code']==a['code'])==rural['TOT_P']
 assert sum(v['households'] for v in vs if v['subdistrict_code']==a['code'])==rural['No_HH']
data=dict(year=2001,sheet='Sheet1',district_code='21',subdistricts=areas,villages=vs,levels=dict(collections.Counter(r['LEVEL'] for r in rows)),source_url='https://censusindia.gov.in/nada/index.php/catalog/20761/download/23893/PC01_PCA_TOT_09_21.xls',landing='https://censusindia.gov.in/nada/index.php/catalog/20761',sha256=hashlib.sha256(raw.read_bytes()).hexdigest())
Path('application/database/fixtures/pilibhit-villages-2001.json').write_text(json.dumps(data,ensure_ascii=False,indent=2),encoding='utf-8')
print(len(vs),areas,collections.Counter(v['subdistrict_code'] for v in vs));print(vs[0])
