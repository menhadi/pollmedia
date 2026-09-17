"""Extract the five pilot ACs from unmodified ECI 2022 workbooks."""
import hashlib
import json
from pathlib import Path
import re
import shutil
import zipfile
import xml.etree.ElementTree as ET
import openpyxl

ROOT = Path(__file__).resolve().parent.parent
RAW = ROOT / 'pilot/raw/elections'
for original, target in [('10-Detailed Results.xlsx', '2022-up-detailed.xlsx'), ('8-Constituency Data Summery Report.xlsx', '2022-up-summary.xlsx')]:
    if not (RAW / target).exists():
        shutil.copyfile(Path.home() / 'Downloads' / original, RAW / target)
ns = {'s': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
z = zipfile.ZipFile(RAW / '2022-up-summary.xlsx')
strings = [''.join(x.itertext()) for x in ET.fromstring(z.read('xl/sharedStrings.xml')).findall('s:si', ns)]
details = list(openpyxl.load_workbook(RAW / '2022-up-detailed.xlsx', read_only=True, data_only=True).active.values)
fixtures = []
for code, slug in [(118, 'baheri'), (127, 'pilibhit'), (128, 'barkhera'), (129, 'puranpur'), (130, 'bisalpur')]:
    root = ET.fromstring(z.read(f'xl/worksheets/sheet{code}.xml'))
    cells = {c.get('r'): strings[int(c.find('s:v', ns).text)] if c.get('t') == 's' else c.find('s:v', ns).text for c in root.findall('.//s:c', ns) if c.find('s:v', ns) is not None}
    assert cells['D2'].startswith(str(code) + '-')
    rows = []
    sheet_rows = []
    for i, r in enumerate(details, 1):
        if r[1] != code:
            continue
        rank, name = re.fullmatch(r'(\d+)\s+(.+)', r[3]).groups()
        row = dict(source_row=int(rank), candidate_name=name, party_at_election=r[7], is_nota=r[7] == 'NOTA', general_votes=int(r[9]), postal_votes=int(r[10]), votes=int(r[11]))
        assert row['general_votes'] + row['postal_votes'] == row['votes']
        rows.append(row)
        sheet_rows.append(i)
    assert sum(r['votes'] for r in rows if not r['is_nota']) == int(cells['F28'])
    assert sum(r['votes'] for r in rows if r['is_nota']) == int(cells['F30'])
    fixtures.append(dict(slug=slug, code=code, year=2022, url='https://old.eci.gov.in/files/file/14185-uttar-pradesh-general-legislative-election-2022/', checked_on='2026-09-15', sha256=hashlib.sha256((RAW / '2022-up-detailed.xlsx').read_bytes()).hexdigest(), totals_sha256=hashlib.sha256((RAW / '2022-up-summary.xlsx').read_bytes()).hexdigest(), source_locator=f'10-Detailed Results, Worksheet rows {min(sheet_rows)}–{max(sheet_rows)}; Summary AC {code}, F13/F22/F25/F28', electors=int(cells['F13']), votes_polled=int(cells['F22'])+int(cells['F25']), valid_candidate_votes=int(cells['F28']), candidates=rows))
(ROOT / 'application/database/fixtures/pilibhit-assembly.json').write_text(json.dumps(fixtures, indent=2), encoding='utf-8')
print(f'Extracted {len(fixtures)} ACs, {sum(len(x["candidates"]) for x in fixtures)} candidate/NOTA rows.')
