"""Resolve retained SOI geometry through fully qualified official identifiers.

The original archive must match its recorded SHA-256. Source feature IDs, names,
codes and administrative labels bind the DBF row to the retained display feature.
Spelling differences between sources never require fuzzy matching or geometry edits.
"""
import argparse
import collections
import hashlib
import json
import sqlite3
import struct
from pathlib import Path

from build_village_display_links import identity_code, normalize_name


def dbf_rows(path):
    with path.open('rb') as stream:
        header = stream.read(32)
        count, header_length, record_length = struct.unpack_from('<IHH', header, 4)
        fields = []
        while stream.tell() < header_length - 1:
            descriptor = stream.read(32)
            if len(descriptor) != 32:
                raise ValueError('Truncated DBF field descriptor')
            name = descriptor[:11].split(b'\0')[0].decode('ascii')
            fields.append((name, descriptor[16]))
        stream.seek(header_length)
        if sum(length for _, length in fields) + 1 != record_length:
            raise ValueError('DBF record length differs from its schema')
        for number in range(count):
            raw = stream.read(record_length)
            if len(raw) != record_length:
                raise ValueError('Truncated original DBF record')
            if raw[:1] == b'*':
                continue
            offset = 1
            row = {}
            for name, length in fields:
                row[name] = raw[offset:offset + length].decode('utf-8').strip()
                offset += length
            yield number + 1, row


def source_fields(row):
    aliases = {'OBJECTID': ['objectid', 'objectid_1'], 'State_LGD': ['state_lgd'],
               'Dist_LGD': ['dist_lgd'], 'Subdis_LGD': ['subdis_lgd'], 'Vill_LGD': ['vill_lgd'],
               'Vill_Cat': ['vill_cat'], 'Vill_name': ['vill_name', 'villl_name'], 'Sub_dist': ['sub_dist', 'subdist'], 'District': ['district']}
    lower = {key.casefold(): (key, value) for key, value in row.items()}
    result = {}
    for field, names in aliases.items():
        matches = [lower[name] for name in names if name in lower]
        if len({value for _, value in matches}) > 1:
            raise ValueError('Conflicting source field aliases: ' + field)
        if matches:
            result[field] = matches[0][1]
    return result


def attribute_rows(entry):
    if entry.get('format', 'dbf') == 'dbf':
        yield from dbf_rows(Path(entry['dbf']))
        return
    if entry['format'] != 'nwic-jsonl':
        raise ValueError('Unknown official attribute schema')
    with Path(entry['dbf']).open(encoding='utf-8') as stream:
        for line in stream:
            row = json.loads(line)
            original = row['fields']
            source = {'State_LGD': original.get('stcode'), 'Dist_LGD': original.get('dtcode'), 'Subdis_LGD': original.get('sdcode'),
                      'Vill_LGD': original.get('vlcode'), 'Vill_name': original.get('village'), 'Sub_dist': original.get('subdistric'),
                      'District': original.get('district'), 'Vill_Cat': original.get('total_urban_rural\n')}
            source['_source_original_ids'] = json.dumps({field: original.get(field) for field in ['id', 'objectid_1', 'objectid']})
            for field in ['id', 'objectid_1', 'objectid']:
                if original.get(field) is not None:
                    value = original[field]
                    source['OBJECTID'] = str(int(value)) if isinstance(value, (int, float)) and int(value) == value else str(value)
                    source['_source_original_id_field'] = field
                    yield row['record'], {key: str(value or '') for key, value in source.items()}
            source['OBJECTID'] = str(row['record'] - 1)
            source['_source_original_id_field'] = 'original GeoJSON feature ordinal (zero-based)'
            yield row['record'], {key: str(value or '') for key, value in source.items()}


