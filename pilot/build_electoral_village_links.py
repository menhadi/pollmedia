"""Link official LGD memberships to existing SOI shapes; never copy geometry."""
import argparse
import collections
import hashlib
import json
import re
import sqlite3
import tarfile
import xml.etree.ElementTree as ET
import zipfile
from decimal import Decimal
from pathlib import Path

NS = '{http://schemas.openxmlformats.org/spreadsheetml/2006/main}'


def rows(path):
    with zipfile.ZipFile(path) as archive:
        if archive.testzip() is not None:
            raise ValueError('Damaged original workbook')
        strings = []
        with archive.open('xl/sharedStrings.xml') as stream:
            for _, element in ET.iterparse(stream, events=['end']):
                if element.tag == NS + 'si':
                    strings.append(''.join(element.itertext()))
                    element.clear()
        with archive.open('xl/worksheets/sheet1.xml') as stream:
            for _, element in ET.iterparse(stream, events=['end']):
                if element.tag != NS + 'row':
                    continue
                values = {}
                for cell in element:
                    column = re.sub('[0-9]', '', cell.get('r'))
                    value = cell.find(NS + 'v')
                    text = value.text if value is not None else ''.join(cell.itertext())
                    values[column] = strings[int(text)] if cell.get('t') == 's' else text
                yield int(element.get('r')), values
                element.clear()


def code(value):
    if not value:
        return ''
    number = Decimal(value)
    if number != int(number) or number < 0:
        raise ValueError('Invalid official code')
    return str(int(number))


def norm(value):
    return re.sub(r'[\W_]', '', str(value).casefold(), flags=re.UNICODE)


def state_context(name, source, relative):
    national = 'https://surveyofindia.gov.in/documents/State_District_Subdistrict_PAN%20INDIA.rar'
    return source == national or norm(name) in norm(source + relative)


