"""Derive urban links from immutable SOI shapes and published Census identities."""
import argparse
import collections
import hashlib
import json
import re
import sqlite3
from pathlib import Path

from build_village_display_links import identity_code, normalize_name
from verify_soi_village_identities import attribute_rows, source_fields, same_source_feature

CIVIC_STATUS_SOURCE = 'https://censusindia.gov.in/nada/index.php/catalog/43625/download/47331/ORGI_Circular_04_2011.pdf'


def civic_name(value):
    match = re.fullmatch(r'(.+?)\s*\(([^()]*)\)\s*', str(value))
    if not match:
        return normalize_name(value), None
    statuses = {'ct': 'CT', 'censustown': 'CT', 'tp': 'TP', 'townpanchayat': 'TP',
                'np': 'NP', 'nagarpanchayat': 'NP', 'mcorp': 'M Corp',
                'mcorporation': 'M Corp', 'municipalcorporation': 'M Corp',
                'm': 'M', 'municipality': 'M', 'mcl': 'M Cl', 'municipalcouncil': 'M Cl',
                'cb': 'CB', 'cantonmentboard': 'CB', 'cantonment': 'CB',
                'mc': 'MC', 'municipalcommittee': 'MC', 'cmc': 'CMC', 'citymunicipalcouncil': 'CMC',
                'tmc': 'TMC', 'townmunicipalcouncil': 'TMC', 'mb': 'MB', 'municipalboard': 'MB',
                'na': 'NA', 'notifiedarea': 'NA', 'nt': 'NT', 'notifiedtown': 'NT',
                'nac': 'NAC', 'notifiedareacommittee': 'NAC', 'notifiedareacouncil': 'NAC',
                'nta': 'NTA', 'notifiedtownarea': 'NTA', 'ina': 'INA', 'industrialnotifiedarea': 'INA',
                'its': 'ITS', 'industrialtownship': 'ITS', 'ts': 'TS', 'township': 'TS',
                'tc': 'TC', 'towncommittee': 'TC', 'townareacommittee': 'TC',
                'st': 'ST', 'smalltowncommittee': 'ST', 'eo': 'EO', 'estateoffice': 'EO'}
    status = statuses.get(normalize_name(match[2]))
    return (normalize_name(match[1]), status) if status else (normalize_name(value), None)


def census_identity(row):
    geo = json.loads(row['geography'])
    return tuple(identity_code(v) for v in [row['state_code'], row['district_code'], geo.get('Subdistt'), geo.get('Town/Village')])


def town_match(properties, state, records, raw=None):
    code = identity_code(properties.get('source_code'))
    historic = {'36': ['28'], '37': ['1'], '38': ['25', '26']}.get(state, [state])
    rows = [row for st in historic for row in records.get((st, code), [])]
    unique = {census_identity(row): row for row in reversed(rows)}
    if not code:
        return None
    if raw and str(raw.get('Subdis_LGD', '')).isdigit() and identity_code(raw.get('State_LGD')) in [state, *historic] and identity_code(raw.get('Vill_LGD')) == code:
        full = [row for key, row in unique.items() if key[1:3] == (identity_code(raw.get('Dist_LGD')), identity_code(raw.get('Subdis_LGD')))]
        if len(full) == 1:
            return full[0], 'Full original SOI state/district/subdistrict/town codes match a unique published Census 2011 town identity; original source feature and archive hashes verified'
    named = [row for row in rows if normalize_name(row['name']) == normalize_name(properties.get('name', ''))]
    if len({census_identity(row) for row in named}) == 1:
        return named[0], 'Original SOI town code and exact normalized published Census town name identify one complete Census geography; other same-code town parts withheld'
    if len(unique) == 1:
        row = next(iter(unique.values()))
        if normalize_name(row['name']) == normalize_name(properties.get('name', '')):
            return row, 'Unique published Census 2011 town code and normalized settlement name within the official state inventory'
        if raw and raw.get('Vill_Cat', '').casefold() in {'urban', 'fully_urban', 'fully urban'} and identity_code(raw.get('State_LGD')) in [state, *historic] and identity_code(raw.get('Vill_LGD')) == code:
            designation = re.fullmatch(r'(.+?)\s*\(\s*C\.?\s*T\.?\s*\)', row['name'], re.IGNORECASE)
            if designation and normalize_name(designation[1]) == normalize_name(properties.get('name', '')):
                return row, 'Archive-bound SOI '+raw['Vill_Cat']+' classification, unchanged source code and exact settlement name match one published Census town identity; only Census Town (CT) designation omitted in SOI'
            census_stem, census_status = civic_name(row['name'])
            source_stem, source_status = civic_name(properties.get('name', ''))
            if census_status and census_stem == source_stem and source_status in {None, census_status}:
                return row, 'Archive-bound SOI urban classification and unchanged town code identify one published Census geography with the exact settlement name; only an omitted or expanded civic-status designation differs, using Census 2011 Circular 4; no Part or OG designation removed'
    return None


