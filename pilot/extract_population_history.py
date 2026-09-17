"""Extract the retrospective population table from the official 2001 Pilibhit handbook."""
import hashlib
import json
from pathlib import Path
import re
import fitz

ROOT = Path(__file__).resolve().parent.parent
source = ROOT / 'pilot/raw/pilibhit-dchb-2001.pdf'
with fitz.open(source) as document:
    text = document[19].get_text()
if 'POPULATION OF THE DISTRICT AT EACH CENSUS FROM 1901' not in text:
    raise ValueError('Statement 3 layout changed.')
rows = []
for area, part in zip(['total', 'rural', 'urban'], re.split(r'\n(?:Total|Rural|Urban)\n', text)[1:]):
    found = re.findall(r'(?m)^(19\d1|2001)\s+([\d,]+)\s+([\d,]+)\s+([\d,]+)', part)
    if [int(r[0]) for r in found] != list(range(1901,2002,10)):
        raise ValueError('Incomplete year sequence.')
    for year, population, male, female in found:
        row = dict(area=area,year=int(year),population=int(population.replace(',','')),male=int(male.replace(',','')),female=int(female.replace(',','')))
        if row['population'] != row['male'] + row['female']:
            raise ValueError('Sex totals do not reconcile.')
        rows.append(row)
if len(rows) != 33:
    raise ValueError('Expected total, rural and urban series.')
for year in range(1901,2002,10):
    records = {r['area']:r for r in rows if r['year']==year}
    for field in ['population','male','female']:
        if records['total'][field] != records['rural'][field] + records['urban'][field]:
            raise ValueError('Rural and urban values do not reconcile.')
payload = dict(title='Pilibhit district population, 1901-2001',edition=2001,source_url='https://censusindia.gov.in/nada/index.php/catalog/43939/download/47621/DH_09_2001_PIL.pdf',landing='https://censusindia.gov.in/nada/index.php/catalog/43939',source_locator='Statement 3, printed page xx, PDF page 20',checked_on='2026-09-16',sha256=hashlib.sha256(source.read_bytes()).hexdigest(),scope='Retrospective district series as presented in the 2001 District Census Handbook. Historical boundaries and rural/urban classification follow the source; not a reconstruction on present-day boundaries.',rows=rows)
(ROOT/'application/database/fixtures/pilibhit-population-history.json').write_text(json.dumps(payload,ensure_ascii=False,indent=2),encoding='utf-8')
print(f'Extracted {len(rows)} area/year records; sex and rural/urban totals reconcile.')
