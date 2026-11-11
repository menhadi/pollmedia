#!/usr/bin/env python3
"""Build the parent-district table for the new districts, using directory names."""
import json
import re
import sqlite3
import unicodedata
from pathlib import Path

BASE = Path('/home/pollmedia/app/application/storage/app/electoral-villages')
db = sqlite3.connect(BASE / 'links.sqlite')

def norm(v):
    return ' '.join(re.sub(r'[^a-z0-9]+', ' ', unicodedata.normalize('NFKD', (v or '')).casefold()).split())

# desired district -> parents, keyed by state code
WANTED = {
    '8': {
        'Balotra': ['Barmer'],
        'Beawar': ['Ajmer', 'Pali', 'Bhilwara'],
        'Deeg': ['Bharatpur'],
        'Didwana-Kuchaman': ['Nagaur'],
        'Khairthal-Tijara': ['Alwar'],
        'Kotputli-Behror': ['Jaipur', 'Alwar'],
        'Salumbar': ['Udaipur'],
    },
    '9': {'Amethi': ['Sultanpur', 'Rae Bareli'], 'Hapur': ['Ghaziabad']},
    '27': {'Palghar': ['Thane']},
    '24': {
        'Chhotaudepur': ['Vadodara'], 'Mahisagar': ['Panch Mahals', 'Kheda'], 'Arvalli': ['Sabar Kantha'],
        'Morbi': ['Rajkot'], 'Devbhumi Dwarka': ['Jamnagar'], 'Botad': ['Bhavnagar', 'Ahmedabad'],
    },
    '23': {'Mauganj': ['Rewa'], 'Pandhurna': ['Chhindwara'], 'Agar-Malwa': ['Shajapur'], 'Niwari': ['Tikamgarh']},
    '29': {'Vijayanagara': ['Ballari']},
    '33': {'Mayiladuthurai': ['Nagapattinam'], 'Chengalpattu': ['Kancheepuram'], 'Kallakurichi': ['Viluppuram']},
    '3': {'Malerkotla': ['Sangrur'], 'Pathankot': ['Gurdaspur'], 'Fazilka': ['Ferozepur']},
    '6': {'Charkhi Dadri': ['Bhiwani']},
}

CITATIONS = {
    '8': ('Rajasthan Gazette Extraordinary, notification No. P.9(18)raj-1/2022 series, 5 August 2023 (effective 7 August 2023)',
          'https://jankalyanfile.rajasthan.gov.in/WebMyWayFiles/OrderEntry/District_Balotra/2023/Notification/O_070823_60a102a9-080a-4a32-a542-f6cd0a58eb8f.pdf'),
    '9': ('Uttar Pradesh district reorganisation (Amethi 2010 from Sultanpur and Rae Bareli; Hapur 2011 from Ghaziabad)', ''),
    '27': ('Maharashtra district reorganisation notification, Palghar created from Thane, 1 August 2014', ''),
    '24': ('Gujarat district reorganisation notifications, 2013 (Chhotaudepur, Mahisagar, Arvalli, Morbi, Devbhumi Dwarka, Botad)', ''),
    '23': ('Madhya Pradesh district reorganisation notifications (Agar-Malwa 2013, Niwari 2018, Mauganj and Pandhurna 2023)', ''),
    '29': ('Karnataka notification, Vijayanagara created from Ballari, 2020', ''),
    '33': ('Tamil Nadu notifications (Chengalpattu and Kallakurichi 2019, Mayiladuthurai 2020)', ''),
    '3': ('Punjab notifications (Pathankot 2011, Fazilka 2011, Malerkotla 2021)', ''),
    '6': ('Haryana notification, Charkhi Dadri created from Bhiwani, 2016', ''),
}

districts = {}
for code, district in db.execute('select state,district from directory'):
    districts.setdefault((code, norm(district)), district)

entries = []
missing = []
for state, wanted in WANTED.items():
    for desired, parents in wanted.items():
        actual = districts.get((state, norm(desired)))
        if not actual:
            missing.append((state, desired))
            continue
        parent_names = []
        for parent in parents:
            name = districts.get((state, norm(parent)))
            if name:
                parent_names.append(name)
            else:
                missing.append((state, f'{desired} parent {parent}'))
        if not parent_names:
            continue
        title, url = CITATIONS[state]
        entries.append({
            'state': None,
            'state_code': state,
            'district': actual,
            'parents': parent_names,
            'notification': {'title': f'{title}. {desired} formed from {", ".join(parents)}.', 'url': url},
        })

state_names = {row[0]: row[1] for row in db.execute('select code,name from states')}
for entry in entries:
    entry['state'] = state_names.get(entry['state_code'], entry['state_code'])

payload = {'schema_version': 1, 'districts': entries}
Path('/home/pollmedia/app/pilot/data/district-parents.json').write_text(json.dumps(payload, indent=1))
print('entries', len(entries), 'missing', missing)
for entry in entries:
    print(' ', entry['state'], '|', entry['district'], '<-', ', '.join(entry['parents']))
