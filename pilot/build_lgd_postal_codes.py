"""Build a source-preserving LGD postal index from complete OGD API pages."""
import argparse
import collections
import hashlib
import json
import pathlib
import re
import sqlite3


def build(source, destination):
    temporary = destination.with_suffix('.building.sqlite')
    if temporary.exists():
        raise RuntimeError('A previous build exists; inspect it before retrying')
    db = sqlite3.connect(temporary)
    try:
        _build(db, source, destination, temporary)
    finally:
        db.close()


def _build(db, source, destination, temporary):
    db.executescript('''
      CREATE TABLE places(kind TEXT,code TEXT,state TEXT,district TEXT,subdistrict TEXT,
        name TEXT,state_name TEXT,district_name TEXT,subdistrict_name TEXT,
        pin TEXT,raw_pin TEXT,source_row INTEGER,status TEXT);
      CREATE TABLE metadata(kind TEXT PRIMARY KEY,json TEXT);
    ''')
    report = {}
    for kind, field, resource in [
        ('village', 'villageCode', 'f17a1608-5f10-4610-bb50-a63c80d83974'),
        ('local-body', 'localBodyCode', '71818d1a-c114-46cb-aa9b-56ed70d4bc4a'),
    ]:
        prefix = 'rural' if kind == 'village' else 'urban'
        offset = 0
        total = None
        updated = None
        previous_code = -1
        archives = []
        counts = collections.Counter()
        while total is None or offset < total:
            path = source / f'{prefix}-{offset}.json'
            raw = path.read_bytes()
            page = json.loads(raw)
            if total is not None and (total != int(page['total']) or updated != page['updated_date']):
                raise ValueError('Source changed between pages')
            total, updated = int(page['total']), page['updated_date']
            if not page['records']:
                raise ValueError('Empty source page')
            archives.append({'file': path.name, 'sha256': hashlib.sha256(raw).hexdigest(), 'rows': len(page['records'])})
            for row in page['records']:
                offset += 1
                code = str(row[field])
                if not code.isdigit() or int(code) < previous_code:
                    raise ValueError('Invalid code or unstable page order')
                previous_code = int(code)
                state = str(row['stateCode'])
                district = str(row.get('districtCode', ''))
                subdistrict = str(row.get('subdistrictCode', ''))
                if not state.isdigit() or (kind == 'village' and (not district.isdigit() or not subdistrict.isdigit())):
                    raise ValueError('Invalid administrative code')
                raw_pin = '' if row.get('pincode') is None else str(row['pincode']).strip()
                valid = bool(re.fullmatch(r'[1-9][0-9]{5}', raw_pin))
                status = 'reported' if valid else ('missing' if raw_pin in ('', '0', 'NULL', 'null', 'NA') else 'invalid')
                counts[status] += 1
                db.execute('INSERT INTO places VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', (
                    kind, code, state, district, subdistrict,
                    row['villageNameEnglish' if kind == 'village' else 'localBodyNameEnglish'],
                    row['stateNameEnglish'], row.get('districtNameEnglish', ''), row.get('subdistrictNameEnglish', ''),
                    raw_pin if valid else None, raw_pin, offset, status,
                ))
            if offset > total:
                raise ValueError('Page count exceeds source total')
        metadata = {'resource': resource, 'source_url': 'https://www.data.gov.in/resource/local-government-directory-lgd-'+('villages' if kind == 'village' else 'local-bodies')+'-pin-codes',
                    'updated_at': updated, 'retrieved_on': '2026-11-02', 'rows': total, 'counts': dict(counts), 'archives': archives}
        db.execute('INSERT INTO metadata VALUES (?,?)', (kind, json.dumps(metadata)))
        report[kind] = metadata
    db.executescript('CREATE INDEX identity ON places(kind,code,state); CREATE INDEX postal ON places(pin,kind,code);')
    db.commit()
    if db.execute('PRAGMA integrity_check').fetchone()[0] != 'ok':
        raise ValueError('Database integrity check failed')
    for kind in report:
        report[kind]['entities'] = db.execute('SELECT count(DISTINCT code) FROM places WHERE kind=?', (kind,)).fetchone()[0]
        report[kind]['multiple_pins'] = db.execute('SELECT count(*) FROM (SELECT code FROM places WHERE kind=? AND pin IS NOT NULL GROUP BY code HAVING count(DISTINCT pin)>1)', (kind,)).fetchone()[0]
    db.close()
    temporary.replace(destination)
    destination.with_suffix('.audit.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
    print(json.dumps({k: {x: v[x] for x in ('rows', 'entities', 'counts', 'multiple_pins', 'updated_at')} for k, v in report.items()}))


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('source', type=pathlib.Path)
    parser.add_argument('destination', type=pathlib.Path)
    args = parser.parse_args()
    build(args.source, args.destination)
