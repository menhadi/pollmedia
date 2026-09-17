"""Preserve and normalize the official Pilibhit PC LGD export."""
import collections
import hashlib
import json
import xml.etree.ElementTree as ET
import zipfile
from pathlib import Path

raw = Path('pilot/raw/lgd-pilibhit-electoral-2026-09-16.xlsx')
ns = {'s': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
with zipfile.ZipFile(raw) as workbook:
    root = ET.fromstring(workbook.read('xl/worksheets/sheet1.xml'))
rows = []
for row in root.findall('.//s:row', ns)[1:]:
    cells = {''.join(filter(str.isalpha, c.attrib['r'])): ''.join(c.itertext()) for c in row}
    rows.append([cells.get(chr(65 + i), '') for i in range(13)])
assert len(rows) == 1955
villages = []
for r in rows:
    assert r[1] == 'Pilibhit'
    assert r[2] in ['Pilibhit', 'Barkhera', 'Puranpur', 'Bisalpur', 'Baheri']
    if not r[7]:
        continue
    assert r[7].isdigit() and not r[11]
    villages.append(dict(lgd_code=r[7], name=r[8], district_code=r[3], ac=r[2].lower(), pc='pilibhit', source_row=int(r[0])+1))
assert len(villages) == len({v['lgd_code'] for v in villages})
lgd = json.loads(Path('application/database/fixtures/pilibhit-lgd.json').read_text(encoding='utf-8'))
local = {v['code']: v for v in lgd['villages']}
for v in villages:
    if v['district_code'] == '173':
        assert v['lgd_code'] in local
data = dict(url='https://lgdirectory.gov.in/rptMappedGPNWardforPCAC.do', checked_on='2026-09-16',
            generated_at='16/09/2026 09:17:01 AM', sha256=hashlib.sha256(raw.read_bytes()).hexdigest(),
            total_rows=len(rows), urban_ward_rows=sum(not r[7] for r in rows), villages=villages)
Path('application/database/fixtures/pilibhit-lgd-electoral.json').write_text(json.dumps(data, indent=2), encoding='utf-8')
census = json.loads(Path('application/database/fixtures/pilibhit-villages-2011.json').read_text(encoding='utf-8'))
matched = {local[v['lgd_code']]['census_2011_code'] for v in villages if v['lgd_code'] in local}
print('Matched Census 2011 profiles:', sum(v['code'] in matched for v in census['villages']))
print('Unmatched:', [(v['code'], v['name']) for v in census['villages'] if v['code'] not in matched])
