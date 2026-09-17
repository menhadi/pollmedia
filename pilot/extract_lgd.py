"""Extract Pilibhit geography from the approved official UP LGD archive."""
import hashlib
import json
import xml.etree.ElementTree as ET
import zipfile
from pathlib import Path

archive = Path('pilot/raw/lgd-up-2026-09-16.zip')
groups = []
sources = []
with zipfile.ZipFile(archive) as bundle:
    for index, name in enumerate(bundle.namelist()):
        rows = []
        digest = hashlib.sha256(bundle.read(name)).hexdigest()
        sources.append(dict(filename=name, sha256=digest))
        with bundle.open(name) as stream:
            for _, row in ET.iterparse(stream, events=['end']):
                if row.tag.endswith('}Row'):
                    cells = [''.join(cell.itertext()).strip() for cell in row]
                    if len(cells) > 5 and cells[2 if index < 2 else 3] == 'Pilibhit':
                        rows.append(cells)
                    row.clear()
        groups.append(rows)

villages = {}
for r in groups[0]:
    assert r[1] == '173' and r[5] not in villages
    villages[r[5]] = dict(code=r[5], name=r[7], district_code=r[1], district_name=r[2],
                         subdistrict_code=r[3], subdistrict_name=r[4], status=r[9],
                         census_2001_code=r[10], census_2011_code=r[11], panchayats=[], blocks=[])
for r in groups[1]:
    assert r[1] == '173' and r[9] in villages
    v = villages[r[9]]
    assert (v['census_2011_code'], v['census_2001_code']) == (r[11], r[12])
    if r[13].isdigit():
        item = dict(code=r[13], name=r[14])
        if item not in v['panchayats']:
            v['panchayats'].append(item)
for r in groups[2]:
    assert r[2] == '173' and r[6] in villages
    item = dict(code=r[4], name=r[5])
    if item not in villages[r[6]]['blocks']:
        villages[r[6]]['blocks'].append(item)
data = dict(checked_on='2026-09-16', url='https://lgdirectory.gov.in/downloadDirectory.do',
            sha256=hashlib.sha256(archive.read_bytes()).hexdigest(), sources=sources,
            villages=list(villages.values()))
Path('application/database/fixtures/pilibhit-lgd.json').write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding='utf-8')
print('Imported', len(villages), 'LGD village records')
