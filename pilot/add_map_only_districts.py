#!/usr/bin/env python3
"""Add map-only district entries for districts without a Census 2011 district record."""
import json
import re
import sqlite3
from pathlib import Path

BASE = Path('/home/pollmedia/app/application')
fixture_path = BASE / 'database/fixtures/district-dashboard-urls.json'
fixture = json.loads(fixture_path.read_text())
existing_paths = {row.get('path') for row in fixture.values()}

catalogue = json.loads((BASE / 'storage/app/india-map/catalogue.json').read_text())
layers = [layer for layer in catalogue['layers'] if layer['file'].startswith('maps/nwdp-')]

links = sqlite3.connect(BASE / 'storage/app/electoral-villages/links.sqlite')
states = {name: code for code, name in links.execute('select code,name from states')}
codes = {}
for state, district, code in links.execute('select d.state, d.district, a.district from directory d join ancestry a on a.lgd=d.lgd'):
    codes.setdefault((state, district), set()).add(code)

STATE_DISPLAY = {'Dadra and Nagar Haveli': 'Dadra and Nagar Haveli and Daman and Diu'}


def slug(value):
    return re.sub(r'[^a-z0-9]+', '-', value.lower().replace('&', 'and')).strip('-')

added = []
for layer in sorted(layers, key=lambda row: (row['state'], row['district'])):
    state_name = layer['state']
    state_code = states.get(state_name) or '38'
    path = f"/india/districts/{slug(STATE_DISPLAY.get(state_name, state_name))}/{slug(layer['district'])}"
    if path in existing_paths:
        continue
    lgd_codes = sorted(codes.get((state_code, layer['district']), []))
    if len(lgd_codes) != 1:
        print('skip (no unique LGD district code)', state_name, layer['district'], lgd_codes)
        continue
    key = f'{str(state_code).zfill(2)}:{lgd_codes[0].zfill(3)}'
    if key in fixture:
        print('skip (key exists)', key, layer['district'])
        continue
    fixture[key] = {
        'state': str(state_code).zfill(2),
        'district': lgd_codes[0].zfill(3),
        'name': layer['district'],
        'state_name': STATE_DISPLAY.get(state_name, state_name),
        'path': path,
        'map_only': True,
    }
    existing_paths.add(path)
    added.append({'key': key, 'path': path})

fixture_path.write_text(json.dumps(fixture, indent=4, ensure_ascii=False) + '\n')
print(json.dumps({'added': len(added), 'entries': added}, indent=1))
