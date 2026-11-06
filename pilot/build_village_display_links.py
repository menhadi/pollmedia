"""Add display crosswalks without changing original LGD tables or geometry."""
import argparse
import collections
import hashlib
import json
import re
import sqlite3
from pathlib import Path


def normalize_name(value):
    value = re.sub(r'\s*\(\s*\d+\s*\)\s*$', '', str(value))
    return re.sub(r'[\W_]', '', value.casefold())


def identity_code(value):
    text = str(value or '').strip()
    return str(int(text)) if text.isdigit() and 0 < int(text) < 999999 else ''


def candidates(properties, state, directory):
    code = identity_code(properties.get('source_code'))
    if not code or properties.get('status') == 'ambiguous':
        return []
    return [r for r in directory.get(code, []) if r['state'] == state
            and normalize_name(r['name']) == normalize_name(properties.get('name', ''))
            and normalize_name(r['subdistrict']) == normalize_name(properties.get('subdistrict') or properties.get('source_subdistrict', ''))]


def census_inventory(path):
    records = collections.defaultdict(list)
    if path is None:
        return records
    with path.open(encoding='utf-8') as stream:
        for line in stream:
            row = json.loads(line)
            if row[6] == 'VILLAGE':
                records[(row[2], identity_code(row[5]))].append(row)
    return records


def census_candidates(properties, state, directory, records):
    code = identity_code(properties.get('source_code'))
    if not code or properties.get('status') == 'ambiguous':
        return []
    census_states = {'36': ['28'], '37': ['01'], '38': ['25', '26']}.get(state, [str(state).zfill(2)])
    published = [r for census_state in census_states for r in records.get((census_state, code), [])]
    identities = {(r[2], r[3], r[4], r[5]) for r in published}
    if len(identities) != 1 or normalize_name(published[0][7]) != normalize_name(properties.get('name', '')):
        return []
    matches = [r for r in directory.get(code, []) if r['state'] == state and identity_code(r.get('census2011')) == code]
    return matches if len(matches) == 1 else []


def build(application, output, census_path=None):
    original = application / 'storage/app/electoral-villages/links.sqlite'
    original_sha = hashlib.sha256(original.read_bytes()).hexdigest()
    db = sqlite3.connect(f'file:{original.as_posix()}?mode=ro', uri=True)
    db.row_factory = sqlite3.Row
    directory = collections.defaultdict(dict)
    for row in db.execute('SELECT * FROM directory'):
        row = dict(row)
        for code in {identity_code(row['lgd']), identity_code(row['census2011'])} - {''}:
            directory[code][row['lgd']] = row
    directory = {key: list(rows.values()) for key, rows in directory.items()}
    states = {normalize_name(r['name']): r['code'] for r in db.execute('SELECT * FROM states')}
    layers = {r['file']: r['sha256'] for r in db.execute('SELECT * FROM layers')}
    existing = {(r['file'], r['feature_index']) for r in db.execute('SELECT file,feature_index FROM shapes')}
    catalogue = json.loads((application / 'storage/app/india-map/catalogue.json').read_text())
    if output.exists():
        raise FileExistsError(output)
    target = sqlite3.connect(output)
    target.executescript('CREATE TABLE shapes(file TEXT,feature_index INTEGER,lgd TEXT,state TEXT,basis TEXT,sha256 TEXT,PRIMARY KEY(file,feature_index)); CREATE INDEX shapes_lgd ON shapes(state,lgd); CREATE TABLE layers(file TEXT PRIMARY KEY,sha256 TEXT,source TEXT,edition TEXT); CREATE TABLE metadata(json TEXT);')
    previous = application / 'storage/app/electoral-villages/display-links.sqlite'
    retained = 0
    if previous.exists():
        old = sqlite3.connect(f'file:{previous.as_posix()}?mode=ro', uri=True)
        metadata = json.loads(old.execute('SELECT json FROM metadata').fetchone()[0])
        if metadata['original_links_sha256'] != original_sha:
            raise ValueError('Previous crosswalk has a different LGD source edition')
        rows = old.execute('SELECT file,feature_index,lgd,state,basis,sha256 FROM shapes').fetchall()
        target.executemany('INSERT INTO shapes VALUES (?,?,?,?,?,?)', rows)
        retained = len(rows)
        existing.update((r[0], r[1]) for r in rows)
        if old.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='layers'").fetchone():
            rows = old.execute('SELECT file,sha256,source,edition FROM layers').fetchall()
            target.executemany('INSERT INTO layers VALUES (?,?,?,?)', rows)
        old.close()
    records = census_inventory(census_path)
    counts = collections.Counter()
    bases = collections.Counter()
    for layer in catalogue['layers']:
        file = layer['file']
        if file in layers and layers[file] != layer['sha256']:
            continue
        path = application / 'storage/app/india-map/snapshot' / file
        if not path.exists():
            path = application / 'public' / file
        raw = path.read_bytes()
        if hashlib.sha256(raw).hexdigest() != layer['sha256']:
            raise ValueError(f'Geometry changed: {file}')
        data = json.loads(raw)
        state = states.get(normalize_name(layer['state']))
        code_counts = collections.Counter(identity_code(f['properties'].get('source_code')) for f in data.get('features', []))
        registered = file in layers
        for index, feature in enumerate(data.get('features', [])):
            if (file, index) in existing:
                continue
            properties = feature['properties']
            if code_counts[identity_code(properties.get('source_code'))] != 1:
                continue
            matches = candidates(properties, state, directory)
            basis = 'Unique official code, village name (terminal directory number ignored), subdistrict and state; boundary edition unverified'
            method = 'directory_context'
            if len(matches) != 1:
                matches = census_candidates(properties, state, directory, records)
                basis = 'Published Census 2011 code and village name, unique geographic identity, and explicit unique LGD Census crosswalk; current LGD ancestry retained; boundary edition unverified'
                method = 'published_census_crosswalk'
            if len(matches) != 1:
                continue
            match = matches[0]
            if not registered:
                target.execute('INSERT OR IGNORE INTO layers VALUES (?,?,?,?)', (file, layer['sha256'], layer['source'], layer.get('metadata', {}).get('source_edition', 'Unverified')))
                registered = True
            target.execute('INSERT INTO shapes VALUES (?,?,?,?,?,?)', (file, index, match['lgd'], state, basis, layer['sha256']))
            counts[layer['state']] += 1
            bases[method] += 1
    audit = {'original_links_sha256': original_sha, 'retained_shapes': retained,
             'added_shapes': sum(counts.values()), 'total_display_shapes': retained + sum(counts.values()),
             'states': dict(counts), 'match_methods': dict(bases),
             'additional_layers': target.execute('SELECT count(*) FROM layers').fetchone()[0],
             'census_inventory_sha256': hashlib.sha256(census_path.read_bytes()).hexdigest() if census_path else None,
             'policy': 'Additive display identity crosswalk; original IDs, tables and polygons intact. No fuzzy or name-only matches; ambiguous source or Census identities withheld.'}
    target.execute('INSERT INTO metadata VALUES (?)', (json.dumps(audit),))
    target.commit()
    target.close()
    db.close()
    print(json.dumps(audit), flush=True)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('application', type=Path)
    parser.add_argument('output', type=Path)
    parser.add_argument('--census-inventory', type=Path)
    args = parser.parse_args()
    build(args.application, args.output, args.census_inventory)