def build(membership, directory, shapes, output):
    output.mkdir(parents=True, exist_ok=True)
    path = output / 'links.sqlite'
    if path.exists():
        raise FileExistsError('Use a fresh output directory')
    db = sqlite3.connect(path)
    db.executescript('''
      CREATE TABLE states(code TEXT PRIMARY KEY,name TEXT);
      CREATE TABLE seats(state TEXT,kind TEXT,code TEXT,name TEXT,PRIMARY KEY(state,kind,code));
      CREATE TABLE membership(source_row INTEGER PRIMARY KEY,state TEXT,pc TEXT,ac TEXT,entity_type TEXT,entity_code TEXT,entity_name TEXT,coverage TEXT);
      CREATE INDEX membership_pc ON membership(state,pc,entity_type,entity_code);
      CREATE INDEX membership_ac ON membership(state,ac,entity_type,entity_code);
      CREATE TABLE directory(lgd TEXT PRIMARY KEY,state TEXT,district TEXT,subdistrict TEXT,name TEXT,census2011 TEXT);
      CREATE INDEX directory_census ON directory(census2011);
      CREATE TABLE ancestry(lgd TEXT PRIMARY KEY,state TEXT,district TEXT,subdistrict TEXT);
      CREATE INDEX ancestry_district ON ancestry(state,district);
      CREATE INDEX ancestry_subdistrict ON ancestry(state,subdistrict);
      CREATE TABLE layers(file TEXT PRIMARY KEY,sha256 TEXT,source TEXT,edition TEXT);
      CREATE TABLE shapes(file TEXT,feature_index INTEGER,lgd TEXT,state TEXT,basis TEXT,PRIMARY KEY(file,feature_index));
      CREATE INDEX shapes_lgd ON shapes(state,lgd);
    ''')
    types, coverage = collections.Counter(), collections.Counter()
    for position, r in rows(membership):
        if position == 2:
            assert r['B'] == 'State Code' and r['H'] == 'Entity Type' and r['K'] == 'Coverage Type'
        if position < 3:
            continue
        state, pc, ac = code(r.get('B')), code(r.get('D')), code(r.get('F'))
        db.execute('INSERT OR IGNORE INTO states VALUES (?,?)', (state, r.get('C')))
        for kind, identifier, name in [('pc', pc, r.get('E')), ('ac', ac, r.get('G'))]:
            if identifier and identifier != '0' and name:
                existing = db.execute('SELECT name FROM seats WHERE state=? AND kind=? AND code=?', (state, kind, identifier)).fetchone()
                if existing and existing[0] != name:
                    raise ValueError('Constituency code has conflicting names')
                db.execute('INSERT OR IGNORE INTO seats VALUES (?,?,?,?)', (state, kind, identifier, name))
        entity, identifier, extent = r.get('H'), code(r.get('I')), r.get('K')
        if not state or not pc or not entity or not identifier or extent not in ['Fully Covered', 'Partly Covered']:
            raise ValueError(f'Incomplete source row {position}: {r}')
        db.execute('INSERT INTO membership VALUES (?,?,?,?,?,?,?,?)', (position, state, pc, ac, entity, identifier, r.get('J'), extent))
        types[entity] += 1
        coverage[extent] += 1
    db.commit()
    directory_count = 0
    for position, r in rows(directory):
        if position == 2:
            assert r['H'] == 'Village Code' and r['O'] == 'Census 2011 Code'
        if position < 3:
            continue
        db.execute('INSERT OR IGNORE INTO states VALUES (?,?)', (code(r.get('B')), r.get('C')))
        db.execute('INSERT INTO directory VALUES (?,?,?,?,?,?)', (code(r.get('H')), code(r.get('B')), r.get('E'), r.get('G'), r.get('J'), code(r.get('O'))))
        db.execute('INSERT INTO ancestry VALUES (?,?,?,?)', (code(r.get('H')), code(r.get('B')), code(r.get('D')), code(r.get('F'))))
        directory_count += 1
    db.commit()
    linked = collections.Counter()
    with tarfile.open(shapes, 'r:gz') as archive:
        for member in archive:
            filename = member.name.removeprefix('./')
            if not member.isfile() or not filename.endswith('.geojson') or not (filename.startswith('maps/') or '/maps/' in filename):
                continue
            raw = archive.extractfile(member).read()
            data = json.loads(raw)
            metadata = data.get('metadata', {})
            source = metadata.get('source', '')
            if not source.startswith(('https://surveyofindia.gov.in/', 'https://nwdp.nwic.gov.in/')):
                continue
            relative = filename if filename.startswith('maps/') else 'maps/' + filename.split('/maps/', 1)[1]
            db.execute('INSERT INTO layers VALUES (?,?,?,?)', (relative, hashlib.sha256(raw).hexdigest(), source, str(metadata.get('source_edition', metadata.get('boundary_edition', 'Unverified')))))
            for index, feature in enumerate(data.get('features', [])):
                p = feature.get('properties', {})
                identifier = str(p.get('source_code') or '')
                if not identifier or identifier in ['0', '999999'] or p.get('status') == 'ambiguous':
                    linked['ambiguous_or_missing_source_code'] += 1
                    continue
                candidates = db.execute('SELECT * FROM directory WHERE lgd=? OR census2011=?', (identifier, identifier)).fetchall()
                candidates = [r for r in candidates if norm(r[4]) == norm(p.get('name')) and norm(r[3]) == norm(p.get('subdistrict') or p.get('source_subdistrict'))]
                if len(candidates) != 1:
                    linked['unmatched_or_ambiguous'] += 1
                    continue
                candidate = candidates[0]
                # State comes from the official directory; require a state-specific source URL/file.
                state_name = db.execute('SELECT name FROM states WHERE code=?', (candidate[1],)).fetchone()
                if not state_name or not state_context(state_name[0], source, relative):
                    linked['state_context_unverified'] += 1
                    continue
                db.execute('INSERT INTO shapes VALUES (?,?,?,?,?)', (relative, index, candidate[0], candidate[1], 'Unique official code + village name + subdistrict + state source context; boundary edition unverified'))
                linked['linked_shapes'] += 1
            db.commit()
    sources = [{'file': p.name, 'sha256': hashlib.sha256(p.read_bytes()).hexdigest()} for p in [membership, directory]]
    audit = {'source_url': 'https://lgdirectory.gov.in/downloadDirectory.do', 'checked_on': '2026-10-13', 'sources': sources,
             'source_rows': sum(types.values()), 'entity_types': dict(types), 'coverage_types': dict(coverage), 'directory_villages': directory_count,
             'shape_identity_checks': dict(linked), 'geometry_policy': 'Reuse hashed existing district files. Partial memberships never render a whole village as fully covered. Identity links do not verify boundary editions.'}
    db.execute('CREATE TABLE metadata(json TEXT)')
    db.execute('INSERT INTO metadata VALUES (?)', (json.dumps(audit),))
    db.commit()
    db.execute('VACUUM')
    db.close()
    (output / 'audit.json').write_text(json.dumps(audit, indent=2), encoding='utf8')
    print(json.dumps(audit))