def verified_candidates(source, state, directory, census):
    code = identity_code(source.get('Vill_LGD'))
    district = identity_code(source.get('Dist_LGD'))
    subdistrict = identity_code(source.get('Subdis_LGD'))
    source_state = identity_code(source.get('State_LGD'))
    historic_states = {'36': ['28'], '37': ['1'], '38': ['25', '26']}.get(state, [state])
    if not all([code, district, subdistrict]) or source_state not in [state, *historic_states]:
        return []
    if source.get('Vill_Cat', '').casefold() != 'rural':
        return []
    candidates = {}
    for row in directory.get(code, []):
        if row['state'] != state:
            continue
        if identity_code(row['district_code']) == district and identity_code(row['subdistrict_code']) == subdistrict:
            candidates[row['lgd']] = (row, 'Full source village/district/subdistrict codes match official current LGD directory or its Census crosswalk within the same LGD ancestry')
        if identity_code(row['lgd']) != code:
            continue
        historic_code = identity_code(row['census2011'])
        crosswalk = [r for r in directory.get(historic_code, []) if r['state'] == state and identity_code(r['census2011']) == historic_code]
        historical = [r for historic in historic_states for r in census.get((historic, historic_code), [])]
        historic_identities = {(identity_code(r[2]), identity_code(r[3]), identity_code(r[4]), identity_code(r[5])) for r in historical}
        if len(crosswalk) == 1 and len(historic_identities) == 1 and historical:
            parent = historical[0]
            if district in {identity_code(row['district_code']), identity_code(parent[3])} and subdistrict in {identity_code(row['subdistrict_code']), identity_code(parent[4])}:
                candidates.setdefault(row['lgd'], (row, 'Exact source LGD village code with historical or mixed parent codes verified through its explicit unique LGD Census crosswalk and unique published full Census 2011 village identity'))
    published = [r for historic in historic_states for r in census.get((historic, code), [])]
    identities = {(identity_code(r[2]), identity_code(r[3]), identity_code(r[4]), identity_code(r[5])) for r in published}
    full = [r for r in published if identity_code(r[3]) == district and identity_code(r[4]) == subdistrict]
    if len(identities) == 1 and published:
        mapped = [r for r in directory.get(code, []) if r['state'] == state and identity_code(r['census2011']) == code]
        if len(mapped) == 1:
            row = mapped[0]
            historic = published[0]
            if full:
                candidates.setdefault(row['lgd'], (row, 'Full source Census 2011 state/district/subdistrict/village identity matches a published Census record and the explicit unique current LGD Census crosswalk'))
            elif district in {identity_code(row['district_code']), identity_code(historic[3])} and subdistrict in {identity_code(row['subdistrict_code']), identity_code(historic[4])}:
                candidates.setdefault(row['lgd'], (row, 'Source district/subdistrict codes use mixed current LGD and Census 2011 parent namespaces, each verified against the same unique published Census village identity and its explicit unique current LGD Census crosswalk'))
    return list(candidates.values()) if len(candidates) == 1 else []


def same_source_feature(properties, source_id, layer, raw):
    source_identifier = str(source_id) if source_id is not None else ''
    original_identifier = str(raw.get('OBJECTID', ''))
    if not source_identifier.isdigit() or not original_identifier.isdigit() or int(source_identifier) != int(original_identifier):
        return False
    if raw.get('_source_original_ids'):
        original_ids = json.loads(raw['_source_original_ids'])
        if properties.get('source_record_id') is not None and identity_code(properties['source_record_id']) not in {identity_code(str(original_ids.get(field))) for field in ['id', 'objectid_1']}:
            return False
        if normalize_name(properties.get('source_district', '')) != normalize_name(raw.get('District', '')):
            return False
    return (identity_code(properties.get('source_code')) == identity_code(raw.get('Vill_LGD'))
            and normalize_name(properties.get('name', '')) == normalize_name(raw.get('Vill_name', ''))
            and normalize_name(properties.get('source_subdistrict') or properties.get('subdistrict', '')) == normalize_name(raw.get('Sub_dist', '')))