def build(app, inventory, rural_inventory, manifest, output):
    catalogue = json.loads((app/'storage/app/india-map/catalogue.json').read_bytes())
    db = sqlite3.connect(f"file:{app/'storage/app/electoral-villages/links.sqlite'}?mode=ro", uri=True)
    states = {normalize_name(name): code for code, name in db.execute('SELECT code,name FROM states')}
    directory = collections.defaultdict(list)
    names = {}
    for lgd, state, district, subdistrict, census, name in db.execute('SELECT d.lgd,d.state,a.district,a.subdistrict,d.census2011,d.district FROM directory d JOIN ancestry a ON a.lgd=d.lgd AND a.state=d.state'):
        directory[(state, identity_code(census))].append((lgd, district, subdistrict))
        names[(state, district)] = name
    db.close()
    towns = collections.defaultdict(list)
    for line in inventory.open(encoding='utf-8'):
        row = json.loads(line)
        if row['level'] != 'TOWN' or row['residence'] != 'Urban':
            raise ValueError('Urban inventory contains a non-town record')
        key = census_identity(row)
        towns[(key[0], key[3])].append(row)
    census_parents = collections.defaultdict(set)
    for line in rural_inventory.open(encoding='utf-8'):
        row = json.loads(line)
        if row[6] != 'VILLAGE':
            continue
        historical_state, code = identity_code(row[2]), identity_code(row[5])
        for current_state in states.values():
            historic = {'36':['28'], '37':['1'], '38':['25','26']}.get(current_state, [current_state])
            if historical_state not in historic:
                continue
            for _, district, _ in directory.get((current_state, code), []):
                census_parents[(historical_state, identity_code(row[3]))].add((current_state, district))
    supplemental = app/'database/fixtures/lgd-urban-districts.json'
    if supplemental.exists():
        for row in json.loads(supplemental.read_bytes())['districts']:
            census_parents[(identity_code(row['census_state']), identity_code(row['census_district']))].add((row['state'], row['code']))
            names[(row['state'], row['code'])] = row['name']
    sources = json.loads(manifest.read_bytes())['sources']
    by_archive = {(s['state'], s['sha256']): s for s in sources}
    cache_key = None
    source_rows = {}
    layers = {}
    district_layers = collections.defaultdict(lambda: collections.defaultdict(list))
    counts = collections.Counter()
    qualified = collections.Counter()
    held = collections.Counter()
    unresolved = []
    for layer in sorted(catalogue['layers'], key=lambda row: (row['state'], row['metadata'].get('sha256') or row['metadata'].get('archive_sha256') or '', row['file'])):
        if layer.get('kind') == 'district':
            continue
        state = states.get(normalize_name(layer['state']))
        archive = layer['metadata'].get('sha256') or layer['metadata'].get('archive_sha256')
        entry = by_archive.get((layer['state'], archive))
        key = (layer['state'], archive)
        if key != cache_key:
            source_rows = collections.defaultdict(list)
            cache_key = key
            if entry:
                path = Path(entry['dbf'])
                if hashlib.sha256(path.read_bytes()).hexdigest() != entry['dbf_sha256']:
                    raise ValueError('Pinned official source attributes changed')
                for number, original in attribute_rows(entry):
                    attributes = {k:v for k,v in original.items() if k.casefold() not in {'objectid','objectid_1'}}
                    for field, value in original.items():
                        if field.casefold() not in {'objectid','objectid_1'}:
                            continue
                        raw = source_fields(dict(attributes, OBJECTID=value))
                        if original.get('_source_original_ids'):
                            raw['_source_original_ids'] = original['_source_original_ids']
                        raw['_source_id_field'] = original.get('_source_original_id_field', field)
                        identifier = str(value)
                        if identifier.isdigit():
                            source_rows[str(int(identifier))].append((number, raw))
        path = app/'storage/app/india-map/snapshot'/layer['file']
        if not path.exists():
            path = app/'public'/layer['file']
        content = path.read_bytes()
        if hashlib.sha256(content).hexdigest() != layer['sha256']:
            raise ValueError('Original polygon edition changed')
        records = {}
        for index, feature in enumerate(json.loads(content)['features']):
            properties = feature['properties']
            identifier = str(feature.get('id', ''))
            raw_matches = {n:r for n,r in source_rows.get(str(int(identifier)) if identifier.isdigit() else '', []) if same_source_feature(properties, feature.get('id'), layer, r)}
            raw = next(iter(raw_matches.values())) if len(raw_matches) == 1 else None
            code = identity_code(properties.get('source_code'))
            historic = {'36':['28'], '37':['1'], '38':['25','26']}.get(state,[state])
            candidate = any(towns.get((st, code)) for st in historic)
            if not candidate and not (raw and raw.get('Vill_Cat', '').casefold() == 'urban'):
                continue
            matched = town_match(properties, state, towns, raw)
            if not matched:
                reason = 'invalid_or_placeholder_source_code' if not code else 'no_unique_published_town_identity' if not candidate else 'name_or_parent_identity_requires_official_evidence'
                held[reason] += 1
                unresolved.append({'file':layer['file'],'feature_index':index,'state':layer['state'],'name':properties.get('name'),'code':properties.get('source_code'),'reason':reason})
                continue
            row, basis = matched
            identity = census_identity(row)
            direct = normalize_name(row['name']) == normalize_name(properties.get('name', ''))
            parents = {(state,d) for _,d,_ in directory.get((state, code), [])}
            if len(parents) != 1:
                parents = {(s,d) for s,d in census_parents.get(identity[:2], set()) if s == state}
            if raw:
                current = (state, identity_code(raw.get('Dist_LGD')))
                if current in names and normalize_name(names[current]) == normalize_name(raw.get('District','')):
                    parents = {current}
            record = {'record_id':row['id'],'record_key':row['record_key'],'edition_id':row['edition_id'],'source_sha256':row['source_sha256'],
                      'original_census_geography':json.loads(row['geography']),'source_key':row['source_key'],'source_row':row['source_row'],'source_url':row['source_url'],
                      'census_identity':list(identity),'census_name':row['name'],'source_id':feature.get('id'),'source_code':properties.get('source_code'),
                      'source_name':properties.get('name'),'basis':basis+'; dated town values, boundary edition correspondence unverified',
                      'current_districts':[{'state':s,'district':d} for s,d in sorted(parents)] if len(parents)==1 else [],
                      'navigation_basis':'Explicit LGD village Census crosswalk or a unique existing LGD/Census district association; administrative navigation only, not boundary equivalence'}
            if raw and not direct:
                record['source_evidence'] = {'source_url':entry['url'],'archive_sha256':archive,'attribute_sha256':entry['dbf_sha256'],
                    'original_record':next(iter(raw_matches)),'original_codes':{k:raw.get(k) for k in ['State_LGD','Dist_LGD','Subdis_LGD','Vill_LGD']}}
                if 'Census 2011 Circular 4' in basis:
                    record['source_evidence']['civic_status_reference'] = CIVIC_STATUS_SOURCE
            records[str(index)] = record
            counts[layer['state']] += 1
            if not direct:
                qualified[layer['state']] += 1
            if len(parents)==1:
                parent = next(iter(parents))
                district_layers[':'.join(parent)][layer['id']].append(index)
        if records:
            layers[layer['id']] = {'file':layer['file'],'sha256':layer['sha256'],'state':layer['state'],'records':records}
    result = {'year':2011,'scope':'Verified Census town identities linked to retained SOI source shapes. LGD parents are navigation context; no municipal or ward identifier inferred.',
              'inventory_sha256':hashlib.sha256(inventory.read_bytes()).hexdigest(),'original_lgd_sha256':hashlib.sha256((app/'storage/app/electoral-villages/links.sqlite').read_bytes()).hexdigest(),
              'layers':layers,'district_layers':{key:[{'id':i,'indices':v} for i,v in value.items()] for key,value in district_layers.items()},
              'counts':dict(counts),'qualified_name_differences':dict(qualified),'held':dict(held)}
    output.write_bytes(json.dumps(result,separators=(',',':')).encode())
    output.with_suffix('.unresolved.jsonl').write_bytes(''.join(json.dumps(r)+'\n' for r in unresolved).encode())
    print(json.dumps({'urban_shapes':sum(counts.values()),'states':dict(counts),'additional_qualified_name_differences':dict(qualified),'held':dict(held),'districts':len(district_layers)}),flush=True)


if __name__ == '__main__':
    p=argparse.ArgumentParser()
    for name in ['application','inventory','rural_inventory','manifest','output']:
        p.add_argument(name,type=Path)
    args=p.parse_args()
    build(args.application,args.inventory,args.rural_inventory,args.manifest,args.output)