def enrich(directory, output):
    db = sqlite3.connect(output / 'links.sqlite')
    db.executescript('CREATE TABLE IF NOT EXISTS ancestry(lgd TEXT PRIMARY KEY,state TEXT,district TEXT,subdistrict TEXT); CREATE INDEX IF NOT EXISTS ancestry_district ON ancestry(state,district); CREATE INDEX IF NOT EXISTS ancestry_subdistrict ON ancestry(state,subdistrict);')
    for position, r in rows(directory):
        if position < 3:
            continue
        db.execute('INSERT OR IGNORE INTO states VALUES (?,?)', (code(r.get('B')), r.get('C')))
        db.execute('INSERT OR REPLACE INTO ancestry VALUES (?,?,?,?)', (code(r.get('H')), code(r.get('B')), code(r.get('D')), code(r.get('F'))))
    db.commit()
    print(json.dumps({'official_ancestry_villages': db.execute('SELECT COUNT(*) FROM ancestry').fetchone()[0], 'official_states': db.execute('SELECT COUNT(*) FROM states').fetchone()[0]}), flush=True)
    db.close()


def relink(shapes, output):
    db = sqlite3.connect(output / 'links.sqlite')
    states = dict(db.execute('SELECT code,name FROM states'))
    expected = dict(db.execute('SELECT file,sha256 FROM layers'))
    counts = collections.Counter()
    db.execute('DELETE FROM shapes')
    with tarfile.open(shapes, 'r:gz') as archive:
        for member in archive:
            filename = member.name.removeprefix('./')
            if not member.isfile() or not filename.endswith('.geojson') or not (filename.startswith('maps/') or '/maps/' in filename):
                continue
            relative = filename if filename.startswith('maps/') else 'maps/' + filename.split('/maps/', 1)[1]
            if relative not in expected:
                continue
            raw = archive.extractfile(member).read()
            if hashlib.sha256(raw).hexdigest() != expected[relative]:
                raise ValueError('Archived geometry changed')
            data = json.loads(raw)
            source = data['metadata']['source']
            for index, feature in enumerate(data['features']):
                p = feature.get('properties', {})
                identifier = str(p.get('source_code') or '')
                if not identifier or identifier in ['0', '999999'] or p.get('status') == 'ambiguous':
                    counts['ambiguous_or_missing_source_code'] += 1
                    continue
                candidates = db.execute('SELECT * FROM directory WHERE lgd=? OR census2011=?', (identifier, identifier)).fetchall()
                candidates = [r for r in candidates if norm(r[4]) == norm(p.get('name')) and norm(r[3]) == norm(p.get('subdistrict') or p.get('source_subdistrict'))]
                if len(candidates) != 1:
                    counts['unmatched_or_ambiguous'] += 1
                    continue
                candidate = candidates[0]
                if not state_context(states[candidate[1]], source, relative):
                    counts['state_context_unverified'] += 1
                    continue
                db.execute('INSERT INTO shapes VALUES (?,?,?,?,?)', (relative, index, candidate[0], candidate[1], 'Unique official code + village name + subdistrict + official national/state source context; boundary edition unverified'))
                counts['linked_shapes'] += 1
            db.commit()
    audit = json.loads(db.execute('SELECT json FROM metadata').fetchone()[0])
    audit['shape_identity_checks'] = dict(counts)
    audit['official_states'] = len(states)
    audit['official_ancestry_villages'] = db.execute('SELECT COUNT(*) FROM ancestry').fetchone()[0]
    db.execute('UPDATE metadata SET json=?', (json.dumps(audit),))
    db.commit()
    db.close()
    (output / 'audit.json').write_text(json.dumps(audit, indent=2), encoding='utf8')
    print(json.dumps(audit), flush=True)