def build(application, manifest_path, census_path, output):
    original = application / 'storage/app/electoral-villages/links.sqlite'
    previous = original.with_name('display-links.sqlite')
    original_sha = hashlib.sha256(original.read_bytes()).hexdigest()
    if output.exists():
        raise FileExistsError(output)
    db = sqlite3.connect(f'file:{original.as_posix()}?mode=ro', uri=True)
    db.row_factory = sqlite3.Row
    directory = collections.defaultdict(dict)
    for record in db.execute('SELECT d.*,a.district AS district_code,a.subdistrict AS subdistrict_code FROM directory d JOIN ancestry a ON a.lgd=d.lgd AND a.state=d.state'):
        row = dict(record)
        for code in {identity_code(row['lgd']), identity_code(row['census2011'])} - {''}:
            directory[code][row['lgd']] = row
    directory = {key: list(rows.values()) for key, rows in directory.items()}
    states = {normalize_name(r['name']): r['code'] for r in db.execute('SELECT * FROM states')}
    census = collections.defaultdict(list)
    for line in census_path.open(encoding='utf-8'):
        row = json.loads(line)
        if row[6] == 'VILLAGE':
            census[(identity_code(row[2]), identity_code(row[5]))].append(row)
    old = sqlite3.connect(f'file:{previous.as_posix()}?mode=ro', uri=True)
    metadata = json.loads(old.execute('SELECT json FROM metadata').fetchone()[0])
    if metadata['original_links_sha256'] != original_sha:
        raise ValueError('LGD source edition changed')
    target = sqlite3.connect(output)
    old.backup(target)
    target.execute('CREATE TABLE IF NOT EXISTS evidence(file TEXT,feature_index INTEGER,json TEXT,PRIMARY KEY(file,feature_index))')
    linked = {(r[0], r[1]) for r in db.execute('SELECT file,feature_index FROM shapes')}
    linked.update(old.execute('SELECT file,feature_index FROM shapes'))
    old.close()
    catalogue = json.loads((application / 'storage/app/india-map/catalogue.json').read_text())
    sources = json.loads(manifest_path.read_text())['sources']
    counts = collections.Counter()
    held = collections.Counter()
    added = []
    for entry in sources:
        raw_path = Path(entry['dbf'])
        if hashlib.sha256(raw_path.read_bytes()).hexdigest() != entry['dbf_sha256']:
            raise ValueError('Source attributes changed')
        source_rows = collections.defaultdict(list)
        for number, original_row in attribute_rows(entry):
            identifiers = [(key, value) for key, value in original_row.items() if key.casefold() in {'objectid', 'objectid_1'}]
            attributes = {key: value for key, value in original_row.items() if key.casefold() not in {'objectid', 'objectid_1'}}
            seen_ids = set()
            for key, value in identifiers:
                row = source_fields(dict(attributes, OBJECTID=value))
                row['_source_id_field'] = original_row.get('_source_original_id_field', key)
                if original_row.get('_source_original_ids'):
                    row['_source_original_ids'] = original_row['_source_original_ids']
                identifier = identity_code(value)
                if identifier in seen_ids:
                    continue
                seen_ids.add(identifier)
                if {'OBJECTID', 'State_LGD', 'Dist_LGD', 'Subdis_LGD', 'Vill_LGD', 'Vill_name', 'District', 'Sub_dist', 'Vill_Cat'} <= row.keys():
                    source_rows[identifier].append((number, row))
        for layer in catalogue['layers']:
            if layer['state'] != entry['state'] or (layer['metadata'].get('sha256') or layer['metadata'].get('archive_sha256')) != entry['sha256']:
                continue
            file = layer['file']
            path = application / 'storage/app/india-map/snapshot' / file
            if not path.exists():
                path = application / 'public' / file
            raw = path.read_bytes()
            if hashlib.sha256(raw).hexdigest() != layer['sha256']:
                raise ValueError('Original display geometry changed')
            features = json.loads(raw).get('features', [])
            state = states.get(normalize_name(layer['state']))
            for index, feature in enumerate(features):
                if (file, index) in linked:
                    continue
                sources_for_id = [(number, row) for number, row in source_rows.get(identity_code(feature.get('id')), [])
                                  if same_source_feature(feature['properties'], feature.get('id'), layer, row)]
                sources_for_id = list({number: (number, row) for number, row in sources_for_id}.values())
                if len(sources_for_id) != 1:
                    held['source_feature_identifier_not_unique'] += 1
                    continue
                number, source = sources_for_id[0]
                if not same_source_feature(feature['properties'], feature.get('id'), layer, source):
                    held['source_feature_attributes_differ'] += 1
                    continue
                matches = verified_candidates(source, state, directory, census)
                if len(matches) != 1:
                    held['qualified_official_identity_unresolved'] += 1
                    continue
                village, basis = matches[0]
                basis += '; SOI archive SHA and retained source feature checked; polygon boundary edition unverified'
                target.execute('INSERT INTO shapes VALUES (?,?,?,?,?,?)', (file, index, village['lgd'], state, basis, layer['sha256']))
                proof = {'source_url': entry['url'], 'source_archive_sha256': entry['sha256'], 'source_attribute_sha256': entry['dbf_sha256'], 'source_attribute_format': entry.get('format', 'dbf'), 'source_record': number,
                         'source_id': feature.get('id'), 'source_id_field': source['_source_id_field'], 'source_codes': {k: source[k] for k in ['State_LGD', 'Dist_LGD', 'Subdis_LGD', 'Vill_LGD']},
                         'source_name': source['Vill_name'], 'lgd_code': village['lgd'], 'census2011_code': village['census2011'], 'basis': basis}
                if source.get('_source_original_ids'):
                    proof['original_source_identifiers'] = json.loads(source['_source_original_ids'])
                target.execute('INSERT INTO evidence VALUES (?,?,?)', (file, index, json.dumps(proof)))
                counts[entry['state']] += 1
                added.append({'file': file, 'feature_index': index, 'lgd': village['lgd'], 'state': state, 'basis': basis})
    total = target.execute('SELECT count(*) FROM shapes').fetchone()[0]
    passes = metadata.get('qualified_soi_passes', [])
    if metadata.get('qualified_soi_added_shapes'):
        passes.append({key: value for key, value in metadata.items() if key.startswith('qualified_') and key != 'qualified_soi_passes'})
    metadata.update({'qualified_soi_passes': passes, 'qualified_soi_added_shapes': sum(counts.values()), 'qualified_soi_states': dict(counts), 'total_display_shapes': total,
                     'qualified_soi_archives': sources, 'qualified_soi_held': dict(held), 'qualified_census_inventory_sha256': hashlib.sha256(census_path.read_bytes()).hexdigest()})
    target.execute('DELETE FROM metadata')
    target.execute('INSERT INTO metadata VALUES (?)', (json.dumps(metadata),))
    target.commit()
    target.close()
    output.with_suffix('.added.json').write_text(json.dumps(added, indent=2))
    print(json.dumps({'added_shapes': sum(counts.values()), 'states': dict(counts), 'held': dict(held), 'total_display_shapes': total}), flush=True)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('application', type=Path)
    parser.add_argument('manifest', type=Path)
    parser.add_argument('census', type=Path)
    parser.add_argument('output', type=Path)
    args = parser.parse_args()
    build(args.application, args.manifest, args.census, args.output)
