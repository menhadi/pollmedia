"""Extract a bounded, aggregate-only SIR sample and Census baseline."""
import json, re, collections
from pathlib import Path
import pypdfium2 as pdfium
import openpyxl

ROOT=Path(__file__).resolve().parent
manifest={r['id']:r for r in json.loads((ROOT/'acquisition.json').read_text())}
src=manifest['sir-pilibhit-uncollected']
pdf=pdfium.PdfDocument(ROOT/src['path'])
parts={}
current=None
dates=set()
for page_i in range(len(pdf)):
    page=pdf[page_i]
    text=page.get_textpage().get_text_range().replace('\r','')
    found=re.search(r'AC:\s*(\d+)-([^;]+);\s*Part:\s*(\d+)-([^\n]+)',text)
    if found:
        ac,ac_name,part,name=found.groups()
        part=int(part)
        if part not in parts and len(parts)>=20:
            break
        current=parts.setdefault(part,dict(ac=ac,ac_name=ac_name.strip(),part=part,name=name.strip(),pages=[],counts=collections.Counter(),serials=[]))
    if current is None:
        raise ValueError('Page without established part')
    dates.update(re.findall(r'Date of Generation:\s*(\d+/\d+/\d+)',text))
    current['pages'].append(page_i+1)
    starts=list(re.finditer(r'(?m)^(\d+)\s+(\d+)\s+',text))
    for i,m in enumerate(starts):
        record=text[m.start():starts[i+1].start() if i+1<len(starts) else len(text)]
        current['serials'].append(int(m[1]))
        reason=re.search(r'\b(Death|Permanently Shifted|Prmanently Shifted|Already enrolled|Absent|Not traceable|Untraceable|Refused)\b',record,re.I)
        label=reason[1].lower() if reason else 'unclassified'
        # Source page 45 row 22 spells this category "Prmanently Shifted".
        current['counts'][{'prmanently shifted':'permanently shifted'}.get(label,label)]+=1
    page.close()

sir=[]
for p in parts.values():
    seq=p.pop('serials')
    assert seq == list(range(1,len(seq)+1)), (p['part'],'row sequence incomplete',p['pages'],sorted(set(range(1,max(seq)+1))-set(seq)))
    p['listed_records']=len(seq)
    p['source_url']=src['url']+'#page='+str(p['pages'][0])
    sir.append(p)
assert len(dates)==1

cs=manifest['census-pilibhit-2011']
wb=openpyxl.load_workbook(ROOT/cs['path'],read_only=True,data_only=True)
sheet=wb.worksheets[0]
it=iter(sheet.values)
header=next(it)
rows=[dict(zip(header,r)) for r in it]
district=next(r for r in rows if r['Level']=='DISTRICT' and r['TRU']=='Total')
villages=[r for r in rows if r['Level']=='VILLAGE']
levels=dict(collections.Counter(r['Level'] for r in rows))
sample=[]
for r in villages[:20]:
    assert r['TOT_P']==r['TOT_M']+r['TOT_F']
    sample.append(dict(code=r['Town/Village'],name=r['Name'],subdistrict_code=r['Subdistt'],population=r['TOT_P'],households=r['No_HH'],literate=r['P_LIT'],population_0_6=r['P_06']))
out=dict(sir=dict(title='Uncollected enumeration forms',edition='SIR enumeration material',generated_date=next(iter(dates)),ac='127',ac_name='Pilibhit',pdf_pages=len(pdf),sample_parts=len(sir),parts=sir,source_url=src['url'],landing=src['landing'],sha256=src['sha256']),census=dict(year=2011,sheet=sheet.title,levels=levels,district_population=district['TOT_P'],villages=sample,source_url=cs['url'],landing=cs['landing'],sha256=cs['sha256']),checks=dict(sir_serial_sequences='consecutive from 1 for each complete sampled part',sir_unclassified_rows=sum(p['counts'].get('unclassified',0) for p in sir),census_sex_totals='all sampled village rows reconcile'))
(ROOT/'data').mkdir(exist_ok=True)
(ROOT/'data'/'sample.json').write_text(json.dumps(out,ensure_ascii=False,indent=2),encoding='utf-8')
print(json.dumps(dict(parts=len(sir),sir_records=sum(p['listed_records'] for p in sir),pdf_pages=len(pdf),generated_dates=list(dates),census_levels=levels,census_villages=len(sample),checks=out['checks']),indent=2))