def add_legacy(path, output):
    db = sqlite3.connect(output / 'links.sqlite')
    raw = path.read_bytes()
    data = json.loads(raw)
    source = data['sources']['geometry']
    if not source.startswith('https://surveyofindia.gov.in/'):
        raise ValueError('Missing official existing geometry source')
    relative = 'maps/' + path.name
    db.execute('INSERT OR REPLACE INTO layers VALUES (?,?,?,?)', (relative, hashlib.sha256(raw).hexdigest(), source, 'Unverified'))
    linked = 0
    for index, row in enumerate(data['rows']):
        if not row.get('geometry') or row.get('geometry_status') != 'code_linked' or row.get('status') != 'linked':
            continue
        links = {str(link['code']) for link in row.get('directory_links', []) if link.get('census_2011_code') == row['code']}
        candidates = db.execute('SELECT lgd,state FROM directory WHERE census2011=?', (row['code'],)).fetchall()
        candidates = [r for r in candidates if r[0] in links]
        if len(candidates) != 1:
            continue
        lgd, state = candidates[0]
        db.execute('INSERT OR REPLACE INTO shapes VALUES (?,?,?,?,?)', (relative, index, lgd, state, 'Existing accepted geometry code link + accepted Census/LGD crosswalk rechecked against national directory; boundary edition unverified'))
        linked += 1
    audit = json.loads(db.execute('SELECT json FROM metadata').fetchone()[0])
    audit['existing_structured_district_files'] = [{'file': relative, 'sha256': hashlib.sha256(raw).hexdigest(), 'linked_shapes': linked}]
    audit['shape_identity_checks']['linked_shapes'] = db.execute('SELECT COUNT(*) FROM shapes').fetchone()[0]
    db.execute('UPDATE metadata SET json=?', (json.dumps(audit),))
    db.commit()
    db.close()
    (output / 'audit.json').write_text(json.dumps(audit, indent=2), encoding='utf8')
    print(json.dumps({'existing_file': relative, 'linked_shapes': linked}), flush=True)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    for name in ['membership', 'directory', 'shapes', 'output']:
        parser.add_argument('--' + name, type=Path, required=name in ['directory', 'output'])
    parser.add_argument('--enrich', action='store_true')
    parser.add_argument('--relink', action='store_true')
    parser.add_argument('--legacy', type=Path)
    args = parser.parse_args()
    if args.legacy:
        add_legacy(args.legacy, args.output)
    elif args.relink:
        if not args.shapes:
            parser.error('--shapes is required for relinking')
        relink(args.shapes, args.output)
    elif args.enrich:
        enrich(args.directory, args.output)
    else:
        if not args.membership or not args.shapes:
            parser.error('--membership and --shapes are required for a new build')
        build(args.membership, args.directory, args.shapes, args.output)
